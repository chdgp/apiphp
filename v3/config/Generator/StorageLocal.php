<?php

class StorageLocal
{
    private const DEFAULT_PERMISSIONS = 0755;
    private const IMAGE_FORMATS = [
        'image/jpeg' => ['.jpg', IMAGETYPE_JPEG],
        'image/png' => ['.png', IMAGETYPE_PNG],
        'image/gif' => ['.gif', IMAGETYPE_GIF],
        'image/webp' => ['.webp', IMAGETYPE_WEBP]
    ];
    private const DEFAULT_WIDTH = 800;
    private const STORAGE_PATH = 'storage/';
    private const QUALITY = 80;

    /**
     * Obtiene la URL base actual del servidor.
     * @param bool $path Incluir la ruta del proyecto
     * @return string URL base actual
     */
    public static function get_dominio_now(bool $path = false): string
    {
        static $baseUrl = null;

        if ($baseUrl === null) {
            $protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $dominio = $_SERVER['HTTP_HOST'];
            $baseUrl = "$protocolo://$dominio";
        }

        if (!$path) {
            return $baseUrl;
        }

        static $pathUrl = null;
        if ($pathUrl === null) {
            $fulldir = str_replace('/config/Generator', '', dirname(__FILE__));
            $pathUrl = $baseUrl . str_replace($_SERVER['DOCUMENT_ROOT'], '', $fulldir);
        }

        return $pathUrl;
    }

    /**
     * Crea un directorio si no existe
     * @param string $path Ruta del directorio
     * @return bool Éxito de la operación
     */
    private static function createDirectory(string $path): bool
    {
        return file_exists($path) || mkdir($path, self::DEFAULT_PERMISSIONS, true);
    }

    /**
     * Crea una imagen desde una cadena de datos
     * @param string $data Datos de la imagen
     * @param int $type Tipo de imagen
     * @return resource|GdImage|false
     */
    private static function createImageFromString(string $data, int $type)
    {
        $image = @imagecreatefromstring($data);
        if (!$image) {
            return false;
        }

        // Preservar transparencia según el tipo de imagen
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        return $image;
    }

    /**
     * Procesa y optimiza una imagen
     * @param resource|GdImage $image Recurso de imagen
     * @param int $targetWidth Ancho objetivo
     * @param int $type Tipo de imagen
     * @return resource|GdImage|false Imagen procesada o false en caso de error
     */
    private static function processImage($image, int $targetWidth = self::DEFAULT_WIDTH, int $type = IMAGETYPE_JPEG)
    {
        if (!is_resource($image) && !($image instanceof \GdImage)) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width <= $targetWidth) {
            return $image;
        }

        $ratio = $targetWidth / $width;
        $newHeight = (int) floor($height * $ratio);

        $newImage = imagecreatetruecolor($targetWidth, $newHeight);
        if (!$newImage) {
            return false;
        }

