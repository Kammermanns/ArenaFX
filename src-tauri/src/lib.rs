//! ArenaFX – Tauri-Befehle. Jeder Befehl prüft selbst, wer angemeldet ist;
//! die Oberfläche hat keinen direkten Zugriff auf Dateien.

mod backup;
mod error;
mod legacy;
mod store;

use std::collections::BTreeMap;
use std::path::PathBuf;
use std::sync::{Mutex, MutexGuard};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use serde::Serialize;
use tauri::ipc::Response;
use tauri::{AppHandle, Manager, State};
use tauri_plugin_dialog::DialogExt;

use error::{err, err_detail, AppResult};
use store::{
    hash_password, needs_rehash, validate_admin_username, validate_app_name, validate_hotkeys,
    validate_language, verify_password, AudioFile, Config, ConfigState, MusicScope, SetupInput,
    Store, TeamInput, AUDIO_EXTENSIONS, MIN_ADMIN_PASSWORD,
};

const MAX_LOGIN_FAILURES: u32 = 5;
const LOGIN_LOCK: Duration = Duration::from_secs(30);

#[derive(Clone, Debug, PartialEq)]
enum Role {
    Admin,
    Team(String),
}

#[derive(Default)]
struct LoginGuard {
    failures: u32,
    locked_until: Option<Instant>,
}

pub struct AppState {
    store: Store,
    session: Mutex<Option<Role>>,
    write_lock: Mutex<()>,
    login_guard: Mutex<LoginGuard>,
    legacy_source: Mutex<Option<PathBuf>>,
}

fn lock<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex
        .lock()
        .unwrap_or_else(|poisoned| poisoned.into_inner())
}

impl AppState {
    fn new(root: PathBuf) -> Self {
        AppState {
            store: Store::new(root),
            session: Mutex::new(None),
            write_lock: Mutex::new(()),
            login_guard: Mutex::new(LoginGuard::default()),
            legacy_source: Mutex::new(None),
        }
    }

    fn role(&self) -> Option<Role> {
        lock(&self.session).clone()
    }

    fn set_role(&self, role: Option<Role>) {
        *lock(&self.session) = role;
    }

    fn require_login(&self) -> AppResult<Role> {
        let role = self.role().ok_or(err("err_not_logged_in"))?;
        // Ein gelöschtes Team verliert sofort den Zugang.
        if let Role::Team(id) = &role {
            if self.store.load_ready()?.team(id).is_none() {
                self.set_role(None);
                return Err(err("err_not_logged_in"));
            }
        }
        Ok(role)
    }

    fn require_admin(&self) -> AppResult<()> {
        match self.require_login()? {
            Role::Admin => Ok(()),
            Role::Team(_) => Err(err("err_forbidden")),
        }
    }

    fn require_team_access(&self, team_id: &str) -> AppResult<Role> {
        let role = self.require_login()?;
        match &role {
            Role::Admin => Ok(role),
            Role::Team(own) if own == team_id => Ok(role),
            Role::Team(_) => Err(err("err_forbidden")),
        }
    }

    /// Konfiguration laden, ändern und sicher speichern – nie zwei Änderungen gleichzeitig.
    fn modify<T>(&self, change: impl FnOnce(&mut Config) -> AppResult<T>) -> AppResult<T> {
        let _guard = lock(&self.write_lock);
        let mut config = self.store.load_ready()?;
        let result = change(&mut config)?;
        self.store.save(&config)?;
        Ok(result)
    }

    fn music_dir_for(&self, scope: &str, team_id: Option<&str>) -> AppResult<PathBuf> {
        let scope = MusicScope::parse(scope)?;
        match scope {
            MusicScope::General => self.require_admin()?,
            MusicScope::Team => {
                let team_id = team_id.ok_or(err("err_team_not_found"))?;
                self.require_team_access(team_id)?;
                self.store
                    .load_ready()?
                    .team(team_id)
                    .ok_or(err("err_team_not_found"))?;
            }
        }
        self.store.music_dir(scope, team_id)
    }
}

// ---------------------------------------------------------------------------
// Dialoge (laufen im Rust-Teil, damit die Oberfläche keine Pfade wählen kann)
// ---------------------------------------------------------------------------

