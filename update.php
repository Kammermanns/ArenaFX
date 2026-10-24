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
header('Cache-Control: no-store');

const UPDATE_REPOSITORY = 'Kammermanns/ArenaFX';
const UPDATE_API_BASE = 'https://api.github.com';
const UPDATE_ALLOWED_HOSTS = ['api.github.com', 'github.com', 'codeload.github.com'];
const UPDATE_MAX_ARCHIVE_BYTES = 30 * 1024 * 1024;
const UPDATE_MAX_EXTRACTED_BYTES = 60 * 1024 * 1024;
const UPDATE_MAX_ENTRIES = 3000;
const UPDATE_KEEP_BACKUPS = 3;
const UPDATE_CACHE_SECONDS = 600;
const UPDATE_REQUIRED_FILES = ['index.php', 'login.php', 'settings.php', 'save_settings.php', 'update.php', 'version.php'];
const UPDATE_ALLOWED_EXTENSIONS = ['php', 'css', 'js', 'md', 'txt', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'ico', 'webp', 'woff', 'woff2', 'json'];

$appRoot = __DIR__;
$backupRoot = $appRoot . DIRECTORY_SEPARATOR . 'update-backups';

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function currentVersion(): string {
    $version = require __DIR__ . '/version.php';
    return is_string($version) ? $version : '0.0.0';
}

function normalizeVersion(string $tag): ?string {
    return preg_match('/\Av?(\d+\.\d+\.\d+)\z/', trim($tag), $matches) ? $matches[1] : null;
}

function updateTempDir(string $prefix): string {
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700)) {
        throw new RuntimeException('Temporärer Ordner konnte nicht erstellt werden.');
    }
    return $dir;
}

