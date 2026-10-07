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

$baseDir = __DIR__ . '/media';
$configFile = $baseDir . '/config.json';

$action = $_POST['action'] ?? '';

function ensureDir($dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

function ensureCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function verifyCsrfToken(): bool {
    $token = (string)($_POST['csrf_token'] ?? '');
    return hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token);
}

function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}

function passwordMatches(string $password, string $storedPassword): bool {
    if ($storedPassword === '') {
        return false;
    }

    if (password_get_info($storedPassword)['algo'] !== null) {
        return password_verify($password, $storedPassword);
    }

    return hash_equals($storedPassword, $password);
}

function validateUploadedFile(array $file, array $allowedExtensions, array $allowedMimeTypes, int $maxSize): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'message' => 'Datei wurde nicht korrekt hochgeladen'];
    }

    $tmpName = $file['tmp_name'] ?? '';
    $originalName = (string)($file['name'] ?? '');
    if (!is_uploaded_file($tmpName) || !is_file($tmpName)) {
        return ['valid' => false, 'message' => 'Ungültige Datei'];
    }

    $fileSize = filesize($tmpName);
    if ($fileSize === false || $fileSize > $maxSize) {
        return ['valid' => false, 'message' => 'Datei ist zu groß'];
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        return ['valid' => false, 'message' => 'Nicht unterstütztes Dateiformat'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpName);
    if (!is_string($mimeType) || !in_array($mimeType, $allowedMimeTypes, true)) {
        return ['valid' => false, 'message' => 'Ungültiger Dateityp'];
    }

    return [
        'valid' => true,
        'tmp_name' => $tmpName,
        'name' => $originalName,
        'extension' => $extension,
        'mime' => $mimeType,
    ];
}

function persistConfig(array $config): bool {
    global $configFile;
    $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        return false;
    }
    $temporaryFile = $configFile . '.tmp';
    if (file_put_contents($temporaryFile, $encoded, LOCK_EX) === false) {
        return false;
    }
    return rename($temporaryFile, $configFile);
}

function loginRateLimitFile(): string {
    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'soundbox-login-limits';
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return $directory . DIRECTORY_SEPARATOR . hash('sha256', $ip) . '.json';
}

function getLoginLockoutSeconds(): int {
    $file = loginRateLimitFile();
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        return 0;
    }
    flock($handle, LOCK_EX);
    $contents = stream_get_contents($handle);
    $state = json_decode($contents ?: '{}', true);
    $attempts = is_array($state['attempts'] ?? null) ? $state['attempts'] : [];
    $now = time();
    $attempts = array_values(array_filter($attempts, static fn($time): bool => is_int($time) && $time > $now - 900));
    $lockout = count($attempts) >= 8 ? max(0, 900 - ($now - $attempts[0])) : 0;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode(['attempts' => $attempts]));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $lockout;
}

function recordLoginFailure(): void {
    $file = loginRateLimitFile();
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        return;
    }
    flock($handle, LOCK_EX);
    $contents = stream_get_contents($handle);
    $state = json_decode($contents ?: '{}', true);
    $now = time();
    $attempts = is_array($state['attempts'] ?? null) ? $state['attempts'] : [];
    $attempts = array_values(array_filter($attempts, static fn($time): bool => is_int($time) && $time > $now - 900));
    $attempts[] = $now;
    if (count($attempts) > 100) {
        $attempts = array_slice($attempts, -100);
    }
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode(['attempts' => $attempts]));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function clearLoginFailures(): void {
    $file = loginRateLimitFile();
    if (is_file($file)) {
        $handle = fopen($file, 'c+');
        if ($handle === false) return;
        flock($handle, LOCK_EX);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode(['attempts' => []]));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function failConfigLoad(): void {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'media/config.json fehlt, ist leer oder beschädigt. Bitte aus einem Backup wiederherstellen.']);
    exit;
}

function loadConfig() {
    global $configFile;

    $raw = is_file($configFile) ? file_get_contents($configFile) : false;
    if ($raw === false || trim($raw) === '') {
        failConfigLoad();
    }

    $json = json_decode($raw, true);
    if (!is_array($json)
        || !isset($json['teams']) || !is_array($json['teams'])
        || !isset($json['admin']['username'], $json['admin']['password'])
        || !is_string($json['admin']['username']) || !is_string($json['admin']['password'])
        || $json['admin']['username'] === '' || $json['admin']['password'] === '') {
        failConfigLoad();
    }

    $appName = trim((string)($json['app_name'] ?? 'Eishockey Soundbox'));
    $json['app_name'] = $appName !== '' ? $appName : 'Eishockey Soundbox';

    foreach ($json['teams'] as &$team) {
        if (!isset($team['password'])) {
            $team['password'] = '';
        }
    }
    unset($team);

    return $json;
}