fn dialog_text(language: &str, key: &str) -> &'static str {
    match (language, key) {
        ("en", "music") => "Choose audio files",
        ("fr", "music") => "Choisir des fichiers audio",
        (_, "music") => "Audiodateien auswählen",
        ("en", "effect") => "Choose sound for the effect button",
        ("fr", "effect") => "Choisir le son du bouton d'effet",
        (_, "effect") => "Ton für den Effekt-Button auswählen",
        ("en", "logo") => "Choose club logo (PNG or JPG)",
        ("fr", "logo") => "Choisir le logo du club (PNG ou JPG)",
        (_, "logo") => "Vereinslogo auswählen (PNG oder JPG)",
        ("en", "backup_save") => "Save backup",
        ("fr", "backup_save") => "Enregistrer la sauvegarde",
        (_, "backup_save") => "Backup speichern",
        ("en", "backup_open") => "Choose backup file",
        ("fr", "backup_open") => "Choisir le fichier de sauvegarde",
        (_, "backup_open") => "Backup-Datei auswählen",
        ("en", "legacy") => "Choose the folder of the old ArenaFX or Soundbox installation",
        ("fr", "legacy") => "Choisir le dossier de l'ancienne installation ArenaFX ou Soundbox",
        (_, "legacy") => "Ordner der alten ArenaFX- oder Soundbox-Installation auswählen",
        ("en", "audio") => "Audio",
        (_, "audio") => "Audio",
        ("en", "image") => "Images",
        ("fr", "image") => "Images",
        (_, "image") => "Bilder",
        _ => "ArenaFX",
    }
}

fn current_language(state: &AppState) -> String {
    state
        .store
        .load_ready()
        .map(|c| c.language)
        .unwrap_or_else(|_| "de".into())
}

/// Nur in Entwickler-Builds: automatische Tests können Dialog-Antworten vorgeben.
#[cfg(debug_assertions)]
fn test_dialog_answer() -> Option<Option<Vec<PathBuf>>> {
    let file = std::env::var_os("ARENAFX_TEST_DIALOGS")?;
    let mut queue: Vec<serde_json::Value> =
        serde_json::from_str(&std::fs::read_to_string(&file).ok()?).ok()?;
    if queue.is_empty() {
        return None;
    }
    let answer = queue.remove(0);
    let _ = std::fs::write(&file, serde_json::to_string(&queue).unwrap_or_default());
    Some(match answer {
        serde_json::Value::String(path) => Some(vec![PathBuf::from(path)]),
        serde_json::Value::Array(paths) => Some(
            paths
                .iter()
                .filter_map(|p| p.as_str().map(PathBuf::from))
                .collect(),
        ),
        _ => None,
    })
}

#[cfg(not(debug_assertions))]
fn test_dialog_answer() -> Option<Option<Vec<PathBuf>>> {
    None
}

fn file_dialog(app: &AppHandle, title: &str) -> tauri_plugin_dialog::FileDialogBuilder<tauri::Wry> {
    let mut builder = app.dialog().file().set_title(title);
    if let Some(window) = app.get_webview_window("main") {
        builder = builder.set_parent(&window);
    }
    builder
}

fn pick_files(
    app: &AppHandle,
    title: &str,
    filter: &str,
    extensions: &[&str],
    multiple: bool,
) -> Option<Vec<PathBuf>> {
    if let Some(answer) = test_dialog_answer() {
        return answer;
    }
    let builder = file_dialog(app, title).add_filter(filter, extensions);
    let picked = if multiple {
        builder.blocking_pick_files()
    } else {
        builder.blocking_pick_file().map(|f| vec![f])
    };
    let paths: Vec<PathBuf> = picked?
        .into_iter()
        .filter_map(|f| f.into_path().ok())
        .collect();
    (!paths.is_empty()).then_some(paths)
}

fn pick_folder(app: &AppHandle, title: &str) -> Option<PathBuf> {
    if let Some(answer) = test_dialog_answer() {
        return answer.and_then(|paths| paths.into_iter().next());
    }
    file_dialog(app, title)
        .blocking_pick_folder()?
        .into_path()
        .ok()
}

