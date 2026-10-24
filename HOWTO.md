# Soundbox – Installations- und Bedienungsanleitung

## 1. Installation unter Windows mit XAMPP

### Voraussetzungen

- Windows mit installiertem XAMPP und PHP.
- Apache muss laufen. Für Datei-Uploads muss außerdem die PHP-Erweiterung `fileinfo` aktiviert sein.
- Apache muss `.htaccess`-Regeln für den Projektordner erlauben (`AllowOverride` nicht auf `None` setzen). Ohne diese Regel kann Apache die Sperre des `media`-Ordners ignorieren.
- Es wird keine Datenbank und keine zusätzliche PHP-Bibliothek benötigt.

### Schritte

1. Starte das XAMPP Control Panel und aktiviere **Apache**.
2. Lege den Projektordner unter `C:\xampp\htdocs\` ab und nenne ihn `soundbox` (beim Klonen: `git clone https://github.com/Kammermanns/ArenaFX soundbox`). Die PHP-Dateien müssen direkt darin liegen, zum Beispiel `C:\xampp\htdocs\soundbox\setup.php`. Bei einem anderen Ordnernamen ändern sich die URLs in dieser Anleitung entsprechend.
3. Apache/PHP muss im Projektordner und in `media` schreiben können, damit die Erstkonfiguration und Uploads erstellt werden können. Vergib nur nötige Schreibrechte.
4. Öffne beim ersten Aufruf `http://localhost/soundbox/setup.php` (der Login leitet ohne Konfiguration automatisch dorthin weiter).
5. Lege Admin-Benutzername und ein starkes Passwort fest, gib einen Ordnernamen für das erste Team sowie den Vereinsnamen an und lade das PNG-/JPG-Vereinslogo hoch.
6. Nach erfolgreicher Ersteinrichtung wirst du als Admin angemeldet. Lege im Admin-Bereich anschließend Passwörter für die Teams an.
7. Bei vorhandener `media/config.json` ist die Installation bereits eingerichtet; `setup.php` ist dann gesperrt und verweist zurück zum Login.

### Upload-Größe in PHP

Die Anwendung akzeptiert Mediendateien bis 25 MB pro Datei. Falls größere Uploads bereits beim Übertragen abgebrochen werden, prüfe in der von XAMPP verwendeten `php.ini` die Werte `upload_max_filesize` (mindestens `25M`) und `post_max_size` (etwas höher, zum Beispiel `30M`). Das Wiederherstellen eines Backups erfordert, dass beide PHP-Grenzen mindestens so groß wie die ausgewählte Backup-Datei sind; außerdem begrenzt die Anwendung Backups auf 1 GB. Starte Apache nach einer Änderung neu.

### Sicherung und Aktualisierung

Sichere vor Änderungen mindestens den Ordner `media` (darin liegen Konfiguration, Teams, Musik, Effekte und Logo). Beim Aktualisieren den bestehenden `media`-Ordner nicht versehentlich überschreiben oder löschen.

## 2. Starten und anmelden

1. Starte XAMPP und aktiviere Apache.
2. Öffne die Soundbox unter `http://localhost/soundbox/login.php`.
3. Wähle Admin oder dein Team aus und gib das dazugehörige Passwort ein.

## 3. Anmelden

- **Admin:** Wähle `Admin` aus der Dropdown-Liste und gib den bei der Einrichtung festgelegten Admin-Benutzernamen sowie das Admin-Passwort ein.
- **Team:** Wähle das gewünschte Team aus und gib dessen Team-Passwort ein.
- Nach erfolgreicher Anmeldung wird der Benutzer automatisch zur Soundbox oder zum Einstellungsbereich weitergeleitet.
- Ein bereits angemeldeter Benutzer wird beim erneuten Öffnen des Logins automatisch weitergeleitet.

## 4. Soundbox verwenden

1. Auf der Hauptseite werden die Musikstücke und Effekt-Töne des angemeldeten Teams angezeigt.
2. Musik wird über den Play-Button gestartet.
3. Effekte können über die vorhandenen Effekt-Buttons abgespielt werden.
4. Allgemeine Musik ist ein Fallback für alle Teams. Team-spezifische Musik wird bevorzugt.

## 5. Als Admin verwalten

Nach der Anmeldung öffnest du den Abschnitt **Admin-Bereich**.

Der Adminbereich ist in **Allgemein**, **Teams**, **Musik**, **Effekte** und **Backup** aufgeteilt. Die Übersicht oben zeigt die Anzahl der Teams, Teams mit gesetztem Passwort und Musikdateien. Über die Bereichsnavigation wechselst du direkt zum gewünschten Verwaltungsbereich.

### Admin-Zugang ändern

1. Öffne den Admin-Bereich.
2. Gib zur Bestätigung das aktuelle Admin-Passwort ein.
3. Passe den Benutzernamen an (3–50 Zeichen) und gib optional ein neues Passwort mit mindestens 12 Zeichen ein. Bleibt das Feld leer, bleibt das Passwort unverändert.
4. Speichere die Änderung und merke dir den Benutzernamen – er wird bei der Anmeldung benötigt.

### Teams verwalten

1. Öffne **Mannschaften verwalten**.
2. Teamnamen eingeben. Die Ordner-ID wird automatisch und sicher aus dem Namen gebildet; bei gleichen Slugs ergänzt das System eine Nummer.
3. Teamnamen müssen eindeutig sein. Doppelte Namen werden im Formular markiert und auch serverseitig abgewiesen.
4. Für Teams ohne Passwort ein neues Passwort mit mindestens 8 Zeichen eintragen. Die Teamliste zeigt an, ob bereits ein Passwort gesetzt ist.
5. Bei bestehenden Teams kann das Passwortfeld leer bleiben, wenn das Passwort unverändert bleiben soll.
6. Mit **Teams speichern** werden die Änderungen übernommen. Passwörter werden als Hash gespeichert und nach dem Speichern nicht wieder angezeigt.
7. Ein Team kann über die Entfernen-Schaltfläche gelöscht werden.

