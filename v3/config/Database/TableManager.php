<?php

require_once(__DIR__ . "/DatabaseConnection.php");
require_once(__DIR__ . "/../Library/Security/SecurityUtil.php");
require_once(__DIR__ . "/DatabaseMiddleware.php");

/**
 * MySQL table management and query builder class
 */
class TableManager extends DatabaseConnection
{
    private float $time;
    private static $tableCache = [];
    private DatabaseMiddleware $middleware;
    private const CACHE_DIR = __DIR__ . '/../Cache';
    private const CACHE_DURATION = 3600; // 1 hour

    public function __construct()
    {
        parent::__construct();
        $this->time = microtime(true);
        $this->initializeCache();
        $this->middleware = new DatabaseMiddleware();
    }

    /**
     * Initialize cache directory
     * @throws RuntimeException
     */
    private function initializeCache(): void
    {
        if (!is_dir(self::CACHE_DIR) && !mkdir(self::CACHE_DIR, 0755, true)) {
            throw new RuntimeException("Failed to create cache directory");
        }
    }

    /**
     * Convierte un valor que puede ser array en un valor simple
     */
    private function normalizeValue($value)
    {
        if (is_array($value) && count($value) === 1) {
            return reset($value);
        }
        return $value;
    }

    /**
     * Normaliza un array de datos, convirtiendo arrays de un solo elemento en valores simples
     */
    private function normalizeData($data)
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            $normalized[$key] = $this->normalizeValue($value);
        }
        return $normalized;
    }


    private static function objectToArray($obj): array
    {
        if (is_object($obj)) {
            $obj = (array) $obj;
        }

        if (is_array($obj)) {
            return array_map(fn($element) => self::objectToArray($element), $obj);
        }

        return [$obj];
    }

    private static function allArrayOneArray(array $valid_data): array
    {
        return $values = array_map(function ($value) {
            return is_array($value) ? implode(', ', $value) : $value;
        }, array_values($valid_data));
    }


    /**
     * Get table description with caching
     * @param string $tableName
     * @param bool $force
     * @return array
     * @throws PDOException|RuntimeException
     */
    private function getTableDescription(string $tableName, bool $force = false): array
    {
        // Check memory cache first
        if (!$force && isset(static::$tableCache[$tableName])) {
            return static::$tableCache[$tableName];
        }

        $cacheFile = static::CACHE_DIR . "/{$tableName}_description.json";

        // Check file cache if not in memory
        if (!$force && is_file($cacheFile)) {
            $cacheAge = time() - filemtime($cacheFile);
            if ($cacheAge < static::CACHE_DURATION) {
                $cachedData = json_decode(file_get_contents($cacheFile), true);
                if ($cachedData !== null) {
                    static::$tableCache[$tableName] = $cachedData;
                    return $cachedData;
                }
            }
        }

        // Validate table name
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new RuntimeException("Invalid table name");
        }

        try {
            // Get fresh data from database
            $pdo = static::getPDO();
            $stmt = $pdo->prepare("DESCRIBE `$tableName`");
            $stmt->execute();
            $description = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Save to cache
            static::$tableCache[$tableName] = $description;
            file_put_contents($cacheFile, json_encode($description, JSON_THROW_ON_ERROR));

            return $description;
        } catch (PDOException $e) {
            throw new PDOException("Failed to describe table: " . $e->getMessage(), (int) $e->getCode());
        } catch (JsonException $e) {
            throw new RuntimeException("Failed to cache table description: " . $e->getMessage());
        }
    }


    public function describeEntityNewCache(string $tableName)
    {
        return $this->getTableDescription($tableName, true);
    }


    /**
     * Optimiza la generación de alias de tabla
     */
    private function generateTableAlias(string $table): string
    {
        return substr($table, 0, 1) . substr(md5($table), 0, 3);
    }

    private function extractCustomJoinTables(array $customJoins): array
    {
        $tables = [];
        foreach ($customJoins as $join) {
            if (preg_match('/\bJOIN\s+(\w+)\b/i', $join, $matches)) {
                $tables[] = $matches[1];
            }
        }
        return $tables;
    }

    /**
     * Procesa las condiciones WHERE de manera optimizada
     */
    private function processWhereConditions(array $tables, array $data, bool $alias_activar, array $table_aliases, ?array $customJoins = null): array
    {
        $where_conditions = [];
        $values = [];

        // Agregar tablas de custom joins
        if ($customJoins !== null) {
            $joinTables = $this->extractCustomJoinTables($customJoins);
            $tables = array_merge($tables, $joinTables);
        }

        // Procesar cada tabla
        foreach ($tables as $table) {
            $table_description = $this->getTableDescription($table);
            $table_fields = array_column($table_description, 'Field');
            $table_prefix = $alias_activar ? "{$table_aliases[$table]}." : "$table.";

            foreach ($data as $key => $value) {
                if (in_array($key, $table_fields) && $value !== null && $value !== '') {
                    $where_conditions[] = "$table_prefix$key = ?";
                    $values[] = $value;
                }
            }
        }

        return ['conditions' => $where_conditions, 'values' => $values];
    }

    /**
     * Realiza una consulta SELECT en una o varias tablas de la base de datos.
     *
     * @param array                 $tables       las tablas de las que se seleccionarán los datos
     * @param array|object          $data         los datos para filtrar la consulta
     * @param array                 $filter       filtros adicionales para la consulta
     * @param array                 $customJoins  joins personalizados para la consulta
     * @param array|null            $subquery     subconsultas para incluir
     * @param array                 $fieldper     campos permitidos en la consulta
     * @param bool                  $alias_activar si se deben usar alias en la consulta
     * @param int|null              $limit        límite de resultados
     * @param array|null|string     $orden        orden de los resultados
     *
     * @return object resultado de la consulta
     */
    public function selectAllTables(
        array $tables,
        $data = null,
        $filter = [],
        $customJoins = [],
        $subquery = null,
        $fieldper = [],
        $alias_activar = true,
        $limit = null,
        $orden = null
    ) {
        $adb = self::getPDO();
        $obj = (object) [];

        // Normalizar datos
        if ($data !== null) {
            $data = self::objectToArray($data);
            $data = $this->normalizeData($data);
        }

        // Validar los datos
        if ($data !== null) {
            foreach ($tables as $table) {
                if (!$this->middleware->validate($table, $data, 'select')) {
                    return (object) [
                        'msj' => 'Error de validación en la tabla ' . $table,
                        'errors' => $this->middleware->getErrors(),
                        'resp' => 'err'
                    ];
                }
            }
        }

        // Construir consulta SQL
        $sql = "SELECT " . (!empty($fieldper) ? implode(", ", $fieldper) : "*");
        $values = [];
        $where_conditions = [];

        // Generar alias de tablas
        $firstTable = reset($tables);
        $table_aliases = [];
        if ($alias_activar) {
            foreach ($tables as $table) {
                $table_aliases[$table] = $this->generateTableAlias($table);
            }
        }

        // Agregar FROM y JOINs
        $sql .= " FROM $firstTable" . ($alias_activar ? " AS {$table_aliases[$firstTable]}" : "");

        foreach ($tables as $table) {
            if ($table !== $firstTable) {
                $sql .= " NATURAL JOIN $table" .
                    ($alias_activar ? " AS {$table_aliases[$table]}" : "");
            }
        }

        // Agregar joins personalizados
        if ($customJoins !== null) {
            $sql .= " " . implode(" ", $customJoins);
        }

        // Procesar WHERE
        if ($data !== null) {
            $whereResult = $this->processWhereConditions($tables, $data, $alias_activar, $table_aliases, $customJoins);
            $where_conditions = array_merge($where_conditions, $whereResult['conditions']);
            $values = array_merge($values, $whereResult['values']);
        }

        // Agregar filtros adicionales
        if ($filter !== null) {
            foreach ($filter as $condition) {
                if (isset($condition['field'], $condition['value'])) {
                    $operator = $condition['operator'] ?? '=';
        
                    if ($operator === 'IN' && is_array($condition['value'])) {
                        // Si el operador es IN, la condición necesita ser manejada de forma especial.
                        $placeholders = implode(',', array_fill(0, count($condition['value']), '?'));
                        $where_conditions[] = "{$condition['field']} IN ($placeholders)";
                        $values = array_merge($values, $condition['value']);
                    } elseif ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
                        // Si el operador es IS NULL o IS NOT NULL, no agregamos valores.
                        $where_conditions[] = "{$condition['field']} $operator";
                    } else {
                        // Para otros operadores, se mantiene el comportamiento estándar
                        $where_conditions[] = "{$condition['field']} $operator ?";
                        $values[] = $condition['value'];
                    }
                }
            }
        }
        

        // Agregar subconsultas
        if ($subquery !== null) {
            $where_conditions = array_merge($where_conditions, $subquery);
        }

        // Finalizar consulta
        if (!empty($where_conditions)) {
            $sql .= " WHERE " . implode(" AND ", $where_conditions);
        }


        if (!empty($orden)) {
            $sql .= " ORDER BY " . (is_array($orden) ? implode(", ", $orden) : $orden);
        }
        
        if ($limit !== null) {
            $sql .= " LIMIT ".$limit;
        }

        try {
            $stmt = $adb->prepare($sql);
            $stmt->execute($values);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (ConfigurationManager::isDebugMode()) {
                $obj->debug = (object) [
                    'sql' => $sql,
                    'values' => $values,
                    'result_count' => count($result)
                ];
            }

            $obj->data = $result;
            $obj->count = count($result);
            $obj->resp = !empty($result) ? 'ok' : 'empty';
            $obj->TIME_SECOND = SecurityUtil::timeSecond($this->time);

        } catch (PDOException $e) {
            $obj->msj = "Error en la consulta: " . $e->getMessage();
            $obj->resp = 'err';
            if (ConfigurationManager::isDebugMode()) {
                $obj->debug = (object) [
                    'sql' => $sql,
                    'values' => $values,
                    'error' => $e->getMessage()
                ];
            }
        }

        $adb = null;
        return $obj;
    }

    /**
     * Inserta datos en una tabla de la base de datos.
     *
     * @param string       $table_name el nombre de la tabla en la que se insertarán los datos
     * @param array|object $data       los datos que se insertarán en la tabla
     *
     * @return object un objeto que contiene la respuesta de la inserción, incluyendo el ID generado si corresponde
     */
    public function insert($table_name, $data)
    {
        $adb = self::getPDO();
        $obj = (object) [];
        $data = self::objectToArray($data);
        $data = $this->normalizeData($data);  // Normalizar los datos

        // Obtener la descripción de la tabla
        $table_description = $this->getTableDescription($table_name);

        // Filtrar los campos de $data para asegurarse de que sean válidos y no sean NULL,
        // y que no sean claves primarias o autoincrementables
        $valid_data = [];

        foreach ($table_description as $column) {
            $key = $column['Field'];
            // Verifica si el campo cumple con las condiciones
            if (
                $column['Key'] != 'PRI'
                && strpos($column['Extra'], 'auto_increment') === false
                && isset($data[$key])
            ) {
                $valid_data[$key] = $data[$key];
            }

            // Agregar campos de fecha
            if (
                $column['Null'] == 'NO'
                && $column['Type'] == 'datetime'
                && !isset($valid_data[$key])
            ) {
                $valid_data[$key] = date('Y-m-d H:i:s');
            }
        }

        if (empty($valid_data)) {
            return (object) ['msj' => 'No se proporcionaron campos válidos para la inserción', 'resp' => 'err'];
        }

        // Validar los datos usando el middleware
        if (!$this->middleware->validate($table_name, $valid_data, 'insert')) {
            return (object) [
                'msj' => 'Error de validación',
                'errors' => $this->middleware->getErrors(),
                'resp' => 'err'
            ];
        }

        // Construir la cláusula de inserción
        $keys = implode(', ', array_keys($valid_data));
        $placeholders = implode(', ', array_fill(0, count($valid_data), '?'));
        $values = self::allArrayOneArray($valid_data);

        // Construir la consulta SQL de inserción de forma dinámica
        $sql = "INSERT INTO $table_name ($keys) VALUES ($placeholders)";

        $stmt = $adb->prepare($sql);

        try {
            $stmt->execute($values);

            if (ConfigurationManager::isDebugMode()) {
                $obj->debug['insert'] = (object) [
                    'sql' => $sql,
                    'keys' => $keys,
                    'placeholders' => $placeholders,
                    'values' => $values,
                ];
            }
        } catch (PDOException $e) {
            $obj = (object) [
                'msj' => 'Error al insertar los datos en la [' . $table_name . ']: ' . $e->getMessage(),
                'sql' => $sql,
                'keys' => $keys,
                'placeholders' => $placeholders,
                'values' => $values,
                'valid_data' => $valid_data,
                'resp' => 'err'
            ];
            return $obj;
        }

        $newname = 'id' . $table_name;
        $obj->insert_data[$newname] = $adb->lastInsertId();
        $obj->resp = ($obj->insert_data[$newname]) ? 'add' : 'err';
        $obj->TIME_SECOND = SecurityUtil::timeSecond($this->time);
        $adb = null;

        return $obj;
    }

    /**
     * Actualiza registros en una tabla de la base de datos.
     *
     * @param string $table_name el nombre de la tabla en la que actualizar los datos
     * @param array  $data       un arreglo asociativo con los datos a actualizar
     * @param array  $where      un arreglo asociativo con los criterios de actualización
     *
     * @return array|object el número de filas afectadas por la actualización o false si falla
     */
    public function update($table_name, $data, $where = null)
    {
        $adb = self::getPDO();
        $obj = (object) [];
        $data = self::objectToArray($data);
        $data = $this->normalizeData($data);  // Normalizar los datos
        if ($where !== null) {
            $where = self::objectToArray($where);
            $where = $this->normalizeData($where);  // Normalizar where
        }
        // Validar los datos usando el middleware
        if (!$this->middleware->validate($table_name, $data, 'update')) {
            return (object) [
                'msj' => 'Error de validación',
                'errors' => $this->middleware->getErrors(),
                'resp' => 'err'
            ];
        }

        // Obtener la descripción de la tabla
        $table_description = $this->getTableDescription($table_name);

        // Filtrar los campos de $data para asegurarse de que sean válidos y no sean NULL,
        // y que no sean claves primarias o autoincrementables
        $valid_data = [];
        foreach ($data as $key => $value) {
            foreach ($table_description as $column) {
                if (
                    $column['Field'] == $key
                    && $column['Key'] != 'PRI'
                    && strpos($column['Extra'], 'auto_increment') === false
                ) {
                    $valid_data[$key] = $value;
                    break;
                }

                if (
                    $column['Type'] == 'datetime'
                    && substr($column['Field'], 0, 6) === 'update'
                ) {
                    $valid_data[$column['Field']] = date('Y-m-d H:i:s');
                    // var_dump($valid_data); die;
                }
            }
        }

        if (empty($valid_data)) {
            return ['msj' => 'No se proporcionaron campos válidos para la actualización', 'resp' => 'err'];
        }

        // Construir la cláusula WHERE basada en el arreglo $where
        $where_conditions = [];
        foreach ($where as $key => $value) {
            foreach ($table_description as $column) {
                if ($column['Field'] == $key) {
                    $where_conditions[] = "$key = ?";
                    $where_values[] = $value;
                    break;
                }
            }
        }

        if (empty($where_conditions)) {
            return ['msj' => 'No se proporcionaron campos válidos para la cláusula WHERE', 'resp' => 'err'];
        }

        $where_clause = implode(' AND ', $where_conditions);

        $nameset_values = [];
        foreach ($valid_data as $key => $value) {
            $nameset_values[] = "$key = ?";
            $set_values[] = $value;
        }

        $set_clause = implode(', ', $nameset_values);

        $xsql = "UPDATE $table_name SET $set_clause WHERE $where_clause";
        $stmt = $adb->prepare($xsql);

        $values = array_merge(self::allArrayOneArray($set_values), $where_values);
        $stmt->execute($values);

        if (ConfigurationManager::isDebugMode()) {
            $obj->debug['update'] = (object) [
                'sql' => $xsql,
                'valid_data' => $valid_data,
                'where_conditions' => $where_conditions,
                'where_values' => $where_values,
                'values' => $values,
            ];
        }

        $obj->resp = ($obj->count = $stmt->rowCount()) ? 'edit' : 'no_change';
        $obj->TIME_SECOND = SecurityUtil::timeSecond($this->time);

        $adb = null;

        return $obj;
    }

    /**
     * Elimina registros de una tabla de la base de datos.
     *
     * @param string $table_name el nombre de la tabla en la que realizar la eliminación
     * @param array  $data       un arreglo asociativo con los criterios de eliminación
     *
     * @return array|object el número de filas afectadas por la eliminación o false si falla
     */
    public function delete($table_name, $data)
    {
        $adb = self::getPDO();
        $obj = (object) [];
        $data = self::objectToArray($data);
        $data = $this->normalizeData($data);  // Normalizar los datos

        // Validar los datos usando el middleware
        if (!$this->middleware->validate($table_name, $data, 'delete')) {
            return (object) [
                'msj' => 'Error de validación',
                'errors' => $this->middleware->getErrors(),
                'resp' => 'err'
            ];
        }

        // Obtener la descripción de la tabla
        $table_description = $this->getTableDescription($table_name);

        // Verificar que el campo en la condición de borrado sea un campo que contenga "id"
        $valid_where = [];
        foreach ($data as $key => $value) {
            foreach ($table_description as $column) {
                if ($column['Field'] == $key && stripos($key, 'id') !== false) {
                    $valid_where[$key] = $value;
                    break;
                }
            }
        }
        $obj->delete_data = $valid_where;

        if (empty($valid_where)) {
            return ['msj' => 'La condición de borrado no contiene un campo válido con [id]', 'resp' => 'err'];
        }

        $where_clause = implode(' AND ', array_map(function ($key) {
            return "$key = ?";
        }, array_keys($valid_where)));

        $stmt = $adb->prepare("DELETE FROM $table_name WHERE $where_clause");
        $stmt->execute(array_values($valid_where));
        $obj->resp = ($obj->count = $stmt->rowCount()) ? 'dele' : 'no_registro';
        $obj->TIME_SECOND = SecurityUtil::timeSecond($this->time);

        $adb = null;

        return $obj;
    }

    /**
     * Set validation rules for a table
     * @param string $table Table name
     * @param array $rules Validation rules
     * @return void
     */
    public function setValidationRules(string $table, array $rules): void
    {
        $this->middleware->addRules($table, $rules);
    }

    /**
     * Set custom validation messages
     * @param array $messages Custom validation messages
     * @return void
     */
    public function setValidationMessages(array $messages): void
    {
        $this->middleware->setMessages($messages);
    }

    /*
     * ==========================================
     * DEPRECATED METHODS - Will be removed in future versions
     * ==========================================
     */

    /**
     * @deprecated This method is deprecated and will be removed in future versions.
     * Use array_merge() instead.
     */
    public function combineObjects(object $obj1, object $obj2): object
    {
        trigger_error('Method ' . __METHOD__ . ' is deprecated', E_USER_DEPRECATED);
        return (object) array_merge((array) $obj1, (array) $obj2);
    }

    /**
     * @deprecated This method is deprecated and will be removed in future versions.
     * Use direct array access instead.
     */
    public function getInsert($datatable_name, $name)
    {
        trigger_error('Method ' . __METHOD__ . ' is deprecated', E_USER_DEPRECATED);
        return $datatable_name->insert_data[$name];
    }

    /**
     * @deprecated This method is deprecated and will be removed in future versions.
     * Use selectAllTables() instead with appropriate parameters.
     */
    public function select(string $table_name, array $data = [], array $options = []): object
    {
        trigger_error('Method ' . __METHOD__ . ' is deprecated. Use selectAllTables() instead', E_USER_DEPRECATED);
        try {
            $table_description = $this->getTableDescription($table_name);
            $valid_data = array_intersect_key($data, array_flip(array_column($table_description, 'Field')));
            $where_conditions = [];
            $params = [];
            foreach ($valid_data as $key => $value) {
                $where_conditions[] = "`$key` = :$key";
                $params[":$key"] = $value;
            }

            $where_clause = !empty($where_conditions) ? " WHERE " . implode(" AND ", $where_conditions) : "";
            $limit_clause = isset($options['limit']) ? " LIMIT " . (int) $options['limit'] : "";
            $order_clause = isset($options['order']) ? " ORDER BY " . $options['order'] : "";

            $query = "SELECT * FROM `$table_name`" . $where_clause . $order_clause . $limit_clause;

            $pdo = self::getPDO();
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);

            return (object) [
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'count' => $stmt->rowCount(),
                'query' => $query,
                'params' => $params
            ];
        } catch (PDOException $e) {
            throw new PDOException("Select query failed: " . $e->getMessage(), (int) $e->getCode());
        }
    }


    /**
     * Construye una respuesta estandarizada a partir de múltiples selects.
     * 
     * @param array<string,object> $selectsWithKeys Array asociativo donde:
     *  - Las keys son los nombres de los campos en la respuesta
     *  - Los valores son objetos select con propiedades:
     *  - data: mixed (datos del select)
     *  - resp: mixed (información de respuesta)
     *  - TIME_SECOND: float (tiempo de ejecución)
     * 
     * @return object Objeto con la estructura:
     *  {
     *      [key1]: mixed (data del select1),
     *      [key2]: mixed (data del select2),
     *      ...,
     *      resp: mixed (resp del primer select),
     *      TIME_SECOND: float (suma de tiempos)
     *  }
     */
    public static function buildSelectResponse(array $selectsWithKeys): object
    {
        if (empty($selectsWithKeys)) {
            return (object) [
                'resp' => null,
                'TIME_SECOND' => 0
            ];
        }

        // Obtenemos el primer select para 'resp'
        $firstSelect = reset($selectsWithKeys);

        return (object) [
            // Extraemos todos los data usando array_map
            ...array_map(fn($select) => $select->data, $selectsWithKeys),

            // Agregamos resp del primer select
            'resp' => $firstSelect->resp,

            // Calculamos el tiempo total usando array_reduce
            'TIME_SECOND' => array_reduce(
                $selectsWithKeys,
                fn($total, $select) => $total + $select->TIME_SECOND,
                0
            )
        ];
    }




    /**
     * Calcula el límite y el offset para la paginación.
     *
     * Esta función devuelve el valor que debe ser utilizado en la cláusula LIMIT de una consulta SQL
     * para implementar la paginación. El número de página se multiplica por la cantidad de registros
     * por página para calcular el `offset` y el número de registros que se deben mostrar.
     *
     * @param int $pagina El número de página que se desea obtener. Debe ser mayor o igual a 1.
     * @return string El valor del límite en formato 'offset, cantidad' que puede ser utilizado en SQL.
     */
    public static function obtenerLimit($pagina) {
        $registrosPorPagina = 20;  // Número de registros por página
        $offset = ($pagina - 1) * $registrosPorPagina;  // Cálculo del offset
    
        return "$offset, $registrosPorPagina";  // Retorna el límite en formato 'offset, cantidad'
    }
}