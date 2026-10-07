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
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
if (!is_file(__DIR__ . '/media/config.json')) {
    header('Location: setup.php');
    exit;
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'team' || !isset($_SESSION['team_id']) || trim((string)$_SESSION['team_id']) === '') {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eishockey Soundbox</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="app-shell">
    <header class="soundboard-top panel">
        <div class="soundboard-title">
            <small>Aktives Team</small>
            <div id="display-team">Team: -</div>
        </div>
        <div class="soundboard-actions">
            <a href="settings.php" class="btn btn-secondary">Einstellungen</a>
            <a href="logout.php" class="btn btn-danger">Abmelden</a>
        </div>
    </header>

    <div class="panel soundboard-control">
        <div style="display:flex; align-items:center; gap:12px;">
            <div>
                <div class="soundboard-status" id="display-status">Bereit</div>
                <small class="text-muted">Soundboard aktiv</small>
            </div>
        </div>
        <div class="logo-container" aria-label="Vereinslogo">
            <img id="main-logo" src="media.php?type=logo" alt="Vereinslogo" onerror="this.style.display='none'">
        </div>
    </div>

    <section class="panel soundboard-control" aria-label="Lautstärke und Warm-Up-Modus">
        <label class="mode-toggle" for="warmup-mode">
            <input type="checkbox" id="warmup-mode" style="width: 20px; height: 20px;">
            <span>Warm-Up-Modus</span>
        </label>
        <div style="display:flex; align-items:center; gap:12px; flex:1; min-width:0;">
            <span class="text-muted">Lautstärke</span>
            <input type="range" class="volume-slider" id="volume-slider" min="0" max="1" step="0.05" value="0.8" oninput="setVolume(this.value)">
            <strong id="volume-label">80%</strong>
        </div>
    </section>

    <main class="panel" style="padding: 18px; margin-top: 18px;">
        <div class="effects-grid" id="effects-grid" aria-live="polite">
            <!-- Werden dynamisch geladen -->
        </div>
        <button class="btn-main" id="btn-main" onclick="toggleMainMusic(event)" aria-label="Musik starten oder stoppen">MUSIK START</button>
    </main>
</div>

<script>
    let currentData = null;
    let audioContext = new (window.AudioContext || window.webkitAudioContext)();
    let currentSource = null;
    let currentEffectSource = null;
    let gainNode = audioContext.createGain();
    gainNode.connect(audioContext.destination);

    let isPlaying = false;
    let isEffectPlaying = false;
    let isStopping = false;
    let currentAudioBuffer = null;
    let playedQueue = [];

    window.onload = () => {
        document.getElementById('main-logo').src = 'media.php?type=logo&v=' + new Date().getTime();
        loadData();
        setVolume(0.8);
        setInterval(keepSessionAlive, 5 * 60 * 1000);
    };

    async function keepSessionAlive() {
        try {
            let res = await fetch('get_data.php', { cache: 'no-store' });
            let data = await res.json();
            if (!data.logged_in) {
                document.getElementById('display-status').innerText = 'Sitzung abgelaufen – bitte neu anmelden';
            }
        } catch (error) {
            document.getElementById('display-status').innerText = 'Fehler: Server nicht erreichbar';
        }
    }

    async function loadData(teamId = '') {
        let url = 'get_data.php' + (teamId ? '?team=' + encodeURIComponent(teamId) : '');
        try {
            let res = await fetch(url, { cache: 'no-store' });
            if (!res.ok) throw new Error('http');
            currentData = await res.json();
        } catch (error) {
            document.getElementById('display-status').innerText = 'Fehler: Server nicht erreichbar';
            return;
        }
        document.title = currentData.app_name || 'Eishockey Soundbox';
        document.getElementById('display-team').innerText = 'Team: ' + getSelectedTeamName() + ' (' + currentData.music_source + ')';

        if (!currentData.logged_in) {
            document.getElementById('display-status').innerText = 'Bitte Team mit Passwort laden';
            document.getElementById('display-team').innerText = 'Team: Bitte anmelden';
            let grid = document.getElementById('effects-grid');
            grid.innerHTML = '';
            for (let i = 0; i < 6; i++) {
                let btn = document.createElement('button');
                btn.className = 'btn-effect';
                btn.innerText = (i + 1) + '. - gesperrt -';
                btn.disabled = true;
                grid.appendChild(btn);
            }
            return;
        }

        document.getElementById('display-team').innerText = 'Team: ' + getSelectedTeamName() + ' (' + currentData.music_source + ')';

        let grid = document.getElementById('effects-grid');
        grid.innerHTML = '';
        (currentData.effects || []).forEach((effect, i) => {
            if (!effect || !effect.path) return;
            let btn = document.createElement('button');
            btn.className = 'btn-effect';
            btn.innerText = (effect.slot || (i + 1)) + '. ' + effect.name;
            btn.onclick = (e) => {
                e.target.blur();
                playEffect(effect.path, effect.name);
            };
            grid.appendChild(btn);
        });

        effectBufferCache.clear();
        (currentData.effects || []).forEach(effect => {
            if (effect && effect.path) getEffectBuffer(effect.path).catch(() => {});
        });
    }

    function getSelectedTeamName() {
        let t = currentData.teams.find(x => x.id === currentData.current_team);
        return t ? t.name : '';
    }

    function getAudioDisplayName(path) {
        let fileName = path;
        try {
            fileName = new URL(path, window.location.href).searchParams.get('file') || path.split('/').pop();
            fileName = decodeURIComponent(fileName);
        } catch (error) {
            fileName = path.split('/').pop();
        }
        return fileName.replace(/\.(mp3|wav|ogg)$/i, '');
    }

    const effectBufferCache = new Map();

    async function fetchAudioBuffer(path) {
        let response = await fetch(path, { credentials: 'same-origin' });
        if (response.status === 401 || response.status === 403) throw new Error('session');
        if (!response.ok) throw new Error('http');
        let arrayBuffer = await response.arrayBuffer();
        return audioContext.decodeAudioData(arrayBuffer);
    }

    function getEffectBuffer(path) {
        if (!effectBufferCache.has(path)) {
            let pending = fetchAudioBuffer(path).catch(error => {
                effectBufferCache.delete(path);
                throw error;
            });
            effectBufferCache.set(path, pending);
        }
        return effectBufferCache.get(path);
    }

    function showAudioError(error) {
        isPlaying = false;
        let mainBtn = document.getElementById('btn-main');
        mainBtn.innerText = 'MUSIK START [Leertaste]';
        mainBtn.classList.remove('playing');
        document.getElementById('display-status').innerText = error && error.message === 'session'
            ? 'Sitzung abgelaufen – bitte neu anmelden'
            : 'Fehler: Audiodatei konnte nicht geladen werden';
    }

    function setVolume(val) {
        gainNode.gain.cancelScheduledValues(audioContext.currentTime);
        gainNode.gain.setValueAtTime(Number(val), audioContext.currentTime);
        document.getElementById('volume-label').innerText = Math.round(val * 100) + '%';
    }

    async function toggleMainMusic(e) {
        if (e && e.target) e.target.blur(); 
        if (!currentData || !currentData.logged_in) {
            alert('Bitte zuerst ein Team mit Passwort laden.');
            return;
        }
        if (isStopping) return;

        if (audioContext.state === 'suspended') {
            await audioContext.resume();
        }

        if (isPlaying || isEffectPlaying) {
            stopAll(800);
        } else {
            playRandomMusic();
        }
    }

    async function playRandomMusic() {
        stopEffect();

        if (!currentData.songs || currentData.songs.length === 0) {
            document.getElementById('display-status').innerText = 'Fehler: Keine Musik!';
            return;
        }

        if (playedQueue.length >= currentData.songs.length) playedQueue = [];
        let available = currentData.songs.filter(s => !playedQueue.includes(s));
        let selectedSong = available[Math.floor(Math.random() * available.length)];
        playedQueue.push(selectedSong);

        document.getElementById('display-status').innerText = 'Lade Audio...';
        try {
            currentAudioBuffer = await fetchAudioBuffer(selectedSong);
        } catch (error) {
            showAudioError(error);
            return;
        }

        if (currentSource) {
            currentSource.onended = null;
            try { currentSource.stop(); } catch(err) {}
        }

        currentSource = audioContext.createBufferSource();
        currentSource.buffer = currentAudioBuffer;
        currentSource.connect(gainNode);

        let isWarmup = document.getElementById('warmup-mode').checked;
        let startPos = 0;

        if (!isWarmup && currentAudioBuffer.duration > 20) {
            startPos = Math.random() * (currentAudioBuffer.duration - 15);
        }

        currentSource.start(0, startPos);
        isPlaying = true;

        let songName = getAudioDisplayName(selectedSong);
        document.getElementById('display-status').innerText = (isWarmup ? '[WARM-UP] ' : 'PLAY: ') + songName;
        let mainBtn = document.getElementById('btn-main');
        mainBtn.innerText = 'STOPP [Leertaste]';
        mainBtn.classList.add('playing');

        currentSource.onended = () => {
            if (isPlaying && isWarmup && !isStopping) {
                playRandomMusic();
            }
        };
    }

    function stopEffect() {
        if (currentEffectSource) {
            currentEffectSource.onended = null;
            try { currentEffectSource.stop(); } catch(err) {}
            currentEffectSource = null;
        }
        isEffectPlaying = false;
    }

    function stopAll(fadeMs = 800) {
        if ((!isPlaying && !isEffectPlaying) || isStopping) return;

        isStopping = true;
        let fadeTime = fadeMs / 1000;

        stopEffect();

        if (isPlaying && fadeMs > 0) {
            gainNode.gain.setValueAtTime(gainNode.gain.value, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.0001, audioContext.currentTime + fadeTime);
        }

        setTimeout(() => {
            if (currentSource) {
                try { currentSource.stop(); } catch(err) {}
                currentSource = null;
            }
            isPlaying = false;
            isStopping = false;

            setVolume(document.getElementById('volume-slider').value);

            if (isEffectPlaying) return;
            document.getElementById('display-status').innerText = 'Bereit (Stopp)';
            let mainBtn = document.getElementById('btn-main');
            mainBtn.innerText = 'MUSIK START [Leertaste]';
            mainBtn.classList.remove('playing');
        }, fadeMs);
    }

    async function playEffect(path, name) {
        if (!currentData || !currentData.logged_in) {
            alert('Bitte zuerst ein Team mit Passwort laden.');
            return;
        }
        if (isPlaying) stopAll(0);
        stopEffect();

        if (audioContext.state === 'suspended') {
            await audioContext.resume();
        }

        let buffer;
        try {
            buffer = await getEffectBuffer(path);
        } catch (error) {
            showAudioError(error);
            return;
        }

        currentEffectSource = audioContext.createBufferSource();
        currentEffectSource.buffer = buffer;
        currentEffectSource.connect(gainNode);
        
        currentEffectSource.onended = () => {
            isEffectPlaying = false;
            if (!isPlaying) {
                document.getElementById('display-status').innerText = 'Bereit (Stopp)';
                let mainBtn = document.getElementById('btn-main');
                mainBtn.innerText = 'MUSIK START [Leertaste]';
                mainBtn.classList.remove('playing');
            }
        };

        currentEffectSource.start(0);
        isEffectPlaying = true;

        document.getElementById('display-status').innerText = 'EFFEKT: ' + name;
        let mainBtn = document.getElementById('btn-main');
        mainBtn.innerText = 'EFFEKT STOPPEN [Leertaste]';
        mainBtn.classList.add('playing');
    }

    // Tastatur-Steuerung
    window.addEventListener('keydown', (e) => {
        if (e.code === 'Space') {
            e.preventDefault();
            if (document.activeElement) document.activeElement.blur();
            toggleMainMusic();
        } else if (e.key >= '1' && e.key <= '6') {
            let idx = parseInt(e.key) - 1;
            if (currentData && currentData.effects && currentData.effects[idx]) {
                if (document.activeElement) document.activeElement.blur();
                playEffect(currentData.effects[idx].path, currentData.effects[idx].name);
            }
        }
    });
</script>

</body>
</html>