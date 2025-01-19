<?php
// Error reporting configuration
ini_set('display_errors', 'on');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);

require_once '../config/Library/Security/SecurityUtil.php';


function extractModesFromSwitch($filePath, $className)
{
    $modes = [];
    $content = file_get_contents($filePath);

    //$pattern = "/public\s+function\s+switch_{$className}\s*\(\s*\\\$request\s*\)\s*\{(.*?)\}\s*(?:public|private|protected|$)/s";
    $pattern = '/match\s*\(.*?\)\s*\{(.*?)\}/s';
    if (preg_match($pattern, $content, $matches)) {
        $switchBody = $matches[1];

        // Check for match structure
        preg_match_all("/['\"](.*?)['\"]\s*=>/", $switchBody, $matchModes);
        $modes = $matchModes[1];

        // Check for switch structure
        if (empty($modes) && strpos($switchBody, 'switch') !== false) {
            $casePattern = "/case\s+['\"](.+?)['\"]\s*:/";

            preg_match_all($casePattern, $switchBody, $caseModes);
            $modes = $caseModes[1];

        }
    }
    return $modes;
}

// Función para generar combinaciones dinámicas de las funciones
function generateFunctionCombinations($functionName)
{
    // Combinaciones dinámicas con diferentes formas de invocar funciones
    $combinations = [];

    // Caso de función estática (self::)
    $combinations[] = 'self::' . $functionName;
    $combinations[] = 'self:::' . $functionName;

    // Caso de función de instancia (a través de $this)
    $combinations[] = '$this->' . $functionName;

    // Combinación de $this-> con otras llamadas a métodos (como $this->db->method)
    // Ejemplo: $this->db->method, $this->service->method, etc.
    $combinations[] = '$this->db->' . $functionName;

    return $combinations;
}

function extractMatchKeys($fileContent)
{
    // Expresión regular para encontrar el bloque de 'match' y extraer las claves y las funciones
    preg_match_all('/\'(.*?)\'\s*=>\s*(self::[a-zA-Z0-9_]+|[\$a-zA-Z0-9_]+->[a-zA-Z0-9_]+)/', $fileContent, $matches);

    // $matches[1] contiene las claves (por ejemplo, 'insert_info')
    // $matches[2] contiene las funciones asociadas (por ejemplo, 'self::_insert_info' o '$this->db->describeEntityNewCache')

    $functions = [];
    foreach ($matches[1] as $index => $key) {
        // Agregar tanto el prefijo 'self::' como las funciones de instancias (como '$this->')
        $functions['values'][] = $matches[2][$index];
        $functions['key'][] = $matches[1][$index];
    }

    return $functions;
}

// Función para obtener solo los comentarios de las funciones relevantes
function getFunctionCommentsInSwitch($filePath)
{
    // Leer el contenido del archivo
    $fileContent = file_get_contents($filePath);

    // Extraer las funciones dentro del match
    $functions = extractMatchKeys($fileContent);

    // Expresión regular para encontrar los comentarios de las funciones
    preg_match_all('/\/\*\*.*?\*\/\s*(public|protected|private)?\s*function\s+([a-zA-Z0-9_]+)/s', $fileContent, $matches);

    // El arreglo $matches contiene tres elementos:
    // 0 => el comentario completo, 1 => tipo de acceso (opcional), 2 => el nombre de la función

    $comments = [];
    foreach ($matches[2] as $index => $function) {
        // Generamos todas las combinaciones posibles para el nombre de la función
        $combinations = generateFunctionCombinations($function);

        // Si alguna de las combinaciones está en la lista de funciones extraídas, guardamos el comentario
        foreach ($combinations as $combination) {
            // Buscar si la combinación de función está en las funciones extraídas
            $keyIndex = array_search($combination, $functions['values']);  // Obtener el índice de la función en 'values'

            if ($keyIndex !== false) {
                // Guardamos el comentario en el índice correspondiente de 'key' de las funciones
                $comments[$functions['key'][$keyIndex]] = trim($matches[0][$index]); // Guardamos el comentario con la clave
                break; // Ya encontramos el comentario, no necesitamos seguir verificando
            }
        }
    }

    return $comments;
}