fn save_file(app: &AppHandle, title: &str, file_name: &str) -> Option<PathBuf> {
    if let Some(answer) = test_dialog_answer() {
        return answer.and_then(|paths| paths.into_iter().next());
    }
    file_dialog(app, title)
        .add_filter("ZIP", &["zip"])
        .set_file_name(file_name)
        .blocking_save_file()?
        .into_path()
        .ok()
}

fn today() -> String {
    let days = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs() / 86_400)
        .unwrap_or(0) as i64;
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1_460 + doe / 36_524 - doe / 146_096) / 365;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let day = doy - (153 * mp + 2) / 5 + 1;
    let month = if mp < 10 { mp + 3 } else { mp - 9 };
    let year = yoe + era * 400 + i64::from(month <= 2);
    format!("{year:04}-{month:02}-{day:02}")
}

// ---------------------------------------------------------------------------
// Status, Einrichtung, Anmeldung
// ---------------------------------------------------------------------------

#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
struct TeamOption {
    id: String,
    name: String,
    locked: bool,
}

#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
struct AppStatus {
    status: &'static str,
    recovered: bool,
    version: String,
    app_name: String,
    language: String,
    role: Option<&'static str>,
    team_id: Option<String>,
    team_name: Option<String>,
    teams: Vec<TeamOption>,
    has_logo: bool,
    data_dir: String,
}

#[tauri::command]
fn app_status(app: AppHandle, state: State<'_, AppState>) -> AppStatus {
    let version = app.package_info().version.to_string();
    let data_dir = state.store.root().display().to_string();
    let mut status = AppStatus {
        status: "setup",
        recovered: false,
        version,
        app_name: "ArenaFX".into(),
        language: "de".into(),
        role: None,
        team_id: None,
        team_name: None,
        teams: Vec::new(),
        has_logo: false,
        data_dir,
    };
    match state.store.load() {
        ConfigState::Missing => {
            state.set_role(None);
        }
        ConfigState::Broken => {
            state.set_role(None);
            status.status = "broken";
        }
        ConfigState::Ready { config, recovered } => {
            status.status = "ready";
            status.recovered = recovered;
            status.app_name = config.app_name.clone();
            status.language = config.language.clone();
            status.has_logo = config.logo.is_some();
            status.teams = config
                .teams
                .iter()
                .map(|t| TeamOption {
                    id: t.id.clone(),
                    name: t.name.clone(),
                    locked: t.password_hash.is_none(),
                })
                .collect();
            match state.role() {
                Some(Role::Admin) => status.role = Some("admin"),
                Some(Role::Team(id)) => match config.team(&id) {
                    Some(team) => {
                        status.role = Some("team");
                        status.team_id = Some(team.id.clone());
                        status.team_name = Some(team.name.clone());
                    }
                    None => state.set_role(None),
                },
                None => {}
            }
        }
    }
    status
}

#[tauri::command]
fn setup(state: State<'_, AppState>, input: SetupInput) -> AppResult<()> {
    let _guard = lock(&state.write_lock);
    state.store.create_initial(&input)?;
    state.set_role(Some(Role::Admin));
    Ok(())
}

#[tauri::command]
async fn pick_legacy_folder(
    app: AppHandle,
    state: State<'_, AppState>,
) -> AppResult<Option<legacy::LegacyPreview>> {
    let setup_mode = matches!(state.store.load(), ConfigState::Missing);
    if !setup_mode {
        state.require_admin()?;
    }
    let language = current_language(&state);
    let Some(folder) = pick_folder(&app, dialog_text(&language, "legacy")) else {
        return Ok(None);
    };
    let preview = legacy::preview(&folder)?;
    *lock(&state.legacy_source) = Some(folder);
    Ok(Some(preview))
}

fn run_legacy_import(
    state: &AppState,
    admin: store::Admin,
) -> AppResult<legacy::LegacyImportResult> {
    let source = lock(&state.legacy_source)
        .clone()
        .ok_or(err("err_legacy_not_found"))?;
    let staging = backup::staging_dir(&state.store, "import")?;
    let outcome = legacy::build(&source, admin, &staging).and_then(|(config, result)| {
        backup::replace_data(&state.store, &staging, &config).map(|_| result)
    });
    if outcome.is_err() {
        let _ = std::fs::remove_dir_all(&staging);
    }
    *lock(&state.legacy_source) = None;
    outcome
}

