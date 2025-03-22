<?php

class FileManager
{
    private $storageDir;
    private $filePath;

    public function __construct($fileField = 'attachment', $storageDir = 'storage/temp')
    {
        $this->storageDir = $storageDir;
        $this->ensureDirectoryExists();
        $this->filePath = null;

        if (isset($_FILES[$fileField]) && $_FILES[$fileField]['error'] === UPLOAD_ERR_OK) {
            $this->createFileFromLocal($_FILES[$fileField]['tmp_name'], $_FILES[$fileField]['name']);
        } elseif (isset($_POST[$fileField]) && filter_var($_POST[$fileField], FILTER_VALIDATE_URL)) {
            $this->createFileFromUrl($_POST[$fileField]);
        } else {
            // throw new Exception("No valid file or URL provided.");
        }
    }

    private function ensureDirectoryExists()
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    public function getFileUrl()
    {
        return $this->filePath;
    }

    private function createFileFromLocal($tmpFilePath, $fileName)
    {
        $this->filePath = $this->storageDir . '/' . basename($fileName);
        if (move_uploaded_file($tmpFilePath, $this->filePath)) {
            return $this->filePath;
        } else {
            //throw new Exception("Error al mover el archivo.");
        }
    }

    private function createFileFromUrl($url)
    {
        $fileName = basename($url);
        $this->filePath = $this->storageDir . '/' . $fileName;

        $fileContent = file_get_contents($url);
        if ($fileContent === false) {
            //throw new Exception("Error al obtener el archivo de la URL.");
        }

        file_put_contents($this->filePath, $fileContent);
        return $this->filePath;
    }

    public function deleteFile()
    {
        if ($this->filePath && file_exists($this->filePath)) {
            unlink($this->filePath);
        } else {
            // throw new Exception("El archivo no existe o no se ha creado.");
        }
    }
}