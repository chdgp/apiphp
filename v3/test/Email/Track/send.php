<?php
chdir(directory: "../../../");
//print_r(getcwd());die;

require_once "config/Core/Init.php";
require_once "config/Core/ConfigurationManager.php";
require_once "config/Library/Email/EmailService.php";
require_once "config/Library/Email/TrackedEmailService.php";
require_once "config/Library/Email/EmailServicePool.php";

// Configurar headers para respuesta JSON
header(header: 'Content-Type: application/json; charset=utf-8');

/**
 * Función helper para enviar respuestas JSON
 */
function sendJsonResponse($success, $data = [], $message = '', $code = 200)
{
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// Verificar método de solicitud
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(false, [], 'Método no permitido', 405);
}

// Obtener datos del POST
$inputData = json_decode(file_get_contents('php://input'), true);

if (!$inputData) {
    $inputData = $_POST;
}

// Validar datos requeridos
$requiredFields = ['to', 'subject', 'body'];
foreach ($requiredFields as $field) {
    if (empty($inputData[$field])) {
        sendJsonResponse(false, [], "Campo requerido: {$field}", 400);
    }
}

try {
    // Crear instancia del servicio de email
    //$emailService = new TrackedEmailService();

    // Crear instancia del servicio de email desde la base de datos
    $emailService = (new EmailServicePool())->getTrackedEmailService();

    // Preparar el cuerpo del correo con HTML básico
    $htmlBody = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>' . htmlspecialchars($inputData['subject']) . '</title>
    </head>
    <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
        <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
            <h2 style="color: #2c3e50;">' . htmlspecialchars($inputData['subject']) . '</h2>
            <div style="background: #f9f9f9; border-radius: 5px; padding: 15px; margin: 20px 0;">
                ' . $inputData['body'] . '
            </div>
            <div style="color: #666; font-size: 12px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
                Este es un correo automático. Por favor no responda directamente.
            </div>
        </div>
    </body>
    </html>';

    /*
    if (file_exists($path) && is_file($path) && is_readable($path) && filesize($path) > 0) {
        echo "El archivo está listo para ser adjuntado\n";
        echo "Tamaño: " . filesize($path) . " bytes\n";
        echo "Tipo MIME: " . mime_content_type($path) . "\n";
    } else {
        echo "El archivo no es válido o no se puede acceder a él\n";
    }
    */


    // Enviar el correo
    $array = $emailService->sendEmail(
        $inputData['to'],
        $inputData['subject'],
        $htmlBody,
        true,
        [],   // CC
        [],   // BCC
        [] //[__DIR__ . '/file.pdf' => 'file_test.pdf']
    );

    if (!empty($array)) {
        $response = [
            'tracking_id' => $array[0],
            'recipient' => $inputData['to'],
            'subject' => $inputData['subject'],
            'sent_date' => date('Y-m-d H:i:s'),
            'tracking_url' => $array[1]
        ];

        sendJsonResponse(true, $response, 'Correo enviado exitosamente');
    } else {
        sendJsonResponse(false, [], 'Error al enviar el correo', 500);
    }

} catch (Exception $e) {
    sendJsonResponse(false, [], 'Error interno del servidor: ' . $e->getMessage(), 500);
}


/**
 * Usando cURL:
 *  curl -X POST http://tudominio.com/v3/config/test/Email/send.php \
 * -H "Content-Type: application/json" \
 * -d '{
 *     "to": "destinatario@email.com",
 *     "subject": "Prueba de correo con tracking",
 *     "body": "<p>Este es un correo de prueba con <strong>tracking</strong> incluido.</p>"
 * }'
 */