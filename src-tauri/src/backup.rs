//! Backup als einzelne ZIP-Datei und sicherer Austausch des gesamten Datenbestands.

use std::fs;
use std::io::{self, Read, Write};
use std::path::{Path, PathBuf};

use rand::Rng;
use serde::{Deserialize, Serialize};
use zip::write::SimpleFileOptions;
use zip::{CompressionMethod, ZipArchive, ZipWriter};

use crate::error::{err, err_detail, AppResult};
use crate::store::{
    is_valid_audio_name, is_valid_team_id, Config, Store, EFFECT_SLOTS, MAX_AUDIO_BYTES,
    MAX_LOGO_BYTES,
};

const MANIFEST: &str = "arenafx-backup.json";
const FORMAT: &str = "arenafx-backup";
const MAX_ENTRIES: usize = 50_000;
const MAX_TOTAL_BYTES: u64 = 40 * 1024 * 1024 * 1024;

#[derive(Serialize, Deserialize)]
struct Manifest {
    format: String,
    app_version: String,
}

fn random_suffix() -> String {
    format!("{:08x}", rand::thread_rng().gen::<u32>())
}

/// Legt einen leeren Arbeitsordner im Datenordner an (gleiches Laufwerk, damit Umbenennen klappt).
pub fn staging_dir(store: &Store, prefix: &str) -> AppResult<PathBuf> {
    fs::create_dir_all(store.root())?;
    let dir = store.root().join(format!("{prefix}-{}", random_suffix()));
    fs::create_dir(&dir)?;
    Ok(dir)
}

/// Ersetzt `media` und `config.json` durch den Inhalt von `staging/media` und `config`.
/// Schlägt ein Schritt fehl, wird der vorherige Zustand wiederhergestellt.
pub fn replace_data(store: &Store, staging: &Path, config: &Config) -> AppResult<()> {
    let media = store.media_dir();
    let new_media = staging.join("media");
    fs::create_dir_all(&new_media)?;
    let old_media = store.root().join(format!("media-alt-{}", random_suffix()));
    let had_media = media.exists();
    if had_media {
        fs::rename(&media, &old_media)?;
    }
    if let Err(error) = fs::rename(&new_media, &media) {
        if had_media {
            let _ = fs::rename(&old_media, &media);
        }
        return Err(error.into());
    }
    if let Err(error) = store.save(config) {
        let _ = fs::remove_dir_all(&media);
        if had_media {
            let _ = fs::rename(&old_media, &media);
        }
        return Err(error);
    }
    if had_media {
        let _ = fs::remove_dir_all(&old_media);
    }
    let _ = fs::remove_dir_all(staging);
    Ok(())
}

fn media_files(store: &Store, config: &Config) -> AppResult<Vec<(String, PathBuf)>> {
    let media = store.media_dir();
    let mut files = Vec::new();
    if let Some(logo) = &config.logo {
        let path = media.join(logo);
        if path.is_file() {
            files.push((format!("media/{logo}"), path));
        }
    }
    for file in Store::list_audio(&store.general_music_dir()) {
        files.push((
            format!("media/allgemein/musik/{}", file.name),
            store.general_music_dir().join(&file.name),
        ));
    }
    for team in &config.teams {
        let music = store.team_music_dir(&team.id)?;
        for file in Store::list_audio(&music) {
            files.push((
                format!("media/teams/{}/musik/{}", team.id, file.name),
                music.join(&file.name),
            ));
        }
        let effects = store.team_effects_dir(&team.id)?;
        for effect in team.effects.iter().flatten() {
            let path = effects.join(&effect.file);
            if path.is_file() {
                files.push((
                    format!("media/teams/{}/effekte/{}", team.id, effect.file),
                    path,
                ));
            }
        }
    }
    Ok(files)
}