function removeTree(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

/** Sucht ein CA-Zertifikatsbündel, damit HTTPS auch unter XAMPP ohne curl.cainfo funktioniert. */
function caBundlePath(): ?string {
    foreach (['curl.cainfo', 'openssl.cafile'] as $setting) {
        $configured = (string)ini_get($setting);
        if ($configured !== '' && is_file($configured)) {
            return null; // PHP ist bereits korrekt konfiguriert
        }
    }
    $phpDir = dirname(PHP_BINARY);
    $candidates = [
        $phpDir . '/extras/ssl/cacert.pem',
        $phpDir . '/../apache/bin/curl-ca-bundle.crt',
        'C:/xampp/apache/bin/curl-ca-bundle.crt',
        'C:/xampp/php/extras/ssl/cacert.pem',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return null;
}

function isAllowedUrl(string $url): bool {
    $parts = parse_url($url);
    return is_array($parts)
        && ($parts['scheme'] ?? '') === 'https'
        && in_array(strtolower((string)($parts['host'] ?? '')), UPDATE_ALLOWED_HOSTS, true);
}

/**
 * Lädt eine URL per HTTPS von GitHub. Weiterleitungen werden nur innerhalb der
 * erlaubten GitHub-Hosts verfolgt. Gibt den Inhalt zurück oder schreibt ihn in $targetFile.
 */
function httpGet(string $url, int $maxBytes, ?string $targetFile = null, string $accept = 'application/vnd.github+json'): string {
    $headers = ['Accept: ' . $accept, 'User-Agent: ArenaFX-Updater/' . currentVersion(), 'X-GitHub-Api-Version: 2022-11-28'];

    for ($redirects = 0; $redirects <= 5; $redirects++) {
        if (!isAllowedUrl($url)) {
            throw new RuntimeException('Unerwartete Download-Adresse.');
        }

        $buffer = '';
        $received = 0;
        $handle = $targetFile !== null ? fopen($targetFile, 'wb') : null;
        if ($targetFile !== null && $handle === false) {
            throw new RuntimeException('Download-Datei konnte nicht angelegt werden.');
        }

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            $options = [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADER => false,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$buffer, &$received, $handle, $maxBytes): int {
                    $received += strlen($chunk);
                    if ($received > $maxBytes) {
                        return 0; // bricht den Download ab
                    }
                    if ($handle) {
                        fwrite($handle, $chunk);
                    } else {
                        $buffer .= $chunk;
                    }
                    return strlen($chunk);
                },
            ];
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                $options[CURLOPT_PROTOCOLS_STR] = 'https';
            } elseif (defined('CURLOPT_PROTOCOLS')) {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            }
            $caBundle = caBundlePath();
            if ($caBundle !== null) {
                $options[CURLOPT_CAINFO] = $caBundle;
            }
            curl_setopt_array($curl, $options);
            $ok = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $location = (string)curl_getinfo($curl, CURLINFO_REDIRECT_URL);
            $errorNumber = curl_errno($curl);
            curl_close($curl);
            if ($handle) {
                fclose($handle);
            }

            if ($received > $maxBytes) {
                throw new RuntimeException('Die Update-Datei ist unerwartet gross.');
            }
            if ($ok === false) {
                if (in_array($errorNumber, [35, 51, 58, 60, 77], true)) {
                    throw new RuntimeException('Die sichere Verbindung zu GitHub konnte nicht geprüft werden. In der php.ini muss curl.cainfo auf ein CA-Zertifikatsbündel zeigen.');
                }
                throw new RuntimeException('GitHub ist nicht erreichbar. Bitte Internetverbindung prüfen.');
            }
        } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $context = stream_context_create([
                'http' => ['header' => implode("\r\n", $headers), 'follow_location' => 0, 'timeout' => 120, 'ignore_errors' => true],
                'ssl' => array_filter(['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => caBundlePath()]),
            ]);
            $stream = @fopen($url, 'rb', false, $context);
            if ($stream === false) {
                if ($handle) fclose($handle);
                throw new RuntimeException('GitHub ist nicht erreichbar. Bitte Internetverbindung prüfen.');
            }
            $meta = stream_get_meta_data($stream);
            $status = 0;
            $location = '';
            foreach ($meta['wrapper_data'] ?? [] as $line) {
                if (preg_match('#\AHTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int)$m[1];
                if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
            }
            while (!feof($stream)) {
                $chunk = (string)fread($stream, 65536);
                $received += strlen($chunk);
                if ($received > $maxBytes) {
                    fclose($stream);
                    if ($handle) fclose($handle);
                    throw new RuntimeException('Die Update-Datei ist unerwartet gross.');
                }
                if ($handle) {
                    fwrite($handle, $chunk);
                } else {
                    $buffer .= $chunk;
                }
            }
            fclose($stream);
            if ($handle) fclose($handle);
        } else {
            if ($handle) fclose($handle);
            throw new RuntimeException('Für Updates wird die PHP-Erweiterung curl benötigt.');
        }

        if ($status >= 300 && $status < 400 && $location !== '') {
            $url = $location;
            continue;
        }
        if ($status === 403 || $status === 429) {
            throw new RuntimeException('GitHub hat zu viele Anfragen erhalten. Bitte in einer Stunde erneut versuchen.');
        }
        if ($status === 404) {
            throw new RuntimeException('Es wurde noch kein Release veröffentlicht.');
        }
        if ($status !== 200) {
            throw new RuntimeException('GitHub antwortete unerwartet (HTTP ' . $status . ').');
        }
        return $buffer;
    }
    throw new RuntimeException('Zu viele Weiterleitungen beim Download.');
}

function cacheFile(): string {
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'arenafx-update-' . hash('sha256', __DIR__) . '.json';
}

function latestRelease(bool $useCache): array {
    $cache = cacheFile();
    if ($useCache && is_file($cache) && filemtime($cache) > time() - UPDATE_CACHE_SECONDS) {
        $cached = json_decode((string)file_get_contents($cache), true);
        if (is_array($cached) && isset($cached['version'])) {
            return $cached;
        }
    }

    $data = json_decode(httpGet(UPDATE_API_BASE . '/repos/' . UPDATE_REPOSITORY . '/releases/latest', 1024 * 1024), true);
    if (!is_array($data)) {
        throw new RuntimeException('Die Antwort von GitHub war ungültig.');
    }
    $version = normalizeVersion((string)($data['tag_name'] ?? ''));
    if ($version === null) {
        throw new RuntimeException('Das neueste Release hat keine gültige Versionsnummer (z. B. v1.2.0).');
    }
    $release = [
        'version' => $version,
        'tag' => (string)$data['tag_name'],
        'name' => (string)($data['name'] ?? ''),
        'notes' => function_exists('mb_substr') ? mb_substr((string)($data['body'] ?? ''), 0, 20000) : substr((string)($data['body'] ?? ''), 0, 20000),
        'published_at' => (string)($data['published_at'] ?? ''),
        'html_url' => isAllowedUrl((string)($data['html_url'] ?? '')) ? (string)$data['html_url'] : '',
        'zipball_url' => (string)($data['zipball_url'] ?? ''),
    ];
    @file_put_contents($cache, json_encode($release), LOCK_EX);
    return $release;
}

/** Prüft einen Archivpfad und gibt den Zielpfad relativ zum Programmordner zurück, oder null zum Überspringen. */
function updateTargetPath(string $relative): ?string {
    if ($relative === '' || str_ends_with($relative, '/')) {
        return null;
    }
    if (!preg_match('#\A[A-Za-z0-9._/-]+\z#', $relative) || preg_match('#(\A|/)\.\.?(/|\z)#', $relative) || str_starts_with($relative, '/')) {
        throw new RuntimeException('Das Update-Archiv enthält einen unzulässigen Dateipfad.');
    }
    $first = explode('/', $relative)[0];
    if ($first === 'update-backups' || ($first === 'media' && $relative !== 'media/.htaccess')) {
        return null; // Benutzerdaten und Sicherungen werden nie überschrieben
    }
    $basename = basename($relative);
    if ($basename === '.htaccess') {
        return $relative;
    }
    if (preg_match('#(\A|/)\.#', $relative)) {
        return null; // .gitignore, .gitattributes, .github usw.
    }
    return in_array(strtolower(pathinfo($basename, PATHINFO_EXTENSION)), UPDATE_ALLOWED_EXTENSIONS, true) ? $relative : null;
}

/** Entpackt das GitHub-Archiv geprüft in $stageDir und gibt die Liste der Dateien zurück. */
function extractUpdate(string $archiveFile, string $stageDir): array {
    $entries = [];
    $total = 0;
    $prefix = null;

    $collect = static function (string $name, int $size, callable $read, bool $isLink) use (&$entries, &$total, &$prefix): void {
        $name = str_replace('\\', '/', $name);
        $slash = strpos($name, '/');
        if ($slash === false) {
            throw new RuntimeException('Das Update-Archiv hat ein unerwartetes Format.');
        }
        $root = substr($name, 0, $slash);
        $prefix ??= $root;
        if ($root !== $prefix) {
            throw new RuntimeException('Das Update-Archiv hat ein unerwartetes Format.');
        }
        $target = updateTargetPath(substr($name, $slash + 1));
        if ($target === null) {
            return;
        }
        if ($isLink) {
            throw new RuntimeException('Das Update-Archiv enthält einen symbolischen Link.');
        }
        if (count($entries) >= UPDATE_MAX_ENTRIES) {
            throw new RuntimeException('Das Update-Archiv enthält zu viele Dateien.');
        }
        $total += $size;
        if ($total > UPDATE_MAX_EXTRACTED_BYTES) {
            throw new RuntimeException('Das entpackte Update ist unerwartet gross.');
        }
        $entries[$target] = $read;
    };

    if (class_exists(ZipArchive::class)) {
        $zip = new ZipArchive();
        if ($zip->open($archiveFile, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Das Update-Archiv konnte nicht geöffnet werden.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new RuntimeException('Das Update-Archiv ist beschädigt.');
            }
            $isLink = false;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && $opsys === ZipArchive::OPSYS_UNIX) {
                $isLink = (($attributes >> 16) & 0170000) === 0120000;
            }
            $collect((string)$stat['name'], (int)$stat['size'], static fn() => $zip->getFromIndex($i), $isLink);
        }
        $files = [];
        foreach ($entries as $target => $read) {
            $files[$target] = $read();
        }
        $zip->close();
    } else {
        $phar = new PharData($archiveFile);
        $base = 'phar://' . str_replace('\\', '/', $archiveFile) . '/';
        foreach (new RecursiveIteratorIterator($phar) as $entry) {
            if (!$entry->isFile()) continue;
            $path = substr(str_replace('\\', '/', $entry->getPathname()), strlen($base));
            $collect($path, (int)$entry->getSize(), static fn() => file_get_contents($entry->getPathname()), false);
        }
        $files = [];
        foreach ($entries as $target => $read) {
            $files[$target] = $read();
        }
    }

    foreach (UPDATE_REQUIRED_FILES as $required) {
        if (!isset($files[$required])) {
            throw new RuntimeException('Das Update-Archiv ist unvollständig (' . $required . ' fehlt).');
        }
    }

    foreach ($files as $target => $contents) {
        if (!is_string($contents) || strlen($contents) > UPDATE_MAX_EXTRACTED_BYTES) {
            throw new RuntimeException('Eine Datei im Update-Archiv konnte nicht gelesen werden.');
        }
        $destination = $stageDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $target);
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0700, true)) {
            throw new RuntimeException('Update konnte nicht vorbereitet werden.');
        }
        if (file_put_contents($destination, $contents) === false) {
            throw new RuntimeException('Update konnte nicht vorbereitet werden.');
        }
    }
    return array_keys($files);
}