ensureDir($baseDir);
ensureDir($baseDir . '/allgemein/musik');
ensureDir($baseDir . '/teams');

if ($action === 'initialize_setup') {
    if (!verifyCsrfToken()) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Sitzung abgelaufen. Bitte Einrichtung neu laden.']);
        exit;
    }

    $setupLockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'soundbox-setup-' . hash('sha256', __DIR__) . '.lock';
    $setupLock = fopen($setupLockPath, 'c');
    if ($setupLock === false || !flock($setupLock, LOCK_EX | LOCK_NB)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Die Ersteinrichtung wird bereits ausgeführt.']);
        exit;
    }

    if (file_exists($configFile)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Die Soundbox ist bereits eingerichtet.']);
        exit;
    }

    $adminUsername = trim((string)($_POST['admin_username'] ?? ''));
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $folderName = trim((string)($_POST['folder_name'] ?? ''));
    $clubName = trim((string)($_POST['club_name'] ?? ''));

    if (!preg_match('/\A[a-zA-Z0-9_.-]{3,50}\z/', $adminUsername)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Admin-Benutzername: 3–50 Zeichen, Buchstaben, Zahlen, Punkt, Bindestrich oder Unterstrich.']);
        exit;
    }
    $passwordLength = preg_match_all('/./us', $adminPassword, $passwordCharacters);
    if ($passwordLength === false || $passwordLength < 12 || strlen($adminPassword) > 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Admin-Passwort muss mindestens 12 Zeichen lang sein.']);
        exit;
    }
    if (!preg_match('/\A[a-zA-Z0-9_-]{2,48}\z/', $folderName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ordnername: 2–48 Zeichen, nur Buchstaben, Zahlen, Bindestrich oder Unterstrich.']);
        exit;
    }
    if ($clubName === '' || strlen($clubName) > 320 || preg_match('/[\x00-\x1F\x7F]/', $clubName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Vereinsname ist erforderlich und darf höchstens 80 Zeichen lang sein.']);
        exit;
    }

    $logo = $_FILES['club_logo'] ?? null;
    if (!is_array($logo)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bitte ein Vereinslogo im PNG- oder JPG-Format auswählen.']);
        exit;
    }
    $logoValidation = validateUploadedFile($logo, ['png', 'jpg', 'jpeg'], ['image/png', 'image/jpeg'], 5 * 1024 * 1024);
    if (!$logoValidation['valid']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $logoValidation['message']]);
        exit;
    }

    $teamDir = $baseDir . '/teams/' . $folderName;
    $directories = [
        $baseDir . '/allgemein/musik',
        $baseDir . '/teams',
        $teamDir . '/musik',
        $teamDir . '/effekte'
    ];
    foreach ($directories as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Ordnerstruktur konnte nicht angelegt werden. Schreibrechte für media prüfen.']);
            exit;
        }
    }

    $mediaAccessRules = "Options -Indexes\nRequire all denied\n";
    $mediaAccessFile = $baseDir . '/.htaccess';
    if (!is_file($mediaAccessFile) || !str_contains((string)file_get_contents($mediaAccessFile), 'Require all denied')) {
        if (file_put_contents($mediaAccessFile, $mediaAccessRules, LOCK_EX) === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Medien-Zugriffsschutz konnte nicht eingerichtet werden.']);
            exit;
        }
    }

    $config = [
        'app_name' => $clubName,
        'admin' => ['username' => $adminUsername, 'password' => hashPassword($adminPassword)],
        'teams' => [[
            'id' => $folderName,
            'name' => $clubName,
            'password' => '',
            'password_set' => false
        ]]
    ];

    $logoExtension = $logoValidation['extension'] === 'jpeg' ? 'jpg' : $logoValidation['extension'];
    $logoPath = $baseDir . '/logo.' . $logoExtension;
    if (!move_uploaded_file($logoValidation['tmp_name'], $logoPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Vereinslogo konnte nicht gespeichert werden.']);
        exit;
    }

    $temporaryConfig = $configFile . '.setup-' . bin2hex(random_bytes(6));
    $encodedConfig = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!is_string($encodedConfig) || file_put_contents($temporaryConfig, $encodedConfig, LOCK_EX) === false || !rename($temporaryConfig, $configFile)) {
        @unlink($temporaryConfig);
        @unlink($logoPath);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Konfiguration konnte nicht gespeichert werden. Schreibrechte für media prüfen.']);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['role'] = 'admin';
    $_SESSION['admin_user'] = $adminUsername;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    echo json_encode(['success' => true, 'redirect' => 'settings.php']);
    exit;
}

