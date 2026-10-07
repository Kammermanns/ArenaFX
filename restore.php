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
header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function archiveMediaRelativePath(string $archivePath): ?string {
    $normalizedPath = str_replace('\\', '/', $archivePath);
    if (!preg_match('#\.tar/media/(.+)\z#', $normalizedPath, $matches)) {
        return null;
    }
    return $matches[1];
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    respond(403, ['success' => false, 'message' => 'Nur Admin darf eine Wiederherstellung durchführen.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'POST erforderlich.']);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
    respond(419, ['success' => false, 'message' => 'Sitzung abgelaufen. Bitte erneut anmelden.']);
}

$upload = $_FILES['backup_file'] ?? null;
if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
    respond(400, ['success' => false, 'message' => 'Bitte ein gültiges Backup-Archiv auswählen.']);
}
if (($upload['size'] ?? PHP_INT_MAX) > 1024 * 1024 * 1024) {
    respond(413, ['success' => false, 'message' => 'Backup darf maximal 1 GB groß sein.']);
}
if (!class_exists(PharData::class)) {
    respond(500, ['success' => false, 'message' => 'Tar-Unterstützung ist auf diesem Server nicht verfügbar.']);
}

$mediaRoot = realpath(__DIR__ . '/media');
if ($mediaRoot === false) {
    respond(500, ['success' => false, 'message' => 'Media-Ordner nicht gefunden.']);
}

$tempRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'soundbox-restore-' . bin2hex(random_bytes(12));
if (!mkdir($tempRoot, 0700)) {
    respond(500, ['success' => false, 'message' => 'Temporärer Wiederherstellungsordner konnte nicht erstellt werden.']);
}
$uploadedArchive = $tempRoot . DIRECTORY_SEPARATOR . 'backup.tar.gz';
$extractRoot = $tempRoot . DIRECTORY_SEPARATOR . 'extract';
$backupCopy = $tempRoot . DIRECTORY_SEPARATOR . 'before-restore';
$originalFiles = [];
$snapshotComplete = false;
$restoreSucceeded = false;
$restoreError = null;

try {
    if (!move_uploaded_file($upload['tmp_name'], $uploadedArchive)) {
        throw new RuntimeException('Upload fehlgeschlagen.');
    }

    $compressedArchive = new PharData($uploadedArchive);
    $compressedArchive->decompress();
    $tarPath = substr($uploadedArchive, 0, -3);
    if (!is_file($tarPath)) {
        throw new RuntimeException('Backup-Archiv konnte nicht entpackt werden.');
    }
    $archive = new PharData($tarPath);
    if (!mkdir($extractRoot, 0700)) {
        throw new RuntimeException('Archiv konnte nicht vorbereitet werden.');
    }

    $entries = [];
    $expandedBytes = 0;
    foreach (new RecursiveIteratorIterator($archive) as $entry) {
        if (!$entry->isFile()) {
            continue;
        }
        if (method_exists($entry, 'isLink') && $entry->isLink()) {
            throw new RuntimeException('Backup enthält einen symbolischen Link.');
        }
        $relative = archiveMediaRelativePath((string)$entry->getPathName());
        if ($relative === null) continue;
        $relative = 'media/' . $relative;
        if (preg_match('#(^|/)\.\.(/|$)#', $relative) || preg_match('/\.(php[0-9]?|phtml|phar)$/i', $relative)) {
            throw new RuntimeException('Backup enthält einen unzulässigen Dateipfad.');
        }
        $relativePath = substr($relative, strlen('media/'));
        if ($relativePath === '' || str_starts_with($relativePath, '/') || !preg_match('/\A(?:config\.json|logo\.(?:png|jpg)|\.htaccess|allgemein\/musik\/[A-Za-z0-9][A-Za-z0-9 _.-]{0,175}\.(?:mp3|wav|ogg)|teams\/[a-zA-Z0-9_-]{1,64}\/(?:musik|effekte)\/[A-Za-z0-9][A-Za-z0-9 _.-]{0,175}\.(?:mp3|wav|ogg))\z/i', $relativePath)) {
            throw new RuntimeException('Backup enthält einen ungültigen Dateipfad.');
        }
        $expandedBytes += (int)$entry->getSize();
        if ($expandedBytes > 2 * 1024 * 1024 * 1024) {
            throw new RuntimeException('Entpackte Backup-Größe überschreitet 2 GB.');
        }
        $entries[$relativePath] = $entry;
        if (count($entries) > 50000) {
            throw new RuntimeException('Backup enthält zu viele Dateien.');
        }
    }

    if (!isset($entries['config.json'])) {
        throw new RuntimeException('Im Backup fehlt media/config.json.');
    }
    $configJson = file_get_contents($entries['config.json']->getPathName());
    $config = json_decode((string)$configJson, true);
    if (!is_array($config) || !isset($config['admin'], $config['teams']) || !is_array($config['admin']) || !is_array($config['teams']) || !isset($config['admin']['username'], $config['admin']['password'])) {
        throw new RuntimeException('Die Konfiguration im Backup ist ungültig.');
    }
    $seenTeamIds = [];
    foreach ($config['teams'] as $team) {
        if (!is_array($team) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', (string)($team['id'] ?? '')) || trim((string)($team['name'] ?? '')) === '') {
            throw new RuntimeException('Backup-Konfiguration enthält ungültige Teamdaten.');
        }
        $id = (string)$team['id'];
        if (isset($seenTeamIds[$id])) {
            throw new RuntimeException('Backup-Konfiguration enthält doppelte Team-IDs.');
        }
        $seenTeamIds[$id] = true;
    }

    foreach ($entries as $relativePath => $entry) {
        if ($relativePath === '.htaccess') {
            continue;
        }
        $destination = $extractRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('Archivdateien konnten nicht vorbereitet werden.');
        }
        if (!copy($entry->getPathName(), $destination)) {
            throw new RuntimeException('Archivdatei konnte nicht extrahiert werden.');
        }
    }

    $backupArchive = new PharData($backupCopy . '.tar');
    $currentIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mediaRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($currentIterator as $file) {
        if ($file->isFile() && !$file->isLink()) {
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(__DIR__) + 1));
            $originalFiles[$relativePath] = true;
            $backupArchive->addFile($file->getPathname(), $relativePath);
        }
    }
    unset($backupArchive);
    $snapshotComplete = true;

    $restoreIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($restoreIterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relativePath = substr($file->getPathname(), strlen($extractRoot) + 1);
        $target = $mediaRoot . DIRECTORY_SEPARATOR . $relativePath;
        $targetParent = dirname($target);
        if (!is_dir($targetParent) && !mkdir($targetParent, 0755, true) && !is_dir($targetParent)) {
            throw new RuntimeException('Zielordner konnte nicht erstellt werden.');
        }
        if (!copy($file->getPathname(), $target)) {
            throw new RuntimeException('Datei konnte nicht wiederhergestellt werden.');
        }
    }

    $currentIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mediaRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($currentIterator as $item) {
        if (!$item->isFile() || $item->isLink()) continue;
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen(__DIR__) + 1));
        if ($relativePath !== 'media/.htaccess' && !isset($entries[substr($relativePath, strlen('media/'))])) {
            if (!unlink($item->getPathname())) {
                throw new RuntimeException('Nicht im Backup enthaltene Datei konnte nicht entfernt werden.');
            }
        }
    }

    $mediaAccessRules = "Options -Indexes\nRequire all denied\n";
    if (file_put_contents($mediaRoot . DIRECTORY_SEPARATOR . '.htaccess', $mediaAccessRules, LOCK_EX) === false) {
        throw new RuntimeException('Medien-Zugriffsschutz konnte nicht wiederhergestellt werden.');
    }

    $restoreSucceeded = true;
    $_SESSION = [];
    session_destroy();
} catch (Throwable $exception) {
    $restoreError = $exception->getMessage();
    if ($snapshotComplete && is_file($backupCopy . '.tar')) {
        try {
            $rollback = new PharData($backupCopy . '.tar');
            foreach (new RecursiveIteratorIterator($rollback) as $entry) {
                if (!$entry->isFile()) continue;
                $relativePath = archiveMediaRelativePath((string)$entry->getPathName());
                if ($relativePath === null) continue;
                $target = $mediaRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                $parent = dirname($target);
                if (!is_dir($parent)) mkdir($parent, 0755, true);
                copy($entry->getPathName(), $target);
            }

            $rollbackIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mediaRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($rollbackIterator as $item) {
                if (!$item->isFile() || $item->isLink()) continue;
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen(__DIR__) + 1));
                if (!isset($originalFiles[$relativePath])) unlink($item->getPathname());
            }
        } catch (Throwable $rollbackException) {
            error_log('Soundbox restore rollback failed: ' . $rollbackException->getMessage());
        }
    }
} finally {
    if (is_dir($tempRoot)) {
        $cleanup = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($cleanup as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($tempRoot);
    }
}

if (!$restoreSucceeded) {
    error_log('Soundbox restore failed: ' . (string)$restoreError);
    respond(400, ['success' => false, 'message' => 'Wiederherstellung fehlgeschlagen; aktuelle Daten wurden soweit möglich beibehalten.']);
}

http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Backup wurde wiederhergestellt. Bitte neu anmelden.'], JSON_UNESCAPED_UNICODE);