pub fn export(store: &Store, config: &Config, target: &Path, app_version: &str) -> AppResult<()> {
    let temporary = target.with_extension("arenafx-tmp");
    let result = (|| -> AppResult<()> {
        let mut zip = ZipWriter::new(io::BufWriter::new(fs::File::create(&temporary)?));
        let deflated = SimpleFileOptions::default().compression_method(CompressionMethod::Deflated);
        let stored = SimpleFileOptions::default()
            .compression_method(CompressionMethod::Stored)
            .large_file(true);
        let zip_error = |e: zip::result::ZipError| err_detail("err_io", e);

        zip.start_file(MANIFEST, deflated).map_err(zip_error)?;
        zip.write_all(&serde_json::to_vec_pretty(&Manifest {
            format: FORMAT.into(),
            app_version: app_version.into(),
        })?)?;
        zip.start_file("config.json", deflated).map_err(zip_error)?;
        zip.write_all(&serde_json::to_vec_pretty(config)?)?;
        for (name, path) in media_files(store, config)? {
            zip.start_file(name, stored).map_err(zip_error)?;
            io::copy(&mut fs::File::open(path)?, &mut zip)?;
        }
        let mut writer = zip.finish().map_err(zip_error)?;
        writer.flush()?;
        writer
            .into_inner()
            .map_err(|e| err_detail("err_io", e))?
            .sync_all()?;
        fs::rename(&temporary, target)?;
        Ok(())
    })();
    if result.is_err() {
        let _ = fs::remove_file(&temporary);
    }
    result
}

/// Welche Pfade in einem Backup erlaubt sind. Alles andere führt zur Ablehnung.
fn allowed_media_path(path: &str, config: &Config) -> Option<u64> {
    let parts: Vec<&str> = path.split('/').collect();
    match parts.as_slice() {
        ["logo.png"] | ["logo.jpg"] => Some(MAX_LOGO_BYTES),
        ["allgemein", "musik", name] if is_valid_audio_name(name) => Some(MAX_AUDIO_BYTES),
        ["teams", id, "musik", name]
            if is_valid_team_id(id) && config.team(id).is_some() && is_valid_audio_name(name) =>
        {
            Some(MAX_AUDIO_BYTES)
        }
        ["teams", id, "effekte", name] if is_valid_team_id(id) && config.team(id).is_some() => {
            let (slot, extension) = name.split_once('.')?;
            let slot: usize = slot.parse().ok()?;
            ((1..=EFFECT_SLOTS).contains(&slot) && is_valid_audio_name(&format!("x.{extension}")))
                .then_some(MAX_AUDIO_BYTES)
        }
        _ => None,
    }
}

fn read_small_entry<R: Read + io::Seek>(
    archive: &mut ZipArchive<R>,
    name: &str,
) -> AppResult<Vec<u8>> {
    let mut entry = archive
        .by_name(name)
        .map_err(|_| err("err_backup_invalid"))?;
    let mut buffer = Vec::new();
    entry
        .by_ref()
        .take(5 * 1024 * 1024)
        .read_to_end(&mut buffer)?;
    Ok(buffer)
}

