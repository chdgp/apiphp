<?php
chdir("../../../");

/*/ --- LOG DE DEBUG ---
$log_file = 'log_input_binario.txt';
$timestamp = date('[Y-m-d H:i:s]');
$log_message = $timestamp . " | Petición recibida.\n";
$log_message .= "  - Método: " . $_SERVER['REQUEST_METHOD'] . "\n";
$log_message .= "  - Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'N/A') . "\n";
$log_message .= "  - FILES: " . print_r($_FILES, true) . "\n";
$log_message .= "---------------------------------------\n";
@file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
// --- FIN LOG ---*/

// 1. Obtener la imagen del campo multipart/form-data
$input_image_data = null;

if (isset($_FILES['meta_img']) && $_FILES['meta_img']['error'] === UPLOAD_ERR_OK) {
  // Caso 1: Archivo subido mediante multipart/form-data
  $input_image_data = file_get_contents($_FILES['meta_img']['tmp_name']);
} elseif (isset($_POST['meta_img'])) {
  // Caso 2: Campo POST con base64 o binario
  $meta_img = $_POST['meta_img'];

  if (preg_match('/^data:image\/[^;]+;base64,(.+)$/i', $meta_img, $matches)) {
    $input_image_data = base64_decode($matches[1]);
  } elseif (base64_decode($meta_img, true) !== false) {
    $input_image_data = base64_decode($meta_img);
  } else {
    $input_image_data = $meta_img;
  }
} else {
  // Caso 3: Binario directo en php://input
  $raw_input = file_get_contents('php://input');

  $json_data = @json_decode($raw_input, true);

  if ($json_data && isset($json_data['meta_img'])) {
    $meta_img = $json_data['meta_img'];

    if (preg_match('/^data:image\/[^;]+;base64,(.+)$/i', $meta_img, $matches)) {
      $input_image_data = base64_decode($matches[1]);
    } elseif (base64_decode($meta_img, true) !== false) {
      $input_image_data = base64_decode($meta_img);
    } else {
      $input_image_data = $meta_img;
    }
  } else {
    $input_image_data = $raw_input;
  }
}

// 2. Validar que se recibió data
if (empty($input_image_data)) {
  http_response_code(400);
  header("Content-Type: application/json");
  die(json_encode([
    'success' => false,
    'message' => 'Error: No se recibió data de imagen en meta_img.',
    'debug' => [
      'files' => $_FILES,
      'post' => array_keys($_POST),
      'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'N/A'
    ]
  ]));
}

// 3. Validar que es una imagen válida
$image_info = @getimagesizefromstring($input_image_data);

if ($image_info === false) {
  http_response_code(415);
  header("Content-Type: application/json");
  die(json_encode([
    'success' => false,
    'message' => 'Error: El formato de imagen no es válido o no está soportado.',
    'debug' => [
      'data_length' => strlen($input_image_data),
      'first_bytes' => bin2hex(substr($input_image_data, 0, 20))
    ]
  ]));
}

$mime = $image_info['mime'];
$width = $image_info[0];
$height = $image_info[1];

// 4. Crear recurso GD desde el binario
$gd_image = @imagecreatefromstring($input_image_data);

if (!$gd_image) {
  http_response_code(500);
  header("Content-Type: application/json");
  die(json_encode([
    'success' => false,
    'message' => 'Error interno al procesar la imagen.',
    'debug' => [
      'mime' => $mime,
      'dimensions' => "{$width}x{$height}"
    ]
  ]));
}

// 5. Convertir a WebP en memoria
ob_start();
$conversion_success = imagewebp($gd_image, null, 80);
$webp_binary_data = ob_get_clean();

// Liberar memoria automáticamente (PHP 8+ lo hace automáticamente al salir del scope)
unset($gd_image);

if (!$conversion_success) {
  http_response_code(500);
  header("Content-Type: application/json");
  die(json_encode([
    'success' => false,
    'message' => 'Error al convertir la imagen a WebP.'
  ]));
}
/*
// Log exitoso
$log_success = $timestamp . " | ✓ Conversión exitosa\n";
$log_success .= "  - Formato original: {$mime}\n";
$log_success .= "  - Dimensiones: {$width}x{$height}\n";
$log_success .= "  - Tamaño original: " . strlen($input_image_data) . " bytes\n";
$log_success .= "  - Tamaño WebP: " . strlen($webp_binary_data) . " bytes\n";
$log_success .= "---------------------------------------\n";
@file_put_contents($log_file, $log_success, FILE_APPEND | LOCK_EX);
*/
// 6. Devolver el binario WebP
header('Content-Type: image/webp');
header('Content-Length: ' . strlen($webp_binary_data));
header('Cache-Control: public, max-age=31536000');
header('X-Original-Format: ' . $mime);
header('X-Image-Dimensions: ' . $width . 'x' . $height);

echo $webp_binary_data;
exit;