/// Ersteinrichtung mit den Daten einer alten Installation; der Admin-Zugang wird neu festgelegt.
#[tauri::command]
async fn setup_from_legacy(
    state: State<'_, AppState>,
    admin_username: String,
    admin_password: String,
) -> AppResult<legacy::LegacyImportResult> {
    let _guard = lock(&state.write_lock);
    if !matches!(state.store.load(), ConfigState::Missing) {
        return Err(err("err_already_setup"));
    }
    let admin = store::validated_admin(&admin_username, &admin_password)?;
    let result = run_legacy_import(&state, admin)?;
    state.set_role(Some(Role::Admin));
    Ok(result)
}

/// Ersetzt die aktuellen Daten durch die einer alten Installation; der Admin-Zugang bleibt.
#[tauri::command]
async fn import_legacy(state: State<'_, AppState>) -> AppResult<legacy::LegacyImportResult> {
    state.require_admin()?;
    let _guard = lock(&state.write_lock);
    let admin = state.store.load_ready()?.admin;
    run_legacy_import(&state, admin)
}

#[tauri::command]
fn login(
    state: State<'_, AppState>,
    account: String,
    username: Option<String>,
    password: String,
) -> AppResult<&'static str> {
    {
        let guard = lock(&state.login_guard);
        if let Some(until) = guard.locked_until {
            let now = Instant::now();
            if until > now {
                return Err(err_detail("err_login_locked", (until - now).as_secs() + 1));
            }
        }
    }
    let _write = lock(&state.write_lock);
    let mut config = state.store.load_ready()?;
    let password = password.trim();

    let (role, rehash) = if account == "__admin__" {
        let username = username.unwrap_or_default();
        let matches = username.trim().eq_ignore_ascii_case(&config.admin.username)
            || username.trim().to_lowercase() == config.admin.username.to_lowercase();
        if matches && verify_password(password, &config.admin.password_hash) {
            (Some(Role::Admin), needs_rehash(&config.admin.password_hash))
        } else {
            (None, false)
        }
    } else {
        match config.team(&account).and_then(|t| t.password_hash.clone()) {
            Some(hash) if verify_password(password, &hash) => {
                (Some(Role::Team(account.clone())), needs_rehash(&hash))
            }
            _ => (None, false),
        }
    };

    let mut guard = lock(&state.login_guard);
    let Some(role) = role else {
        guard.failures += 1;
        if guard.failures >= MAX_LOGIN_FAILURES {
            guard.failures = 0;
            guard.locked_until = Some(Instant::now() + LOGIN_LOCK);
        }
        return Err(err("err_login_failed"));
    };
    *guard = LoginGuard::default();
    drop(guard);

    if rehash {
        let hash = hash_password(password)?;
        match &role {
            Role::Admin => config.admin.password_hash = hash,
            Role::Team(id) => {
                if let Some(team) = config.team_mut(id) {
                    team.password_hash = Some(hash);
                }
            }
        }
        state.store.save(&config)?;
    }
    let target = if role == Role::Admin {
        "settings.html"
    } else {
        "index.html"
    };
    state.set_role(Some(role));
    Ok(target)
}

#[tauri::command]
fn logout(state: State<'_, AppState>) {
    state.set_role(None);
}

// ---------------------------------------------------------------------------
// Soundbox
// ---------------------------------------------------------------------------

#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
struct Song {
    scope: &'static str,
    name: String,
}

#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
struct EffectButton {
    slot: usize,
    label: String,
}

#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
struct SoundboxView {
    role: &'static str,
    app_name: String,
    language: String,
    team_id: String,
    team_name: String,
    teams: Vec<TeamOption>,
    music_source: &'static str,
    songs: Vec<Song>,
    effects: Vec<EffectButton>,
    hotkeys: BTreeMap<String, String>,
    has_logo: bool,
}

