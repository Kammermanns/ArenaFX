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

## Installation (Windows 10/11)

1. Unter [Releases](https://github.com/Kammermanns/ArenaFX/releases/latest) die Datei `ArenaFX_…_x64-setup.exe` herunterladen.
2. Installieren. Es braucht keine Administratorrechte.
   Windows zeigt eventuell „Der Computer wurde durch Windows geschützt“, weil die App nicht kostenpflichtig signiert ist: **Weitere Informationen → Trotzdem ausführen**.
3. ArenaFX starten und die Einrichtung durchlaufen:
   - **Neu einrichten:** Vereinsname, erstes Team und Admin-Zugang festlegen.
   - **Aus alter Version übernehmen:** den Ordner der bisherigen ArenaFX-1.x- oder Soundbox-Installation wählen (der Ordner, in dem `media` liegt). Teams, Musik, Effekte, Logo und Tastenkürzel werden übernommen, die alten Dateien bleiben unverändert. Der Admin-Zugang wird neu festgelegt. Teams ohne Passwort werden gesperrt übernommen und brauchen danach ein Passwort.

Die App läuft komplett offline. WebView2 ist auf Windows 10/11 bereits vorhanden.

## Bedienung

| Aktion | Maus | Taste (Standard) |
|---|---|---|
| Musik starten / stoppen | grosser Button | Num `+` |
| Durchsage an / aus | „Durchsage“ | Num `0` |
| Effekt 1–6 | Effekt-Buttons | Num `1`–`6` |

Läuft ein Effekt, stoppt die Musik-Taste zuerst den Effekt. Die Tastenkürzel funktionieren, solange das ArenaFX-Fenster aktiv ist.

Als Admin wählst du in der Soundbox oben das Team, dessen Musik und Effekte gespielt werden.

## Daten, Backup und Updates

- Alle Daten liegen in `%APPDATA%\ch.kammermann.arenafx` (in den Einstellungen unter **Backup & Daten → Datenordner öffnen**). Der Ordner bleibt bei Updates und beim Deinstallieren erhalten.
- Passwörter werden nur als Hash (Argon2) gespeichert.
- Die Konfiguration wird sicher geschrieben. Die vorherige Fassung bleibt als `config.json.bak` erhalten und wird automatisch verwendet, falls `config.json` beschädigt ist.
- **Backup speichern** erzeugt eine ZIP-Datei mit allem. **Backup einspielen** ersetzt alle aktuellen Daten durch das Backup, nachdem es vollständig geprüft wurde.
- **Update** (Einstellungen → Update) lädt neue Versionen von GitHub. Die Updates sind signiert, ArenaFX installiert nur Pakete mit gültiger Signatur.

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

Einmalig nötig: Unter **Settings → Secrets and variables → Actions** die Secrets `TAURI_SIGNING_PRIVATE_KEY` (Inhalt der privaten Schlüsseldatei) und `TAURI_SIGNING_PRIVATE_KEY_PASSWORD` (leer lassen, falls ohne Passwort erstellt) anlegen. Der passende öffentliche Schlüssel steht in `tauri.conf.json`. Den privaten Schlüssel sicher aufbewahren: Geht er verloren, können installierte Apps keine Updates mehr annehmen.

## Frühere Versionen

- **v1.0.0:** Web-Version (PHP/XAMPP), siehe Release [v1.0.0](https://github.com/Kammermanns/ArenaFX/releases/tag/v1.0.0).
- **1.x Desktop (Electron):** abgelöst durch diese Version. Die Daten lassen sich bei der Einrichtung übernehmen.