function pruneBackups(string $backupRoot): void {
    $dirs = glob($backupRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
    sort($dirs);
    while (count($dirs) > UPDATE_KEEP_BACKUPS) {
        removeTree(array_shift($dirs));
    }
}

function ensureBackupRoot(string $backupRoot): void {
    if (!is_dir($backupRoot) && !mkdir($backupRoot, 0755, true) && !is_dir($backupRoot)) {
        throw new RuntimeException('Der Ordner update-backups konnte nicht angelegt werden. Schreibrechte prüfen.');
    }
    $rules = "Options -Indexes\nRequire all denied\n";
    if (file_put_contents($backupRoot . DIRECTORY_SEPARATOR . '.htaccess', $rules, LOCK_EX) === false) {
        throw new RuntimeException('Der Zugriffsschutz für update-backups konnte nicht angelegt werden.');
    }
}

// ---------------------------------------------------------------------------

if (($_SESSION['role'] ?? '') !== 'admin') {
    respond(403, ['success' => false, 'message' => 'Nur Admin darf Updates verwalten.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'POST erforderlich.']);
}
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    respond(419, ['success' => false, 'message' => 'Sitzung abgelaufen. Bitte erneut anmelden.']);
}

$action = (string)($_POST['action'] ?? '');
$current = currentVersion();
$isGitCheckout = is_dir($appRoot . DIRECTORY_SEPARATOR . '.git');

if ($action === 'check') {
    try {
        $release = latestRelease(($_POST['force'] ?? '') !== '1');
    } catch (Throwable $exception) {
        respond(502, ['success' => false, 'current_version' => $current, 'message' => $exception->getMessage()]);
    }
    respond(200, [
        'success' => true,
        'current_version' => $current,
        'latest_version' => $release['version'],
        'update_available' => version_compare($release['version'], $current, '>'),
        'release_name' => $release['name'],
        'notes' => $release['notes'],
        'published_at' => $release['published_at'],
        'html_url' => $release['html_url'],
        'git_checkout' => $isGitCheckout,
    ]);
}

if ($action !== 'install') {
    respond(400, ['success' => false, 'message' => 'Unbekannte Aktion.']);
}

if ($isGitCheckout) {
    respond(409, ['success' => false, 'message' => 'Diese Installation ist ein Git-Klon. Bitte mit „git pull“ aktualisieren.']);
}

$lock = fopen(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'arenafx-update-' . hash('sha256', __DIR__) . '.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    respond(409, ['success' => false, 'message' => 'Ein Update läuft bereits.']);
}

ignore_user_abort(true);
@set_time_limit(300);

$tempDir = null;
$backupDir = null;
$replaced = [];
$created = [];

try {
    $release = latestRelease(false);
    $expected = normalizeVersion((string)($_POST['version'] ?? ''));
    if ($expected === null || $expected !== $release['version']) {
        throw new RuntimeException('Inzwischen ist eine andere Version verfügbar. Bitte erneut nach Updates suchen.');
    }
    if (!version_compare($release['version'], $current, '>')) {
        throw new RuntimeException('Die Soundbox ist bereits auf dem neuesten Stand.');
    }
    $zipballUrl = UPDATE_API_BASE . '/repos/' . UPDATE_REPOSITORY . '/zipball/' . rawurlencode($release['tag']);

    $tempDir = updateTempDir('arenafx-update-');
    $archive = $tempDir . DIRECTORY_SEPARATOR . 'update.zip';
    $stage = $tempDir . DIRECTORY_SEPARATOR . 'stage';
    mkdir($stage, 0700);
    httpGet($zipballUrl, UPDATE_MAX_ARCHIVE_BYTES, $archive, 'application/vnd.github+json');
    $files = extractUpdate($archive, $stage);

    // Version nur lesen, nicht ausführen
    $stagedVersionSource = (string)file_get_contents($stage . DIRECTORY_SEPARATOR . 'version.php');
    if (!preg_match("/return\s+'(\d+\.\d+\.\d+)'\s*;/", $stagedVersionSource, $versionMatch) || $versionMatch[1] !== $release['version']) {
        throw new RuntimeException('Die Versionsnummer im Update passt nicht zum Release.');
    }

    // Vorab prüfen, ob alles geschrieben werden kann, damit nicht mittendrin abgebrochen wird.
    foreach ($files as $relative) {
        $target = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $probe = $target;
        while (!file_exists($probe)) {
            $probe = dirname($probe);
        }
        if (!is_writable($probe)) {
            throw new RuntimeException('Keine Schreibrechte für ' . $relative . '. Bitte Rechte des Programmordners prüfen.');
        }
    }

    ensureBackupRoot($backupRoot);
    $newBackupDir = $backupRoot . DIRECTORY_SEPARATOR . date('Ymd-His') . '-v' . $current . '-' . bin2hex(random_bytes(3));
    if (!mkdir($newBackupDir, 0755)) {
        throw new RuntimeException('Sicherung vor dem Update konnte nicht angelegt werden.');
    }
    $backupDir = $newBackupDir; // erst setzen, wenn der Ordner wirklich von diesem Versuch stammt
    foreach ($files as $relative) {
        $target = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($target)) {
            $copy = $backupDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ((!is_dir(dirname($copy)) && !mkdir(dirname($copy), 0755, true)) || !copy($target, $copy)) {
                throw new RuntimeException('Sicherung vor dem Update konnte nicht angelegt werden.');
            }
        }
    }
    file_put_contents($backupDir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
        'from_version' => $current,
        'to_version' => $release['version'],
        'created_at' => date('c'),
        'files' => $files,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Dateien einzeln ersetzen; version.php zuletzt, damit die Version erst nach Erfolg wechselt.
    usort($files, static fn(string $a, string $b): int => ($a === 'version.php') <=> ($b === 'version.php'));
    foreach ($files as $relative) {
        $target = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $source = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $existed = is_file($target);
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Ordner für ' . $relative . ' konnte nicht angelegt werden.');
        }
        $temporary = $target . '.update-tmp';
        if (!@copy($source, $temporary) || !@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Datei ' . $relative . ' konnte nicht ersetzt werden.');
        }
        if ($existed) {
            $replaced[] = $relative;
        } else {
            $created[] = $relative;
        }
    }

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    @unlink(cacheFile());
    pruneBackups($backupRoot);
} catch (Throwable $exception) {
    // Rückgängig machen: ersetzte Dateien zurückholen, neu angelegte entfernen.
    $rollbackFailed = false;
    foreach ($replaced as $relative) {
        $target = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $copy = $backupDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!@copy($copy, $target)) {
            $rollbackFailed = true;
        }
    }
    foreach ($created as $relative) {
        @unlink($appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    if ($backupDir !== null && !$rollbackFailed) {
        removeTree($backupDir); // alles ist wieder im alten Zustand, Sicherung unnötig
    }
    if ($tempDir !== null) {
        removeTree($tempDir);
    }
    error_log('ArenaFX update failed: ' . $exception->getMessage());
    $message = $exception->getMessage();
    if ($rollbackFailed) {
        $message .= ' Achtung: Nicht alle Dateien konnten zurückgesetzt werden. Die Sicherung liegt im Ordner update-backups.';
    } elseif ($replaced || $created) {
        $message .= ' Alle Änderungen wurden rückgängig gemacht.';
    }
    respond(500, ['success' => false, 'message' => $message]);
}

removeTree($tempDir);
respond(200, [
    'success' => true,
    'message' => 'Update auf Version ' . $release['version'] . ' installiert.',
    'previous_version' => $current,
    'current_version' => $release['version'],
    'files_updated' => count($replaced),
    'files_added' => count($created),
]);