#[tauri::command]
fn soundbox(state: State<'_, AppState>, team_id: Option<String>) -> AppResult<SoundboxView> {
    let role = state.require_login()?;
    let config = state.store.load_ready()?;
    let team = match &role {
        Role::Team(own) => config.team(own),
        Role::Admin => team_id
            .as_deref()
            .and_then(|id| config.team(id))
            .or(config.teams.first()),
    }
    .ok_or(err("err_team_not_found"))?;

    let team_songs = Store::list_audio(&state.store.team_music_dir(&team.id)?);
    let (music_source, songs) = if !team_songs.is_empty() {
        (
            "team",
            team_songs
                .into_iter()
                .map(|f| Song {
                    scope: "team",
                    name: f.name,
                })
                .collect(),
        )
    } else {
        let general = Store::list_audio(&state.store.general_music_dir());
        let source = if general.is_empty() {
            "none"
        } else {
            "general"
        };
        (
            source,
            general
                .into_iter()
                .map(|f| Song {
                    scope: "general",
                    name: f.name,
                })
                .collect(),
        )
    };
    let effects = team
        .effects
        .iter()
        .enumerate()
        .filter_map(|(index, effect)| {
            effect.as_ref().map(|e| EffectButton {
                slot: index + 1,
                label: e.label.clone(),
            })
        })
        .collect();
    let teams = match role {
        Role::Admin => config
            .teams
            .iter()
            .map(|t| TeamOption {
                id: t.id.clone(),
                name: t.name.clone(),
                locked: t.password_hash.is_none(),
            })
            .collect(),
        Role::Team(_) => Vec::new(),
    };
    Ok(SoundboxView {
        role: if matches!(role, Role::Admin) {
            "admin"
        } else {
            "team"
        },
        app_name: config.app_name.clone(),
        language: config.language.clone(),
        team_id: team.id.clone(),
        team_name: team.name.clone(),
        teams,
        music_source,
        songs,
        effects,
        hotkeys: config.hotkeys.clone(),
        has_logo: config.logo.is_some(),
    })
}

#[tauri::command]
async fn read_music(
    state: State<'_, AppState>,
    scope: String,
    team_id: String,
    name: String,
) -> AppResult<Response> {
    let role = state.require_login()?;
    let dir = match MusicScope::parse(&scope)? {
        // Allgemeine Musik dürfen alle Angemeldeten abspielen.
        MusicScope::General => state.store.general_music_dir(),
        MusicScope::Team => {
            if let Role::Team(own) = &role {
                if own != &team_id {
                    return Err(err("err_forbidden"));
                }
            }
            state.store.team_music_dir(&team_id)?
        }
    };
    Ok(Response::new(Store::read_audio(&dir, &name)?))
}

#[tauri::command]
async fn read_effect(
    state: State<'_, AppState>,
    team_id: String,
    slot: usize,
) -> AppResult<Response> {
    state.require_team_access(&team_id)?;
    let config = state.store.load_ready()?;
    Ok(Response::new(
        state.store.read_effect(&config, &team_id, slot)?,
    ))
}

#[tauri::command]
fn read_logo(state: State<'_, AppState>) -> Response {
    let logo = state
        .store
        .load_ready()
        .ok()
        .and_then(|config| state.store.read_logo(&config));
    Response::new(logo.map(|(bytes, _)| bytes).unwrap_or_default())
}

// ---------------------------------------------------------------------------
// Einstellungen
// ---------------------------------------------------------------------------

#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
struct TeamSettings {
    id: String,
    name: String,
    has_password: bool,
}

#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
struct SettingsView {
    role: &'static str,
    version: String,
    app_name: String,
    language: String,
    admin_username: Option<String>,
    team_id: Option<String>,
    team_name: Option<String>,
    teams: Vec<TeamSettings>,
    hotkeys: BTreeMap<String, String>,
    general_music: usize,
    team_music: usize,
    has_logo: bool,
    data_dir: Option<String>,
}

