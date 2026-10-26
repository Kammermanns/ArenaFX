// Soundbox: Musik über ein Audio-Element (spart Speicher, erlaubt Springen im Song),
// Effekte vorab dekodiert als AudioBuffer (starten ohne Verzögerung).

const MIME = { mp3: 'audio/mpeg', wav: 'audio/wav', ogg: 'audio/ogg', m4a: 'audio/mp4', flac: 'audio/flac' };
const FADE_MS = 800;
const DUCK_LEVEL = 0.2;

const audio = {
    context: null,
    master: null,
    duck: null,
    fade: null,
    effectGain: null,
    element: new Audio(),
    effectSource: null,
    effectBuffers: new Map(),
};

const state = {
    data: null,
    musicPlaying: false,
    effectPlaying: false,
    stopping: false,
    duckActive: false,
    queue: [],
    nextSong: null,
    currentUrl: null,
    loadToken: 0,
};

const $ = id => document.getElementById(id);

function setupAudioGraph() {
    const context = new (window.AudioContext || window.webkitAudioContext)();
    audio.context = context;
    audio.master = context.createGain();
    audio.duck = context.createGain();
    audio.fade = context.createGain();
    audio.effectGain = context.createGain();
    // Musik: Element -> Ausblenden -> Durchsage-Absenkung -> Gesamtlautstärke
    context.createMediaElementSource(audio.element).connect(audio.fade);
    audio.fade.connect(audio.duck);
    audio.duck.connect(audio.master);
    audio.effectGain.connect(audio.master);
    audio.master.connect(context.destination);
}

async function resumeAudio() {
    if (audio.context.state === 'suspended') await audio.context.resume();
}

function setStatus(text) {
    $('display-status').textContent = text;
}

function updateMainButton() {
    const busy = state.musicPlaying || state.effectPlaying;
    const button = $('btn-main');
    button.classList.toggle('playing', busy);
    $('main-text').textContent = t(state.effectPlaying && !state.musicPlaying ? 'effect_stop' : busy ? 'music_stop' : 'music_start');
}

function setVolume(value) {
    const volume = Math.min(1, Math.max(0, Number(value)));
    audio.master.gain.setTargetAtTime(volume, audio.context.currentTime, 0.02);
    $('volume-label').textContent = `${Math.round(volume * 100)}%`;
    try { localStorage.setItem('arenafx-volume', String(volume)); } catch (error) { /* egal */ }
}

function extensionOf(name) {
    return (name.split('.').pop() || '').toLowerCase();
}

function songKey(song) {
    return `${song.scope}/${song.name}`;
}

function displayName(name) {
    return name.replace(/\.[^.]+$/, '');
}

// ---------------------------------------------------------------------------
// Musik
// ---------------------------------------------------------------------------

function pickNextSong() {
    const songs = state.data.songs;
    if (!songs.length) return null;
    if (state.queue.length >= songs.length) state.queue = [];
    const available = songs.filter(song => !state.queue.includes(songKey(song)));
    return available[Math.floor(Math.random() * available.length)];
}

async function fetchSong(song) {
    const bytes = await api('read_music', { scope: song.scope, teamId: state.data.teamId, name: song.name });
    return URL.createObjectURL(new Blob([bytes], { type: MIME[extensionOf(song.name)] || 'audio/mpeg' }));
}

/** Lädt den nächsten Song schon im Hintergrund, damit der Start ohne Wartezeit klappt. */
function prefetchNext() {
    const song = pickNextSong();
    if (!song) {
        state.nextSong = null;
        return;
    }
    const entry = { song, url: null, promise: null };
    entry.promise = fetchSong(song).then(url => { entry.url = url; return url; }).catch(() => null);
    if (state.nextSong && state.nextSong.url) URL.revokeObjectURL(state.nextSong.url);
    state.nextSong = entry;
}

