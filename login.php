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
$configPath = __DIR__ . '/media/config.json';
if (!is_file($configPath)) {
    header('Location: setup.php');
    exit;
}
if (isset($_SESSION['role']) && $_SESSION['role'] === 'team' && isset($_SESSION['team_id']) && trim((string)$_SESSION['team_id']) !== '') {
    header('Location: index.php');
    exit;
}
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header('Location: settings.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$teams = [['id' => 'default', 'name' => 'Standard', 'password' => '']];
if (file_exists($configPath)) {
    $configData = json_decode(file_get_contents($configPath), true);
    if (is_array($configData) && isset($configData['teams']) && is_array($configData['teams'])) {
        $teams = $configData['teams'];
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Soundbox Login</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-shell">
    <main class="login-panel" aria-labelledby="login-title">
        <img class="login-logo" src="media.php?type=logo" alt="Logo" onerror="this.style.display='none'; this.parentElement.querySelector('.login-logo-fallback').style.display='grid';">
        <div class="login-logo-fallback" aria-hidden="true" style="display:none;">S</div>
        <p class="page-kicker" id="login-app-name">Eishockey Soundbox</p>
        <h1 id="login-title">Anmeldung</h1>
        <form id="login-form">
            <label for="account-select">Anmelden als</label>
            <select id="account-select" name="account_select" required>
                <option value="admin">Admin</option>
                <?php foreach ($teams as $team): ?>
                    <option value="<?= htmlspecialchars((string)($team['id'] ?? '')) ?>"><?= htmlspecialchars((string)($team['name'] ?? 'Unbekannt')) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="password">Passwort</label>
            <input type="password" id="password" name="password" placeholder="Passwort" required>

            <button type="submit" class="btn">Anmelden</button>
        </form>
        <div id="status" class="status" role="status" aria-live="polite"></div>
        <p class="login-hint">Bitte wähle dein Team oder den Admin-Bereich.</p>
    </main>

    <script>
        const csrfToken = <?= json_encode($_SESSION['csrf_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        document.addEventListener('DOMContentLoaded', async () => {
            const data = await fetch('get_data.php').then(response => response.json());
            const appName = data.app_name || 'Eishockey Soundbox';
            document.title = appName + ' Login';
            document.getElementById('login-app-name').textContent = appName;
        });

        document.getElementById('login-form').addEventListener('submit', async function (event) {
            event.preventDefault();

            const accountSelect = document.getElementById('account-select');
            const password = document.getElementById('password').value;
            const status = document.getElementById('status');

            const formData = new FormData();
            formData.append('action', 'login');
            formData.append('csrf_token', csrfToken);
            formData.append('account', accountSelect.value);
            formData.append('password', password);

            status.textContent = 'Anmeldung läuft...';

            const response = await fetch('save_settings.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                window.location.href = data.redirect || 'index.php';
            } else {
                status.textContent = data.message || 'Anmeldung fehlgeschlagen.';
            }
        });
    </script>
</body>
</html>
