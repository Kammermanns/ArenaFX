//! Übernahme der Daten aus früheren Versionen:
//! - ArenaFX 1.x (Electron): Passwörter im Klartext, Effekte als `01_Name.mp3`
//! - Soundbox (PHP): Passwörter als bcrypt-Hash
//!
//! Beide Versionen nutzen denselben `media`-Aufbau mit `config.json`.

use std::collections::BTreeMap;
use std::fs;
use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

use crate::error::{err, AppResult};
use crate::store::{
    audio_extension, copy_atomic, hash_password, is_valid_audio_name, logo_kind, looks_like_audio,
    new_team_id, sanitize_audio_name, validate_hotkeys, Admin, Config, Effect, Store, Team,
    EFFECT_SLOTS, LANGUAGES, MAX_AUDIO_BYTES, MAX_LOGO_BYTES, SCHEMA_VERSION,
};

#[derive(Debug, Deserialize)]
struct LegacyConfig {
    #[serde(default)]
    app_name: Option<String>,
    #[serde(default)]
    language: Option<String>,
    #[serde(default)]
    teams: Vec<LegacyTeam>,
    #[serde(default)]
    hotkeys: Option<BTreeMap<String, String>>,
}

#[derive(Debug, Deserialize)]
struct LegacyTeam {
    #[serde(default)]
    id: Option<String>,
    #[serde(default)]
    name: Option<String>,
    #[serde(default)]
    password: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct LegacyPreview {
    pub source: &'static str,
    pub app_name: String,
    pub teams: Vec<LegacyTeamPreview>,
    pub general_music: usize,
    pub has_logo: bool,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct LegacyTeamPreview {
    pub name: String,
    pub has_password: bool,
    pub music: usize,
    pub effects: usize,
}

#[derive(Debug, Default, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct LegacyImportResult {
    pub teams: usize,
    pub music: usize,
    pub effects: usize,
    pub skipped: usize,
    pub locked_teams: Vec<String>,
}

/// Findet den `media`-Ordner: der Nutzer darf den Programmordner der alten Version
/// oder direkt den `media`-Ordner (bzw. einen Backup-Ordner der Electron-Version) wählen.
pub fn find_media_root(selected: &Path) -> Option<PathBuf> {
    [selected.join("media"), selected.to_path_buf()]
        .into_iter()
        .find(|candidate| candidate.join("config.json").is_file())
}

fn read_legacy_config(media: &Path) -> AppResult<LegacyConfig> {
    let raw =
        fs::read_to_string(media.join("config.json")).map_err(|_| err("err_legacy_not_found"))?;
    let raw = raw.trim_start_matches('\u{feff}');
    serde_json::from_str(raw).map_err(|_| err("err_legacy_invalid"))
}

fn legacy_team_dirs(media: &Path, team: &LegacyTeam) -> Option<PathBuf> {
    let id = team.id.as_deref()?;
    if id.is_empty() || id.contains(['/', '\\']) || id == "." || id == ".." {
        return None;
    }
    Some(media.join("teams").join(id))
}

fn count_audio(dir: &Path) -> usize {
    fs::read_dir(dir)
        .map(|entries| {
            entries
                .filter_map(Result::ok)
                .filter(|entry| entry.file_type().map(|t| t.is_file()).unwrap_or(false))
                .filter(|entry| audio_extension(&entry.file_name().to_string_lossy()).is_some())
                .count()
        })
        .unwrap_or(0)
}

/// Effekt-Dateien der alten Versionen: `01_Name.mp3` bis `06_Name.mp3`.
fn legacy_effects(dir: &Path) -> Vec<(usize, String, PathBuf)> {
    let mut found: BTreeMap<usize, (String, PathBuf)> = BTreeMap::new();
    let Ok(entries) = fs::read_dir(dir) else {
        return Vec::new();
    };
    let mut names: Vec<String> = entries
        .filter_map(Result::ok)
        .filter(|entry| entry.file_type().map(|t| t.is_file()).unwrap_or(false))
        .filter_map(|entry| entry.file_name().to_str().map(str::to_string))
        .collect();
    names.sort();
    for name in names {
        let Some(extension) = audio_extension(&name) else {
            continue;
        };
        let bytes = name.as_bytes();
        if bytes.len() < 3 || !bytes[0].is_ascii_digit() || !bytes[1].is_ascii_digit() {
            continue;
        }
        let slot = usize::from((bytes[0] - b'0') * 10 + (bytes[1] - b'0'));
        if !(1..=EFFECT_SLOTS).contains(&slot) || found.contains_key(&slot) {
            continue;
        }
        let stem = &name[2..name.len() - extension.len() - 1];
        let label: String = stem
            .trim_start_matches('_')
            .replace('_', " ")
            .trim()
            .chars()
            .take(40)
            .collect();
        let label = if label.is_empty() {
            format!("Effekt {slot}")
        } else {
            label
        };
        found.insert(slot, (label, dir.join(&name)));
    }
    found
        .into_iter()
        .map(|(slot, (label, path))| (slot, label, path))
        .collect()
}

pub fn preview(selected: &Path) -> AppResult<LegacyPreview> {
    let media = find_media_root(selected).ok_or(err("err_legacy_not_found"))?;
    let legacy = read_legacy_config(&media)?;
    let is_php = legacy.teams.iter().any(|team| {
        team.password
            .as_deref()
            .is_some_and(|p| p.starts_with("$2"))
    }) || legacy.hotkeys.is_none();
    let teams = legacy
        .teams
        .iter()
        .map(|team| {
            let dir = legacy_team_dirs(&media, team);
            LegacyTeamPreview {
                name: team.name.clone().or(team.id.clone()).unwrap_or_default(),
                has_password: team.password.as_deref().is_some_and(|p| !p.is_empty()),
                music: dir
                    .as_ref()
                    .map(|d| count_audio(&d.join("musik")))
                    .unwrap_or(0),
                effects: dir
                    .as_ref()
                    .map(|d| legacy_effects(&d.join("effekte")).len())
                    .unwrap_or(0),
            }
        })
        .collect();
    Ok(LegacyPreview {
        source: if is_php { "php" } else { "electron" },
        app_name: legacy.app_name.clone().unwrap_or_default(),
        teams,
        general_music: count_audio(&media.join("allgemein").join("musik")),
        has_logo: ["logo.png", "logo.jpg", "logo.jpeg"]
            .iter()
            .any(|f| media.join(f).is_file()),
    })
}

fn copy_audio_dir(source: &Path, target: &Path, result: &mut LegacyImportResult) -> AppResult<()> {
    fs::create_dir_all(target)?;
    let Ok(entries) = fs::read_dir(source) else {
        return Ok(());
    };
    for entry in entries.filter_map(Result::ok) {
        let path = entry.path();
        let name = entry.file_name().to_string_lossy().to_string();
        if !entry.file_type().map(|t| t.is_file()).unwrap_or(false)
            || audio_extension(&name).is_none()
        {
            continue;
        }
        let size = entry.metadata().map(|m| m.len()).unwrap_or(u64::MAX);
        let clean = sanitize_audio_name(&name).filter(|n| is_valid_audio_name(n));
        match clean {
            Some(clean)
                if size <= MAX_AUDIO_BYTES
                    && looks_like_audio(&path)
                    && !target.join(&clean).exists() =>
            {
                copy_atomic(&path, &target.join(clean))?;
                result.music += 1;
            }
            _ => result.skipped += 1,
        }
    }
    Ok(())
}

/// Baut aus den alten Daten eine neue Konfiguration und kopiert alle Medien in `staging`
/// (ein leerer Ordner, der danach als neuer `media`-Ordner eingesetzt wird).
pub fn build(
    selected: &Path,
    admin: Admin,
    staging: &Path,
) -> AppResult<(Config, LegacyImportResult)> {
    let media = find_media_root(selected).ok_or(err("err_legacy_not_found"))?;
    let legacy = read_legacy_config(&media)?;
    let staging_store = Store::new(staging.to_path_buf());
    let mut result = LegacyImportResult::default();

    let mut config = Config {
        schema: SCHEMA_VERSION,
        app_name: legacy
            .app_name
            .as_deref()
            .map(str::trim)
            .filter(|n| !n.is_empty())
            .map(|n| n.chars().take(80).collect())
            .unwrap_or_else(|| "ArenaFX".into()),
        language: legacy
            .language
            .filter(|l| LANGUAGES.contains(&l.as_str()))
            .unwrap_or_else(|| "de".into()),
        admin,
        teams: Vec::new(),
        hotkeys: legacy
            .hotkeys
            .as_ref()
            .and_then(|h| validate_hotkeys(h).ok())
            .unwrap_or_else(crate::store::default_hotkeys),
        logo: None,
    };

    copy_audio_dir(
        &media.join("allgemein").join("musik"),
        &staging_store.general_music_dir(),
        &mut result,
    )?;

    let mut used_names = std::collections::HashSet::new();
    for legacy_team in &legacy.teams {
        let mut name: String = legacy_team
            .name
            .clone()
            .or(legacy_team.id.clone())
            .unwrap_or_default()
            .trim()
            .chars()
            .filter(|c| !c.is_control())
            .take(60)
            .collect();
        if name.is_empty() {
            name = format!("Team {}", config.teams.len() + 1);
        }
        let base = name.clone();
        let mut counter = 2;
        while !used_names.insert(name.to_lowercase()) {
            name = format!("{base} ({counter})");
            counter += 1;
        }

        let password_hash = match legacy_team.password.as_deref().map(str::trim) {
            Some(hash) if hash.starts_with("$2") || hash.starts_with("$argon2") => {
                Some(hash.to_string())
            }
            Some(plain) if !plain.is_empty() => Some(hash_password(plain)?),
            _ => None,
        };
        if password_hash.is_none() {
            result.locked_teams.push(name.clone());
        }

        let mut team = Team {
            id: new_team_id(),
            name,
            password_hash,
            effects: vec![None; EFFECT_SLOTS],
        };
        while config.team(&team.id).is_some() {
            team.id = new_team_id();
        }
        if let Some(old_dir) = legacy_team_dirs(&media, legacy_team) {
            copy_audio_dir(
                &old_dir.join("musik"),
                &staging_store.team_music_dir(&team.id)?,
                &mut result,
            )?;
            let effects_dir = staging_store.team_effects_dir(&team.id)?;
            fs::create_dir_all(&effects_dir)?;
            for (slot, label, path) in legacy_effects(&old_dir.join("effekte")) {
                let source = path
                    .file_name()
                    .map(|n| n.to_string_lossy().to_string())
                    .unwrap_or_default();
                let size = fs::metadata(&path).map(|m| m.len()).unwrap_or(u64::MAX);
                if size > MAX_AUDIO_BYTES || !looks_like_audio(&path) {
                    result.skipped += 1;
                    continue;
                }
                let extension = audio_extension(&source).unwrap_or_else(|| "mp3".into());
                let file = format!("{slot}.{extension}");
                copy_atomic(&path, &effects_dir.join(&file))?;
                team.effects[slot - 1] = Some(Effect {
                    label,
                    file,
                    source,
                });
                result.effects += 1;
            }
        } else {
            fs::create_dir_all(staging_store.team_music_dir(&team.id)?)?;
            fs::create_dir_all(staging_store.team_effects_dir(&team.id)?)?;
        }
        config.teams.push(team);
    }
    result.teams = config.teams.len();

    for candidate in ["logo.png", "logo.jpg", "logo.jpeg"] {
        let path = media.join(candidate);
        let small_enough = fs::metadata(&path)
            .map(|m| m.len() <= MAX_LOGO_BYTES)
            .unwrap_or(false);
        if let (true, Some(kind)) = (small_enough, logo_kind(&path)) {
            let file = format!("logo.{kind}");
            copy_atomic(&path, &staging_store.media_dir().join(&file))?;
            config.logo = Some(file);
            break;
        }
    }

    if config.teams.is_empty() {
        return Err(err("err_legacy_no_teams"));
    }
    Ok((config, result))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::store::tests::{png_bytes, wav_bytes};
    use crate::store::verify_password;

    fn electron_folder(root: &Path) -> PathBuf {
        let program = root.join("Arenafx");
        let media = program.join("media");
        fs::create_dir_all(media.join("allgemein/musik")).unwrap();
        fs::create_dir_all(media.join("teams/Standard/musik")).unwrap();
        fs::create_dir_all(media.join("teams/EHC_Z_rich/effekte")).unwrap();
        fs::create_dir_all(media.join("teams/EHC_Z_rich/musik")).unwrap();
        fs::write(
            media.join("config.json"),
            r#"{"app_name":"EHC Arena","language":"fr","admin":{"username":"admin","password":"admin123"},
               "teams":[{"id":"Standard","name":"Standard","password":""},
                        {"id":"EHC_Z_rich","name":"EHC Zürich","password":"geheim"}],
               "hotkeys":{"music":"Space","durchsage":"num0","effect_1":"num1","effect_2":"num2","effect_3":"num3","effect_4":"num4","effect_5":"num5","effect_6":"num6"}}"#,
        )
        .unwrap();
        fs::write(media.join("allgemein/musik/Don't Stop.wav"), wav_bytes()).unwrap();
        fs::write(media.join("allgemein/musik/kaputt.mp3"), b"kein audio").unwrap();
        fs::write(media.join("teams/EHC_Z_rich/musik/Hymne.wav"), wav_bytes()).unwrap();
        fs::write(
            media.join("teams/EHC_Z_rich/effekte/01_Tor_Horn.wav"),
            wav_bytes(),
        )
        .unwrap();
        fs::write(
            media.join("teams/EHC_Z_rich/effekte/04_Strafe.wav"),
            wav_bytes(),
        )
        .unwrap();
        fs::write(media.join("logo.jpeg"), png_bytes()).unwrap();
        program
    }