function generateApiDocs($token)
{
    global $util;

    // add seguridad para generar documentacion
    //if ($util->verifyToken($token)['valid'] === false) return (object) ['resp' => 403, 'msj' => "Invalid token token or token file not found."];

    $modulesPath = __DIR__ . '/../module';
    $cachePath = __DIR__ . '/../config/Cache';
    $modules = scandir($modulesPath);
    $documentation = "# API Documentation\n\n";

    foreach ($modules as $module) {
        if ($module === '.' || $module === '..')
            continue;

        $controllerPath = $modulesPath . '/' . $module . '/controller';
        $modelPath = $modulesPath . '/' . $module . '/model';
        if (!is_dir($controllerPath) || !is_dir($modelPath))
            continue;

        $controllers = scandir($controllerPath);
        foreach ($controllers as $controller) {
            if (pathinfo($controller, PATHINFO_EXTENSION) !== 'php')
                continue;


            $controllerName = pathinfo($controller, PATHINFO_FILENAME);
            $endpoint = str_replace('.controller', '', $controllerName);
            $modelFile = $modelPath . '/' . $endpoint . '.model.php';
            if (!file_exists($modelFile))
                continue;

            //$documentation .= "## {$endpoint}\n\n";
            $documentation .= "<details><summary>{$endpoint}</summary>\n\n";
            $documentation .= "**POST:** ` module/{$module}/controller/{$endpoint}.controller.php`\n\n";
            $documentation .= "**Parameters (Body):**\n\n";

            // Extract modes from the model file
            $modes_scan = extractModesFromSwitch($modelFile, $endpoint);

            $modes_default = ["select_{$endpoint}", "insert_{$endpoint}", "update_{$endpoint}", "delete_{$endpoint}", "describe_{$endpoint}"]; // Fallback to default modes
            $arratemp = array_merge($modes_scan, $modes_default);
            $modes = array_unique($arratemp);

            $documentation .= "```json\n{\n";
            foreach ($modes as $index => $mode) {
                $documentation .= "   \"mode\": \"{$mode}\"" . ($index < count($modes) - 1 ? "," : "") . "\n";
            }
            $documentation .= "}\n```\n\n";


            $infocoment = getFunctionCommentsInSwitch($modelFile);
            if ($infocoment)
                $documentation .= "<details><summary>Details and info the all \"mode\":</summary>\n\n";
            $i = 0;
            foreach ($infocoment as $index => $mode) {
                $i = +1;
                $documentation .= "{$i}. **{$index}**:" . "\n";
                $documentation .= "   ```php\n";
                $documentation .= "   {$mode}\n";
                $documentation .= "   ```\n\n";
            }
            if ($infocoment)
                $documentation .= "</details>\n\n\n";

            // Read and include the JSON description file
            $jsonFile = "{$cachePath}/{$endpoint}_description.json";
            if (file_exists($jsonFile)) {
                $jsonContent = file_get_contents($jsonFile);
                $fieldDescriptions = json_decode($jsonContent, true);

                if ($fieldDescriptions) {
                    $documentation .= "**Fields:**\n\n";
                    $documentation .= "| Field | Type | Null | Key | Default | Extra |\n";
                    $documentation .= "|-------|------|------|-----|---------|-------|\n";

                    foreach ($fieldDescriptions as $field) {
                        $documentation .= "| {$field['Field']} | {$field['Type']} | {$field['Null']} | {$field['Key']} | " .
                            ($field['Default'] === null ? 'NULL' : $field['Default']) . " | {$field['Extra']} |\n";
                    }

                    $documentation .= "\n";
                }
            }

            $documentation .= "---\n\n";
            $documentation .= "</details>\n\n";

        }
    }

    file_put_contents($modulesPath . '/api_documentation.md', $documentation);
    return (object) ['resp' => 200, 'msj' => "Documentation generated successfully."];
}
$token = isset($_GET['token']) ? $_GET['token'] : null;
echo json_encode(generateApiDocs($token));