<?php
if (session_status() == PHP_SESSION_NONE) session_start();

class Util
{
    public const SECRET_IV = 'B6Po)&dha%$#%$#hus]3wgv8';
    public const METHOD = 'AES-256-CBC';
    private $filePath = __DIR__ . '/csrf_token.json';

    public function __construct($request = null)
    {
        if ($request !== null && property_exists($request, 'mode')) {
            switch ($request->mode) {
                case 'encode':
                    echo json_encode($this->encodeToken($request));
                    break;
                case 'decode':
                    echo json_encode($this->decodeToken($request));
                    break;
                case 'vefToken':
                    $token =  property_exists($request, 'token')
                        ? $request->token
                        : '';
                    echo json_encode($this->verifyToken($token));
                    break;
                case 'getToken':
                    echo json_encode($this->getToken());
                    break;
            }
        }
    }

    /**
     * transforma cualquier array en objeto.
     *
     * @param [array] $req
     *
     * @return void
     */
    public static function getObj($req)
    {
        return json_decode(json_encode($req));
    }

    /**
     * Crea objetos únicos a partir de un array Utilizando una clave específica.
     *
     * @param array  $array el array del que se extraerán los valores únicos
     * @param string $clave la clave que se Utilizará para crear los objetos
     *
     * @return array un array de objetos únicos
     */
    public static function crearObjetosUnicos($array, $clave)
    {
        return array_values(array_map(function ($value) use ($clave) {
            $objeto = new stdClass();
            $objeto->$clave = $value;

            return $objeto;
        }, array_unique(array_column($array, $clave))));
    }