> Vor der ersten Nutzung müssen für alle Teams ohne Passwort Zugangsdaten durch den Admin gesetzt und sicher an die jeweiligen Teamverantwortlichen übergeben werden.

### Backup und Wiederherstellung

1. Im Admin-Bereich unter **Backup und Wiederherstellung** auf **Backup herunterladen** klicken. Die Datei enthält `config.json`, Logo, Musik und Effekte einschließlich Passwort-Hashes. Sie muss vertraulich und getrennt vom Webserver aufbewahrt werden.
2. Für eine Wiederherstellung ein zuvor heruntergeladenes `.tar.gz`-Backup auswählen, das Ersetzen der aktuellen Daten bestätigen und **Backup wiederherstellen** klicken.
3. Die Wiederherstellung ist nur als Admin möglich und ersetzt Konfiguration sowie Medien. Danach ist eine erneute Anmeldung erforderlich.
4. Vor einer Wiederherstellung immer zuerst ein aktuelles Backup herunterladen. Regelmäßig prüfen, dass Backups lesbar sind.

### Medienzugriff und Netzwerkbetrieb

- Medien unter `media` sind nicht mehr direkt per URL abrufbar. Angemeldete Nutzer erhalten Musik und Effekte über einen authentifizierten PHP-Endpunkt; Teamkonten sind auf die eigenen Teamdateien beschränkt.
- Nach der Installation prüfen, dass `http://localhost/soundbox/media/config.json` **403 Forbidden** liefert. Liefert Apache JSON aus, nicht im Netzwerk freigeben und zuerst `.htaccess`/`AllowOverride` korrigieren.
- Der Login begrenzt fehlgeschlagene Versuche pro IP-Adresse auf acht innerhalb von 15 Minuten. Nach erfolgreichem Login wird der Zähler zurückgesetzt.
- Team-Musik-Uploads dürfen nur in das angemeldete Teamverzeichnis schreiben. Dateinamen, Team-IDs und Dateitypen werden serverseitig geprüft.
- Für Zugriff über ein Netzwerk HTTPS über einen korrekt eingerichteten Reverse Proxy oder Webserver verwenden. Nicht über eine unverschlüsselte Verbindung ins Internet freigeben.

### Logo hochladen

1. Wähle ein PNG- oder JPG-Bild.
2. Speichere das Bild.
3. Das Logo wird sofort in der Vorschau angezeigt.

### Musik verwalten

1. Unter **Unterbruchsmusik hochladen** kann Musik für alle Teams oder ein bestimmtes Team ausgewählt werden.
2. Die Zugriffsrechte werden serverseitig geprüft.
3. Hochgeladene Dateien können über die Dateiliste gelöscht werden.
4. Unterstützte Formate: MP3, WAV und OGG.
5. Die maximale Dateigröße beträgt 25 MB pro Datei.

### Effekt-Buttons verwalten

1. Wähle eine Mannschaft aus.
2. Lade für einen freien Effekt-Slot eine MP3-, WAV- oder OGG-Datei hoch.
3. Alternativ kann ein vorhandener Effekt durch einen neuen Dateinamen ersetzt werden.
4. Das Objekt wird im Effekt-Slot gespeichert.

## 6. Als Team verwalten

Nach der Anmeldung steht der **Team-Bereich** zur Verfügung.

### Musik hochladen

1. Öffne die Musikverwaltung im Team-Bereich.
2. Wähle eine oder mehrere MP3-, WAV- oder OGG-Dateien.
3. Speichere die Dateien.
4. Die Dateien werden nur im eigenen Teamordner abgelegt.

### Effekt-Buttons bearbeiten

1. Öffne den Bereich **Effekt-Buttons anpassen**.
2. Lade für einen Slot eine Datei hoch oder ersetze eine vorhandene Datei.
3. Ein Team kann nur seine eigenen Effekt-Slots verändern.

### Team-Einstellungen

Ein Team kann nur die Dateien und Einstellungen seines eigenen Teams ändern. Die Verwaltung anderer Teams oder des Admin-Zugangs ist nicht möglich.

## 7. Abmelden

1. Öffne den Einstellungsbereich.
2. Klicke auf **Abmelden**.
3. Danach wird die Sitzung beendet und du gelangst wieder zum Login.

## 8. Hinweise zur Sicherheit

- Passwortänderungen und Team-Änderungen werden mit einem Sicherheits-Token geschützt.
- Team-Benutzer können nur ihre eigenen Dateien verwalten.
- Admin-Funktionen sind nur für den Admin-Zugang erreichbar.
- Ist `media/config.json` beschädigt, verweigert die Anwendung jede Anmeldung, statt auf Standardzugangsdaten zurückzufallen. In diesem Fall die Datei aus einem Backup wiederherstellen.
- Der Ordner `media` ist per `.gitignore` von Git ausgeschlossen, damit Konfiguration, Passwort-Hashes und Mediendateien nicht versehentlich ins Repository gelangen.
- Hochgeladene Dateien werden auf Typ, Erweiterung und Größe geprüft.
- Die Anwendung sollte nur im lokalen Netzwerk oder hinter einer externen Authentifizierung verwendet werden.
