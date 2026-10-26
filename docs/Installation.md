# ArenaFX – Installation und Einrichtung

## Voraussetzungen

- Windows 10 oder 11 (64 Bit)
- Etwa 20 MB Speicherplatz plus Platz für deine Musik
- Kein Internet nötig, ausser für Updates

## Installation

1. Unter [Releases](https://github.com/Kammermanns/ArenaFX/releases/latest) die Datei **`ArenaFX_…_x64-setup.exe`** herunterladen.
2. Die Datei per Doppelklick starten. Es braucht **keine Administratorrechte**.
3. Den Anweisungen folgen und auf **Installieren** und dann **Fertigstellen** klicken. Das Setup legt eine Verknüpfung im Startmenü und auf dem Desktop an.

### Wenn sich das Setup nicht öffnet

Die Datei ist nicht kostenpflichtig signiert. Windows ist deshalb vorsichtig:

- **„Der Computer wurde durch Windows geschützt“:** auf **Weitere Informationen → Trotzdem ausführen** klicken.
- **Es passiert gar nichts:** Rechtsklick auf die Datei → **Eigenschaften** → unten bei „Sicherheit“ **Zulassen** anhaken → **OK**, dann nochmals starten.
- **Datei liegt in OneDrive:** Die Datei zuerst nach `Downloads` kopieren und dort starten.
- **Immer noch nichts:** Unter **Windows-Sicherheit → Schutzverlauf** nachsehen, ob die Datei blockiert wurde.

## Ersteinrichtung

Beim ersten Start fragt ArenaFX, wie du beginnen möchtest. Ein Standardpasswort gibt es nicht, du legst alle Zugangsdaten selbst fest.

### Neu einrichten

1. Sprache oben rechts wählen.
2. **Vereinsname**, **Name des ersten Teams** und dessen **Passwort** (mindestens 4 Zeichen) eingeben.
3. **Admin-Zugang** festlegen: Benutzername und Passwort (mindestens 8 Zeichen), Passwort wiederholen.
4. **Einrichtung abschliessen**. Du bist danach als Admin angemeldet und kannst Logo, Musik, Effekte und weitere Teams einrichten.

Das Admin-Passwort gut aufbewahren, zum Beispiel im Passwort-Manager. Ohne dieses Passwort kommt niemand mehr in die Einstellungen.

### Aus alter Version übernehmen

Du hast bisher ArenaFX 1.x oder die Web-Soundbox (PHP/XAMPP) benutzt? Dann übernimm die Daten:

1. **Aus alter Version übernehmen** wählen.
2. **Ordner auswählen …** und den Ordner der alten Installation wählen, also den Ordner, in dem `media` liegt. Ein Backup-Ordner der alten Version geht auch.
3. ArenaFX zeigt an, was gefunden wurde: Teams, Anzahl Songs und Effekte.
4. Einen neuen Admin-Zugang festlegen und **Einrichtung abschliessen**.

Übernommen werden Vereinsname, Sprache, Teams mit Passwörtern, allgemeine und Team-Musik, Effekte mit Beschriftung, Logo und Tastenkürzel. Die alten Dateien bleiben unverändert. Teams, die bisher **kein Passwort** hatten, werden gesperrt übernommen. Gib ihnen danach unter **Einstellungen → Teams** ein Passwort.

Die alte Version danach nicht mehr parallel starten, damit nicht zwei Soundboxen gleichzeitig laufen.

## Wo liegen meine Daten?

Alle Einstellungen, Musik, Effekte und das Logo liegen im Datenordner:

```text
%APPDATA%\ch.kammermann.arenafx
```

Am einfachsten öffnest du ihn über **Einstellungen → Backup & Daten → Datenordner öffnen**.

- Der Datenordner bleibt bei **Updates** und beim **Deinstallieren** erhalten.
- Passwörter werden nur verschlüsselt (als Hash) gespeichert.
- Die vorherige Fassung der Einstellungen wird automatisch aufbewahrt. Ist die Datei einmal beschädigt, lädt ArenaFX die Sicherung und zeigt einen Hinweis an.

## Auf einen anderen Computer umziehen

1. Auf dem alten Computer: **Einstellungen → Backup & Daten → Backup speichern …**, zum Beispiel auf einen USB-Stick.
2. Auf dem neuen Computer ArenaFX installieren und einrichten.
3. **Backup & Daten → Backup einspielen …** und die ZIP-Datei wählen.
4. Mit den Zugangsdaten aus dem Backup anmelden.

## Updates

Unter **Einstellungen → Update** sucht ArenaFX nach einer neuen Version. Dafür braucht der Computer eine Internetverbindung. Updates sind signiert, ArenaFX installiert nur geprüfte Pakete. Musik, Teams und Einstellungen bleiben erhalten.

Nie während eines Spiels aktualisieren.

## Deinstallieren

Über **Windows-Einstellungen → Apps → ArenaFX → Deinstallieren**. Der Datenordner bleibt erhalten. Wer auch ihn entfernen möchte, löscht danach den Ordner `%APPDATA%\ch.kammermann.arenafx` von Hand. Vorher ein Backup machen.

## Musik besorgen

Eigene Musik kannst du zum Beispiel aus deiner Musiksammlung als MP3 übernehmen. Webradio-Mitschnitte sind etwa mit [streamWriter](https://streamwriter.org/de) möglich.

**Wichtig:** Das Abspielen von Musik in einer Halle mit Publikum ist eine öffentliche Aufführung und lizenzpflichtig, in der Schweiz über die [SUISA](https://www.suisa.ch). Kläre das mit deinem Verein oder dem Hallenbetreiber.