/// Prüft das Backup vollständig, entpackt es in einen Arbeitsordner und ersetzt dann die Daten.
pub fn import(store: &Store, source: &Path) -> AppResult<Config> {
    let file = fs::File::open(source).map_err(|_| err("err_file_unreadable"))?;
    let mut archive =
        ZipArchive::new(io::BufReader::new(file)).map_err(|_| err("err_backup_invalid"))?;
    if archive.len() > MAX_ENTRIES {
        return Err(err("err_backup_invalid"));
    }
    let manifest: Manifest = serde_json::from_slice(&read_small_entry(&mut archive, MANIFEST)?)
        .map_err(|_| err("err_backup_invalid"))?;
    if manifest.format != FORMAT {
        return Err(err("err_backup_invalid"));
    }
    let raw_config = read_small_entry(&mut archive, "config.json")?;
    let mut config = crate::store::parse_config(&raw_config).ok_or(err("err_backup_invalid"))?;

    // Erst alle Einträge prüfen, bevor irgendetwas geschrieben wird.
    let mut total: u64 = 0;
    let mut media_entries = Vec::new();
    for index in 0..archive.len() {
        let entry = archive
            .by_index(index)
            .map_err(|_| err("err_backup_invalid"))?;
        let name = entry.name().to_string();
        if entry.is_dir() || name == MANIFEST || name == "config.json" {
            continue;
        }
        let relative = name
            .strip_prefix("media/")
            .ok_or_else(|| err_detail("err_backup_invalid", &name))?;
        let limit = allowed_media_path(relative, &config)
            .ok_or_else(|| err_detail("err_backup_invalid", &name))?;
        if entry.size() > limit {
            return Err(err_detail("err_backup_invalid", &name));
        }
        total += entry.size();
        if total > MAX_TOTAL_BYTES {
            return Err(err("err_backup_invalid"));
        }
        media_entries.push((index, relative.to_string(), limit));
    }

    let staging = staging_dir(store, "import")?;
    let result = (|| -> AppResult<()> {
        let staging_media = staging.join("media");
        for (index, relative, limit) in &media_entries {
            let mut entry = archive
                .by_index(*index)
                .map_err(|_| err("err_backup_invalid"))?;
            let target = staging_media.join(relative.replace('/', std::path::MAIN_SEPARATOR_STR));
            fs::create_dir_all(target.parent().expect("hat Elternordner"))?;
            let mut output = fs::File::create(&target)?;
            let written = io::copy(&mut entry.by_ref().take(limit + 1), &mut output)?;
            if written > *limit {
                return Err(err("err_backup_invalid"));
            }
        }
        let staging_store = Store::new(staging.clone());
        if let Some(logo) = config.logo.clone() {
            if !staging_store.media_dir().join(logo).is_file() {
                config.logo = None;
            }
        }
        for team in &mut config.teams {
            let effects_dir = staging_store.team_effects_dir(&team.id)?;
            for slot in team.effects.iter_mut() {
                if slot
                    .as_ref()
                    .is_some_and(|effect| !effects_dir.join(&effect.file).is_file())
                {
                    *slot = None;
                }
            }
        }
        staging_store.ensure_dirs(&config)?;
        Ok(())
    })();
    if let Err(error) = result {
        let _ = fs::remove_dir_all(&staging);
        return Err(error);
    }
    if let Err(error) = replace_data(store, &staging, &config) {
        let _ = fs::remove_dir_all(&staging);
        return Err(error);
    }
    Ok(config)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::store::tests::{png_bytes, ready_store, wav_bytes};
    use crate::store::verify_password;

    fn filled_store() -> (tempfile::TempDir, Store, Config) {
        let (dir, store, mut config) = ready_store();
        let song = dir.path().join("Hymne ä.wav");
        fs::write(&song, wav_bytes()).unwrap();
        Store::add_audio_files(&store.general_music_dir(), std::slice::from_ref(&song)).unwrap();
        let team_id = config.teams[0].id.clone();
        Store::add_audio_files(
            &store.team_music_dir(&team_id).unwrap(),
            std::slice::from_ref(&song),
        )
        .unwrap();
        store
            .set_effect(&mut config, &team_id, 2, "Tor", Some(&song))
            .unwrap();
        let logo = dir.path().join("logo.png");
        fs::write(&logo, png_bytes()).unwrap();
        store.set_logo(&mut config, &logo).unwrap();
        store.save(&config).unwrap();
        (dir, store, config)
    }

    #[test]
    fn export_and_import_roundtrip_replaces_everything() {
        let (dir, store, config) = filled_store();
        let backup = dir.path().join("sicherung.zip");
        export(&store, &config, &backup, "2.0.0").unwrap();

        // Danach ändern: Musik löschen, Datei hinzufügen, Name ändern
        let mut changed = config.clone();
        changed.app_name = "Geändert".into();
        store.save(&changed).unwrap();
        Store::delete_audio(&store.general_music_dir(), "Hymne ä.wav").unwrap();
        fs::write(store.general_music_dir().join("Neu.wav"), wav_bytes()).unwrap();

        let restored = import(&store, &backup).unwrap();
        assert_eq!(restored, config);
        let names: Vec<String> = Store::list_audio(&store.general_music_dir())
            .into_iter()
            .map(|f| f.name)
            .collect();
        assert_eq!(
            names,
            vec!["Hymne ä.wav"],
            "Backup ersetzt, statt zu mischen"
        );
        assert_eq!(
            store
                .read_effect(&restored, &restored.teams[0].id, 2)
                .unwrap(),
            wav_bytes()
        );
        assert!(store.read_logo(&restored).is_some());
        assert!(verify_password(
            "SicheresPasswort",
            &store.load_ready().unwrap().admin.password_hash
        ));
        let leftovers: Vec<_> = fs::read_dir(store.root())
            .unwrap()
            .filter_map(Result::ok)
            .map(|e| e.file_name().to_string_lossy().to_string())
            .filter(|n| n.starts_with("import-") || n.starts_with("media-alt-"))
            .collect();
        assert!(
            leftovers.is_empty(),
            "Arbeitsordner aufgeräumt: {leftovers:?}"
        );
    }

    fn zip_with(entries: &[(&str, Vec<u8>)], path: &Path) {
        let mut zip = ZipWriter::new(fs::File::create(path).unwrap());
        for (name, data) in entries {
            zip.start_file(*name, SimpleFileOptions::default()).unwrap();
            zip.write_all(data).unwrap();
        }
        zip.finish().unwrap();
    }

    #[test]
    fn rejects_manipulated_backups_without_touching_data() {
        let (dir, store, config) = filled_store();
        let manifest = serde_json::to_vec(&Manifest {
            format: FORMAT.into(),
            app_version: "x".into(),
        })
        .unwrap();
        let config_json = serde_json::to_vec(&config).unwrap();
        let before = fs::read(store.config_path()).unwrap();
        type Case<'a> = (&'a str, Vec<(&'a str, Vec<u8>)>);
        let cases: Vec<Case> = vec![
            (
                "zip-slip",
                vec![
                    (MANIFEST, manifest.clone()),
                    ("config.json", config_json.clone()),
                    ("media/../../evil.wav", wav_bytes()),
                ],
            ),
            (
                "fremde Datei",
                vec![
                    (MANIFEST, manifest.clone()),
                    ("config.json", config_json.clone()),
                    ("media/allgemein/musik/x.exe", vec![1]),
                ],
            ),
            (
                "unbekanntes Team",
                vec![
                    (MANIFEST, manifest.clone()),
                    ("config.json", config_json.clone()),
                    ("media/teams/tfremd/musik/a.wav", wav_bytes()),
                ],
            ),
            ("ohne Manifest", vec![("config.json", config_json.clone())]),
            (
                "kaputte Konfiguration",
                vec![
                    (MANIFEST, manifest.clone()),
                    ("config.json", b"{}".to_vec()),
                ],
            ),
        ];
        for (label, entries) in cases {
            let path = dir.path().join(format!("{label}.zip"));
            zip_with(&entries, &path);
            assert_eq!(
                import(&store, &path).unwrap_err().code(),
                "err_backup_invalid",
                "{label}"
            );
        }
        fs::write(dir.path().join("kein.zip"), b"PK kaputt").unwrap();
        assert_eq!(
            import(&store, &dir.path().join("kein.zip"))
                .unwrap_err()
                .code(),
            "err_backup_invalid"
        );
        assert_eq!(fs::read(store.config_path()).unwrap(), before);
        assert!(!dir.path().join("evil.wav").exists());
        assert_eq!(Store::list_audio(&store.general_music_dir()).len(), 1);
    }
}
