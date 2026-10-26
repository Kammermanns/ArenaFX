//! Datenhaltung: Konfiguration, Passwörter und Mediendateien.
//!
//! Alles hier ist unabhängig von Tauri und wird mit `cargo test` geprüft.
//! Aufbau des Datenordners (unter Windows `%APPDATA%\ch.kammermann.arenafx`):
//!
//! ```text
//! config.json            Konfiguration (Passwörter nur als Hash)
//! config.json.bak        vorherige, gültige Fassung
//! media/logo.png|jpg     Vereinslogo
//! media/allgemein/musik  Musik für alle Teams
//! media/teams/<id>/musik
//! media/teams/<id>/effekte/<slot>.<ext>
//! ```

use std::collections::{BTreeMap, HashSet};
use std::fs;
use std::io::{Read, Write};
use std::path::{Path, PathBuf};

use argon2::password_hash::rand_core::OsRng;
use argon2::password_hash::{PasswordHash, PasswordHasher, PasswordVerifier, SaltString};
use argon2::Argon2;
use rand::Rng;
use serde::{Deserialize, Serialize};

use crate::error::{err, err_detail, AppResult};

pub const SCHEMA_VERSION: u32 = 2;
pub const EFFECT_SLOTS: usize = 6;
pub const AUDIO_EXTENSIONS: &[&str] = &["mp3", "wav", "ogg", "m4a", "flac"];
pub const MAX_AUDIO_BYTES: u64 = 150 * 1024 * 1024;
pub const MAX_LOGO_BYTES: u64 = 10 * 1024 * 1024;
pub const LANGUAGES: &[&str] = &["de", "en", "fr"];
pub const HOTKEY_ACTIONS: &[&str] = &[
    "music",
    "durchsage",
    "effect_1",
    "effect_2",
    "effect_3",
    "effect_4",
    "effect_5",
    "effect_6",
];
pub const MIN_ADMIN_PASSWORD: usize = 8;
pub const MIN_TEAM_PASSWORD: usize = 4;

