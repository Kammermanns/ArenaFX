<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Nur Admin darf Backups herunterladen.');
}

$mediaRoot = realpath(__DIR__ . '/media');
if ($mediaRoot === false || !class_exists(PharData::class)) {
    http_response_code(500);
    exit('Backup-Erstellung ist auf diesem Server nicht verfügbar.');
}
if (!is_file($mediaRoot . DIRECTORY_SEPARATOR . '.htaccess') || !str_contains((string)file_get_contents($mediaRoot . DIRECTORY_SEPARATOR . '.htaccess'), 'Require all denied')) {
    http_response_code(500);
    exit('Medien-Zugriffsschutz fehlt. Backup wurde nicht erstellt.');
}

$token = bin2hex(random_bytes(12));
$tempRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'soundbox-backup-' . $token;
if (!mkdir($tempRoot, 0700)) {
    http_response_code(500);
    exit('Temporäres Backup konnte nicht erstellt werden.');
}
$tarPath = $tempRoot . DIRECTORY_SEPARATOR . 'soundbox-backup.tar';
$gzipPath = $tarPath . '.gz';

try {
    $archive = new PharData($tarPath);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($mediaRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        $relativePath = substr($file->getPathname(), strlen(__DIR__) + 1);
        if (!$file->isFile() || $file->isLink() || preg_match('/\.(php[0-9]?|phtml|phar)$/i', $relativePath)) {
            continue;
        }
        $archivePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        $archive->addFile($file->getPathname(), $archivePath);
    }

    $archive->compress(Phar::GZ);
    unset($archive);

    if (!is_file($gzipPath)) {
        throw new RuntimeException('Backup-Archiv wurde nicht erstellt.');
    }

    header('Content-Type: application/gzip');
    header('Content-Length: ' . (string)filesize($gzipPath));
    header('Content-Disposition: attachment; filename="soundbox-backup-' . date('Y-m-d-His') . '.tar.gz"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($gzipPath);
} catch (Throwable $exception) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Backup konnte nicht erstellt werden.';
} finally {
    unset($archive);
    foreach ([$gzipPath, $tarPath] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    @rmdir($tempRoot);
}