    #[test]
    fn imports_electron_folder() {
        let dir = tempfile::tempdir().unwrap();
        let program = electron_folder(dir.path());

        let preview = preview(&program).unwrap();
        assert_eq!(preview.source, "electron");
        assert_eq!(preview.teams.len(), 2);
        assert_eq!(preview.teams[1].effects, 2);
        assert_eq!(preview.general_music, 2);

        let staging = dir.path().join("neu");
        let admin = crate::store::validated_admin("stefan", "NeuesPasswort1").unwrap();
        let (config, result) = build(&program, admin, &staging).unwrap();
        assert_eq!(config.app_name, "EHC Arena");
        assert_eq!(config.language, "fr");
        assert_eq!(config.hotkeys["music"], "Space");
        assert_eq!(
            config.admin.username, "stefan",
            "altes admin/admin123 wird nicht übernommen"
        );
        assert_eq!(result.music, 2);
        assert_eq!(result.skipped, 1);
        assert_eq!(result.effects, 2);
        assert_eq!(result.locked_teams, vec!["Standard"]);

        let zurich = &config.teams[1];
        assert_eq!(zurich.name, "EHC Zürich");
        assert!(verify_password(
            "geheim",
            zurich.password_hash.as_ref().unwrap()
        ));
        let horn = zurich.effects[0].as_ref().unwrap();
        assert_eq!(
            (horn.label.as_str(), horn.file.as_str()),
            ("Tor Horn", "1.wav")
        );
        assert_eq!(zurich.effects[3].as_ref().unwrap().label, "Strafe");
        assert!(config.teams[0].password_hash.is_none());
        assert_eq!(
            config.logo.as_deref(),
            Some("logo.png"),
            "Dateiinhalt bestimmt den Typ"
        );

        let store = Store::new(staging);
        assert!(store.general_music_dir().join("Don't Stop.wav").is_file());
        assert!(store
            .team_effects_dir(&zurich.id)
            .unwrap()
            .join("1.wav")
            .is_file());
    }