#[tauri::command]
fn settings(app: AppHandle, state: State<'_, AppState>) -> AppResult<SettingsView> {
    let role = state.require_login()?;
    let config = state.store.load_ready()?;
    let version = app.package_info().version.to_string();
    Ok(match role {
        Role::Admin => {
            let (general_music, team_music) = state.store.count_music(&config);
            SettingsView {
                role: "admin",
                version,
                app_name: config.app_name.clone(),
                language: config.language.clone(),
                admin_username: Some(config.admin.username.clone()),
                team_id: None,
                team_name: None,
                teams: config
                    .teams
                    .iter()
                    .map(|t| TeamSettings {
                        id: t.id.clone(),
                        name: t.name.clone(),
                        has_password: t.password_hash.is_some(),
                    })
                    .collect(),
                hotkeys: config.hotkeys.clone(),
                general_music,
                team_music,
                has_logo: config.logo.is_some(),
                data_dir: Some(state.store.root().display().to_string()),
            }
        }
        Role::Team(id) => {
            let team = config.team(&id).ok_or(err("err_team_not_found"))?;
            SettingsView {
                role: "team",
                version,
                app_name: config.app_name.clone(),
                language: config.language.clone(),
                admin_username: None,
                team_id: Some(team.id.clone()),
                team_name: Some(team.name.clone()),
                teams: Vec::new(),
                hotkeys: BTreeMap::new(),
                general_music: 0,
                team_music: 0,
                has_logo: config.logo.is_some(),
                data_dir: None,
            }
        }
    })
}

#[tauri::command]
fn save_app_name(state: State<'_, AppState>, name: String) -> AppResult<String> {
    state.require_admin()?;
    let name = validate_app_name(&name)?;
    state.modify(|config| {
        config.app_name = name.clone();
        Ok(name)
    })
}

#[tauri::command]
fn save_language(state: State<'_, AppState>, language: String) -> AppResult<()> {
    state.require_admin()?;
    let language = validate_language(&language)?;
    state.modify(|config| {
        config.language = language;
        Ok(())
    })
}

#[tauri::command]
fn save_admin(
    state: State<'_, AppState>,
    current_password: String,
    username: String,
    new_password: String,
) -> AppResult<()> {
    state.require_admin()?;
    let username = validate_admin_username(&username)?;
    let new_password = new_password.trim().to_string();
    if !new_password.is_empty() && store::password_length(&new_password) < MIN_ADMIN_PASSWORD {
        return Err(err("err_admin_password_short"));
    }
    state.modify(|config| {
        if !verify_password(current_password.trim(), &config.admin.password_hash) {
            return Err(err("err_current_password"));
        }
        config.admin.username = username;
        if !new_password.is_empty() {
            config.admin.password_hash = hash_password(&new_password)?;
        }
        Ok(())
    })
}

#[tauri::command]
fn save_teams(state: State<'_, AppState>, teams: Vec<TeamInput>) -> AppResult<()> {
    state.require_admin()?;
    let _guard = lock(&state.write_lock);
    let mut config = state.store.load_ready()?;
    let removed = state.store.apply_teams(&mut config, &teams)?;
    state.store.ensure_dirs(&config)?;
    state.store.save(&config)?;
    state.store.remove_team_folders(&removed);
    Ok(())
}

#[tauri::command]
fn save_hotkeys(state: State<'_, AppState>, hotkeys: BTreeMap<String, String>) -> AppResult<()> {
    state.require_admin()?;
    let hotkeys = validate_hotkeys(&hotkeys)?;
    state.modify(|config| {
        config.hotkeys = hotkeys;
        Ok(())
    })
}

#[tauri::command]
fn list_music(
    state: State<'_, AppState>,
    scope: String,
    team_id: Option<String>,
) -> AppResult<Vec<AudioFile>> {
    let dir = state.music_dir_for(&scope, team_id.as_deref())?;
    Ok(Store::list_audio(&dir))
}

#[tauri::command]
async fn add_music(
    app: AppHandle,
    state: State<'_, AppState>,
    scope: String,
    team_id: Option<String>,
) -> AppResult<Option<store::AddResult>> {
    let dir = state.music_dir_for(&scope, team_id.as_deref())?;
    let language = current_language(&state);
    let Some(files) = pick_files(
        &app,
        dialog_text(&language, "music"),
        dialog_text(&language, "audio"),
        AUDIO_EXTENSIONS,
        true,
    ) else {
        return Ok(None);
    };
    // Rechte nach dem Dialog nochmals prüfen (Abmeldung während der Auswahl).
    let dir_again = state.music_dir_for(&scope, team_id.as_deref())?;
    if dir_again != dir {
        return Err(err("err_forbidden"));
    }
    Ok(Some(Store::add_audio_files(&dir, &files)?))
}