if (!is_file($configFile)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Ersteinrichtung erforderlich. Bitte setup.php öffnen.']);
    exit;
}

if ($action === 'login') {
    if (!verifyCsrfToken()) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Sitzung abgelaufen. Bitte neu anmelden.']);
        exit;
    }

    $lockoutSeconds = getLoginLockoutSeconds();
    if ($lockoutSeconds > 0) {
        http_response_code(429);
        header('Retry-After: ' . $lockoutSeconds);
        echo json_encode(['success' => false, 'message' => 'Zu viele fehlgeschlagene Anmeldeversuche. Bitte später erneut versuchen.']);
        exit;
    }

    $loginIdentifier = trim((string)($_POST['login_identifier'] ?? $_POST['account'] ?? $_POST['username'] ?? $_POST['account_user'] ?? $_POST['team_id'] ?? ''));
    $password = trim((string)($_POST['password'] ?? ''));

    $config = loadConfig();
    $admin = $config['admin'];
    $adminHash = (string)($admin['password'] ?? '');
    $isAdminRoleLogin = ($_POST['login_role'] ?? '') === 'admin';
    if ($isAdminRoleLogin) {
        $loginIdentifier = (string)$admin['username'];
    }

    if ($loginIdentifier !== '' && strcasecmp((string)$loginIdentifier, (string)$admin['username']) === 0 && passwordMatches($password, $adminHash)) {
        if ($adminHash === $password || password_needs_rehash($adminHash, PASSWORD_DEFAULT)) {
            $admin['password'] = hashPassword($password);
            $config['admin'] = $admin;
            persistConfig($config);
        }

        session_regenerate_id(true);
        clearLoginFailures();
        $_SESSION['role'] = 'admin';
        $_SESSION['admin_user'] = $admin['username'];
        ensureCsrfToken();
        echo json_encode(['success' => true, 'redirect' => 'settings.php']);
        exit;
    }

    if ($isAdminRoleLogin) {
        recordLoginFailure();
        echo json_encode(['success' => false, 'message' => 'Falsches Passwort']);
        exit;
    }

    if ($loginIdentifier === '') {
        echo json_encode(['success' => false, 'message' => 'Benutzername oder Team erforderlich']);
        exit;
    }

    $selectedTeam = null;
    foreach ($config['teams'] as $team) {
        $teamId = (string)($team['id'] ?? '');
        $teamName = (string)($team['name'] ?? '');

        if (strcasecmp($teamId, $loginIdentifier) === 0 || strcasecmp($teamName, $loginIdentifier) === 0) {
            $selectedTeam = $team;
            break;
        }
    }

    if (!$selectedTeam) {
        recordLoginFailure();
        $isAdminLogin = strcasecmp((string)$loginIdentifier, (string)$admin['username']) === 0;
        echo json_encode(['success' => false, 'message' => $isAdminLogin ? 'Falsches Passwort' : 'Team nicht gefunden']);
        exit;
    }

    $storedPassword = (string)($selectedTeam['password'] ?? '');
    if (!passwordMatches($password, $storedPassword)) {
        recordLoginFailure();
        echo json_encode(['success' => false, 'message' => 'Falsches Passwort für dieses Team']);
        exit;
    }

    if ($storedPassword === $password || password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
        $selectedTeam['password'] = hashPassword($password);
        $config['teams'] = array_map(static function (array $team) use ($selectedTeam) {
            return (($team['id'] ?? '') === ($selectedTeam['id'] ?? '')) ? $selectedTeam : $team;
        }, $config['teams']);
        persistConfig($config);
    }

    session_regenerate_id(true);
    clearLoginFailures();
    $_SESSION['role'] = 'team';
    $_SESSION['team_id'] = (string)($selectedTeam['id'] ?? $loginIdentifier);
    $_SESSION['team_name'] = $selectedTeam['name'] ?? $loginIdentifier;
    ensureCsrfToken();

    echo json_encode(['success' => true, 'redirect' => 'index.php']);
    exit;
}