    #[test]
    fn imports_php_media_folder_with_bcrypt_hashes() {
        let dir = tempfile::tempdir().unwrap();
        let media = dir.path().join("media");
        fs::create_dir_all(media.join("teams/ehc/musik")).unwrap();
        let hash = "$2y$10$7UnR2BMirnwbb.T9kgGO.uhkTI5akVu.8jOw2SJFM1DpCgzwm0596";
        fs::write(
            media.join("config.json"),
            format!(r#"{{"app_name":"PHP Box","admin":{{"username":"x","password":"{hash}"}},"teams":[{{"id":"ehc","name":"EHC","password":"{hash}"}}]}}"#),
        )
        .unwrap();
        assert_eq!(preview(&media).unwrap().source, "php");
        let admin = crate::store::validated_admin("stefan", "NeuesPasswort1").unwrap();
        let (config, _) = build(&media, admin, &dir.path().join("neu")).unwrap();
        assert!(verify_password(
            "altesPasswort",
            config.teams[0].password_hash.as_ref().unwrap()
        ));
    }

    #[test]
    fn rejects_folders_without_config() {
        let dir = tempfile::tempdir().unwrap();
        assert_eq!(
            preview(dir.path()).unwrap_err().code(),
            "err_legacy_not_found"
        );
        fs::write(dir.path().join("config.json"), "{kaputt").unwrap();
        assert_eq!(
            preview(dir.path()).unwrap_err().code(),
            "err_legacy_invalid"
        );
    }

    #[test]
    fn ignores_path_tricks_in_old_team_ids() {
        let dir = tempfile::tempdir().unwrap();
        let media = dir.path().join("media");
        fs::create_dir_all(&media).unwrap();
        fs::write(
            media.join("config.json"),
            r#"{"teams":[{"id":"../../geheim","name":"Böse","password":"x1234"}]}"#,
        )
        .unwrap();
        let admin = crate::store::validated_admin("stefan", "NeuesPasswort1").unwrap();
        let (config, result) = build(&media, admin, &dir.path().join("neu")).unwrap();
        assert_eq!(config.teams.len(), 1);
        assert_eq!(result.music, 0);
    }
}
