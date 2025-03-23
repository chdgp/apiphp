<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start(['cookie_secure' => true, 'cookie_httponly' => true]);
}

/**
 * Security and utility class for common operations
 */
class SecurityUtil
{
    private const SECRET_IV = 'B6Po)&dha%$#%$#hus]3wgv8';
    private const METHOD = 'AES-256-CBC';
    private const CACHE_DIR = __DIR__ . '/Cache';
    private const TOKEN_FILE = self::CACHE_DIR . '/csrf_token.json';
    private const TOKEN_LIFETIME = 3600; // 1 hour

    public function __construct(?object $request = null)
    {
        if ($request === null) {
            return;
        }

        $response = match ($request->mode ?? '') {
            'encode' => $this->encodeToken($request),
            'decode' => $this->decodeToken($request),
            'vefToken' => $this->verifyToken($request->token ?? ''),
            'getToken' => $this->getToken(),
            'verifyRequestToken' => $this->verifyRequestToken(),
            default => null
        };

        if ($response !== null) {
            $this->sendJsonResponse($response);
        }
    }

    /**
     * Convert array to object recursively
     * @param array $data
     * @return object
     */
    public static function arrayToObject(array $data): object
    {
        return json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Create unique objects from array based on key
     * @param array $array
     * @param string $key
     * @return array
     */
    public static function createUniqueObjects(array $array, string $key): array
    {
        $uniqueValues = array_unique(array_column($array, $key));
        return array_values(array_map(
            fn($value) => (object) [$key => $value],
            $uniqueValues
        ));
    }

    /**
     * Save log message with proper formatting and error handling
     * @param string $prefix
     * @param string $message
     * @throws RuntimeException
     */
    public static function saveLog(string $prefix, string $message): void
    {
        try {
            $date = new DateTime('now', new DateTimeZone('America/Lima'));
            $logDir = __DIR__ . '/logs';

            if (!is_dir($logDir) && !mkdir($logDir, 0755, true)) {
                throw new RuntimeException("Failed to create log directory");
            }

            $logFile = sprintf(
                '%s/%s_log_%s.log',
                $logDir,
                $prefix,
                $date->format('j.n.Y')
            );

            $logContent = sprintf(
                "[%s]\n%s\n%s\n",
                $date->format('F j, Y, g:i a'),
                $message,
                str_repeat('-', 25)
            );

            if (file_put_contents($logFile, $logContent, FILE_APPEND) === false) {
                throw new RuntimeException("Failed to write to log file");
            }
        } catch (Exception $e) {
            error_log("Logging failed: " . $e->getMessage());
            throw new RuntimeException("Logging failed", 0, $e);
        }
    }

    /**
     * Debug value with proper formatting
     * @param mixed $value
     * @param int $format 0=print_r, 1=var_dump, 2=json_encode
     */
    public static function debug(mixed $value, int $format = 0): void
    {
        if ($format === 2) {
            echo json_encode(
                $value,
                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );
            return;
        }

        echo '<pre>';
        if ($format === 1) {
            var_dump($value);
        } else {
            print_r($value);
        }
        echo '</pre>';
    }

    /**
     * Encrypt token using SHA256 and AES-256-CBC
     * @param mixed $data
     * @return object
     */
    public static function encodeToken($data): object
    {
        try {
            $key = hex2bin(hash('sha256', date('Y-m')));
            $iv = substr(hex2bin(hash('sha256', self::SECRET_IV)), 0, 16);
            $output = openssl_encrypt(
                is_string($data) ? $data : json_encode($data),
                self::METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            return (object) [
                'output' => base64_encode($output),
                'resp' => 'success'
            ];
        } catch (Exception $e) {
            return (object) [
                'mensaje' => 'Error al encode: ' . $e->getMessage(),
                'resp' => 'err'
            ];
        }
    }

    /**
     * Decrypt token using SHA256 and AES-256-CBC
     * @param mixed $data
     * @return object
     */
    public static function decodeToken($data): object
    {
        try {
            $key = hex2bin(hash('sha256', date('Y-m')));
            $iv = substr(hex2bin(hash('sha256', self::SECRET_IV)), 0, 16);
            $decrypted = openssl_decrypt(
                base64_decode(is_string($data) ? $data : json_encode($data)),
                self::METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            return (object) [
                'output' => $decrypted,
                'resp' => 'success'
            ];
        } catch (Exception $e) {
            return (object) [
                'mensaje' => 'Error al decode: ' . $e->getMessage(),
                'resp' => 'err'
            ];
        }
    }

    /**
     * Get database connection from token
     * @param string $token
     * @return object
     */
    public static function getConex(string $token): object
    {
        try {
            $decoded = self::decodeToken($token);
            if ($decoded->resp === 'err') {
                throw new RuntimeException($decoded->mensaje ?? 'Error decoding token');
            }
            return $decoded;
        } catch (Exception $e) {
            return (object) [
                'mensaje' => 'Error getting connection: ' . $e->getMessage(),
                'resp' => 'err'
            ];
        }
    }

    /**
     * Remove mode and token properties from object
     * @param object $data
     * @return object
     */
    public static function delObj(object $data): object
    {
        $newData = clone $data;
        unset($newData->mode, $newData->token);
        return $newData;
    }

    /**
     * Calculate time difference in seconds
     * @param float $startTime
     * @return float
     */
    public static function timeSecond(float $startTime): float
    {
        return microtime(true) - $startTime;
    }

    /**
     * Generate HTML table from database data
     * @param array $data
     * @param array $columnMap
     * @param string $tableId
     * @param string $className
     * @return object
     */
    public static function tableFromDatabase(
        array $data,
        array $columnMap,
        string $tableId = 'myTable',
        string $className = 'table-bordered'
    ): object {
        $html = sprintf(
            '<table id="%s" class="table %s table-hover table-condensed"><thead><tr>',
            htmlspecialchars($tableId),
            htmlspecialchars($className)
        );

        // Generate headers
        foreach ($columnMap as $th => $columnKey) {
            $html .= sprintf('<th>%s</th>', htmlspecialchars($th));
        }

        $html .= '</tr></thead><tbody>';

        // Generate rows
        foreach ($data as $row) {
            $html .= sprintf("<tr data-json='%s'>", json_encode($row));
            foreach ($columnMap as $th => $columnValue) {
                $value = is_callable($columnValue)
                    ? $columnValue($row)
                    : $row[$columnValue];

                $html .= sprintf('<td data-key="%s">%s</td>', $columnValue, htmlspecialchars( (string) $value));
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return (object) ['html' => trim($html)];
    }

    /**
     * Get current domain with optional path
     * @param bool $includePath
     * @return string
     */
    public static function getCurrentDomain(bool $includePath = false): string
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'];
        $baseUrl = "{$protocol}://{$domain}";

        if ($includePath) {
            $currentDir = dirname(__FILE__);
            $fullDir = str_replace('/config', '', $currentDir);
            $relativePath = str_replace($_SERVER['DOCUMENT_ROOT'], '', $fullDir);
            $baseUrl .= $relativePath;
        }

        return $baseUrl;
    }

    /**
     * Generate CSRF token with expiration
     * @return array
     */
    private function generateToken(): array
    {
        $token = bin2hex(random_bytes(32));
        $expiration = time() + self::TOKEN_LIFETIME;

        return [
            'token' => $token,
            'expiration' => $expiration
        ];
    }

    /**
     * Save token to storage
     * @param array $tokenData
     * @throws RuntimeException
     */
    private function saveToken(array $tokenData): void
    {
        if (!is_dir(self::CACHE_DIR) && !mkdir(self::CACHE_DIR, 0755, true)) {
            throw new RuntimeException("Failed to create cache directory");
        }

        if (file_put_contents(self::TOKEN_FILE, json_encode($tokenData)) === false) {
            throw new RuntimeException("Failed to save token");
        }
    }

    /**
     * Get current token or generate new one
     * @return array
     */
    public function getToken(): array
    {
        try {
            if (file_exists(self::TOKEN_FILE)) {
                $tokenData = json_decode(file_get_contents(self::TOKEN_FILE), true);
                // Agregamos un margen de 30 segundos antes de la expiración para regenerar el token
                if ($tokenData && ($tokenData['expiration'] - 30) > time()) {
                    return $tokenData;
                }
            }
        } catch (Exception $e) {
            error_log("Token retrieval failed: " . $e->getMessage());
        }

        $tokenData = $this->generateToken();
        $this->saveToken($tokenData);
        return $tokenData;
    }

    /**
     * Verify CSRF token
     * @param string|null $token
     * @return array
     */
    public function verifyToken(?string $token): array
    {
        try {
            if ($token === null) {
                return [
                    'valid' => false,
                    'message' => 'Token not provided'
                ];
            }

            $tokenData = $this->getToken();
            // Agregamos un margen de 30 segundos para la validación también
            $isValid = hash_equals($tokenData['token'], $token) &&
                ($tokenData['expiration'] - 30) > time();

            return [
                'valid' => $isValid,
                'message' => $isValid ? 'Token valid' : 'Token invalid or expired',
                'token' => $isValid ? null : $tokenData['token'] // Si el token no es válido, enviamos uno nuevo
            ];
        } catch (Exception $e) {
            return [
                'valid' => false,
                'message' => 'Token verification failed'
            ];
        }
    }

    /**
     * Verify token from request header and authorize request
     * @return array
     */
    public function verifyRequestToken(): array
    {
        try {
            // Obtener headers de forma más eficiente
            $headers = array_change_key_case(getallheaders(), CASE_UPPER);

            // Mapeo directo de headers a buscar (en mayúsculas para comparación case-insensitive)
            $headerMap = [
                'X-CSRF-TOKEN' => true,
                'X-TOKEN' => true,
                'TOKEN' => true,
                'AUTHORIZATION' => true
            ];

            // Buscar token de forma eficiente
            $token = null;
            $foundHeader = array_intersect_key($headers, $headerMap);

            if (!empty($foundHeader)) {
                $token = reset($foundHeader);
                // Extraer Bearer token si es necesario
                if (stripos($token, 'Bearer ') === 0) {
                    $token = substr($token, 7);
                }
            }

            // Verificar el token y liberar memoria
            $verification = $this->verifyToken($token);
            unset($headers, $headerMap, $foundHeader);

            if (!$verification['valid']) {
                http_response_code(401);
                return [
                    'success' => false,
                    'message' => $verification['message'],
                    'newToken' => $verification['token'] ?? null
                ];
            }

            return [
                'success' => true,
                'message' => 'Request authorized'
            ];

        } catch (Exception $e) {
            http_response_code(500);
            return [
                'success' => false,
                'message' => 'Authorization verification failed'
            ];
        }
    }

    /**
     * Send JSON response with proper headers
     * @param mixed $data
     */
    private function sendJsonResponse(mixed $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR);
    }

    // @deprecated Legacy methods for backward compatibility
    public static function getObj($req)
    {
        return self::arrayToObject((array) $req);
    }
    public static function crearObjetosUnicos($array, $key)
    {
        return self::createUniqueObjects($array, $key);
    }
    public static function save_log($prefix, $message)
    {
        self::saveLog($prefix, $message);
    }
    public static function get_dominio_now($path = false)
    {
        return self::getCurrentDomain($path);
    }
}

// Initialize utility class with request data if available
$util = new SecurityUtil(isset($_REQUEST) ? (object) $_REQUEST : null);