    /**
     * Guarda un mensaje de registro en un archivo de registro.
     *
     * @param string $prefix  prefijo para el nombre del archivo de registro
     * @param string $message mensaje a ser registrado
     *
     * @return void
     */
    public static function save_log($prefix, $message)
    {
        if (date_default_timezone_get() !== 'America/Lima') {
            date_default_timezone_set('America/Lima');
        }

        $logDir = __DIR__ . '/AD';
        $fileName = $prefix . '_log_' . date('j.n.Y') . '.log';
        $filePath = $logDir . '/' . $fileName;

        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0777, true) && !is_dir($logDir)) {
                error_log("No se pudo crear el directorio de logs: $logDir");
                return;
            }
        }

        // Asegura que $message sea string
        $messageStr = is_object($message) || is_array($message)
            ? print_r($message, true)
            : $message;

        $log = date('F j, Y, g:i a') . PHP_EOL .
            $messageStr . PHP_EOL .
            '-------------------------' . PHP_EOL;

        $result = file_put_contents($filePath, $log, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            error_log("Error al escribir en el archivo de log: $filePath");
        }
    }

    /**
     * Imprime una variable de manera formateada para facilitar la depuración.
     *
     * @param mixed $value   valor a imprimir
     * @param bool  $vardump indica si se debe Utilizar var_dump en lugar de print_r (opcional, por defecto es false)
     */
    public static function debug($value, $vardump = false)
    {
        if ($vardump === 2) {
            echo json_encode($value);

            return;
        }

        echo '<pre>';
        if ($vardump === 1) {
            var_dump($value);
        } else {
            print_r($value);
        }
        echo '</pre>';
    }

    /**
     * encriptar un string basado en sha256 requiere ssl.
     *
     * @param [object] $req:{string}
     *
     * @return object
     */
    public static function encodeToken($req)
    {
        $obj = (object) [];
        try {
            $output = false;
            $key = hex2bin(hash('sha256', date('Y-m')));
            $iv = substr(hex2bin(hash('sha256', self::SECRET_IV)), 0, 16);
            $output = openssl_encrypt($req, self::METHOD, $key, OPENSSL_RAW_DATA, $iv);
            $obj->output = base64_encode($output);
        } catch (PDOException $e) {
            $obj->mensaje = 'Error al encode: ' . $e->getMessage();
            $obj->resp = 'err';
        }

        return $obj;
    }

    /**
     * desencriptar un string basado en sha256 requiere ssl.
     *
     * @param [object] $req:{string}
     *
     * @return object 
     */
    public static function decodeToken($req)
    {
        $obj = (object) [];
        try {
            $key = hex2bin(hash('sha256', date('Y-m')));
            $iv = substr(hex2bin(hash('sha256', self::SECRET_IV)), 0, 16);
            $obj->output = openssl_decrypt(base64_decode($req), self::METHOD, $key, OPENSSL_RAW_DATA, $iv);
        } catch (PDOException $e) {
            $obj->mensaje = 'Error al decode: ' . $e->getMessage();
            $obj->resp = 'err';
        }

        return $obj;
    }

    public static function getConex($token)
    {
        $obj = (object) [];
        try {
            $obj->string = $token;

            return self::decodeToken($obj->string);
        } catch (PDOException $e) {
            $obj->mensaje = 'Error al decode: ' . $e->getMessage();
            $obj->resp = 'err';
        }

        return $obj;
    }

    public static function delObj($_this)
    {
        unset($_this->mode);
        unset($_this->token);

        return $_this;
    }

    public static function timeSecond($inicio)
    {
        list($inicioSeg, $inicioMicro) = explode(' ', $inicio);
        list($finSeg, $finMicro) = explode(' ', microtime(true));

        $diferenciaSegundos = $finSeg - $inicioSeg;
        $diferenciaMicrosegundos = $finMicro - $inicioMicro;

        return $diferenciaSegundos + ($diferenciaMicrosegundos / 1000000);
    }

    public static function tableFromDatabase($data, $columnMap, $tableId = 'myTable', $className = 'table-bordered')
    {
        $html = '';
        $obj = (object) [];
        $html .= '<table id="' . $tableId . '" class="table ' . $className . ' table-hover table-condensed">';
        $html .= '<thead><tr>';

        // Generate table header (th) based on $columnMap keys
        foreach ($columnMap as $th => $columnKey) {
            $html .= '<th>' . $th . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        // Generate table rows and cells (td) based on $data and $columnMap
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($columnMap as $th => $columnValue) {
                if (is_callable($columnValue)) {
                    // Si la columna se mapea a una función, llama a la función con el valor actual de la fila
                    $value = $columnValue($row);
                } else {
                    // De lo contrario, asume que es una propiedad de la fila
                    $value = $row->$columnValue;
                }
                $html .= '<td>' . $value . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $obj->html = trim($html);

        return $obj;
    }

    /**
     * Obtiene la URL base actual del servidor.
     *
     * @return string la URL base actual (incluyendo protocolo HTTP o HTTPS)
     */
    public static function get_dominio_now($path = false)
    {
        // Obtener el protocolo (HTTP o HTTPS)
        $protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';

        // Obtener el nombre de dominio o la dirección IP del servidor
        $dominio = $_SERVER['HTTP_HOST'];

        // Combinar el protocolo y el dominio para formar la URL base
        $url_base = "$protocolo://$dominio";

        $ruta_actual = __FILE__;

        // Obtener la carpeta (directorio) que contiene el archivo actual
        $carpeta_actual = dirname($ruta_actual);
        $fulldir = str_replace('/config', '', $carpeta_actual);

        $url_base .= ($path)
            ? str_replace($_SERVER['DOCUMENT_ROOT'], '', $fulldir)
            : '';

        return $url_base;
    }

    /**
     * Crea una carpeta de almacenamiento y guarda una imagen en formato base64.
     *
     * @param string $base64string la cadena base64 que representa la imagen
     * @param int    $idusuario    el ID del usuario al que pertenece la imagen
     * @param bool   $createWebp   (Opcional) Indica si se debe crear una versión WebP de la imagen (predeterminado: true)
     * @param string $folder       (Opcional) El nombre de la carpeta donde se almacenará la imagen (predeterminado: 'image')
     *
     * @return object un objeto con las rutas de los archivos guardados y un mensaje de respuesta
     */
    public static function createStorageAndImageBase64($base64string, $idusuario, $createWebp = true, $folder = 'image', $randon = '', $create_original = false)
    {
        $_DOMINIO = self::get_dominio_now(true);

        // Verifica si es una url y la retorna
        if (!empty($base64string) && filter_var($base64string, FILTER_VALIDATE_URL)) {
            return (object) ['original' => $base64string, 'webp' => $base64string, 'resp' => 'file_is_url'];
        }

        // Verificar si el base64string contiene una coma para dividir
        if (strpos($base64string, ',') === false) {
            return (object) ['original' => '', 'webp' => '', 'resp' => 'file_not_base64'];
        }

        // Verificar si la carpeta 'storage' existe, si no, crearla
        $storagePath = 'storage/';
        if (!file_exists($storagePath)) {
            mkdir($storagePath, 0777, true); // Crear carpeta con permisos de lectura, escritura y ejecución para todos
        }

        $storagePath .= $folder . '/';
        if (!file_exists($storagePath)) {
            mkdir($storagePath, 0777, true); // Crear carpeta con permisos de lectura, escritura y ejecución para todos
        }

        $storagePath .= date('Y') . '/';
        if (!file_exists($storagePath)) {
            mkdir($storagePath, 0777, true); // Crear carpeta con permisos de lectura, escritura y ejecución para todos
        }

        $storagePath .= date('m') . '/';
        if (!file_exists($storagePath)) {
            mkdir($storagePath, 0777, true); // Crear carpeta con permisos de lectura, escritura y ejecución para todos
        }

        $base64string_parts = explode(',', $base64string);
        $base64_data = $base64string_parts[1];

        // Decodificamos los datos base64
        $fileContents = base64_decode($base64_data);

        // Obtenemos información sobre la imagen para determinar su tipo
        $image_info = getimagesizefromstring($fileContents);
        if ($image_info !== false && isset($image_info['mime'])) {
            // Extraemos el tipo MIME de la imagen
            $mime_type = $image_info['mime'];

            // Definimos el nombre del archivo y la extensión basados en el tipo MIME
            switch ($mime_type) {
                case 'image/jpeg':
                    $extension = '.jpg';
                    break;
                case 'image/png':
                    $extension = '.png';
                    break;
                case 'image/gif':
                    $extension = '.gif';
                    break;
                default:
                    // Si el tipo MIME no es soportado, asignamos una extensión genérica
                    $extension = '.dat';
                    break;
            }

            // Verificamos si se trata de una imagen JPEG o PNG para redimensionarla
            if ($extension == '.jpg' || $extension == '.png' && $createWebp) {
                // Cargar la imagen
                $image = @imagecreatefromstring($fileContents);

                // Verificar si la imagen se ha creado correctamente
                if ($image !== false) {
                    // Redimensionar la imagen
                    $nuevo_ancho = 500; // Nuevo ancho deseado
                    $nuevo_alto = 0; // El alto se calculará automáticamente para mantener la proporción
                    $ancho_original = imagesx($image);
                    $alto_original = imagesy($image);
                    $nuevo_alto = floor($alto_original * ($nuevo_ancho / $ancho_original));
                    $imagen_redimensionada = imagecreatetruecolor($nuevo_ancho, $nuevo_alto);
                    imagecopyresampled($imagen_redimensionada, $image, 0, 0, 0, 0, $nuevo_ancho, $nuevo_alto, $ancho_original, $alto_original);

                    $storagePathWebp = $storagePath . 'webp/';
                    if (!file_exists($storagePathWebp)) {
                        mkdir($storagePathWebp, 0777, true); // Crear carpeta con permisos de lectura, escritura y ejecución para todos
                    }

                    // Guardar la imagen redimensionada en formato WebP
                    $webpFilePath = $storagePathWebp . $randon . '_' . $idusuario . '_' . date('Ymd') . '.webp';
                    imagewebp($imagen_redimensionada, $webpFilePath);

                    // Liberar memoria
                    imagedestroy($image);
                    imagedestroy($imagen_redimensionada);

                    // Retorna el nombre del archivo guardado en formato WebP
                    $basenameWebp = basename($webpFilePath);
                }
            }



            if ($create_original) {
                $storagePathOrig = $storagePath . 'original/';
                if (!file_exists($storagePathOrig)) {
                    mkdir($storagePathOrig, 0777, true); // Crear carpeta con permisos de lectura, escritura y ejecución para todos
                }
                // Definimos el nombre del archivo y la ruta donde será guardado
                $fileName = $idusuario . '_' . date('Ymd') . $extension;
                $filePath = $storagePathOrig . $fileName;

                // Intentamos guardar el archivo en el servidor
                file_put_contents($filePath, $fileContents);
            }

            return (object) ['original' => $create_original ?? $_DOMINIO . '/' . $filePath, 'webp' => $_DOMINIO . '/' . $webpFilePath, 'resp' => 'add_file_create'];
        }

        // Si hubo algún error, retornar NULL
        return (object) ['original' => '', 'webp' => '', 'resp' => 'file_create_err'];
    }



    /**
     * Genera un token CSRF y lo almacena en un archivo JSON.
     *
     * @return object JSON con el token CSRF.
     */
    private function generateToken()
    {
        $token = bin2hex(random_bytes(32));
        $data = [
            'csrf_token' => $token,
            'created_at' => time()
        ];
        file_put_contents($this->filePath, json_encode($data));
        chmod($this->filePath, 0666); // Conceder permisos de escritura
        return (object)['csrf_token' => $token];
    }

    /**
     * Verifica el token CSRF recibido.
     *
     * @param string $token El token CSRF a verificar.
     * @return object JSON con el estado de la verificación.
     */
    public function verifyToken($token)
    {
        if (!$token) return (object)['resp' => 'requiere_param'];

        if (!file_exists($this->filePath)) return (object)(['resp' => 'error', 'message' => 'Token no encontrado']);
        $data = json_decode(file_get_contents($this->filePath), true);

        if (isset($data['csrf_token']) && is_string($data['csrf_token']) && hash_equals($data['csrf_token'], $token)) {
            return (object)(['resp' => 'success', 'message' => 'Token válido']);
        }
        return (object)(['resp' => 'error', 'message' => 'Token no válido']);
    }

    /**
     * Obtiene el token CSRF si es válido y no ha expirado.
     *
     * @return object JSON con el token CSRF o mensaje de error.
     */
    public function getToken()
    {
        if (!file_exists($this->filePath)) {
            return $this->generateToken();
        }

        $data = json_decode(file_get_contents($this->filePath), true);
        $currentTime = time();
        $tokenAge = $currentTime - $data['created_at'];

        if ($tokenAge > 600) { // 600 segundos = 10 minutos
            return $this->generateToken();
        }

        return (object)['csrf_token' => $data['csrf_token']];
    }


    public static function sumarHoras($fecha, $horas, $zonaHoraria = 'America/Lima')
    {
        // Intentamos crear un objeto DateTime desde la cadena de fecha y hora
        $nuevaFecha = DateTime::createFromFormat('Y-m-d H:i:s', $fecha, new DateTimeZone($zonaHoraria));

        // Si falla, intentamos crear un objeto DateTime desde la cadena de fecha
        if ($nuevaFecha === false) {
            $nuevaFecha = DateTime::createFromFormat('Y-m-d', $fecha, new DateTimeZone($zonaHoraria));
            if ($nuevaFecha === false) {
                // Manejar el error si la fecha no se pudo crear
                throw new Exception("Formato de fecha incorrecto");
            }
            // Establecemos la hora a medianoche
            $nuevaFecha->setTime((int)date('H'), (int)date('i'), (int)date('s'));
        }

        // Sumamos las horas
        $nuevaFecha->modify("+$horas hours");
        $fechaformt = $nuevaFecha->format('Y-m-d H:i:s');

        // Devolvemos la nueva fecha formateada
        return (string) $fechaformt;
    }

    /**
     * Genera una sal aleatoria.
     *
     * @param int $length Longitud de la sal en bytes (por defecto 16)
     * @return string Sal generada en formato hexadecimal
     */
    public function generateSalt($length = 16)
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Deriva una clave a partir de una contraseña y una sal usando PBKDF2.
     *
     * @param string $password Contraseña para derivar la clave
     * @param string $salt Sal para usar en la derivación
     * @return string Clave derivada
     */
    public function deriveKey($password, $salt)
    {
        return hash_pbkdf2("sha256", $password, $salt, 10000, 32);
    }

    /**
     * Encripta un texto usando AES-256-CBC con una sal aleatoria y HMAC.
     *
     * @param string $text Texto a encriptar
     * @return string Texto encriptado en formato base64
     */
    public function encrypt($text)
    {
        $salt = self::generateSalt();
        $key = self::deriveKey(self::SECRET_IV, $salt);
        $iv = openssl_random_pseudo_bytes(16);

        $encrypted = openssl_encrypt($text, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        $hmac = hash_hmac('sha256', $encrypted, $key, true);

        $result = $salt . $iv . $hmac . $encrypted;
        return base64_encode($result);
    }

    /**
     * Desencripta un texto previamente encriptado con el método encrypt().
     *
     * @param string $encryptedText Texto encriptado en formato base64
     * @return string Texto desencriptado
     * @throws Exception Si la verificación HMAC falla o si la desencriptación falla
     */
    public function decrypt($encryptedText)
    {
        $decoded = base64_decode($encryptedText);

        $salt = substr($decoded, 0, 32);
        $iv = substr($decoded, 32, 16);
        $hmac = substr($decoded, 48, 32);
        $encrypted = substr($decoded, 80);

        $key = self::deriveKey(self::SECRET_IV, $salt);

        $calculatedHmac = hash_hmac('sha256', $encrypted, $key, true);
        if (!hash_equals($hmac, $calculatedHmac)) {
            throw new Exception("HMAC verification failed");
        }

        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            throw new Exception("Decryption failed");
        }

        return $decrypted;
    }
}

$Util = new Util((object) $_REQUEST);