#[tauri::command]
fn delete_music(
    state: State<'_, AppState>,
    scope: String,
    team_id: Option<String>,
    name: String,
) -> AppResult<()> {
    let dir = state.music_dir_for(&scope, team_id.as_deref())?;
    Store::delete_audio(&dir, &name)
}

#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
struct EffectSlot {
    slot: usize,
    label: String,
    source: String,
    has_file: bool,
}

#[tauri::command]
fn effects(state: State<'_, AppState>, team_id: String) -> AppResult<Vec<EffectSlot>> {
    state.require_team_access(&team_id)?;
    let config = state.store.load_ready()?;
    let team = config.team(&team_id).ok_or(err("err_team_not_found"))?;
    Ok(team
        .effects
        .iter()
        .enumerate()
        .map(|(index, effect)| match effect {
            Some(e) => EffectSlot {
                slot: index + 1,
                label: e.label.clone(),
                source: e.source.clone(),
                has_file: true,
            },
            None => EffectSlot {
                slot: index + 1,
                label: String::new(),
                source: String::new(),
                has_file: false,
            },
        })
        .collect())
}

#[tauri::command]
async fn save_effect(
    app: AppHandle,
    state: State<'_, AppState>,
    team_id: String,
    slot: usize,
    label: String,
    pick_file: bool,
) -> AppResult<bool> {
    state.require_team_access(&team_id)?;
    store::validate_effect_label(&label)?;
    let source = if pick_file {
        let language = current_language(&state);
        match pick_files(
            &app,
            dialog_text(&language, "effect"),
            dialog_text(&language, "audio"),
            AUDIO_EXTENSIONS,
            false,
        ) {
            Some(files) => files.into_iter().next(),
            None => return Ok(false),
        }
    } else {
        None
    };
    state.require_team_access(&team_id)?;
    state.modify(|config| {
        state
            .store
            .set_effect(config, &team_id, slot, &label, source.as_deref())
    })?;
    Ok(true)
}

#[tauri::command]
fn remove_effect(state: State<'_, AppState>, team_id: String, slot: usize) -> AppResult<()> {
    state.require_team_access(&team_id)?;
    state.modify(|config| state.store.remove_effect(config, &team_id, slot))
}

#[tauri::command]
async fn pick_logo(app: AppHandle, state: State<'_, AppState>) -> AppResult<bool> {
    state.require_admin()?;
    let language = current_language(&state);
    let Some(files) = pick_files(
        &app,
        dialog_text(&language, "logo"),
        dialog_text(&language, "image"),
        &["png", "jpg", "jpeg"],
        false,
    ) else {
        return Ok(false);
    };
    state.require_admin()?;
    state.modify(|config| state.store.set_logo(config, &files[0]))?;
    Ok(true)
}

#[tauri::command]
fn remove_logo(state: State<'_, AppState>) -> AppResult<()> {
    state.require_admin()?;
    state.modify(|config| {
        state.store.remove_logo(config);
        Ok(())
    })
}

#[tauri::command]
async fn export_backup(app: AppHandle, state: State<'_, AppState>) -> AppResult<Option<String>> {
    state.require_admin()?;
    let language = current_language(&state);
    let file_name = format!("ArenaFX-Backup-{}.zip", today());
    let Some(mut target) = save_file(&app, dialog_text(&language, "backup_save"), &file_name)
    else {
        return Ok(None);
    };
    if target
        .extension()
        .map(|e| !e.eq_ignore_ascii_case("zip"))
        .unwrap_or(true)
    {
        target.set_extension("zip");
    }
    state.require_admin()?;
    let _guard = lock(&state.write_lock);
    let config = state.store.load_ready()?;
    backup::export(
        &state.store,
        &config,
        &target,
        &app.package_info().version.to_string(),
    )?;
    Ok(Some(target.display().to_string()))
}

