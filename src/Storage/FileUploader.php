<?php

declare(strict_types=1);

namespace ExpressPHP\Storage;

use finfo;
use Throwable;
use ZipArchive;

final class FileUploader
{
    // Rejected even when listed in a configured allowlist: servers may execute or render these.
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps',
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'js', 'mjs',
        'htaccess', 'cgi', 'pl', 'py', 'sh', 'exe', 'bat', 'cmd', 'jsp', 'asp', 'aspx',
    ];

    // OOXML files are ZIP archives; finfo may only report application/zip, so their structure is checked.
    private const OFFICE_ENTRIES = [
        'docx' => 'word/document.xml',
        'xlsx' => 'xl/workbook.xml',
    ];

    private static array $config = [];

    /**
     * Stores the global upload policy: path, size limit, allowed extensions, MIME map, and file modes.
     */
    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Validates one uploaded file and moves it, under a random name, into the private upload directory.
     */
    public function upload(array $file, string $directory = '', array $options = []): array
    {
        $this->validateUpload($file);
        $config = [...self::$config, ...$options];
        // A per-upload MIME list replaces the global per-extension map instead of being ignored by it.
        if (array_key_exists('allowed_mime_types', $options) && !array_key_exists('mime_types_by_extension', $options)) {
            unset($config['mime_types_by_extension']);
        }
        $originalName = $this->originalName($file);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $size = (int)($file['size'] ?? 0);
        $MIMEType = $this->MIMEType((string)$file['tmp_name']);

        $this->validateSize($size, (int)($config['max_size'] ?? 10 * 1024 * 1024));
        $this->validateExtension($extension, $config['allowed_extensions'] ?? []);
        $this->validateMIMEType($extension, $MIMEType, $config);
        $this->validateOfficeArchive($extension, $MIMEType, (string)$file['tmp_name']);

        $root = $this->root($config);
        $subdirectory = $this->subdirectory($directory);
        $destinationDirectory = $subdirectory === '' ? $root : $root . '/' . $subdirectory;
        $this->createDirectory($destinationDirectory, $config);

        $storedName = bin2hex(random_bytes(16)) . ($extension === '' ? '' : '.' . $extension);
        $destination = $destinationDirectory . '/' . $storedName;
        if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
            throw new FileUploadException('The uploaded file could not be stored.');
        }
        @chmod($destination, (int)($config['file_mode'] ?? 0640));

        return [
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'extension' => $extension,
            'mime_type' => $MIMEType,
            'size' => $size,
            'path' => $destination,
            'relative_path' => ltrim($subdirectory . '/' . $storedName, '/'),
        ];
    }

    /**
     * Uploads several files. If one fails, the files already stored are removed.
     */
    public function uploadMany(array $files, string $directory = '', array $options = []): array
    {
        $uploaded = [];
        try {
            foreach ($this->normalize($files) as $file) {
                $uploaded[] = $this->upload($file, $directory, $options);
            }
        } catch (Throwable $exception) {
            // All or nothing: remove files stored earlier in this batch.
            foreach ($uploaded as $stored) {
                @unlink($stored['path']);
            }
            throw $exception;
        }
        return $uploaded;
    }

    /**
     * Returns the absolute path of a stored upload, or null when the relative path does not resolve to a file
     * inside the upload directory. Use this before Response::sendFile() whenever the path comes from a client,
     * so values such as "../../config/app.php" cannot reach other files.
     */
    public function path(string $relativePath): ?string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            return null;
        }
        $root = $this->root(self::$config);
        $candidate = realpath($root . '/' . ltrim(str_replace('\\', '/', $relativePath), '/'));
        return $candidate !== false && str_starts_with($candidate, $root . '/') && is_file($candidate) ? $candidate : null;
    }

    /**
     * Deletes a stored upload by relative path, but only when it resolves inside the upload directory.
     */
    public function delete(string $relativePath): bool
    {
        $root = $this->root(self::$config);
        $candidate = realpath($root . '/' . ltrim($relativePath, '/'));
        if ($candidate === false || !str_starts_with($candidate, $root . '/') || !is_file($candidate)) {
            return false;
        }
        return unlink($candidate);
    }

    /**
     * Rejects PHP upload errors and files that did not arrive through an HTTP upload.
     */
    private function validateUpload(array $file): void
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new FileUploadException($this->uploadError($error));
        }
        $temporaryPath = $file['tmp_name'] ?? null;
        if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
            throw new FileUploadException('The file is not a valid HTTP upload.');
        }
    }

    /**
     * Returns the client's file name reduced to a safe basename, for display only.
     */
    private function originalName(array $file): string
    {
        $name = basename(str_replace('\\', '/', (string)($file['name'] ?? 'file')));
        $name = preg_replace('/[^A-Za-z0-9._ -]/u', '_', $name) ?? 'file';
        return trim($name, '. ') === '' ? 'file' : $name;
    }

    /**
     * Detects the MIME type from the file contents.
     */
    private function MIMEType(string $path): string
    {
        $MIMEType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        return is_string($MIMEType) && $MIMEType !== '' ? strtolower($MIMEType) : 'application/octet-stream';
    }

    /**
     * Rejects empty files and files over the size limit.
     */
    private function validateSize(int $size, int $maximum): void
    {
        if ($size < 1) {
            throw new FileUploadException('The uploaded file is empty.');
        }
        if ($maximum < 1 || $size > $maximum) {
            throw new FileUploadException('The uploaded file exceeds the maximum allowed size.');
        }
    }

    /**
     * Requires an extension that is on the allowlist and is not executable or markup.
     */
    private function validateExtension(string $extension, mixed $allowed): void
    {
        if (!is_array($allowed) || $allowed === []) {
            throw new FileUploadException('No upload extension allowlist is configured.');
        }
        if ($extension === '') {
            throw new FileUploadException('The uploaded file must have an extension.');
        }
        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw new FileUploadException('The uploaded file extension is not allowed.');
        }
        $this->validateAllowed($extension, $allowed, 'extension');
    }

    /**
     * Rejects a value that is not in an allowlist (case-insensitive).
     */
    private function validateAllowed(string $value, array $allowed, string $label): void
    {
        $allowed = array_map(static fn(mixed $item): string => strtolower(trim((string)$item)), $allowed);
        if (!in_array(strtolower($value), $allowed, true)) {
            throw new FileUploadException("The uploaded file {$label} is not allowed.");
        }
    }

    /**
     * Checks the detected MIME type against the extension's map or the allowed MIME list, and fails when neither is
     * configured.
     */
    private function validateMIMEType(string $extension, string $MIMEType, array $config): void
    {
        $mapping = $config['mime_types_by_extension'] ?? [];
        if (is_array($mapping) && $mapping !== []) {
            $allowed = $mapping[$extension] ?? [];
            if (!is_array($allowed) || $allowed === []) {
                throw new FileUploadException('The uploaded file extension has no MIME type policy.');
            }
            $this->validateAllowed($MIMEType, $allowed, 'MIME type');
            return;
        }

        $allowed = $config['allowed_mime_types'] ?? [];
        if (!is_array($allowed) || $allowed === []) {
            throw new FileUploadException('No upload MIME type policy is configured.');
        }
        $this->validateAllowed($MIMEType, $allowed, 'MIME type');
    }

    /**
     * Checks that a docx or xlsx file reported as a plain ZIP has the Office Open XML structure.
     */
    private function validateOfficeArchive(string $extension, string $MIMEType, string $path): void
    {
        $entry = self::OFFICE_ENTRIES[$extension] ?? null;
        if ($entry === null || $MIMEType !== 'application/zip') {
            return;
        }
        if (!class_exists(ZipArchive::class)) {
            throw new FileUploadException('The uploaded document could not be verified.');
        }

        $archive = new ZipArchive();
        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            throw new FileUploadException('The uploaded document is not a valid archive.');
        }
        $valid = $archive->locateName('[Content_Types].xml') !== false && $archive->locateName($entry) !== false;
        $archive->close();
        if (!$valid) {
            throw new FileUploadException('The uploaded file content does not match its extension.');
        }
    }

    /**
     * Returns the real path of the upload directory, creating it if needed.
     */
    private function root(array $config): string
    {
        $path = rtrim((string)($config['path'] ?? dirname(__DIR__, 2) . '/storage/uploads'), '/');
        if ($path === '') {
            throw new FileUploadException('The upload path is not configured.');
        }
        $this->createDirectory($path, $config);
        $realPath = realpath($path);
        if ($realPath === false) {
            throw new FileUploadException('The upload directory is unavailable.');
        }
        return $realPath;
    }

    /**
     * Validates a subdirectory as plain path segments with no traversal.
     */
    private function subdirectory(string $directory): string
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');
        if ($directory === '') {
            return '';
        }
        foreach (explode('/', $directory) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/^[A-Za-z0-9_-]+$/', $segment) !== 1) {
                throw new FileUploadException('The upload directory is invalid.');
            }
        }
        return $directory;
    }

    /**
     * Creates a directory with the configured mode and checks that it is writable.
     */
    private function createDirectory(string $directory, array $config = []): void
    {
        $mode = (int)($config['directory_mode'] ?? 0750);
        if (!is_dir($directory) && !@mkdir($directory, $mode, true) && !is_dir($directory)) {
            throw new FileUploadException('The upload directory could not be created.');
        }
        if (!is_writable($directory)) {
            throw new FileUploadException('The upload directory is not writable.');
        }
    }

    /**
     * Converts PHP's multi-file $_FILES layout into a list of single files.
     */
    private function normalize(array $files): array
    {
        if (!is_array($files['name'] ?? null)) {
            return [$files];
        }
        $normalized = [];
        foreach (array_keys($files['name']) as $index) {
            $normalized[] = [
                'name' => $files['name'][$index] ?? '',
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }
        return $normalized;
    }

    /**
     * Returns a readable message for a PHP upload error code.
     */
    private function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'The upload temporary directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
            default => 'The file upload failed.',
        };
    }
}