        // Configurar transparencia según el tipo de imagen
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
            imagefilledrectangle($newImage, 0, 0, $targetWidth, $newHeight, $transparent);
        }

        if (
            !imagecopyresampled(
                $newImage,
                $image,
                0,
                0,
                0,
                0,
                $targetWidth,
                $newHeight,
                $width,
                $height
            )
        ) {
            imagedestroy($newImage);
            return false;
        }

        return $newImage;
    }

    /**
     * Guarda una imagen en el formato especificado
     * @param resource|GdImage $image Recurso de imagen
     * @param string $filePath Ruta del archivo
     * @param int $type Tipo de imagen
     * @return bool
     */
    private static function saveImage($image, string $filePath, int $type): bool
    {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return imagejpeg($image, $filePath, self::QUALITY);
            case IMAGETYPE_PNG:
                return imagepng($image, $filePath, 9);
            case IMAGETYPE_GIF:
                return imagegif($image, $filePath);
            case IMAGETYPE_WEBP:
                return imagewebp($image, $filePath, self::QUALITY);
            default:
                return false;
        }
    }

    /**
     * Crea una carpeta de almacenamiento y guarda una imagen en formato base64.
     * @param string $base64string Imagen en base64
     * @param int|string $idusuario ID del usuario
     * @param bool $createWebp Crear versión WebP
     * @param string $folder Carpeta de destino
     * @param string $randon Prefijo aleatorio
     * @param bool $create_original Guardar imagen original
     * @return object Resultado de la operación
     */
    public static function createStorageAndImageBase64(
        string $base64string,
        $idusuario,
        bool $createWebp = true,
        string $folder = 'image',
        string $randon = '',
        bool $create_original = false
    ): object {



        // Validar URL
        if (!empty($base64string) && filter_var($base64string, FILTER_VALIDATE_URL)) {
            return (object) [
                'original' => $base64string,
                'webp' => $base64string,
                'resp' => 'file_is_url'
            ];
        }

        // Validar base64
        if (strpos($base64string, ',') === false) {
            return (object) [
                'original' => '',
                'webp' => '',
                'resp' => 'file_not_base64'
            ];
        }

        try {
            $_DOMINIO = self::get_dominio_now(true);

            // Enlazar carpeta con la del usuario
            $folder .= $idusuario ? '/' . $idusuario : '';

            // Crear estructura de directorios
            $paths = [
                self::STORAGE_PATH,
                self::STORAGE_PATH . $folder,
                self::STORAGE_PATH . $folder . '/' . date('Y'),
                self::STORAGE_PATH . $folder . '/' . date('Y') . '/' . date('m')
            ];


            $storagePath = end($paths);
            foreach ($paths as $path) {
                if (!self::createDirectory($path)) {
                    throw new Exception("Failed to create directory: $path");
                }
            }

            // Decodificar y procesar imagen
            $base64Data = explode(',', $base64string);
            $fileContents = base64_decode($base64Data[1], true);
            $mimeType = '';
        
            // Extraer tipo MIME de la cadena base64
            if (isset($base64Data[0])) {
                preg_match('/data:([^;]+)/', $base64Data[0], $matches);
                if (isset($matches[1])) {
                    $mimeType = $matches[1];
                }
            }


            if ($fileContents === false) {
                throw new Exception("Invalid base64 encoding");
            }

            // Procesar SVG
            if ($mimeType === 'image/svg+xml') {
                // Para SVG, simplemente guardamos el archivo original
                $originalPath = $storagePath . '/original';
                self::createDirectory($originalPath);
                
                $filePath = $originalPath . '/' . $randon . '_' . $idusuario . '_' . date('Ymd') . '.svg';
                
                if (!file_put_contents($filePath, $fileContents)) {
                    throw new Exception("Failed to save SVG file");
                }
                
                return (object) [
                    'original' => $_DOMINIO . '/' . $filePath,
                    'webp' => $_DOMINIO . '/' . $filePath, // Para SVG, usamos el mismo archivo como "webp"
                    'resp' => 'add_file_create',
                    'type' => 'image/svg+xml'
                ];
            }

            $image_info = @getimagesizefromstring($fileContents);
            if (!$image_info || !isset($image_info['mime'])) {
                throw new Exception("Invalid image format");
            }

            if (!isset(self::IMAGE_FORMATS[$image_info['mime']])) {
                throw new Exception("Unsupported image format: " . $image_info['mime']);
            }

            [$extension, $imageType] = self::IMAGE_FORMATS[$image_info['mime']];

            // Crear imagen desde string
            $image = self::createImageFromString($fileContents, $imageType);
            if (!$image) {
                throw new Exception("Failed to create image from string");
            }

            $result = ['original' => '', 'webp' => ''];

            try {
                // Procesar y guardar WebP
                if ($createWebp) {
                    $webpPath = $storagePath . '/webp';
                    self::createDirectory($webpPath);

                    $processedImage = self::processImage($image, self::DEFAULT_WIDTH, $imageType);
                    if (!$processedImage) {
                        throw new Exception("Failed to process image");
                    }

                    $webpFilePath = $webpPath . '/' . $randon . '_' . $idusuario . '_' . date('Ymd') . '.webp';

                    if (!self::saveImage($processedImage, $webpFilePath, IMAGETYPE_WEBP)) {
                        throw new Exception("Failed to save WebP image");
                    }

                    $result['webp'] = $_DOMINIO . '/' . $webpFilePath;

                    if ($processedImage !== $image) {
                        imagedestroy($processedImage);
                    }
                }

                // Guardar original si se solicita
                if ($create_original) {
                    $originalPath = $storagePath . '/original';
                    self::createDirectory($originalPath);

                    $filePath = $originalPath . '/' . $idusuario . '_' . date('Ymd') . $extension;
                    if (!file_put_contents($filePath, $fileContents)) {
                        throw new Exception("Failed to save original image");
                    }

                    $result['original'] = $_DOMINIO . '/' . $filePath;
                }

                return (object) array_merge($result, ['resp' => 'add_file_create']);

            } finally {
                // Asegurar que se libere la memoria de la imagen original
                if (isset($image) && ($image instanceof \GdImage || is_resource($image))) {
                    imagedestroy($image);
                }
            }

        } catch (Exception $e) {
            return (object) [
                'original' => '',
                'webp' => '',
                'resp' => 'file_create_err',
                'error' => $e->getMessage()
            ];
        }
    }

    public static function storeFile(string $fileName, array $FILE, string $id, string $folder = 'files'): object
    {
        try {
            // Enlazar carpeta con la del usuario
            $folder .= $id ? '/' . $id : '';

            $fileParts = pathinfo($FILE['name']);
            $extension = isset($fileParts['extension']) ? strtolower($fileParts['extension']) : '';

            // Restricciones de tipos de archivo permitidos
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'docx', 'xlsx', 'xls', 'csv', 'doc', 'txt', 'webp', 'ppt', 'pptx', 'xml','svg']; // Añadir más extensiones según sea necesario
            if (!in_array($extension, $allowedExtensions)) {
                throw new Exception('Invalid file type. Only images and documents are allowed.');
            }

            // Crear estructura de directorios
            $paths = [
                self::STORAGE_PATH,
                self::STORAGE_PATH . $folder,
                self::STORAGE_PATH . $folder . '/' . date('Y'),
                self::STORAGE_PATH . $folder . '/' . date('Y') . '/' . date('m')
            ];

            $storagePath = end($paths);
            foreach ($paths as $path) {
                if (!self::createDirectory($path)) {
                    throw new Exception("Failed to create directory: $path");
                }
            }

            $destinationPath = $storagePath . '/' . $fileName . '.' . $extension;

            // Deshabilitar la ejecución de archivos: Establecer permisos seguros
            if (file_exists($destinationPath)) {
                throw new Exception('File already exists.');
            }

            // Mover el archivo al directorio destino
            if (!move_uploaded_file($FILE['tmp_name'], $destinationPath)) {
                throw new Exception('Failed to move file');
            }

            // Cambiar permisos del archivo para que no sea ejecutable (0x0444 es solo lectura)
            chmod($destinationPath, 0444); // Asegura que el archivo no sea ejecutable

            // Obtener URL base
            $baseUrl = self::get_dominio_now(true);
            return (object) [
                'resp' => 'file_uploaded',
                'original' => $baseUrl . '/' . $destinationPath,
                'type' => $FILE['type']
            ];
        } catch (Exception $e) {
            return (object) [
                'resp' => 'file_create_err',
                'error' => $e->getMessage()
            ];
        }
    }

}