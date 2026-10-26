# ArenaFX

Soundbox für Eishockey-Spiele: Unterbruchsmusik und Effekt-Töne auf Knopfdruck, für mehrere Teams, ohne Internet. Windows-Desktop-App auf Basis von [Tauri 2](https://v2.tauri.app/).

## Funktionen

- **Musik-Taste:** startet einen zufälligen Song an einer zufälligen Stelle, stoppt mit sanftem Ausblenden. Kein Song wiederholt sich, bevor alle gelaufen sind.
- **Warm-Up-Modus:** ganze Songs nacheinander, ohne Pause.
- **Durchsage:** senkt die Musik auf 20 %, damit der Speaker verstanden wird.
- **6 Effekt-Buttons pro Team:** starten ohne Verzögerung und unterbrechen die Musik.
- **Tastenkürzel:** frei belegbar (Standard: Ziffernblock `+` Musik, `0` Durchsage, `1`–`6` Effekte).
- **Teams:** jedes Team hat eigenes Passwort, eigene Musik und eigene Effekte. Ohne eigene Musik läuft die allgemeine Musik.
- **Deutsch, Englisch, Französisch.**
- **Backup** als einzelne ZIP-Datei, **Übernahme** der Daten aus ArenaFX 1.x und der PHP-Soundbox.
- **Updates** direkt aus der App (wenn Internet vorhanden ist).

## Anleitungen

- **[Installation und Einrichtung](docs/Installation.md):** Setup, Ersteinrichtung, Übernahme alter Daten, Datenordner, Umzug, Updates
- **[Bedienungsanleitung](docs/Anleitung.md):** Soundbox, Durchsage, Effekte, Tastenkürzel, Einstellungen, Checkliste für den Spieltag
- **[Regiepult bauen](docs/Regiepult.md):** USB-Numpad zu einem Pult mit grossen Tasten umbauen

## Schnellstart

1. Unter [Releases](https://github.com/Kammermanns/ArenaFX/releases/latest) die Datei `ArenaFX_…_x64-setup.exe` herunterladen und installieren (Windows 10/11, keine Administratorrechte nötig).
2. ArenaFX starten und bei der Einrichtung Verein, erstes Team und Admin-Zugang festlegen oder die Daten einer früheren Version übernehmen.
3. Musik und Effekte hinzufügen, fertig.

| Aktion | Taste (Standard) |
|---|---|
| Musik starten / stoppen | Num `+` |
| Durchsage an / aus | Num `0` |
| Effekt 1–6 | Num `1`–`6` |

Alle Daten liegen in `%APPDATA%\ch.kammermann.arenafx` und bleiben bei Updates und beim Deinstallieren erhalten. Passwörter werden nur als Hash (Argon2) gespeichert.

## Entwicklung

Voraussetzungen: [Rust](https://rustup.rs/), Node.js 20+, unter Windows die [Tauri-Voraussetzungen](https://v2.tauri.app/start/prerequisites/).

```bash
npm install
npm run dev        # App im Entwicklungsmodus starten
npm run build      # Installer bauen (src-tauri/target/release/bundle/nsis)
```

Aufbau:

```text
ui/                 Oberfläche (HTML, CSS, JavaScript, Übersetzungen in ui/locales)
src-tauri/src/      Rust-Teil
  lib.rs            Befehle mit Rechteprüfung, Dialoge, Updater
  store.rs          Konfiguration, Passwörter, Mediendateien
  backup.rs         Backup als ZIP und sicherer Datenaustausch
  legacy.rs         Übernahme aus ArenaFX 1.x und der PHP-Soundbox
tests/e2e/          End-to-End-Test der echten App (Linux, tauri-driver)
```

Die Oberfläche hat keinen direkten Datei- oder Dialogzugriff. Alles läuft über die Befehle in `lib.rs`, die selbst prüfen, ob Admin oder welches Team angemeldet ist.

Tests:

```bash
cd src-tauri && cargo test --lib     # Unit-Tests
tests/e2e/run.sh                     # End-to-End-Test (Linux, siehe Kommentar im Skript)
```

Jeder Pull Request wird automatisch geprüft. Dabei entsteht auch ein Windows-Installer zum Ausprobieren (Actions → Lauf → Artefakt „ArenaFX-Installer-Test“).

### Neue Version veröffentlichen

1. Versionsnummer in `src-tauri/tauri.conf.json`, `src-tauri/Cargo.toml` und `package.json` erhöhen (z. B. `2.1.0`) und nach `main` bringen.
2. Auf GitHub unter **Actions → Version veröffentlichen → Run workflow** starten und optional eintragen, was neu ist.
3. Der Workflow baut den Installer, signiert das Update und veröffentlicht das Release `v2.1.0`. Installierte Apps finden es danach unter **Einstellungen → Update**.

Einmalig nötig: Unter **Settings → Secrets and variables → Actions → New repository secret** das Secret `TAURI_SIGNING_PRIVATE_KEY` mit dem Inhalt der privaten Schlüsseldatei anlegen. Nur wenn der Schlüssel mit Passwort erstellt wurde, zusätzlich `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`. Der passende öffentliche Schlüssel steht in `tauri.conf.json`. Den privaten Schlüssel sicher aufbewahren: Geht er verloren, können installierte Apps keine Updates mehr annehmen.
