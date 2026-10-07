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
if (is_file($configPath)) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Soundbox – Ersteinrichtung</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-shell">
    <main class="login-panel" aria-labelledby="setup-title">
        <div class="login-logo-fallback" aria-hidden="true">S</div>
        <p class="page-kicker">Willkommen</p>
        <h1 id="setup-title">Soundbox einrichten</h1>
        <p class="text-muted" style="margin: 12px 0 22px;">Lege das Admin-Konto und die Vereinsdaten für diese Installation fest.</p>

        <form id="setup-form" enctype="multipart/form-data">
            <label for="admin-username">Admin-Benutzername</label>
            <input type="text" id="admin-username" name="admin_username" minlength="3" maxlength="50" autocomplete="username" required>

            <label for="admin-password">Admin-Passwort</label>
            <input type="password" id="admin-password" name="admin_password" minlength="12" autocomplete="new-password" required>
            <small>Mindestens 12 Zeichen verwenden.</small>

            <label for="folder-name" style="margin-top:16px;">Ordnername für das erste Team</label>
            <input type="text" id="folder-name" name="folder_name" placeholder="z. B. ehc_heim" pattern="[a-zA-Z0-9_-]{2,48}" maxlength="48" required>
            <small>Nur Buchstaben, Zahlen, Bindestrich und Unterstrich; wird als Team-ID und Ordnername genutzt.</small>

            <label for="club-name" style="margin-top:16px;">Vereinsname</label>
            <input type="text" id="club-name" name="club_name" maxlength="80" placeholder="z. B. Eishockey Soundbox" required>

            <label for="club-logo" style="margin-top:16px;">Vereinslogo (PNG oder JPG)</label>
            <input type="file" id="club-logo" name="club_logo" accept="image/png,image/jpeg" required>

            <button type="submit" class="btn" style="width:100%;margin-top:22px;">Einrichtung abschließen</button>
        </form>
        <div id="setup-status" class="status" role="status" aria-live="polite"></div>
    </main>

    <script>
        const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const form = document.getElementById('setup-form');
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const status = document.getElementById('setup-status');
            const password = document.getElementById('admin-password').value;
            if (password.length < 12) {
                status.textContent = 'Das Admin-Passwort muss mindestens 12 Zeichen lang sein.';
                return;
            }
            const data = new FormData(form);
            data.append('action', 'initialize_setup');
            data.append('csrf_token', csrfToken);
            status.textContent = 'Einrichtung wird erstellt …';
            try {
                const response = await fetch('save_settings.php', { method: 'POST', body: data });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    status.textContent = result.message || 'Einrichtung fehlgeschlagen.';
                    return;
                }
                status.textContent = 'Einrichtung abgeschlossen. Weiterleitung …';
                window.location.href = result.redirect || 'settings.php';
            } catch (error) {
                status.textContent = 'Server nicht erreichbar. Bitte prüfe die XAMPP- und PHP-Upload-Einstellungen.';
            }
        });
    </script>
</body>
</html>