if ($action === 'logout') {
    session_unset();
    session_destroy();
    echo json_encode(['success' => true, 'redirect' => 'login.php']);
    exit;
}

// Teams & config.json speichern
if ($action === 'save_teams') {
    if (!verifyCsrfToken()) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Sitzung abgelaufen. Bitte erneut anmelden.']);
        exit;
    }

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Nur Admin darf Teams verwalten']);
        exit;
    }

    $teamsData = json_decode($_POST['teams_json'] ?? '[]', true);
    if (!is_array($teamsData) || count($teamsData) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Mindestens ein Team muss vorhanden sein']);
        exit;
    }

    $config = loadConfig();
    $existingTeams = [];
    foreach ($config['teams'] as $existingTeam) {
        if (is_array($existingTeam) && isset($existingTeam['id'])) {
            $existingTeams[(string)$existingTeam['id']] = $existingTeam;
        }
    }

    $seenIds = [];
    $seenNames = [];
    $idRemap = [];

    foreach ($teamsData as &$team) {
        if (!is_array($team)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ungültige Teamdaten']);
            exit;
        }

        $teamName = trim((string)($team['name'] ?? ''));
        $password = trim((string)($team['password'] ?? ''));
        $oldTeamId = trim((string)($team['id'] ?? ''));
        $existingPassword = (string)($existingTeams[$oldTeamId]['password'] ?? '');
        $isNewTeam = $oldTeamId === '' || !isset($existingTeams[$oldTeamId]);

        if ($teamName === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Teamname darf nicht leer sein.']);
            exit;
        }

        $normalizedName = function_exists('mb_strtolower')
            ? mb_strtolower($teamName, 'UTF-8')
            : strtolower($teamName);
        if (isset($seenNames[$normalizedName])) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Der Teamname „' . $teamName . '“ ist bereits vorhanden.']);
            exit;
        }
        $seenNames[$normalizedName] = true;

        $teamId = $oldTeamId;
        if ($teamId === '' || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $teamId)) {
            $baseId = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $teamName) : $teamName;
            $baseId = strtolower((string)$baseId);
            $baseId = preg_replace('/[^a-z0-9]+/', '_', $baseId);
            $baseId = trim((string)$baseId, '_-');
            if ($baseId === '') {
                $baseId = 'team';
            }
            $baseId = substr($baseId, 0, 48);
            $teamId = $baseId;
            $suffix = 2;
            while (
                isset($seenIds[$teamId]) ||
                (isset($existingTeams[$teamId]) && $teamId !== $oldTeamId) ||
                (is_dir($baseDir . '/teams/' . $teamId) && $teamId !== $oldTeamId)
            ) {
                $suffixText = '_' . $suffix++;
                $teamId = substr($baseId, 0, 64 - strlen($suffixText)) . $suffixText;
            }
            if ($oldTeamId !== '') {
                $idRemap[$oldTeamId] = $teamId;
            }
            $team['id'] = $teamId;
        }

        if (isset($seenIds[$teamId])) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Interne Team-ID doppelt. Bitte Teamnamen prüfen.']);
            exit;
        }
        $seenIds[$teamId] = true;

        if ($isNewTeam && strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Für jedes neue Team muss ein Passwort mit mindestens 8 Zeichen gesetzt werden.']);
            exit;
        }

        if ($password === '') {
            if ($existingPassword === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Für jedes neue Team muss ein Passwort mit mindestens 8 Zeichen gesetzt werden']);
                exit;
            }
            $team['password'] = $existingPassword;
        } else {
            if (strlen($password) < 8) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Team-Passwörter müssen mindestens 8 Zeichen lang sein']);
                exit;
            }
            $team['password'] = hashPassword($password);
        }

    }
    unset($team);

    foreach ($teamsData as &$team) {
        $oldTeamId = (string)($team['id'] ?? '');
        foreach ($idRemap as $previousId => $newId) {
            if ($oldTeamId !== $newId) {
                continue;
            }

            $oldDirectory = $baseDir . '/teams/' . $previousId;
            $newDirectory = $baseDir . '/teams/' . $newId;
            if (is_dir($oldDirectory)) {
                if (!is_dir($newDirectory)) {
                    if (!rename($oldDirectory, $newDirectory)) {
                        http_response_code(500);
                        echo json_encode(['success' => false, 'message' => 'Team-Ordner konnte nicht sicher umbenannt werden.']);
                        exit;
                    }
                } else {
                    foreach (['musik', 'effekte'] as $subDirectory) {
                        $oldSubDirectory = $oldDirectory . '/' . $subDirectory;
                        $newSubDirectory = $newDirectory . '/' . $subDirectory;
                        if (!is_dir($oldSubDirectory)) {
                            continue;
                        }
                        ensureDir($newSubDirectory);
                        foreach (scandir($oldSubDirectory) ?: [] as $fileName) {
                            if ($fileName === '.' || $fileName === '..') {
                                continue;
                            }
                            $oldPath = $oldSubDirectory . '/' . $fileName;
                            $newPath = $newSubDirectory . '/' . $fileName;
                            if (is_file($newPath) || !rename($oldPath, $newPath)) {
                                http_response_code(409);
                                echo json_encode(['success' => false, 'message' => 'Dateikollision beim automatischen Ordnerwechsel. Bitte Dateien umbenennen und erneut speichern.']);
                                exit;
                            }
                        }
                        @rmdir($oldSubDirectory);
                    }
                    @rmdir($oldDirectory);
                }
            }
            break;
        }
    }
    unset($team);

    foreach ($teamsData as $team) {
        $teamDir = $baseDir . '/teams/' . (string)$team['id'];
        ensureDir($teamDir . '/musik');
        ensureDir($teamDir . '/effekte');
    }

    $config['teams'] = $teamsData;
    if (!persistConfig($config)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Teams konnten nicht gespeichert werden. Schreibrechte für media/config.json prüfen.']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'save_admin') {
    if (!verifyCsrfToken()) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Sitzung abgelaufen. Bitte erneut anmelden.']);
        exit;
    }

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Nur Admin darf Admin-Zugang ändern']);
        exit;
    }

    $config = loadConfig();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = trim((string)($_POST['password'] ?? ''));
    if (!preg_match('/\A[a-zA-Z0-9_.-]{3,50}\z/', $username)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Admin-Benutzername: 3–50 Zeichen, Buchstaben, Zahlen, Punkt, Bindestrich oder Unterstrich.']);
        exit;
    }
    $passwordLength = preg_match_all('/./us', $password);
    if ($passwordLength === false || $passwordLength < 12 || strlen($password) > 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Admin-Passwort muss mindestens 12 Zeichen lang sein.']);
        exit;
    }

    $config['admin'] = [
        'username' => $username,
        'password' => hashPassword($password)
    ];
    if (!persistConfig($config)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Admin-Zugang konnte nicht gespeichert werden. Schreibrechte für media/config.json prüfen.']);
        exit;
    }
    $_SESSION['admin_user'] = $username;
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'save_app_settings') {
    if (!verifyCsrfToken()) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Sitzung abgelaufen. Bitte erneut anmelden.']);
        exit;
    }

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Nur Admin darf die Soundbox konfigurieren']);
        exit;
    }

    $config = loadConfig();
    $appName = trim((string)($_POST['app_name'] ?? ''));
    if ($appName === '') {
        echo json_encode(['success' => false, 'message' => 'Name darf nicht leer sein']);
        exit;
    }

    $config['app_name'] = $appName;
    if (!persistConfig($config)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Konfiguration konnte nicht gespeichert werden. Bitte Schreibrechte für media/config.json prüfen.']);
        exit;
    }
    echo json_encode(['success' => true, 'app_name' => $appName]);
    exit;
}

