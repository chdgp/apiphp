<?php
require_once(__DIR__ . "/DatabaseConnection.php");

class SecurityRateLimiter extends DatabaseConnection{
    private $pdo;
    private $requests_table = "zx_ips_limits";
    private $blocked_table = "zx_ips_blocked";
    private $max_requests = 100;
    private $block_duration = 150; 
    private $datetime; 
    private $date; 
    private $time_window = 1; // minutos para resetear el contador

    public function __construct(int $time_window = 1) {
        $this->pdo = parent::getPDO();
        $this->datetime = date('Y-m-d H:i:s');
        $this->date = date('Y-m-d');
        $this->time_window = $time_window;
        $this->initTables();
    }

    private function initTables(): void {
        // Tabla para contar peticiones diarias
        $sql_requests = "CREATE TABLE IF NOT EXISTS " . $this->requests_table . " (
            ip_address VARCHAR(45) NOT NULL,
            date_record DATE NOT NULL,
            request_count INT DEFAULT 1,
            last_request DATETIME NOT NULL,
            PRIMARY KEY (ip_address, date_record)
        ) ENGINE=InnoDB";

        // Tabla para IPs bloqueadas
        $sql_blocked = "CREATE TABLE IF NOT EXISTS " . $this->blocked_table . " (
            ip_address VARCHAR(45) PRIMARY KEY,
            blocked_until DATETIME NOT NULL
        ) ENGINE=InnoDB";

        try {
            $this->pdo->exec($sql_requests);
            $this->pdo->exec($sql_blocked);
        } catch (PDOException $e) {
            throw new Exception("Error creating security tables: " . $e->getMessage());
        }
    }


    public function checkRequest(string $ip_address): bool {
        try {
            // Verificar si la IP está bloqueada
            if ($this->isBlocked($ip_address)) {
                return false;
            }

            
            // Calcular el tiempo límite basado en time_window
            $time_limit = date('Y-m-d H:i:s', strtotime("-{$this->time_window} minutes"));

            // Obtener o crear el registro diario
            $stmt = $this->pdo->prepare("SELECT request_count, last_request 
                FROM " . $this->requests_table . "
                WHERE ip_address = :ip AND date_record = :date");
            
            $stmt->execute([
                ':ip' => $ip_address,
                ':date' => $this->date
            ]);

            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($record) {
                $last_request_time = $record['last_request'];
                #echo '<pre>'; print_r(["$last_request_time > $time_limit"]); die;
                if ($last_request_time > $time_limit) {
                    // Todavía estamos dentro de la ventana de tiempo, incrementar contador
                    $new_count = $record['request_count'] + 1;
                    
                    if ($new_count >= $this->max_requests) {
                        $this->blockIP($ip_address);
                        return false;
                    }

                    $stmt = $this->pdo->prepare("UPDATE " . $this->requests_table . "
                        SET request_count = :count
                        WHERE ip_address = :ip AND date_record = :date");
                        
                    $stmt->execute([
                        ':count' => $new_count,
                        ':ip' => $ip_address,
                        ':date' => $this->date
                    ]);
                } else {
                    // Ha pasado el tiempo configurado, reiniciar contador
                    $stmt = $this->pdo->prepare("UPDATE " . $this->requests_table . "
                        SET request_count = 1, last_request = :last_request
                        WHERE ip_address = :ip AND date_record = :date");
                        
                    $stmt->execute([
                        ':ip' => $ip_address,
                        ':last_request' => $this->datetime,
                        ':date' => $this->date
                    ]);
                }
            } else {
                // Crear nuevo registro para el día
                $stmt = $this->pdo->prepare("INSERT INTO " . $this->requests_table . "
                    (ip_address, date_record, request_count, last_request)
                    VALUES (:ip, :date, 1, :last_request)");
                    
                $stmt->execute([
                    ':ip' => $ip_address,
                    ':last_request' => $this->datetime,
                    ':date' => $this->date
                ]);
            }

            return true;

        } catch (PDOException $e) {
            error_log("Rate limiter error: " . $e->getMessage());
            return true;
        }
    }


    private function isBlocked(string $ip_address): bool {
        try {
            $stmt = $this->pdo->prepare("SELECT blocked_until 
                FROM " . $this->blocked_table . "
                WHERE ip_address = :ip AND blocked_until > :last_request");
            
            $stmt->execute([
                ':last_request' => $this->datetime,
                ':ip' => $ip_address
            ]);
            return $stmt->fetch() !== false;
        } catch (PDOException $e) {
            error_log("Error checking blocked IP: " . $e->getMessage());
            return false;
        }
    }
    
    private function blockIP(string $ip_address): void {
        try {
            $blocked_until = date('Y-m-d H:i:s', time() + $this->block_duration);
            
            $stmt = $this->pdo->prepare("INSERT INTO " . $this->blocked_table . "
                (ip_address, blocked_until) 
                VALUES (:ip, :blocked_until)
                ON DUPLICATE KEY UPDATE blocked_until = VALUES(blocked_until)");
            
            $stmt->execute([
                ':ip' => $ip_address,
                ':blocked_until' => $blocked_until
            ]);

            // Actualizar el registro diario
            $stmt = $this->pdo->prepare("UPDATE " . $this->requests_table . "
                SET request_count = 0, last_request = :last_request
                WHERE ip_address = :ip AND date_record = :date");
            
            $stmt->execute([
                ':ip' => $ip_address,
                ':last_request' => $this->datetime,
                ':date' => $this->date,
            ]);
        } catch (PDOException $e) {
            error_log("Error blocking IP: " . $e->getMessage());
        }
    }

    // Método opcional para limpiar registros antiguos
    public function cleanOldRecords(): void {
        try {
            // Eliminar registros de más de 30 días
            $stmt = $this->pdo->prepare("DELETE FROM " . $this->requests_table . "
                WHERE date_record < DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)");
            $stmt->execute();

            // Eliminar IPs desbloqueadas
            $stmt = $this->pdo->prepare("DELETE FROM " . $this->blocked_table . "
                WHERE blocked_until <= CURRENT_TIMESTAMP");
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error cleaning old records: " . $e->getMessage());
        }
    }
}


$security = new SecurityRateLimiter();

// Verificar si la petición está permitida
if (!$security->checkRequest($_SERVER['REMOTE_ADDR'])) {
    http_response_code(429);
    header('Content-Type: application/json');
    die(json_encode([
        'resp' => 'err',
        'msj' => 'Too many requests. Please try again later.',
        'datetime' => date('Y-m-d H:i:s'),
        'datetime' => $_SERVER['REMOTE_ADDR'],
    ]));
}