// ---------------------------------------------------------------------------
// Konfiguration
// ---------------------------------------------------------------------------

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct Config {
    #[serde(default = "schema_version")]
    pub schema: u32,
    pub app_name: String,
    #[serde(default = "default_language")]
    pub language: String,
    pub admin: Admin,
    #[serde(default)]
    pub teams: Vec<Team>,
    #[serde(default = "default_hotkeys")]
    pub hotkeys: BTreeMap<String, String>,
    #[serde(default)]
    pub logo: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct Admin {
    pub username: String,
    pub password_hash: String,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct Team {
    pub id: String,
    pub name: String,
    /// `None` = kein Passwort gesetzt, Anmeldung für dieses Team gesperrt.
    #[serde(default)]
    pub password_hash: Option<String>,
    #[serde(default)]
    pub effects: Vec<Option<Effect>>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct Effect {
    pub label: String,
    /// Dateiname im Effektordner, z. B. `3.mp3`.
    pub file: String,
    /// Ursprünglicher Dateiname, nur zur Anzeige.
    #[serde(default)]
    pub source: String,
}

fn schema_version() -> u32 {
    SCHEMA_VERSION
}

fn default_language() -> String {
    "de".into()
}

pub fn default_hotkeys() -> BTreeMap<String, String> {
    let mut map = BTreeMap::new();
    map.insert("music".into(), "numadd".into());
    map.insert("durchsage".into(), "num0".into());
    for slot in 1..=EFFECT_SLOTS {
        map.insert(format!("effect_{slot}"), format!("num{slot}"));
    }
    map
}

impl Config {
    pub fn team(&self, id: &str) -> Option<&Team> {
        self.teams.iter().find(|team| team.id == id)
    }

    pub fn team_mut(&mut self, id: &str) -> Option<&mut Team> {
        self.teams.iter_mut().find(|team| team.id == id)
    }

    /// Ergänzt fehlende Werte und prüft, ob die Datei in sich stimmig ist.
    fn normalize_and_validate(mut self) -> Option<Config> {
        if self.admin.username.trim().is_empty() || self.admin.password_hash.is_empty() {
            return None;
        }
        if !LANGUAGES.contains(&self.language.as_str()) {
            self.language = default_language();
        }
        let mut ids = HashSet::new();
        for team in &mut self.teams {
            if !is_valid_team_id(&team.id)
                || !ids.insert(team.id.clone())
                || team.name.trim().is_empty()
            {
                return None;
            }
            team.effects.resize(EFFECT_SLOTS, None);
            team.effects.truncate(EFFECT_SLOTS);
        }
        let mut hotkeys = default_hotkeys();
        for action in HOTKEY_ACTIONS {
            if let Some(value) = self.hotkeys.get(*action) {
                hotkeys.insert((*action).into(), value.clone());
            }
        }
        self.hotkeys = hotkeys;
        if let Some(logo) = &self.logo {
            if logo != "logo.png" && logo != "logo.jpg" {
                self.logo = None;
            }
        }
        self.schema = SCHEMA_VERSION;
        Some(self)
    }
}

/// Liest und prüft eine Konfiguration (auch aus einem Backup).
pub fn parse_config(bytes: &[u8]) -> Option<Config> {
    let config: Config = serde_json::from_slice(bytes).ok()?;
    config.normalize_and_validate()
}

pub enum ConfigState {
    /// Noch nicht eingerichtet.
    Missing,
    /// `recovered` = config.json war defekt, die Sicherung config.json.bak wurde verwendet.
    Ready { config: Config, recovered: bool },
    /// Weder config.json noch die Sicherung sind lesbar.
    Broken,
}

// ---------------------------------------------------------------------------
// Passwörter
// ---------------------------------------------------------------------------

pub fn hash_password(password: &str) -> AppResult<String> {
    let salt = SaltString::generate(&mut OsRng);
    Argon2::default()
        .hash_password(password.as_bytes(), &salt)
        .map(|hash| hash.to_string())
        .map_err(|error| err_detail("err_io", error))
}

/// Prüft ein Passwort gegen einen Argon2-Hash oder einen bcrypt-Hash aus der PHP-Version.
pub fn verify_password(password: &str, hash: &str) -> bool {
    if hash.starts_with("$argon2") {
        return PasswordHash::new(hash)
            .map(|parsed| {
                Argon2::default()
                    .verify_password(password.as_bytes(), &parsed)
                    .is_ok()
            })
            .unwrap_or(false);
    }
    if hash.starts_with("$2") {
        return bcrypt::verify(password, hash).unwrap_or(false);
    }
    false
}

pub fn needs_rehash(hash: &str) -> bool {
    !hash.starts_with("$argon2id$")
}

pub fn password_length(password: &str) -> usize {
    password.chars().count()
}

// ---------------------------------------------------------------------------
// Namen und Dateien prüfen
// ---------------------------------------------------------------------------

pub fn is_valid_team_id(id: &str) -> bool {
    !id.is_empty()
        && id.len() <= 64
        && id
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b == b'-' || b == b'_')
}

pub fn new_team_id() -> String {
    let value: u32 = rand::thread_rng().gen();
    format!("t{value:08x}")
}

const WINDOWS_FORBIDDEN: &str = "<>:\"/\\|?*";
const WINDOWS_RESERVED: &[&str] = &[
    "CON", "PRN", "AUX", "NUL", "COM1", "COM2", "COM3", "COM4", "COM5", "COM6", "COM7", "COM8",
    "COM9", "LPT1", "LPT2", "LPT3", "LPT4", "LPT5", "LPT6", "LPT7", "LPT8", "LPT9",
];

pub fn audio_extension(name: &str) -> Option<String> {
    let extension = Path::new(name).extension()?.to_str()?.to_ascii_lowercase();
    AUDIO_EXTENSIONS
        .contains(&extension.as_str())
        .then_some(extension)
}

/// Ein Dateiname, wie er im Musikordner liegen darf (Windows-tauglich, ohne Pfadanteile).
pub fn is_valid_audio_name(name: &str) -> bool {
    let length = name.chars().count();
    if length == 0
        || length > 180
        || name.starts_with('.')
        || name.ends_with('.')
        || name.ends_with(' ')
    {
        return false;
    }
    if name
        .chars()
        .any(|c| c.is_control() || WINDOWS_FORBIDDEN.contains(c))
    {
        return false;
    }
    let stem = name
        .split('.')
        .next()
        .unwrap_or("")
        .trim()
        .to_ascii_uppercase();
    if WINDOWS_RESERVED.contains(&stem.as_str()) {
        return false;
    }
    audio_extension(name).is_some()
}

/// Macht aus einem beliebigen Dateinamen einen gültigen (unzulässige Zeichen werden zu `_`).
pub fn sanitize_audio_name(name: &str) -> Option<String> {
    let extension = audio_extension(name)?;
    let stem = &name[..name.len() - extension.len() - 1];
    let mut clean: String = stem
        .chars()
        .map(|c| {
            if c.is_control() || WINDOWS_FORBIDDEN.contains(c) {
                '_'
            } else {
                c
            }
        })
        .collect();
    clean = clean.trim().trim_matches('.').trim().to_string();
    if clean.is_empty() {
        clean = "Audio".into();
    }
    if clean.chars().count() > 160 {
        clean = clean
            .chars()
            .take(160)
            .collect::<String>()
            .trim_end()
            .to_string();
    }
    let upper = clean.to_ascii_uppercase();
    if WINDOWS_RESERVED.contains(&upper.as_str()) {
        clean.push('_');
    }
    let result = format!("{clean}.{extension}");
    is_valid_audio_name(&result).then_some(result)
}

/// Prüft die ersten Bytes einer Datei, damit keine beliebigen Dateien als Musik landen.
pub fn looks_like_audio(path: &Path) -> bool {
    let mut header = [0u8; 12];
    let Ok(mut file) = fs::File::open(path) else {
        return false;
    };
    let Ok(read) = file.read(&mut header) else {
        return false;
    };
    let header = &header[..read];
    header.starts_with(b"ID3")
        || (header.len() >= 2 && header[0] == 0xFF && header[1] & 0xE0 == 0xE0)
        || (header.starts_with(b"RIFF") && header.len() >= 12 && &header[8..12] == b"WAVE")
        || header.starts_with(b"OggS")
        || header.starts_with(b"fLaC")
        || (header.len() >= 8 && &header[4..8] == b"ftyp")
}

pub fn logo_kind(path: &Path) -> Option<&'static str> {
    let mut header = [0u8; 8];
    let mut file = fs::File::open(path).ok()?;
    let read = file.read(&mut header).ok()?;
    if read >= 8 && header == [0x89, b'P', b'N', b'G', 0x0D, 0x0A, 0x1A, 0x0A] {
        Some("png")
    } else if read >= 3 && header[..3] == [0xFF, 0xD8, 0xFF] {
        Some("jpg")
    } else {
        None
    }
}

fn is_regular_file(path: &Path) -> bool {
    fs::symlink_metadata(path)
        .map(|meta| meta.file_type().is_file())
        .unwrap_or(false)
}

/// Kopiert über eine temporäre Datei, damit nie eine halbe Datei liegen bleibt.
pub fn copy_atomic(source: &Path, target: &Path) -> AppResult<()> {
    let temporary = target.with_extension("arenafx-tmp");
    let result = fs::copy(source, &temporary).and_then(|_| fs::rename(&temporary, target));
    if result.is_err() {
        let _ = fs::remove_file(&temporary);
    }
    result.map_err(Into::into)
}

pub fn write_atomic(target: &Path, contents: &[u8]) -> AppResult<()> {
    let temporary = target.with_extension("arenafx-tmp");
    let result = (|| -> std::io::Result<()> {
        let mut file = fs::File::create(&temporary)?;
        file.write_all(contents)?;
        file.sync_all()?;
        drop(file);
        fs::rename(&temporary, target)
    })();
    if result.is_err() {
        let _ = fs::remove_file(&temporary);
    }
    result.map_err(Into::into)
}

#[derive(Debug, Clone, Serialize, PartialEq)]
pub struct AudioFile {
    pub name: String,
    pub size: u64,
}

#[derive(Debug, Default, Serialize)]
pub struct AddResult {
    pub added: Vec<String>,
    pub skipped: Vec<Skipped>,
}

#[derive(Debug, Serialize)]
pub struct Skipped {
    pub name: String,
    pub reason: &'static str,
}

#[derive(Debug, Clone, Copy, PartialEq)]
pub enum MusicScope {
    General,
    Team,
}

impl MusicScope {
    pub fn parse(value: &str) -> AppResult<Self> {
        match value {
            "general" => Ok(MusicScope::General),
            "team" => Ok(MusicScope::Team),
            _ => Err(err("err_invalid_request")),
        }
    }
}

// ---------------------------------------------------------------------------
// Datenordner
// ---------------------------------------------------------------------------

pub struct Store {
    root: PathBuf,
}

impl Store {
    pub fn new(root: PathBuf) -> Self {
        Store { root }
    }

    pub fn root(&self) -> &Path {
        &self.root
    }

    pub fn config_path(&self) -> PathBuf {
        self.root.join("config.json")
    }

    fn backup_config_path(&self) -> PathBuf {
        self.root.join("config.json.bak")
    }

    pub fn media_dir(&self) -> PathBuf {
        self.root.join("media")
    }

    pub fn general_music_dir(&self) -> PathBuf {
        self.media_dir().join("allgemein").join("musik")
    }

    pub fn team_dir(&self, team_id: &str) -> AppResult<PathBuf> {
        if !is_valid_team_id(team_id) {
            return Err(err("err_team_not_found"));
        }
        Ok(self.media_dir().join("teams").join(team_id))
    }

    pub fn team_music_dir(&self, team_id: &str) -> AppResult<PathBuf> {
        Ok(self.team_dir(team_id)?.join("musik"))
    }

    pub fn team_effects_dir(&self, team_id: &str) -> AppResult<PathBuf> {
        Ok(self.team_dir(team_id)?.join("effekte"))
    }

    pub fn music_dir(&self, scope: MusicScope, team_id: Option<&str>) -> AppResult<PathBuf> {
        match scope {
            MusicScope::General => Ok(self.general_music_dir()),
            MusicScope::Team => self.team_music_dir(team_id.ok_or(err("err_team_not_found"))?),
        }
    }

    fn read_config_file(path: &Path) -> Option<Config> {
        parse_config(&fs::read(path).ok()?)
    }

    pub fn load(&self) -> ConfigState {
        let main = self.config_path();
        if let Some(config) = Self::read_config_file(&main) {
            return ConfigState::Ready {
                config,
                recovered: false,
            };
        }
        let backup = self.backup_config_path();
        if let Some(config) = Self::read_config_file(&backup) {
            return ConfigState::Ready {
                config,
                recovered: true,
            };
        }
        if !main.exists() && !backup.exists() {
            return ConfigState::Missing;
        }
        ConfigState::Broken
    }

    pub fn load_ready(&self) -> AppResult<Config> {
        match self.load() {
            ConfigState::Ready { config, .. } => Ok(config),
            ConfigState::Missing => Err(err("err_setup_required")),
            ConfigState::Broken => Err(err("err_config_broken")),
        }
    }

    /// Speichert sicher: neue Datei schreiben, die bisherige gültige Fassung als .bak behalten, dann ersetzen.
    pub fn save(&self, config: &Config) -> AppResult<()> {
        fs::create_dir_all(&self.root)?;
        let encoded = serde_json::to_vec_pretty(config)?;
        let main = self.config_path();
        if Self::read_config_file(&main).is_some() {
            fs::copy(&main, self.backup_config_path())?;
        }
        write_atomic(&main, &encoded)
    }

    pub fn ensure_dirs(&self, config: &Config) -> AppResult<()> {
        fs::create_dir_all(self.general_music_dir())?;
        for team in &config.teams {
            fs::create_dir_all(self.team_music_dir(&team.id)?)?;
            fs::create_dir_all(self.team_effects_dir(&team.id)?)?;
        }
        Ok(())
    }

    // --- Einrichtung -------------------------------------------------------

    pub fn create_initial(&self, input: &SetupInput) -> AppResult<Config> {
        if !matches!(self.load(), ConfigState::Missing) {
            return Err(err("err_already_setup"));
        }
        let admin = validated_admin(&input.admin_username, &input.admin_password)?;
        let app_name = validate_app_name(&input.app_name)?;
        let team_name = validate_team_name(&input.team_name)?;
        if password_length(input.team_password.trim()) < MIN_TEAM_PASSWORD {
            return Err(err("err_team_password_short"));
        }
        let config = Config {
            schema: SCHEMA_VERSION,
            app_name,
            language: validate_language(&input.language)?,
            admin,
            teams: vec![Team {
                id: new_team_id(),
                name: team_name,
                password_hash: Some(hash_password(input.team_password.trim())?),
                effects: vec![None; EFFECT_SLOTS],
            }],
            hotkeys: default_hotkeys(),
            logo: None,
        };
        self.ensure_dirs(&config)?;
        self.save(&config)?;
        Ok(config)
    }

    // --- Musik -------------------------------------------------------------

    pub fn list_audio(dir: &Path) -> Vec<AudioFile> {
        let Ok(entries) = fs::read_dir(dir) else {
            return Vec::new();
        };
        let mut files: Vec<AudioFile> = entries
            .filter_map(Result::ok)
            .filter_map(|entry| {
                let name = entry.file_name().to_str()?.to_string();
                let meta = fs::symlink_metadata(entry.path()).ok()?;
                let size = meta.len();
                (meta.file_type().is_file() && is_valid_audio_name(&name))
                    .then_some(AudioFile { name, size })
            })
            .collect();
        files.sort_by_key(|file| file.name.to_lowercase());
        files
    }

    fn unique_name(dir: &Path, name: &str) -> String {
        if !dir.join(name).exists() {
            return name.to_string();
        }
        let extension = audio_extension(name).unwrap_or_default();
        let stem = &name[..name.len() - extension.len() - 1];
        (2..)
            .map(|n| format!("{stem} ({n}).{extension}"))
            .find(|candidate| !dir.join(candidate).exists())
            .expect("endlose Folge")
    }

    pub fn add_audio_files(dir: &Path, sources: &[PathBuf]) -> AppResult<AddResult> {
        fs::create_dir_all(dir)?;
        let mut result = AddResult::default();
        for source in sources {
            let original = source
                .file_name()
                .map(|n| n.to_string_lossy().to_string())
                .unwrap_or_default();
            let skip = |reason| Skipped {
                name: original.clone(),
                reason,
            };
            let Some(clean) = sanitize_audio_name(&original) else {
                result.skipped.push(skip("err_unsupported_format"));
                continue;
            };
            match fs::metadata(source) {
                Ok(meta) if meta.is_file() && meta.len() <= MAX_AUDIO_BYTES => {}
                Ok(meta) if meta.is_file() => {
                    result.skipped.push(skip("err_file_too_large"));
                    continue;
                }
                _ => {
                    result.skipped.push(skip("err_file_unreadable"));
                    continue;
                }
            }
            if !looks_like_audio(source) {
                result.skipped.push(skip("err_not_audio"));
                continue;
            }
            let name = Self::unique_name(dir, &clean);
            match copy_atomic(source, &dir.join(&name)) {
                Ok(()) => result.added.push(name),
                Err(_) => result.skipped.push(skip("err_file_unreadable")),
            }
        }
        Ok(result)
    }

    pub fn delete_audio(dir: &Path, name: &str) -> AppResult<()> {
        if !is_valid_audio_name(name) {
            return Err(err("err_invalid_file_name"));
        }
        let path = dir.join(name);
        if !is_regular_file(&path) {
            return Err(err("err_file_not_found"));
        }
        fs::remove_file(path)?;
        Ok(())
    }

    pub fn read_audio(dir: &Path, name: &str) -> AppResult<Vec<u8>> {
        if !is_valid_audio_name(name) {
            return Err(err("err_invalid_file_name"));
        }
        let path = dir.join(name);
        let meta = fs::symlink_metadata(&path).map_err(|_| err("err_file_not_found"))?;
        if !meta.file_type().is_file() {
            return Err(err("err_file_not_found"));
        }
        if meta.len() > MAX_AUDIO_BYTES {
            return Err(err("err_file_too_large"));
        }
        Ok(fs::read(path)?)
    }

    // --- Effekte -----------------------------------------------------------

    pub fn set_effect(
        &self,
        config: &mut Config,
        team_id: &str,
        slot: usize,
        label: &str,
        source: Option<&Path>,
    ) -> AppResult<()> {
        let label = validate_effect_label(label)?;
        if !(1..=EFFECT_SLOTS).contains(&slot) {
            return Err(err("err_invalid_request"));
        }
        let effects_dir = self.team_effects_dir(team_id)?;
        let team = config.team_mut(team_id).ok_or(err("err_team_not_found"))?;
        let index = slot - 1;
        match source {
            Some(source) => {
                let original = source
                    .file_name()
                    .map(|n| n.to_string_lossy().to_string())
                    .unwrap_or_default();
                let extension = audio_extension(&original).ok_or(err("err_unsupported_format"))?;
                let meta = fs::metadata(source).map_err(|_| err("err_file_unreadable"))?;
                if !meta.is_file() {
                    return Err(err("err_file_unreadable"));
                }
                if meta.len() > MAX_AUDIO_BYTES {
                    return Err(err("err_file_too_large"));
                }
                if !looks_like_audio(source) {
                    return Err(err("err_not_audio"));
                }
                fs::create_dir_all(&effects_dir)?;
                let file = format!("{slot}.{extension}");
                copy_atomic(source, &effects_dir.join(&file))?;
                if let Some(Some(old)) = team.effects.get(index) {
                    if old.file != file {
                        let _ = fs::remove_file(effects_dir.join(&old.file));
                    }
                }
                team.effects[index] = Some(Effect {
                    label,
                    file,
                    source: original,
                });
            }
            None => match team.effects.get_mut(index) {
                Some(Some(effect)) => effect.label = label,
                _ => return Err(err("err_effect_needs_file")),
            },
        }
        Ok(())
    }

    pub fn remove_effect(&self, config: &mut Config, team_id: &str, slot: usize) -> AppResult<()> {
        if !(1..=EFFECT_SLOTS).contains(&slot) {
            return Err(err("err_invalid_request"));
        }
        let effects_dir = self.team_effects_dir(team_id)?;
        let team = config.team_mut(team_id).ok_or(err("err_team_not_found"))?;
        if let Some(effect) = team.effects[slot - 1].take() {
            let _ = fs::remove_file(effects_dir.join(effect.file));
        }
        Ok(())
    }

    pub fn read_effect(&self, config: &Config, team_id: &str, slot: usize) -> AppResult<Vec<u8>> {
        let team = config.team(team_id).ok_or(err("err_team_not_found"))?;
        let effect = team
            .effects
            .get(slot.wrapping_sub(1))
            .and_then(Option::as_ref)
            .ok_or(err("err_file_not_found"))?;
        let path = self.team_effects_dir(team_id)?.join(&effect.file);
        if !is_regular_file(&path) {
            return Err(err("err_file_not_found"));
        }
        Ok(fs::read(path)?)
    }

    // --- Logo --------------------------------------------------------------

    pub fn set_logo(&self, config: &mut Config, source: &Path) -> AppResult<()> {
        let meta = fs::metadata(source).map_err(|_| err("err_file_unreadable"))?;
        if !meta.is_file() {
            return Err(err("err_file_unreadable"));
        }
        if meta.len() > MAX_LOGO_BYTES {
            return Err(err("err_file_too_large"));
        }
        let kind = logo_kind(source).ok_or(err("err_not_image"))?;
        let media = self.media_dir();
        fs::create_dir_all(&media)?;
        let file = format!("logo.{kind}");
        copy_atomic(source, &media.join(&file))?;
        for other in ["logo.png", "logo.jpg"] {
            if other != file {
                let _ = fs::remove_file(media.join(other));
            }
        }
        config.logo = Some(file);
        Ok(())
    }

    pub fn remove_logo(&self, config: &mut Config) {
        if let Some(file) = config.logo.take() {
            let _ = fs::remove_file(self.media_dir().join(file));
        }
    }

    pub fn read_logo(&self, config: &Config) -> Option<(Vec<u8>, &'static str)> {
        let file = config.logo.as_ref()?;
        let path = self.media_dir().join(file);
        if !is_regular_file(&path) || fs::metadata(&path).ok()?.len() > MAX_LOGO_BYTES {
            return None;
        }
        let mime = if file.ends_with(".png") {
            "image/png"
        } else {
            "image/jpeg"
        };
        Some((fs::read(path).ok()?, mime))
    }

    // --- Teams -------------------------------------------------------------

    /// Übernimmt die Teamliste aus den Einstellungen. Gibt die IDs entfernter Teams zurück.
    pub fn apply_teams(&self, config: &mut Config, input: &[TeamInput]) -> AppResult<Vec<String>> {
        if input.is_empty() {
            return Err(err("err_team_required"));
        }
        let mut names = HashSet::new();
        let mut seen_ids = HashSet::new();
        let mut teams = Vec::with_capacity(input.len());
        for entry in input {
            let name = validate_team_name(&entry.name)?;
            if !names.insert(name.to_lowercase()) {
                return Err(err_detail("err_team_name_duplicate", name));
            }
            let password = entry
                .password
                .as_deref()
                .map(str::trim)
                .filter(|p| !p.is_empty());
            if let Some(password) = password {
                if password_length(password) < MIN_TEAM_PASSWORD {
                    return Err(err_detail("err_team_password_short", name));
                }
            }
            let team = match entry.id.as_deref().filter(|id| !id.is_empty()) {
                Some(id) => {
                    let existing = config.team(id).ok_or(err("err_team_not_found"))?;
                    if !seen_ids.insert(id.to_string()) {
                        return Err(err("err_invalid_request"));
                    }
                    let password_hash = match password {
                        Some(password) => Some(hash_password(password)?),
                        None => existing.password_hash.clone(),
                    };
                    Team {
                        name,
                        password_hash,
                        ..existing.clone()
                    }
                }
                None => {
                    let password =
                        password.ok_or_else(|| err_detail("err_team_password_required", &name))?;
                    let mut id = new_team_id();
                    while config.team(&id).is_some() || seen_ids.contains(&id) {
                        id = new_team_id();
                    }
                    seen_ids.insert(id.clone());
                    Team {
                        id,
                        name,
                        password_hash: Some(hash_password(password)?),
                        effects: vec![None; EFFECT_SLOTS],
                    }
                }
            };
            teams.push(team);
        }
        let removed: Vec<String> = config
            .teams
            .iter()
            .filter(|team| !seen_ids.contains(&team.id))
            .map(|team| team.id.clone())
            .collect();
        config.teams = teams;
        Ok(removed)
    }

    pub fn remove_team_folders(&self, team_ids: &[String]) {
        for id in team_ids {
            if let Ok(dir) = self.team_dir(id) {
                let _ = fs::remove_dir_all(dir);
            }
        }
    }

    pub fn count_music(&self, config: &Config) -> (usize, usize) {
        let general = Self::list_audio(&self.general_music_dir()).len();
        let team = config
            .teams
            .iter()
            .filter_map(|team| self.team_music_dir(&team.id).ok())
            .map(|dir| Self::list_audio(&dir).len())
            .sum();
        (general, team)
    }
}

// ---------------------------------------------------------------------------
// Eingaben prüfen
// ---------------------------------------------------------------------------

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SetupInput {
    pub admin_username: String,
    pub admin_password: String,
    pub app_name: String,
    pub language: String,
    pub team_name: String,
    pub team_password: String,
}

#[derive(Debug, Deserialize)]
pub struct TeamInput {
    pub id: Option<String>,
    pub name: String,
    pub password: Option<String>,
}

fn has_control_chars(value: &str) -> bool {
    value.chars().any(char::is_control)
}

pub fn validate_admin_username(username: &str) -> AppResult<String> {
    let username = username.trim();
    let length = username.chars().count();
    if !(3..=50).contains(&length) || has_control_chars(username) {
        return Err(err("err_admin_username"));
    }
    Ok(username.to_string())
}

pub fn validated_admin(username: &str, password: &str) -> AppResult<Admin> {
    let username = validate_admin_username(username)?;
    let password = password.trim();
    if password_length(password) < MIN_ADMIN_PASSWORD {
        return Err(err("err_admin_password_short"));
    }
    if password.len() > 1024 {
        return Err(err("err_invalid_request"));
    }
    Ok(Admin {
        username,
        password_hash: hash_password(password)?,
    })
}

pub fn validate_app_name(name: &str) -> AppResult<String> {
    let name = name.trim();
    let length = name.chars().count();
    if length == 0 || length > 80 || has_control_chars(name) {
        return Err(err("err_app_name"));
    }
    Ok(name.to_string())
}

pub fn validate_team_name(name: &str) -> AppResult<String> {
    let name = name.trim();
    let length = name.chars().count();
    if length == 0 || length > 60 || has_control_chars(name) {
        return Err(err("err_team_name"));
    }
    Ok(name.to_string())
}

pub fn validate_language(language: &str) -> AppResult<String> {
    LANGUAGES
        .contains(&language)
        .then(|| language.to_string())
        .ok_or(err("err_invalid_request"))
}

pub fn validate_effect_label(label: &str) -> AppResult<String> {
    let label = label.trim();
    let length = label.chars().count();
    if length == 0 || length > 40 || has_control_chars(label) {
        return Err(err("err_effect_label"));
    }
    Ok(label.to_string())
}

pub fn validate_hotkeys(input: &BTreeMap<String, String>) -> AppResult<BTreeMap<String, String>> {
    let mut result = BTreeMap::new();
    let mut used = HashSet::new();
    for action in HOTKEY_ACTIONS {
        let value = input
            .get(*action)
            .map(|v| v.trim().to_string())
            .unwrap_or_default();
        if value.chars().count() > 20 || has_control_chars(&value) {
            return Err(err("err_invalid_request"));
        }
        if !value.is_empty() && !used.insert(value.to_lowercase()) {
            return Err(err_detail("err_hotkey_duplicate", value));
        }
        result.insert((*action).to_string(), value);
    }
    Ok(result)
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

#[cfg(test)]
pub(crate) mod tests {
    use super::*;

    pub fn wav_bytes() -> Vec<u8> {
        let mut data = b"RIFF\x24\x00\x00\x00WAVEfmt ".to_vec();
        data.extend_from_slice(&[
            16, 0, 0, 0, 1, 0, 1, 0, 0x40, 0x1F, 0, 0, 0x80, 0x3E, 0, 0, 2, 0, 16, 0,
        ]);
        data.extend_from_slice(b"data\x00\x00\x00\x00");
        data
    }

    pub fn png_bytes() -> Vec<u8> {
        vec![0x89, b'P', b'N', b'G', 0x0D, 0x0A, 0x1A, 0x0A, 0, 0, 0, 0]
    }

    pub fn setup_input() -> SetupInput {
        SetupInput {
            admin_username: "stefan".into(),
            admin_password: "SicheresPasswort".into(),
            app_name: "EHC Test".into(),
            language: "de".into(),
            team_name: "EHC Zürich".into(),
            team_password: "team1".into(),
        }
    }

    pub fn ready_store() -> (tempfile::TempDir, Store, Config) {
        let dir = tempfile::tempdir().unwrap();
        let store = Store::new(dir.path().join("data"));
        let config = store.create_initial(&setup_input()).unwrap();
        (dir, store, config)
    }

    #[test]
    fn setup_creates_hashed_config_and_folders() {
        let (_dir, store, config) = ready_store();
        assert!(config.admin.password_hash.starts_with("$argon2id$"));
        assert!(verify_password(
            "SicheresPasswort",
            &config.admin.password_hash
        ));
        assert!(!verify_password("falsch", &config.admin.password_hash));
        let team = &config.teams[0];
        assert_eq!(team.name, "EHC Zürich");
        assert!(store.team_music_dir(&team.id).unwrap().is_dir());
        assert!(store.general_music_dir().is_dir());
        let raw = fs::read_to_string(store.config_path()).unwrap();
        assert!(!raw.contains("SicheresPasswort") && !raw.contains("team1"));
        assert!(
            matches!(store.create_initial(&setup_input()), Err(e) if e.code() == "err_already_setup")
        );
    }

    #[test]
    fn setup_rejects_weak_input() {
        let dir = tempfile::tempdir().unwrap();
        let store = Store::new(dir.path().to_path_buf());
        let mut input = setup_input();
        input.admin_password = "kurz".into();
        assert_eq!(
            store.create_initial(&input).unwrap_err().code(),
            "err_admin_password_short"
        );
        let mut input = setup_input();
        input.team_password = "abc".into();
        assert_eq!(
            store.create_initial(&input).unwrap_err().code(),
            "err_team_password_short"
        );
        assert!(matches!(store.load(), ConfigState::Missing));
    }

    #[test]
    fn broken_config_falls_back_to_backup_and_is_never_replaced_by_defaults() {
        let (_dir, store, mut config) = ready_store();
        config.app_name = "Zweite Fassung".into();
        store.save(&config).unwrap();
        fs::write(store.config_path(), "{kaputt").unwrap();
        match store.load() {
            ConfigState::Ready { config, recovered } => {
                assert!(recovered);
                assert_eq!(config.app_name, "EHC Test");
            }
            _ => panic!("Sicherung wurde nicht verwendet"),
        }
        fs::write(store.backup_config_path(), "auch kaputt").unwrap();
        assert!(matches!(store.load(), ConfigState::Broken));
        assert_eq!(store.load_ready().unwrap_err().code(), "err_config_broken");
        assert!(
            matches!(store.create_initial(&setup_input()), Err(e) if e.code() == "err_already_setup")
        );
    }

    #[test]
    fn bcrypt_hashes_from_php_version_are_accepted() {
        // Erzeugt mit PHP: password_hash('altesPasswort', PASSWORD_DEFAULT)
        let hash = "$2y$10$7UnR2BMirnwbb.T9kgGO.uhkTI5akVu.8jOw2SJFM1DpCgzwm0596";
        assert!(verify_password("altesPasswort", hash));
        assert!(!verify_password("anderes", hash));
        assert!(needs_rehash(hash));
        assert!(
            !verify_password("admin123", "admin123"),
            "Klartext darf nie akzeptiert werden"
        );
    }

    #[test]
    fn audio_names_are_checked_and_sanitized() {
        assert!(is_valid_audio_name("Don't Stop Me Now.mp3"));
        assert!(is_valid_audio_name("Zürich Hymne (Live).ogg"));
        assert!(!is_valid_audio_name("../config.json"));
        assert!(!is_valid_audio_name("a/b.mp3"));
        assert!(!is_valid_audio_name("con.mp3"));
        assert!(!is_valid_audio_name("song.exe"));
        assert_eq!(
            sanitize_audio_name("Was?Wie:Wo.MP3").as_deref(),
            Some("Was_Wie_Wo.mp3")
        );
        assert_eq!(sanitize_audio_name("nul.wav").as_deref(), Some("nul_.wav"));
        assert_eq!(sanitize_audio_name("bild.png"), None);
    }

    #[test]
    fn music_add_list_delete_roundtrip() {
        let (dir, store, _config) = ready_store();
        let source_dir = dir.path().join("quelle");
        fs::create_dir_all(&source_dir).unwrap();
        let song = source_dir.join("Don't Stop Me Now.wav");
        fs::write(&song, wav_bytes()).unwrap();
        let fake = source_dir.join("virus.mp3");
        fs::write(&fake, b"MZ\x90\x00 kein Audio").unwrap();

        let target = store.general_music_dir();
        let result = Store::add_audio_files(&target, &[song.clone(), song.clone(), fake]).unwrap();
        assert_eq!(
            result.added,
            vec!["Don't Stop Me Now.wav", "Don't Stop Me Now (2).wav"]
        );
        assert_eq!(result.skipped.len(), 1);
        assert_eq!(result.skipped[0].reason, "err_not_audio");

        let listed: Vec<String> = Store::list_audio(&target)
            .into_iter()
            .map(|f| f.name)
            .collect();
        assert_eq!(listed.len(), 2);
        assert_eq!(
            Store::read_audio(&target, "Don't Stop Me Now.wav").unwrap(),
            wav_bytes()
        );
        Store::delete_audio(&target, "Don't Stop Me Now.wav").unwrap();
        assert_eq!(Store::list_audio(&target).len(), 1);
        assert_eq!(
            Store::delete_audio(&target, "../config.json")
                .unwrap_err()
                .code(),
            "err_invalid_file_name"
        );
        assert_eq!(
            Store::read_audio(&target, "fehlt.mp3").unwrap_err().code(),
            "err_file_not_found"
        );
    }

    #[test]
    fn effects_keep_labels_with_umlauts_and_slots() {
        let (dir, store, mut config) = ready_store();
        let team_id = config.teams[0].id.clone();
        let source = dir.path().join("Tor-Horn.wav");
        fs::write(&source, wav_bytes()).unwrap();
        assert_eq!(
            store
                .set_effect(&mut config, &team_id, 3, "Tor", None)
                .unwrap_err()
                .code(),
            "err_effect_needs_file"
        );
        store
            .set_effect(&mut config, &team_id, 3, "Tor für Zürich!", Some(&source))
            .unwrap();
        let effect = config.teams[0].effects[2].clone().unwrap();
        assert_eq!(effect.label, "Tor für Zürich!");
        assert_eq!(effect.file, "3.wav");
        assert_eq!(
            store.read_effect(&config, &team_id, 3).unwrap(),
            wav_bytes()
        );
        store
            .set_effect(&mut config, &team_id, 3, "Neuer Name", None)
            .unwrap();
        assert_eq!(
            config.teams[0].effects[2].as_ref().unwrap().label,
            "Neuer Name"
        );
        store.remove_effect(&mut config, &team_id, 3).unwrap();
        assert!(config.teams[0].effects[2].is_none());
        assert!(!store
            .team_effects_dir(&team_id)
            .unwrap()
            .join("3.wav")
            .exists());
        assert_eq!(
            store
                .set_effect(&mut config, &team_id, 7, "x", Some(&source))
                .unwrap_err()
                .code(),
            "err_invalid_request"
        );
    }

    #[test]
    fn logo_switches_between_png_and_jpg() {
        let (dir, store, mut config) = ready_store();
        let png = dir.path().join("logo.png");
        fs::write(&png, png_bytes()).unwrap();
        let jpg = dir.path().join("logo.jpeg");
        fs::write(&jpg, [0xFF, 0xD8, 0xFF, 0xE0, 0, 0]).unwrap();
        store.set_logo(&mut config, &jpg).unwrap();
        assert_eq!(config.logo.as_deref(), Some("logo.jpg"));
        store.set_logo(&mut config, &png).unwrap();
        assert_eq!(config.logo.as_deref(), Some("logo.png"));
        assert!(!store.media_dir().join("logo.jpg").exists());
        let (_, mime) = store.read_logo(&config).unwrap();
        assert_eq!(mime, "image/png");
        let text = dir.path().join("logo.txt");
        fs::write(&text, "kein Bild").unwrap();
        assert_eq!(
            store.set_logo(&mut config, &text).unwrap_err().code(),
            "err_not_image"
        );
    }

    #[test]
    fn teams_require_unique_names_and_passwords() {
        let (_dir, store, mut config) = ready_store();
        let first = config.teams[0].id.clone();
        let duplicate = vec![
            TeamInput {
                id: Some(first.clone()),
                name: "EHC".into(),
                password: None,
            },
            TeamInput {
                id: None,
                name: "ehc".into(),
                password: Some("geheim".into()),
            },
        ];
        assert_eq!(
            store
                .apply_teams(&mut config, &duplicate)
                .unwrap_err()
                .code(),
            "err_team_name_duplicate"
        );
        let without_password = vec![TeamInput {
            id: None,
            name: "Junioren".into(),
            password: None,
        }];
        assert_eq!(
            store
                .apply_teams(&mut config.clone(), &without_password)
                .unwrap_err()
                .code(),
            "err_team_password_required"
        );

        let old_hash = config.teams[0].password_hash.clone();
        let input = vec![
            TeamInput {
                id: Some(first.clone()),
                name: "EHC Zürich 1".into(),
                password: Some("".into()),
            },
            TeamInput {
                id: None,
                name: "Junioren".into(),
                password: Some("jun1".into()),
            },
        ];
        let removed = store.apply_teams(&mut config, &input).unwrap();
        assert!(removed.is_empty());
        assert_eq!(
            config.teams[0].id, first,
            "Umbenennen behält die ID und damit den Ordner"
        );
        assert_eq!(config.teams[0].password_hash, old_hash);
        assert!(verify_password(
            "jun1",
            config.teams[1].password_hash.as_ref().unwrap()
        ));

        let keep_second = vec![TeamInput {
            id: Some(config.teams[1].id.clone()),
            name: "Junioren".into(),
            password: None,
        }];
        let removed = store.apply_teams(&mut config, &keep_second).unwrap();
        assert_eq!(removed, vec![first]);
        assert_eq!(
            store.apply_teams(&mut config, &[]).unwrap_err().code(),
            "err_team_required"
        );
    }

    #[test]
    fn hotkeys_reject_duplicates() {
        let mut map = default_hotkeys();
        assert!(validate_hotkeys(&map).is_ok());
        map.insert("effect_2".into(), "NUMADD".into());
        assert_eq!(
            validate_hotkeys(&map).unwrap_err().code(),
            "err_hotkey_duplicate"
        );
        map.insert("effect_2".into(), "".into());
        map.insert("effect_3".into(), "".into());
        assert!(
            validate_hotkeys(&map).is_ok(),
            "mehrere leere Tasten sind erlaubt"
        );
    }
}