// Logo speichern
if ($action === 'upload_logo') {
    if (!verifyCsrfToken()) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Sitzung abgelaufen. Bitte erneut anmelden.']);
        exit;
    }

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Nur Admin darf das Logo ändern']);
        exit;
    }

    if (isset($_FILES['logo'])) {
        $validation = validateUploadedFile(
            $_FILES['logo'],
            ['png', 'jpg', 'jpeg'],
            ['image/png', 'image/jpeg'],
            5 * 1024 * 1024
        );

        if (!$validation['valid']) {
            echo json_encode(['success' => false, 'message' => $validation['message']]);
            exit;
        }

        $target = $baseDir . '/logo.png';
        if (move_uploaded_file($validation['tmp_name'], $target)) {
            echo json_encode(['success' => true, 'timestamp' => time()]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Logo-Upload fehlgeschlagen']);
    exit;
}

// Musik hochladen
if ($action === 'upload_music') {
    if (!verifyCsrfToken()) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Sitzung abgelaufen. Bitte erneut anmelden.']);
        exit;
    }

    $role = $_SESSION['role'] ?? '';
    $teamId = trim((string)($_POST['team_id'] ?? ''));
    $target = trim((string)($_POST['target'] ?? ''));

    if (!in_array($role, ['admin', 'team'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Nicht angemeldet']);
        exit;
    }

    if ($role === 'team') {
        $sessionTeamId = trim((string)($_SESSION['team_id'] ?? ''));
        if ($target !== 'team' || $sessionTeamId === '' || ($teamId !== '' && !hash_equals($sessionTeamId, $teamId))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Teams dürfen nur ihren eigenen Musikordner verwenden']);
            exit;
        }
        $teamId = $sessionTeamId;
    }

    if (!in_array($target, ['allgemein', 'team'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungültiger Upload-Zielordner']);
        exit;
    }

    if ($role === 'admin' && $target === 'allgemein') {
        $teamId = '';
    } elseif (!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $teamId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungültige Team-ID']);
        exit;
    }

    $config = loadConfig();
    if ($target === 'team') {
        $teamExists = false;
        foreach ($config['teams'] as $team) {
            if (is_array($team) && (string)($team['id'] ?? '') === $teamId) {
                $teamExists = true;
                break;
            }
        }
        if (!$teamExists) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Team existiert nicht']);
            exit;
        }
    }

    $targetDir = $target === 'allgemein'
        ? $baseDir . '/allgemein/musik'
        : $baseDir . '/teams/' . $teamId . '/musik';

    ensureDir($targetDir);
    if (realpath($targetDir) === false || !str_starts_with(realpath($targetDir), realpath($baseDir) . DIRECTORY_SEPARATOR)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungültiger Zielpfad']);
        exit;
    }

    if (isset($_FILES['music_files'])) {
        $files = $_FILES['music_files'];
        if (!is_array($files['tmp_name']) || count($files['tmp_name']) < 1 || count($files['tmp_name']) > 20) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Bitte 1 bis 20 Musikdateien gleichzeitig auswählen']);
            exit;
        }
        $validFiles = [];

        foreach ($files['tmp_name'] as $idx => $tmpName) {
            $validation = validateUploadedFile(
                ['error' => $files['error'][$idx], 'tmp_name' => $tmpName, 'name' => $files['name'][$idx]],
                ['mp3', 'wav', 'ogg'],
                ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/x-wav'],
                25 * 1024 * 1024
            );

            if (!$validation['valid']) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $validation['message']]);
                exit;
            }
            if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9 _.-]{0,175}\.' . preg_quote($validation['extension'], '/') . '\z/i', $validation['name'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Dateiname enthält unzulässige Zeichen']);
                exit;
            }

            $validFiles[] = $validation;
        }

        foreach ($validFiles as $file) {
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $file['name'];
            if (file_exists($targetPath) || !move_uploaded_file($file['tmp_name'], $targetPath)) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Musikdatei konnte nicht gespeichert werden']);
                exit;
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }
}