async function playMusic() {
    stopEffect();
    if (!state.data.songs.length) {
        setStatus(t('error_no_music'));
        return;
    }
    const token = ++state.loadToken;
    let entry = state.nextSong;
    if (!entry || !state.data.songs.some(song => songKey(song) === songKey(entry.song))) {
        prefetchNext();
        entry = state.nextSong;
    }
    state.nextSong = null;
    state.queue.push(songKey(entry.song));
    setStatus(t('loading_audio'));

    const url = entry.url || await entry.promise;
    if (token !== state.loadToken) {
        if (url) URL.revokeObjectURL(url);
        return;
    }
    if (!url) {
        setStatus(t('error_loading'));
        prefetchNext();
        return;
    }
    const warmup = $('warmup-mode').checked;
    const element = audio.element;
    element.pause();
    if (state.currentUrl) URL.revokeObjectURL(state.currentUrl);
    state.currentUrl = url;
    element.src = url;

    try {
        await new Promise((resolve, reject) => {
            element.onloadedmetadata = resolve;
            element.onerror = () => reject(new Error('load'));
        });
        if (token !== state.loadToken) return;
        const duration = element.duration;
        if (!warmup && Number.isFinite(duration) && duration > 20) {
            element.currentTime = Math.random() * (duration - 15);
        }
        audio.fade.gain.cancelScheduledValues(audio.context.currentTime);
        audio.fade.gain.setValueAtTime(1, audio.context.currentTime);
        await element.play();
    } catch (error) {
        if (token !== state.loadToken) return;
        setStatus(t('error_playback'));
        state.musicPlaying = false;
        updateMainButton();
        prefetchNext();
        return;
    }
    state.musicPlaying = true;
    setStatus(`${t(warmup ? 'warmup_prefix' : 'play_prefix')} ${displayName(entry.song.name)}`);
    updateMainButton();
    element.onended = () => {
        if (token !== state.loadToken) return;
        state.musicPlaying = false;
        if ($('warmup-mode').checked && !state.stopping) {
            playMusic();
        } else {
            setStatus(t('ready'));
            updateMainButton();
        }
    };
    prefetchNext();
}

function stopMusicNow() {
    state.loadToken++;
    audio.element.pause();
    audio.element.onended = null;
    state.musicPlaying = false;
}

function stopAll(fadeMs = FADE_MS) {
    if (state.stopping) return;
    stopEffect();
    if (!state.musicPlaying || fadeMs <= 0) {
        stopMusicNow();
        setStatus(t('ready'));
        updateMainButton();
        return;
    }
    state.stopping = true;
    const now = audio.context.currentTime;
    audio.fade.gain.cancelScheduledValues(now);
    audio.fade.gain.setValueAtTime(audio.fade.gain.value, now);
    audio.fade.gain.linearRampToValueAtTime(0, now + fadeMs / 1000);
    setStatus(t('fading_out'));
    setTimeout(() => {
        stopMusicNow();
        state.stopping = false;
        setStatus(t('ready'));
        updateMainButton();
    }, fadeMs);
}

async function toggleMain() {
    await resumeAudio();
    if (state.stopping) return;
    if (state.musicPlaying || state.effectPlaying) stopAll();
    else playMusic();
}

// ---------------------------------------------------------------------------
// Durchsage und Effekte
// ---------------------------------------------------------------------------

async function toggleDuck() {
    await resumeAudio();
    state.duckActive = !state.duckActive;
    audio.duck.gain.setTargetAtTime(state.duckActive ? DUCK_LEVEL : 1, audio.context.currentTime, 0.08);
    $('btn-durchsage').classList.toggle('active', state.duckActive);
    $('durchsage-text').textContent = t(state.duckActive ? 'durchsage_active' : 'durchsage');
}

function stopEffect() {
    if (audio.effectSource) {
        audio.effectSource.onended = null;
        try { audio.effectSource.stop(); } catch (error) { /* schon beendet */ }
        audio.effectSource = null;
    }
    state.effectPlaying = false;
}

async function loadEffects() {
    audio.effectBuffers.clear();
    const teamId = state.data.teamId;
    await Promise.all(state.data.effects.map(async effect => {
        try {
            const bytes = await api('read_effect', { teamId, slot: effect.slot });
            const buffer = await audio.context.decodeAudioData(bytes);
            if (state.data.teamId === teamId) audio.effectBuffers.set(effect.slot, buffer);
        } catch (error) {
            console.error('Effekt', effect.slot, error);
        }
    }));
    renderEffects();
}

async function playEffect(slot) {
    const effect = state.data.effects.find(item => item.slot === slot);
    const buffer = audio.effectBuffers.get(slot);
    if (!effect) return;
    await resumeAudio();
    if (!buffer) {
        setStatus(t('error_effect_loading'));
        return;
    }
    if (state.musicPlaying) stopMusicNow();
    stopEffect();
    const source = audio.context.createBufferSource();
    source.buffer = buffer;
    source.connect(audio.effectGain);
    source.onended = () => {
        if (audio.effectSource !== source) return;
        audio.effectSource = null;
        state.effectPlaying = false;
        if (!state.musicPlaying) setStatus(t('ready'));
        updateMainButton();
    };
    audio.effectSource = source;
    source.start();
    state.effectPlaying = true;
    setStatus(`${t('effect_prefix')} ${effect.label}`);
    updateMainButton();
}