#[tauri::command]
async fn import_backup(app: AppHandle, state: State<'_, AppState>) -> AppResult<bool> {
    state.require_admin()?;
    let language = current_language(&state);
    let Some(files) = pick_files(
        &app,
        dialog_text(&language, "backup_open"),
        "ZIP",
        &["zip"],
        false,
    ) else {
        return Ok(false);
    };
    state.require_admin()?;
    let _guard = lock(&state.write_lock);
    backup::import(&state.store, &files[0])?;
    state.set_role(None);
    Ok(true)
}

#[tauri::command]
fn open_data_folder(app: AppHandle, state: State<'_, AppState>) -> AppResult<()> {
    if !matches!(state.store.load(), ConfigState::Broken) {
        state.require_admin()?;
    }
    use tauri_plugin_opener::OpenerExt;
    app.opener()
        .open_path(state.store.root().display().to_string(), None::<&str>)
        .map_err(|e| err_detail("err_io", e))
}

// ---------------------------------------------------------------------------
// Updates
// ---------------------------------------------------------------------------

#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
struct UpdateInfo {
    current_version: String,
    version: String,
    notes: String,
}

/// Fragt GitHub nach einer neueren Version. Gibt es noch kein Release mit Update-Datei
/// (z. B. vor der ersten Veröffentlichung), ist das kein Fehler, sondern "kein Update".
async fn check_for_update(
    updater: &tauri_plugin_updater::Updater,
) -> AppResult<Option<tauri_plugin_updater::Update>> {
    match updater.check().await {
        Ok(update) => Ok(update),
        Err(tauri_plugin_updater::Error::ReleaseNotFound) => Ok(None),
        Err(error) => Err(err_detail("err_update_check", error)),
    }
}

#[tauri::command]
async fn check_update(app: AppHandle, state: State<'_, AppState>) -> AppResult<Option<UpdateInfo>> {
    state.require_admin()?;
    use tauri_plugin_updater::UpdaterExt;
    let updater = app
        .updater()
        .map_err(|e| err_detail("err_update_check", e))?;
    let update = check_for_update(&updater).await?;
    Ok(update.map(|update| UpdateInfo {
        current_version: update.current_version.clone(),
        version: update.version.clone(),
        notes: update.body.clone().unwrap_or_default(),
    }))
}

#[tauri::command]
async fn install_update(app: AppHandle, state: State<'_, AppState>) -> AppResult<()> {
    state.require_admin()?;
    use tauri_plugin_updater::UpdaterExt;
    let updater = app
        .updater()
        .map_err(|e| err_detail("err_update_check", e))?;
    let update = check_for_update(&updater)
        .await?
        .ok_or(err("err_no_update"))?;
    update
        .download_and_install(|_, _| {}, || {})
        .await
        .map_err(|e| err_detail("err_update_install", e))?;
    app.restart();
}

// ---------------------------------------------------------------------------

fn data_dir(app: &AppHandle) -> PathBuf {
    #[cfg(debug_assertions)]
    if let Some(dir) = std::env::var_os("ARENAFX_DATA_DIR") {
        return PathBuf::from(dir);
    }
    app.path()
        .app_data_dir()
        .expect("Datenordner nicht verfügbar")
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    let mut builder = tauri::Builder::default();
    #[cfg(desktop)]
    {
        builder = builder.plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| {
            if let Some(window) = app.get_webview_window("main") {
                let _ = window.unminimize();
                let _ = window.set_focus();
            }
        }));
    }
    builder
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_updater::Builder::new().build())
        .setup(|app| {
            let dir = data_dir(app.handle());
            std::fs::create_dir_all(&dir)?;
            app.manage(AppState::new(dir));
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            app_status,
            setup,
            pick_legacy_folder,
            setup_from_legacy,
            import_legacy,
            login,
            logout,
            soundbox,
            read_music,
            read_effect,
            read_logo,
            settings,
            save_app_name,
            save_language,
            save_admin,
            save_teams,
            save_hotkeys,
            list_music,
            add_music,
            delete_music,
            effects,
            save_effect,
            remove_effect,
            pick_logo,
            remove_logo,
            export_backup,
            import_backup,
            open_data_folder,
            check_update,
            install_update,
        ])
        .run(tauri::generate_context!())
        .expect("ArenaFX konnte nicht gestartet werden");
}
