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

$type = (string)($_GET['type'] ?? '');
$role = (string)($_SESSION['role'] ?? '');
$teamId = (string)($_SESSION['team_id'] ?? '');

$mediaRoot = realpath(__DIR__ . '/media');
if ($mediaRoot === false) {
    http_response_code(404);
    exit;
}

if ($type === 'logo') {
    $logoPaths = [
        $mediaRoot . DIRECTORY_SEPARATOR . 'logo.png',
        $mediaRoot . DIRECTORY_SEPARATOR . 'logo.jpg'
    ];
    $path = '';
    $mime = '';
    foreach ($logoPaths as $candidate) {
        if (is_file($candidate)) {
            $candidateMime = (new finfo(FILEINFO_MIME_TYPE))->file($candidate);
            if (in_array($candidateMime, ['image/png', 'image/jpeg'], true)) {
                $path = $candidate;
                $mime = $candidateMime;
                break;
            }
        }
    }
    if ($path === '') {
        http_response_code(404);
        exit;
    }
} else {
    if (!in_array($role, ['admin', 'team'], true)) {
        http_response_code(403);
        exit;
    }

    $file = (string)($_GET['file'] ?? '');
    if ($file === '' || basename($file) !== $file || !preg_match('/\A[a-zA-Z0-9 _.-]{1,180}\z/', $file)) {
        http_response_code(404);
        exit;
    }

    $configData = json_decode((string)@file_get_contents($mediaRoot . DIRECTORY_SEPARATOR . 'config.json'), true);
    $configuredTeams = is_array($configData['teams'] ?? null) ? $configData['teams'] : [];

    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimeByExtension = [
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg'
    ];
    if (!isset($mimeByExtension[$extension])) {
        http_response_code(404);
        exit;
    }
    $mime = $mimeByExtension[$extension];

    if ($type === 'music' && ($_GET['scope'] ?? '') === 'general') {
        $path = $mediaRoot . DIRECTORY_SEPARATOR . 'allgemein' . DIRECTORY_SEPARATOR . 'musik' . DIRECTORY_SEPARATOR . $file;
    } elseif ($type === 'music' && ($_GET['scope'] ?? '') === 'team') {
        $requestedTeamId = (string)($_GET['team'] ?? '');
        $teamExists = count(array_filter($configuredTeams, static fn($team): bool => is_array($team) && (string)($team['id'] ?? '') === $requestedTeamId)) > 0;
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $requestedTeamId) || !$teamExists || ($role === 'team' && !hash_equals($teamId, $requestedTeamId))) {
            http_response_code(403);
            exit;
        }
        $path = $mediaRoot . DIRECTORY_SEPARATOR . 'teams' . DIRECTORY_SEPARATOR . $requestedTeamId . DIRECTORY_SEPARATOR . 'musik' . DIRECTORY_SEPARATOR . $file;
    } elseif ($type === 'effect') {
        $requestedTeamId = (string)($_GET['team'] ?? '');
        $teamExists = count(array_filter($configuredTeams, static fn($team): bool => is_array($team) && (string)($team['id'] ?? '') === $requestedTeamId)) > 0;
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $requestedTeamId) || !$teamExists || ($role === 'team' && !hash_equals($teamId, $requestedTeamId))) {
            http_response_code(403);
            exit;
        }
        $path = $mediaRoot . DIRECTORY_SEPARATOR . 'teams' . DIRECTORY_SEPARATOR . $requestedTeamId . DIRECTORY_SEPARATOR . 'effekte' . DIRECTORY_SEPARATOR . $file;
    } else {
        http_response_code(404);
        exit;
    }
}

$resolvedPath = realpath($path);
if ($resolvedPath === false || !is_file($resolvedPath) || ($type !== 'logo' && strpos($resolvedPath, $mediaRoot . DIRECTORY_SEPARATOR) !== 0)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($resolvedPath));
header('Content-Disposition: inline; filename="' . rawurlencode(basename($resolvedPath)) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($resolvedPath);