if ($action === 'delete_music') {
    if (!verifyCsrfToken()) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Sitzung abgelaufen. Bitte erneut anmelden.']);
        exit;
    }

    $role = $_SESSION['role'] ?? '';
    $teamId = trim((string)($_POST['team_id'] ?? ''));
    $target = trim((string)($_POST['target'] ?? ''));
    $fileName = basename((string)($_POST['file_name'] ?? ''));

    if (!in_array($role, ['admin', 'team'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Nicht angemeldet']);
        exit;
    }

    if ($role === 'team') {
        $sessionTeamId = trim((string)($_SESSION['team_id'] ?? ''));
        if ($target !== 'team' || $sessionTeamId === '' || ($teamId !== '' && !hash_equals($sessionTeamId, $teamId))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Teams dürfen nur eigene Dateien löschen']);
            exit;
        }
        $teamId = $sessionTeamId;
    }

    if (!in_array($target, ['allgemein', 'team'], true) || $fileName === '' || $fileName !== preg_replace('/[^A-Za-z0-9_\.\-]/', '', $fileName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungültiger Dateiname oder Zielordner']);
        exit;
    }

    if ($target === 'team') {
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $teamId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ungültige Team-ID']);
            exit;
        }
        $configuredIds = array_map(static fn(array $team): string => (string)($team['id'] ?? ''), loadConfig()['teams']);
        if (!in_array($teamId, $configuredIds, true)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Team existiert nicht']);
            exit;
        }
    }

    if (!in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), ['mp3', 'wav', 'ogg'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nicht unterstütztes Dateiformat']);
        exit;
    }

    if ($target === 'team' && $teamId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Team erforderlich']);
        exit;
    }

    $targetDir = $target === 'allgemein'
        ? $baseDir . '/allgemein/musik'
        : $baseDir . '/teams/' . $teamId . '/musik';

    $filePath = $targetDir . '/' . $fileName;
    if (!is_file($filePath) || realpath(dirname($filePath)) !== realpath($targetDir)) {
        echo json_encode(['success' => false, 'message' => 'Datei nicht gefunden']);
        exit;
    }

    if (!unlink($filePath)) {
        echo json_encode(['success' => false, 'message' => 'Datei konnte nicht gelöscht werden']);
        exit;
    }

    echo json_encode(['success' => true]);
    exit;
}

// Effekt & Button-Namen speichern
if ($action === 'upload_effect') {
    if (!verifyCsrfToken()) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Sitzung abgelaufen. Bitte erneut anmelden.']);
        exit;
    }

    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, ['admin', 'team'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Nicht angemeldet']);
        exit;
    }

    $teamId = trim((string)($_POST['team_id'] ?? ''));

    if ($role === 'team') {
        $teamId = trim((string)($_SESSION['team_id'] ?? ''));
        if ($teamId === '') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Kein Team zugeordnet']);
            exit;
        }
        if (isset($_POST['team_id']) && trim((string)$_POST['team_id']) !== '' && trim((string)$_POST['team_id']) !== $teamId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Teams dürfen nur ihre eigenen Effekte verändern']);
            exit;
        }
    }

    $config = loadConfig();
    $teamExists = false;
    foreach ($config['teams'] as $team) {
        if (is_array($team) && (string)($team['id'] ?? '') === $teamId) {
            $teamExists = true;
            break;
        }
    }

    $slot = (int)($_POST['slot'] ?? 0);
    $customName = trim($_POST['custom_name'] ?? '');

    if (!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $teamId) || !$teamExists || $slot < 1 || $slot > 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungültige Mannschaft oder Parameter']);
        exit;
    }

    $effectsDir = $baseDir . '/teams/' . $teamId . '/effekte';
    ensureDir($effectsDir);

    $existingFiles = glob($effectsDir . '/' . sprintf('%02d', $slot) . '_*');
    $oldFile = count($existingFiles) > 0 ? $existingFiles[0] : null;

    $cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $customName);

    if (isset($_FILES['effect_file'])) {
        $validation = validateUploadedFile(
            $_FILES['effect_file'],
            ['mp3', 'wav', 'ogg'],
            ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/x-wav'],
            25 * 1024 * 1024
        );

        if (!$validation['valid']) {
            echo json_encode(['success' => false, 'message' => $validation['message']]);
            exit;
        }

        $ext = $validation['extension'];
        $filename = sprintf('%02d', $slot) . '_' . $cleanName . '.' . $ext;
        $targetPath = $effectsDir . '/' . $filename;
        if (move_uploaded_file($validation['tmp_name'], $targetPath)) {
            if ($oldFile && file_exists($oldFile) && realpath($oldFile) !== realpath($targetPath)) {
                unlink($oldFile);
            }
            echo json_encode(['success' => true]);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Effektdatei konnte nicht gespeichert werden']);
        exit;
    }
    else if ($oldFile && file_exists($oldFile)) {
        $ext = pathinfo($oldFile, PATHINFO_EXTENSION);
        $newFilename = sprintf('%02d', $slot) . '_' . $cleanName . '.' . $ext;
        if (!rename($oldFile, $effectsDir . '/' . $newFilename)) {
            echo json_encode(['success' => false, 'message' => 'Effekt konnte nicht umbenannt werden']);
            exit;
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Keine Sound-Datei vorhanden']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Ungueltige Aktion']);