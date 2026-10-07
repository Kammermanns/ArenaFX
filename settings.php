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
if (!is_file(__DIR__ . '/media/config.json')) {
    header('Location: setup.php');
    exit;
}
$role = $_SESSION['role'] ?? '';
if ($role !== 'admin' && $role !== 'team') {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

    $teamId = $_SESSION['team_id'] ?? '';
    $teamName = $_SESSION['team_name'] ?? 'Team';
    $configPath = __DIR__ . '/media/config.json';
    $appName = 'Eishockey Soundbox';
    if (is_file($configPath)) {
        $config = json_decode((string)file_get_contents($configPath), true);
        $configuredName = trim((string)($config['app_name'] ?? ''));
        if ($configuredName !== '') {
            $appName = $configuredName;
        }
    }
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName) ?> â€“ Einstellungen</title>
    <link rel="stylesheet" href="assets/style.css?v=20261007093738">
</head>
<body class="<?= $role === 'admin' ? 'admin-layout' : 'team-layout' ?>">
<div class="app-shell settings-page">
    <header class="settings-header panel">
        <div>
            <p class="page-kicker"><?php echo $role === 'admin' ? 'Administration' : 'Team Bereich'; ?></p>
            <h1 class="page-title" style="font-size: clamp(1.7rem, 5vw, 2.5rem); margin-top: 6px;">
                <?php if ($role === 'admin'): ?><svg class="settings-title-icon" aria-hidden="true" viewBox="0 0 28 28" focusable="false"><path d="M4 6h20M4 14h20M4 22h20"/><circle cx="10" cy="6" r="2.5"/><circle cx="18" cy="14" r="2.5"/><circle cx="8" cy="22" r="2.5"/></svg><?php endif; ?>
                <?php echo $role === 'admin' ? 'Admin-Bereich' : htmlspecialchars($teamName); ?>
            </h1>
        </div>
        <div class="settings-header-actions">
            <a href="index.php" class="btn btn-secondary"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="m14 5-7 7 7 7M7 12h14"/></svg> Zurück zur Soundbox</a>
            <a href="logout.php" class="btn btn-danger"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M10 5H5v14h5M14 8l4 4-4 4M8 12h10"/></svg> Abmelden</a>
        </div>
    </header>

    <?php if ($role === 'admin'): ?>
        <section class="admin-overview" aria-label="Ãœbersicht">
            <div class="admin-welcome">
                <div>
                    <p class="page-kicker">Ãœbersicht</p>
                    <h2>Willkommen im Adminbereich</h2>
                    <p class="text-muted">Verwalte Vereinsdaten, Teams und Sounddateien an einem Ort.</p>
                </div>
            </div>
            <div class="admin-stats">
                <article class="admin-stat-card">
                    <span class="admin-stat-icon" aria-hidden="true"><svg class="ui-icon" viewBox="0 0 24 24"><path d="m12 3 8 3v5c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-3Z"/><path d="M12 7v10"/></svg></span>
                    <div><span>Verein</span><strong id="stat-club-name">Soundbox</strong></div>
                </article>
                <article class="admin-stat-card">
                    <span class="admin-stat-icon" aria-hidden="true"><svg class="ui-icon" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg></span>
                    <div><span>Teams</span><strong id="stat-password-count">â€“</strong></div>
                </article>
                <article class="admin-stat-card">
                    <span class="admin-stat-icon" aria-hidden="true"><svg class="ui-icon" viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></span>
                    <div><strong id="stat-music-count">â€“</strong><span>Musikdateien</span></div>
                </article>
            </div>
        </section>

        <nav class="admin-nav" role="tablist" aria-label="Adminbereiche">
            <button type="button" role="tab" aria-selected="true" aria-controls="admin-section-general" tabindex="0" class="admin-nav-item active" data-admin-section="general"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.7a8 8 0 0 1-1.7 1l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.7-1l-1.7.7-1.4-2.4L7.3 15a8 8 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.7a8 8 0 0 1 1.7-1l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.7 1l1.7-.7 1.4 2.4-1.4 1.1a8 8 0 0 1-.1 2Z"/></svg> Allgemein</button>
            <button type="button" role="tab" aria-selected="false" aria-controls="admin-section-teams" tabindex="-1" class="admin-nav-item" data-admin-section="teams"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM16 4.2a3.5 3.5 0 0 1 0 6.6M17 15h1a3 3 0 0 1 3 3v2"/></svg> Teams verwalten</button>
            <button type="button" role="tab" aria-selected="false" aria-controls="admin-section-music" tabindex="-1" class="admin-nav-item" data-admin-section="music"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M13 4v12.5a3.5 3.5 0 1 1-2-3.2V7l9-2v9.5a3.5 3.5 0 1 1-2-3.2V3l-5 1Z"/></svg> Musik verwalten</button>
            <button type="button" role="tab" aria-selected="false" aria-controls="admin-section-effects" tabindex="-1" class="admin-nav-item" data-admin-section="effects"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="m13 2-9 12h7l-1 8 10-13h-7l1-7Z"/></svg> Effekte verwalten</button>
            <button type="button" role="tab" aria-selected="false" aria-controls="admin-section-backup" tabindex="-1" class="admin-nav-item" data-admin-section="backup"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v7c0 1.7 3.6 3 8 3s8-1.3 8-3v-7"/></svg> Backup und Wiederherstellung</button>
        </nav>

        <section class="admin-section active" role="tabpanel" id="admin-section-general" aria-label="Allgemeine Einstellungen" tabindex="0">
            <div class="section-heading">
                <div><h2>Allgemeine Einstellungen</h2><p>Name, Admin-Zugang und Vereinslogo verwalten.</p></div>
            </div>
            <div class="settings-grid general-settings-grid">
        <div class="card general-settings-card">
            <h2>Soundbox-Name</h2>
            <label>Bezeichnung</label>
            <input type="text" id="app-name" placeholder="Eishockey Soundbox">
            <button type="button" class="btn" onclick="saveAppSettings()"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M5 4h12l3 3v13H4V4h1ZM8 4v6h8V4M8 20v-7h8v7"/></svg> Name speichern</button>
        </div>

        <div class="card general-settings-card">
            <h2>Admin-Zugang</h2>
            <label>Benutzername</label>
            <input type="text" id="admin-username" placeholder="admin">
            <label>Passwort</label>
            <input type="password" id="admin-password" placeholder="Passwort">
            <button type="button" class="btn" onclick="saveAdminSettings()"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M5 4h12l3 3v13H4V4h1ZM8 4v6h8V4M8 20v-7h8v7"/></svg> Admin-Zugang speichern</button>
        </div>

        <div class="card general-settings-card">
            <h2>Vereinslogo</h2>
            <form id="form-logo" enctype="multipart/form-data">
                <label>Bilddatei wählen (PNG/JPG):</label>
                <input type="file" name="logo" accept="image/png, image/jpeg">
                <div class="general-logo-preview">
                    <img id="logo-preview-img" src="media.php?type=logo" class="logo-preview" onerror="this.style.display='none'">
                </div>
                <button type="button" class="btn" onclick="uploadLogo()"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M12 16V4m0 0L7 9m5-5 5 5M4 15v5h16v-5"/></svg> Logo speichern</button>
            </form>
        </div>
            </div>
            <div class="admin-feedback" id="feedback-general" role="status" aria-live="polite" hidden></div>
        </section>

        <section class="admin-section" role="tabpanel" id="admin-section-teams" aria-label="Teams verwalten" tabindex="0">
            <div class="section-heading"><div><h2>Teams verwalten</h2><p>Teamnamen und Passwörter bearbeiten.</p></div></div>
        <div class="card">
            <h2>Mannschaften</h2>
            <div id="teams-list"></div>
            <div class="card-actions">
                <button type="button" class="btn btn-secondary" onclick="addTeamField()"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Team hinzufügen</button>
                <button type="button" class="btn" onclick="saveTeams()"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M5 4h12l3 3v13H4V4h1ZM8 4v6h8V4M8 20v-7h8v7"/></svg> Änderungen speichern</button>
            </div>
        </div>
            <div class="admin-feedback" id="feedback-teams" role="status" aria-live="polite" hidden></div>
        </section>

        <section class="admin-section" role="tabpanel" id="admin-section-music" aria-label="Musik verwalten" tabindex="0">
            <div class="section-heading"><div><h2>Musik verwalten</h2><p>Allgemeine Fallback-Musik und teambezogene Titel verwalten.</p></div></div>
        <div class="card">
            <h2>Musikbibliothek</h2>
            <form id="form-music">
                <label>Zielordner:</label>
                <select id="music-target" name="target" onchange="toggleTeamSelect(); loadMusicFiles();">
                    <option value="allgemein">Allgemeine Musik (Fallback für alle)</option>
                    <option value="team">Team-Spezifische Musik</option>
                </select>
                <div id="team-select-box" style="display:none;">
                    <label>Mannschaft wählen:</label>
                    <select id="music-team-id" name="team_id" onchange="loadMusicFiles()"></select>
                </div>
                <label>MP3/WAV-Dateien auswählen:</label>
                <input type="file" name="music_files[]" accept="audio/*" multiple>
                <button type="button" class="btn music-upload-button" onclick="uploadMusic()"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M12 16V4m0 0L7 9m5-5 5 5M4 15v5h16v-5"/></svg> Musik hochladen</button>
            </form>
            <div id="music-file-list" style="margin-top: 18px;"></div>
        </div>
            <div class="admin-feedback" id="feedback-music" role="status" aria-live="polite" hidden></div>
        </section>

        <section class="admin-section" role="tabpanel" id="admin-section-effects" aria-label="Effekte verwalten" tabindex="0">
            <div class="section-heading"><div><h2>Effekte verwalten</h2><p>Effekt-Slots für ein ausgewähltes Team konfigurieren.</p></div></div>
        <div class="card">
            <h2>Effekt-Buttons</h2>
            <label class="effect-team-label" for="effect-team-select">Mannschaft auswählen:</label>
            <select id="effect-team-select" onchange="loadEffectSlots()"></select>
            <div class="effects-builder" id="effects-builder"></div>
        </div>
            <div class="admin-feedback" id="feedback-effects" role="status" aria-live="polite" hidden></div>
        </section>

        <section class="admin-section" role="tabpanel" id="admin-section-backup" aria-label="Backup und Wiederherstellung" tabindex="0">
            <div class="section-heading"><div><h2>Backup und Wiederherstellung</h2><p>Eine Sicherung enthält alle Einstellungen, Passwort-Hashes und Mediendateien.</p></div></div>
        <div class="card">
            <h2>Sicherungen</h2>
            <p>Das Backup enthält Konfiguration, Logo, Musik und Effekte. Bewahre es geschützt auf, da es Passwort-Hashes und alle Mediendateien enthält.</p>
            <a class="btn backup-download-button" href="backup.php"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M12 4v12m0 0 5-5m-5 5-5-5M4 19h16"/></svg> Backup herunterladen</a>
            <form id="restore-form" style="margin-top: 16px;">
                <label for="backup-file">Backup-Datei (.tar.gz) auswählen</label>
                <input type="file" id="backup-file" name="backup_file" accept=".gz,.tar.gz,application/gzip" class="backup-file-input" required>
                <label style="display:flex;align-items:center;gap:8px;margin:12px 0;">
                    <input type="checkbox" id="restore-confirm" required style="width:auto;">
                    Aktuelle Konfiguration und Medien ersetzen
                </label>
                <button type="button" class="btn btn-danger" onclick="restoreBackup()"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M4 11a8 8 0 1 1 2 6M4 5v6h6"/></svg> Backup wiederherstellen</button>
            </form>
        </div>
            <div class="admin-feedback" id="feedback-backup" role="status" aria-live="polite" hidden></div>
        </section>
    <?php else: ?>
        <div class="card">
            <h2>Team: <?php echo htmlspecialchars($teamName); ?></h2>
            <p style="margin-bottom: 15px; color:#ccc;">Du kannst hier nur das eigene Team verwalten.</p>
        </div>

        <div class="card">
            <h2>1. Musik hochladen</h2>
            <form id="form-music-team">
                <input type="hidden" name="team_id" value="<?php echo htmlspecialchars($teamId); ?>">
                <input type="hidden" name="target" value="team">
                <label>MP3/WAV Dateien auswählen:</label>
                <br>
                <input type="file" name="music_files[]" accept="audio/*" multiple>
                <br>
                <button type="button" class="btn" onclick="uploadTeamMusic()"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M12 16V4m0 0L7 9m5-5 5 5M4 15v5h16v-5"/></svg> Musik hochladen</button>
            </form>
            <div id="team-music-file-list" style="margin-top: 18px;"></div>
        </div>

        <div class="card">
            <h2>2. Effekt-Buttons anpassen</h2>
            <div class="effects-builder" id="team-effects-builder"></div>
        </div>
        <div class="admin-feedback" id="feedback-team" role="status" aria-live="polite" hidden></div>
    <?php endif; ?>
