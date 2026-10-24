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

$teams = [["id" => "default", "name" => "Standard"]];
$configData = [];
$adminConfig = ['username' => 'admin'];
if (file_exists($configFile)) {
    $configData = json_decode(file_get_contents($configFile), true);
    if (isset($configData['teams'])) {
        $teams = array_map(static function (array $team): array {
            $team['password_set'] = !empty($team['password']);
            unset($team['password']);
            return $team;
        }, $configData['teams']);
    }
    if (isset($configData['admin'])) {
        $adminConfig = ['username' => $configData['admin']['username'] ?? 'admin'];
    }
}

$loggedInTeamId = $_SESSION['team_id'] ?? '';
$userRole = $_SESSION['role'] ?? 'guest';
$authenticated = $userRole === 'admin' || ($userRole === 'team' && $loggedInTeamId !== '');
$appName = trim((string)($configData['app_name'] ?? 'Eishockey Soundbox')) !== ''
    ? trim((string)$configData['app_name'])
    : 'Eishockey Soundbox';
$teamId = isset($_GET['team']) ? $_GET['team'] : ($loggedInTeamId ?: ($teams[0]['id'] ?? 'default'));

if ($userRole === 'team' && $loggedInTeamId !== '') {
    $teamId = $loggedInTeamId;
}

if ($loggedInTeamId !== '' && !empty($teams)) {
    $teamExists = false;
    foreach ($teams as $team) {
        if (($team['id'] ?? '') === $loggedInTeamId) {
            $teamExists = true;
            break;
        }
    }

    if ($teamExists) {
        $teamId = $loggedInTeamId;
    }
}

$validTeamIds = array_map(static fn(array $team): string => (string)($team['id'] ?? ''), $teams);
if (!in_array($teamId, $validTeamIds, true)) {
    $teamId = (string)($teams[0]['id'] ?? 'default');
}

$teamMusicDir = $baseDir . '/teams/' . $teamId . '/musik';
$generalMusicDir = $baseDir . '/allgemein/musik';

$musicFiles = [];
$musicSource = "Keine Musik";

if (is_dir($teamMusicDir)) {
    $files = array_diff(scandir($teamMusicDir), array('.', '..'));
    foreach ($files as $f) {
        if (preg_match('/\.(mp3|wav|ogg)$/i', $f)) {
            $musicFiles[] = 'media.php?type=music&scope=team&team=' . rawurlencode($teamId) . '&file=' . rawurlencode($f);
        }
    }
    if (count($musicFiles) > 0) {
        $musicSource = "Team-Musik";
    }
}

if ($authenticated && count($musicFiles) === 0 && is_dir($generalMusicDir)) {
    $files = array_diff(scandir($generalMusicDir), array('.', '..'));
    foreach ($files as $f) {
        if (preg_match('/\.(mp3|wav|ogg)$/i', $f)) {
            $musicFiles[] = 'media.php?type=music&scope=general&file=' . rawurlencode($f);
        }
    }
    if (count($musicFiles) > 0) {
        $musicSource = "Allg. Musik";
    }
}

$effectsDir = $baseDir . '/teams/' . $teamId . '/effekte';
$effectFiles = [];

if ($authenticated && is_dir($effectsDir)) {
    $files = array_diff(scandir($effectsDir), array('.', '..'));
    sort($files);
    foreach ($files as $f) {
        if (preg_match('/\.(mp3|wav|ogg)$/i', $f)) {
            $filename = pathinfo($f, PATHINFO_FILENAME);
            $slot = (int)substr($filename, 0, 2);
            if ($slot < 1 || $slot > 6 || isset($effectFiles[$slot])) {
                continue;
            }
            $effectName = preg_replace('/\.(mp3|wav|ogg)$/i', '', preg_replace('/^\d{2}_/', '', $filename));

            $effectFiles[$slot] = [
                'slot' => $slot,
                'name' => $effectName,
                'path' => 'media.php?type=effect&team=' . rawurlencode($teamId) . '&file=' . rawurlencode($f)
            ];
        }
    }
}

$generalMusicFiles = [];
if ($authenticated && is_dir($generalMusicDir)) {
    $files = array_diff(scandir($generalMusicDir), array('.', '..'));
    sort($files);
    foreach ($files as $f) {
        if (preg_match('/\.(mp3|wav|ogg)$/i', $f)) {
            $generalMusicFiles[] = [
                'name' => $f,
                'path' => 'media.php?type=music&scope=general&file=' . rawurlencode($f)
            ];
        }
    }
}

$teamMusicFiles = [];
if ($authenticated && is_dir($teamMusicDir)) {
    $files = array_diff(scandir($teamMusicDir), array('.', '..'));
    sort($files);
    foreach ($files as $f) {
        if (preg_match('/\.(mp3|wav|ogg)$/i', $f)) {
            $teamMusicFiles[] = [
                'name' => $f,
                'path' => 'media.php?type=music&scope=team&team=' . rawurlencode($teamId) . '&file=' . rawurlencode($f)
            ];
        }
    }
}

echo json_encode([
    'role' => $userRole,
    'app_name' => $appName,
    'teams' => $teams,
    'admin' => $userRole === 'admin' ? $adminConfig : null,
    'current_team' => $teamId,
    'logged_in' => $loggedInTeamId !== '' || $userRole === 'admin',
    'authenticated_team' => $loggedInTeamId,
    'team_name' => $_SESSION['team_name'] ?? '',
    'music_source' => $authenticated ? $musicSource : 'Keine Musik',
    'songs' => $authenticated ? $musicFiles : [],
    'general_music' => $authenticated ? $generalMusicFiles : [],
    'team_music' => $authenticated ? $teamMusicFiles : [],
    'effects' => $authenticated ? array_values($effectFiles) : []
]);