// ---------------------------------------------------------------------------
// Anzeige
// ---------------------------------------------------------------------------

function renderEffects() {
    const grid = $('effects-grid');
    grid.replaceChildren();
    for (let slot = 1; slot <= 6; slot++) {
        const effect = state.data.effects.find(item => item.slot === slot);
        const hotkey = state.data.hotkeys[`effect_${slot}`];
        const ready = effect && audio.effectBuffers.has(slot);
        const button = el('button', {
            type: 'button',
            class: 'btn-effect',
            disabled: !ready,
            onclick: event => { event.currentTarget.blur(); playEffect(slot); },
        },
            el('span', { class: 'effect-number', text: String(slot) }),
            el('span', { class: 'effect-label', text: effect ? effect.label : t('effect_empty') }),
            hotkey ? el('kbd', { class: 'key-hint', text: keyLabel(hotkey) }) : null,
        );
        grid.append(button);
    }
}

function renderHeader() {
    const data = state.data;
    document.title = data.appName;
    $('display-team').textContent = data.teamName;
    $('music-source').textContent = t(`source_${data.musicSource}`, { count: data.songs.length });
    $('music-key').textContent = data.hotkeys.music ? keyLabel(data.hotkeys.music) : '';
    $('durchsage-key').textContent = data.hotkeys.durchsage ? keyLabel(data.hotkeys.durchsage) : '';
    const select = $('team-select');
    if (data.role === 'admin' && data.teams.length > 1) {
        select.replaceChildren(...data.teams.map(team => el('option', { value: team.id, text: team.name })));
        select.value = data.teamId;
        select.hidden = false;
        $('display-team').hidden = true;
    }
}

async function loadTeam(teamId) {
    stopAll(0);
    state.data = await api('soundbox', { teamId: teamId || null });
    state.queue = [];
    if (state.nextSong && state.nextSong.url) URL.revokeObjectURL(state.nextSong.url);
    state.nextSong = null;
    renderHeader();
    renderEffects();
    setStatus(t('ready'));
    updateMainButton();
    try { sessionStorage.setItem('arenafx-team', state.data.teamId); } catch (error) { /* egal */ }
    await loadEffects();
    prefetchNext();
}

function handleKey(event) {
    const target = event.target;
    if (target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) && target.type !== 'checkbox' && target.type !== 'range') return;
    if (event.repeat || event.ctrlKey || event.altKey || event.metaKey) return;
    const key = normalizeKey(event);
    if (!key || !state.data) return;
    const hotkeys = state.data.hotkeys;
    const matches = value => value && value.toLowerCase() === key.toLowerCase();
    if (matches(hotkeys.music)) {
        event.preventDefault();
        toggleMain();
    } else if (matches(hotkeys.durchsage)) {
        event.preventDefault();
        toggleDuck();
    } else {
        for (let slot = 1; slot <= 6; slot++) {
            if (matches(hotkeys[`effect_${slot}`])) {
                event.preventDefault();
                playEffect(slot);
                return;
            }
        }
    }
}

(async () => {
    const status = await api('app_status');
    if (status.status !== 'ready' || !status.role) return go('login.html');
    await initI18n(status.language);
    setupAudioGraph();

    let volume = 0.8;
    try { volume = Number(localStorage.getItem('arenafx-volume') ?? 0.8); } catch (error) { /* egal */ }
    $('volume-slider').value = String(volume);
    setVolume(volume);
    $('volume-slider').addEventListener('input', event => setVolume(event.target.value));
    $('btn-main').addEventListener('click', event => { event.currentTarget.blur(); toggleMain(); });
    $('btn-durchsage').addEventListener('click', event => { event.currentTarget.blur(); toggleDuck(); });
    $('team-select').addEventListener('change', event => loadTeam(event.target.value));
    $('logout').addEventListener('click', async () => {
        stopAll(0);
        await api('logout');
        go('login.html');
    });
    window.addEventListener('keydown', handleKey);
    loadLogoInto($('main-logo'));

    let remembered = null;
    try { remembered = sessionStorage.getItem('arenafx-team'); } catch (error) { /* egal */ }
    try {
        await loadTeam(remembered);
    } catch (error) {
        if (error.code === 'err_not_logged_in') return go('login.html');
        setStatus(errorText(error));
    }
})();