</div>

<script>
    const csrfToken = <?= json_encode($_SESSION['csrf_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const csrfFormData = () => {
        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        return formData;
    };

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function audioDisplayName(fileName) {
        return String(fileName ?? '').replace(/\.(mp3|wav|ogg)$/i, '');
    }

    function audioFileNameFromPath(path) {
        try {
            return new URL(path, window.location.href).searchParams.get('file') || '';
        } catch (error) {
            return String(path ?? '').split('/').pop();
        }
    }

    let currentConfig = { team: null, teams: [], admin: { username: 'admin', password: 'admin123' }, role: '<?php echo htmlspecialchars($role); ?>' };

    window.onload = async () => {
        let res = await fetch('get_data.php');
        currentConfig = await res.json();

        if (currentConfig.role === 'team') {
            document.getElementById('team-effects-builder') && loadTeamEffectSlots(currentConfig.current_team);
            loadTeamMusicFiles();
            return;
        }

        initializeAdminNavigation();
        renderTeams();
        fillTeamDropdowns();
        loadMusicFiles();
        loadEffectSlots();
        refreshLogoPreview();
        document.getElementById('app-name').value = currentConfig.app_name || 'Eishockey Soundbox';
        document.getElementById('admin-username').value = currentConfig.admin?.username || 'admin';
        document.getElementById('admin-password').value = '';
        updateAdminOverview();
    };

    function initializeAdminNavigation() {
        const tabs = Array.from(document.querySelectorAll('[data-admin-section]'));
        const selectTab = (selectedTab, moveFocus = false) => {
            const sectionName = selectedTab.dataset.adminSection;
            tabs.forEach(tab => {
                const active = tab === selectedTab;
                tab.classList.toggle('active', active);
                tab.setAttribute('aria-selected', String(active));
                tab.tabIndex = active ? 0 : -1;
            });
            document.querySelectorAll('.admin-section').forEach(section => {
                const active = section.id === `admin-section-${sectionName}`;
                section.classList.toggle('active', active);
                section.hidden = !active;
            });
            if (moveFocus) selectedTab.focus();
        };

        tabs.forEach((button, index) => {
            button.addEventListener('click', () => selectTab(button));
            button.addEventListener('keydown', event => {
                let nextIndex = index;
                if (event.key === 'ArrowRight') nextIndex = (index + 1) % tabs.length;
                else if (event.key === 'ArrowLeft') nextIndex = (index - 1 + tabs.length) % tabs.length;
                else if (event.key === 'Home') nextIndex = 0;
                else if (event.key === 'End') nextIndex = tabs.length - 1;
                else return;
                event.preventDefault();
                selectTab(tabs[nextIndex], true);
            });
        });
        document.querySelectorAll('.admin-section').forEach(section => {
            section.hidden = !section.classList.contains('active');
        });
    }

    function showFeedback(area, message, state = 'success') {
        const feedback = document.getElementById(`feedback-${area}`);
        if (!feedback) return;
        feedback.textContent = message;
        feedback.className = `admin-feedback ${state}`;
        feedback.hidden = false;
    }

    async function updateAdminOverview() {
        const teams = currentConfig.teams || [];
        const passwordCount = teams.filter(team => team.password_set).length;
        const appName = currentConfig.app_name || 'Soundbox';
        document.getElementById('stat-club-name').textContent = appName;
        document.getElementById('stat-password-count').textContent = `${passwordCount} Teams mit Passwort`;

        try {
            const responses = await Promise.all(teams.map(team =>
                fetch(`get_data.php?team=${encodeURIComponent(team.id)}`).then(response => response.json())
            ));
            const generalCount = responses[0]?.general_music?.length || 0;
            const teamCount = responses.reduce((total, data) => total + (data.team_music?.length || 0), 0);
            document.getElementById('stat-music-count').textContent = generalCount + teamCount;
        } catch (error) {
            document.getElementById('stat-music-count').textContent = 'â€“';
        }
    }

    function refreshLogoPreview() {
        let img = document.getElementById('logo-preview-img');
        if (!img) return;
        img.style.display = 'block';
        img.src = 'media.php?type=logo&v=' + new Date().getTime();
    }

    async function uploadLogo() {
        let form = document.getElementById('form-logo');
        let formData = new FormData(form);
        formData.append('action', 'upload_logo');
        formData.append('csrf_token', csrfToken);
        showFeedback('general', 'Logo wird hochgeladen â€¦', 'pending');
        try {
            let res = await fetch('save_settings.php', { method: 'POST', body: formData });
            let data = await res.json();
            if (data.success) {
                showFeedback('general', 'Vereinslogo wurde gespeichert.');
                refreshLogoPreview();
            } else {
                showFeedback('general', data.message || 'Logo konnte nicht gespeichert werden.', 'error');
            }
        } catch (error) {
            showFeedback('general', 'Upload fehlgeschlagen. Bitte Verbindung und Dateigrösse prüfen.', 'error');
        }
    }

    function renderTeams() {
        let container = document.getElementById('teams-list');
        if (!container) return;
        container.innerHTML = '';
        const usedNames = new Set();
        currentConfig.teams.forEach((t, idx) => {
            let div = document.createElement('div');
            div.className = 'team-item';
            const nameValue = escapeHtml(t.name || '');
            const normalizedName = String(t.name || '').trim().toLocaleLowerCase('de');
            const duplicateName = normalizedName !== '' && usedNames.has(normalizedName);
            if (normalizedName) usedNames.add(normalizedName);
            div.innerHTML = `
                <label class="team-field"><span>Teamname</span><input type="text" value="${nameValue}" placeholder="Teamname" aria-invalid="${duplicateName}" onchange="currentConfig.teams[${idx}].name = this.value; renderTeams()">${duplicateName ? '<small class="team-name-error">Dieser Teamname ist doppelt.</small>' : ''}</label>
                <label class="team-field"><span>Passwort</span><input type="password" value="" autocomplete="new-password" placeholder="Neues Passwort (leer = unverändert)" onchange="currentConfig.teams[${idx}].password = this.value"></label>
                ${currentConfig.teams.length > 1 ? `<button class="btn btn-danger team-remove" aria-label="${nameValue} entfernen" title="Team entfernen" onclick="removeTeam(${idx})"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M5 5l14 14M19 5 5 19"/></svg></button>` : ''}
            `;
            container.appendChild(div);
        });
    }

    function addTeamField() {
        currentConfig.teams.push({ id: '', name: '', password: '' });
        renderTeams();
        const nameInput = document.querySelector('#teams-list .team-item:last-child .team-field input');
        nameInput?.focus();
    }

    function removeTeam(idx) {
        currentConfig.teams.splice(idx, 1);
        renderTeams();
    }

    async function saveTeams() {
        const teamNames = currentConfig.teams.map(team => String(team.name || '').trim().toLocaleLowerCase('de'));
        const hasDuplicateNames = teamNames.some((name, index) => name !== '' && teamNames.indexOf(name) !== index);
        if (hasDuplicateNames) {
            showFeedback('teams', 'Jeder Teamname muss eindeutig sein. Bitte doppelte Namen ändern.', 'error');
            renderTeams();
            return;
        }

        let formData = new FormData();
        formData.append('action', 'save_teams');
        formData.append('csrf_token', csrfToken);
        formData.append('teams_json', JSON.stringify(currentConfig.teams));

        showFeedback('teams', 'Teamänderungen werden gespeichert …', 'pending');
        try {
            let res = await fetch('save_settings.php', { method: 'POST', body: formData });
            let data = await res.json();
            if (!data.success) {
                showFeedback('teams', data.message || 'Teams konnten nicht gespeichert werden.', 'error');
                return;
            }
            let configResponse = await fetch('get_data.php');
            currentConfig = await configResponse.json();
            showFeedback('teams', 'Teamänderungen wurden gespeichert.');
            renderTeams();
            fillTeamDropdowns();
            loadEffectSlots();
            updateAdminOverview();
        } catch (error) {
            showFeedback('teams', 'Teams konnten wegen eines Verbindungsfehlers nicht gespeichert werden.', 'error');
        }
    }

    async function saveAppSettings() {
        let appName = document.getElementById('app-name').value.trim();
        if (!appName) {
            showFeedback('general', 'Bitte einen Vereinsnamen eingeben.', 'error');
            return;
        }

        let formData = new FormData();
        formData.append('action', 'save_app_settings');
        formData.append('csrf_token', csrfToken);
        formData.append('app_name', appName);

        showFeedback('general', 'Name wird gespeichert â€¦', 'pending');
        try {
            let res = await fetch('save_settings.php', { method: 'POST', body: formData });
            let data = await res.json();
            if (!data.success) {
                showFeedback('general', data.message || 'Name konnte nicht gespeichert werden.', 'error');
                return;
            }
            currentConfig.app_name = data.app_name;
            document.getElementById('stat-club-name').textContent = data.app_name;
            document.title = data.app_name;
            const pageTitle = document.querySelector('.page-title');
            if (pageTitle) pageTitle.textContent = data.app_name;
            showFeedback('general', 'Soundbox-Name wurde gespeichert.');
        } catch (error) {
            showFeedback('general', 'Name konnte wegen eines Verbindungsfehlers nicht gespeichert werden.', 'error');
        }
    }

    async function restoreBackup() {
        const fileInput = document.getElementById('backup-file');
        const confirmed = document.getElementById('restore-confirm').checked;
        if (!fileInput.files.length || !confirmed) {
            showFeedback('backup', 'Bitte eine Backup-Datei wählen und das Ersetzen der aktuellen Daten bestätigen.', 'error');
            return;
        }
        if (!window.confirm('Die aktuelle Konfiguration und alle Medien werden durch das Backup ersetzt. Vorher ein Backup herunterladen. Fortfahren?')) return;

        const formData = new FormData(document.getElementById('restore-form'));
        formData.append('csrf_token', csrfToken);
        showFeedback('backup', 'Backup wird geprüft und wiederhergestellt. Bitte das Fenster nicht schliessen …', 'pending');
        try {
            const response = await fetch('restore.php', { method: 'POST', body: formData });
            const result = await response.json();
            showFeedback('backup', result.message || (result.success ? 'Wiederherstellung abgeschlossen.' : 'Wiederherstellung fehlgeschlagen.'), result.success ? 'success' : 'error');
            if (result.success) window.location.href = 'login.php';
        } catch (error) {
            showFeedback('backup', 'Wiederherstellung fehlgeschlagen. Prüfe die PHP-Uploadgrenze und das Backup-Archiv.', 'error');
        }
    }

    async function saveAdminSettings() {
        let username = document.getElementById('admin-username').value.trim();
        let password = document.getElementById('admin-password').value.trim();

        if (!username || !password) {
            showFeedback('general', 'Bitte Admin-Benutzername und neues Passwort eingeben.', 'error');
            return;
        }

        let formData = new FormData();
        formData.append('action', 'save_admin');
        formData.append('csrf_token', csrfToken);
        formData.append('username', username);
        formData.append('password', password);

        showFeedback('general', 'Admin-Zugang wird gespeichert â€¦', 'pending');
        try {
            let res = await fetch('save_settings.php', { method: 'POST', body: formData });
            let data = await res.json();
            if (data.success) {
                document.getElementById('admin-password').value = '';
                showFeedback('general', 'Admin-Zugang wurde gespeichert.');
            } else {
                showFeedback('general', data.message || 'Admin-Zugang konnte nicht gespeichert werden.', 'error');
            }
        } catch (error) {
            showFeedback('general', 'Admin-Zugang konnte wegen eines Verbindungsfehlers nicht gespeichert werden.', 'error');
        }
    }

    function fillTeamDropdowns() {
        let musicSelect = document.getElementById('music-team-id');
        let effectSelect = document.getElementById('effect-team-select');
        if (!musicSelect || !effectSelect) return;
        musicSelect.innerHTML = '';
        effectSelect.innerHTML = '';

        currentConfig.teams.forEach(t => {
            musicSelect.innerHTML += `<option value="${t.id}">${t.name}</option>`;
            effectSelect.innerHTML += `<option value="${t.id}">${t.name}</option>`;
        });
    }

    function toggleTeamSelect() {
        let val = document.getElementById('music-target').value;
        let box = document.getElementById('team-select-box');
        if (box) box.style.display = val === 'team' ? 'block' : 'none';
    }

    async function uploadMusic() {
        let form = document.getElementById('form-music');
        let formData = new FormData(form);
        formData.append('action', 'upload_music');
        formData.append('csrf_token', csrfToken);

        showFeedback('music', 'Musikdateien werden hochgeladen â€¦', 'pending');
        try {
            let res = await fetch('save_settings.php', { method: 'POST', body: formData });
            let data = await res.json();
            if (data.success) {
                await loadMusicFiles();
                updateAdminOverview();
                showFeedback('music', 'Musikdateien wurden hochgeladen.');
            } else {
                showFeedback('music', data.message || 'Musik-Upload fehlgeschlagen.', 'error');
            }
        } catch (error) {
            showFeedback('music', 'Upload fehlgeschlagen. Bitte Verbindung, Dateityp und Dateigrösse prüfen.', 'error');
        }
    }

    async function uploadTeamMusic() {
        let form = document.getElementById('form-music-team');
        let formData = new FormData(form);
        formData.append('action', 'upload_music');
        formData.append('csrf_token', csrfToken);

        showFeedback('team', 'Musikdateien werden hochgeladen ¦', 'pending');
        try {
            let res = await fetch('save_settings.php', { method: 'POST', body: formData });
            let data = await res.json();
            if (data.success) {
                await loadTeamMusicFiles();
                showFeedback('team', 'Musikdateien wurden hochgeladen.');
            } else {
                showFeedback('team', data.message || 'Musik-Upload fehlgeschlagen.', 'error');
            }
        } catch (error) {
            showFeedback('team', 'Upload fehlgeschlagen. Bitte Verbindung, Dateityp und Dateigrösse prüfen.', 'error');
        }
    }

    async function loadMusicFiles() {
        let target = document.getElementById('music-target')?.value || 'team';
        let teamId = document.getElementById('music-team-id')?.value || currentConfig.current_team || '';
        let res = await fetch('get_data.php' + (teamId && target === 'team' ? '?team=' + encodeURIComponent(teamId) : ''));
        let data = await res.json();
        let list = document.getElementById('music-file-list');
        if (!list) return;

        let files = target === 'allgemein' ? data.general_music : data.team_music;
        list.innerHTML = files && files.length > 0
            ? files.map(file => `
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #333;">
                    <span>${escapeHtml(audioDisplayName(file.name))}</span>
                    <button type="button" class="btn btn-danger" onclick="deleteMusic('${escapeHtml(target)}', '${escapeHtml(teamId)}', '${escapeHtml(file.name)}')">Löschen</button>
                </div>`).join('')
            : '<p style="color:#aaa;">Keine Musik vorhanden.</p>';
    }

    async function deleteMusic(target, teamId, fileName) {
        if (!window.confirm('Datei wirklich löschen?')) return;

        let formData = new FormData();
        formData.append('action', 'delete_music');
        formData.append('csrf_token', csrfToken);
        formData.append('target', target);
        formData.append('team_id', teamId);
        formData.append('file_name', fileName);

        const feedbackArea = currentConfig.role === 'team' ? 'team' : 'music';
        showFeedback(feedbackArea, 'Datei wird gelöscht …', 'pending');
        try {
            let res = await fetch('save_settings.php', { method: 'POST', body: formData });
            let data = await res.json();
            if (data.success) {
                if (currentConfig.role === 'team') {
                    await loadTeamMusicFiles();
                } else {
                    await loadMusicFiles();
                    updateAdminOverview();
                }
                showFeedback(feedbackArea, 'Musikdatei wurde gelöscht.');
            } else {
                showFeedback(feedbackArea, data.message || 'Datei konnte nicht gelÃ¶scht werden.', 'error');
            }
        } catch (error) {
            showFeedback(feedbackArea, 'Datei konnte wegen eines Verbindungsfehlers nicht gelÃ¶scht werden.', 'error');
        }
    }

    async function loadTeamMusicFiles() {
        let teamId = currentConfig.current_team || '';
        let res = await fetch('get_data.php?team=' + encodeURIComponent(teamId));
        let data = await res.json();
        let list = document.getElementById('team-music-file-list');
        if (!list) return;

        let files = data.team_music || [];
        list.innerHTML = files.length > 0
            ? files.map(file => `
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #333;">
                    <span>${escapeHtml(audioDisplayName(file.name))}</span>
                    <button type="button" class="btn btn-danger" onclick="deleteMusic('team', '${escapeHtml(teamId)}', '${escapeHtml(file.name)}')">Löschen</button>
                </div>`).join('')
            : '<p style="color:#aaa;">Keine Musik vorhanden.</p>';
    }

    async function loadEffectSlots() {
        let teamId = document.getElementById('effect-team-select').value || (currentConfig.teams[0] ? currentConfig.teams[0].id : '');
        if (!teamId) return;

        let res = await fetch('get_data.php?team=' + teamId);
        let teamData = await res.json();

        let builder = document.getElementById('effects-builder');
        if (!builder) return;
        builder.innerHTML = '';

        for (let slot = 1; slot <= 6; slot++) {
            let currentEff = teamData.effects[slot - 1];
            let currentName = currentEff ? currentEff.name : '';
            let fileName = currentEff ? audioDisplayName(audioFileNameFromPath(currentEff.path)) : 'Keine Datei abgelegt';

            let div = document.createElement('div');
            div.className = 'effect-slot';
            div.innerHTML = `
                <h3>Button ${slot}</h3>
                <div class="file-info">${escapeHtml(fileName)}</div>
                <input type="text" id="eff-name-${slot}" value="${escapeHtml(currentName)}" placeholder="Button-Beschriftung">
                <label class="effect-file-label" for="eff-file-${slot}">MP3/WAV-Datei auswählen</label>
                <input type="file" id="eff-file-${slot}" accept="audio/*">
                <button type="button" class="btn" onclick="saveEffectSlot('${escapeHtml(teamId)}', ${slot})"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M5 4h12l3 3v13H4V4h1ZM8 4v6h8V4M8 20v-7h8v7"/></svg> Button ${slot} speichern</button>
            `;
            builder.appendChild(div);
        }
    }

    async function loadTeamEffectSlots(teamId) {
        let res = await fetch('get_data.php?team=' + teamId);
        let teamData = await res.json();
        let builder = document.getElementById('team-effects-builder');
        if (!builder) return;
        builder.innerHTML = '';

        for (let slot = 1; slot <= 6; slot++) {
            let currentEff = teamData.effects[slot - 1];
            let currentName = currentEff ? currentEff.name : '';
            let fileName = currentEff ? audioDisplayName(audioFileNameFromPath(currentEff.path)) : 'Keine Datei abgelegt';

            let div = document.createElement('div');
            div.className = 'effect-slot';
            div.innerHTML = `
                <h3>Button ${slot}</h3>
                <div class="file-info">${escapeHtml(fileName)}</div>
                <input type="text" id="team-eff-name-${slot}" value="${escapeHtml(currentName)}" placeholder="Button-Beschriftung">
                <label class="effect-file-label" for="team-eff-file-${slot}">MP3/WAV-Datei auswählen</label>
                <input type="file" id="team-eff-file-${slot}" accept="audio/*">
                <button type="button" class="btn" onclick="saveEffectSlot('${escapeHtml(teamId)}', ${slot}, true)"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M5 4h12l3 3v13H4V4h1ZM8 4v6h8V4M8 20v-7h8v7"/></svg> Button ${slot} speichern</button>
            `;
            builder.appendChild(div);
        }
    }

    async function saveEffectSlot(teamId, slot, teamMode = false) {
        let nameInput = document.getElementById(teamMode ? `team-eff-name-${slot}` : `eff-name-${slot}`).value;
        let fileInput = document.getElementById(teamMode ? `team-eff-file-${slot}` : `eff-file-${slot}`).files[0];

        if (!nameInput) {
            showFeedback(teamMode ? 'team' : 'effects', 'Bitte einen Namen für den Effekt-Button eingeben.', 'error');
            return;
        }

        let formData = new FormData();
        formData.append('action', 'upload_effect');
        formData.append('csrf_token', csrfToken);
        formData.append('team_id', teamId);
        formData.append('slot', slot);
        formData.append('custom_name', nameInput);
        if (fileInput) formData.append('effect_file', fileInput);

        const feedbackArea = teamMode ? 'team' : 'effects';
        showFeedback(feedbackArea, `Effekt-Button ${slot} wird gespeichert â€¦`, 'pending');
        try {
            let res = await fetch('save_settings.php', { method: 'POST', body: formData });
            let data = await res.json();
            if (data.success) {
            if (teamMode) {
                    await loadTeamEffectSlots(teamId);
            } else {
                    await loadEffectSlots();
            }
                showFeedback(feedbackArea, `Effekt-Button ${slot} wurde gespeichert.`);
            } else {
                showFeedback(feedbackArea, data.message || `Effekt-Button ${slot} konnte nicht gespeichert werden.`, 'error');
            }
        } catch (error) {
            showFeedback(feedbackArea, `Effekt-Button ${slot} konnte wegen eines Verbindungsfehlers nicht gespeichert werden.`, 'error');
        }
    }
</script>

</body>
</html>










