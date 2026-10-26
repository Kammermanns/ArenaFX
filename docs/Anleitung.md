# ArenaFX – Anleitung

ArenaFX ist eine Regie-Software für den Eishockeysport. Sie steuert Hallenmusik, Durchsagen und Soundeffekte und ist für den Einsatz am Regietisch gebaut: wenige grosse Bedienelemente, alles auch über den Ziffernblock, damit unter Zeitdruck nichts schiefgeht.

## Hauptmerkmale

- **Intelligente Musiksteuerung:** zufällige Titelauswahl ohne Wiederholungen. Bei Spielunterbrechungen startet die Musik mitten im Song, ideal für kurze Pausen.
- **Warm-Up-Modus:** Songs laufen von Beginn an und nahtlos nacheinander, zum Beispiel beim Aufwärmen der Teams.
- **Durchsage-Absenkung:** Per Knopfdruck wird die Musik sanft um 80 % leiser, damit Hallendurchsagen klar verständlich sind.
- **Effekt-Soundboard:** 6 Schnellzugriff-Buttons für Torsounds, Strafen, Jingles und Orgel-Einspieler. Die Effekte starten ohne Verzögerung.
- **Tastenkürzel:** Bedienung über den Ziffernblock ohne Maus, frei belegbar.
- **Teams und Sprachen:** eigene Musik und Effekte pro Mannschaft, Bedienung auf Deutsch, Französisch und Englisch.
- **Offline:** ArenaFX braucht im Spielbetrieb kein Internet.

---

## Anmeldung

1. Starte **ArenaFX**.
2. Wähle auf der Anmeldeseite deine Mannschaft oder **Admin** aus.
   - Teams melden sich nur mit ihrem Passwort an.
   - Als Admin gibst du zusätzlich deinen Benutzernamen ein.
3. Klicke auf **Anmelden**.

Teams werden direkt zur Soundbox geführt, der Admin zu den Einstellungen. Ein Team mit dem Zusatz „gesperrt“ hat noch kein Passwort. Der Admin vergibt es unter **Einstellungen → Teams**.

Nach fünf falschen Passwörtern ist die Anmeldung für 30 Sekunden gesperrt.

## Die Soundbox (Hauptansicht)

Oben siehst du das aktive Team und darunter, welche Musik gespielt wird: Team-Musik oder allgemeine Musik. Als Admin kannst du oben das Team wechseln, dessen Musik und Effekte gespielt werden.

Alle Funktionen lassen sich per Maus oder über die Tastatur bedienen. Die zugeordnete Taste steht jeweils klein auf dem Button.

### Musik abspielen und stoppen

1. **Starten:** Klicke auf **MUSIK START** oder drücke **Num +**.
2. **Normaler Spielmodus:** Der Song startet an einer zufälligen Stelle, damit bei kurzen Unterbrechungen sofort Stimmung entsteht.
3. **Stoppen:** Nochmals **Num +** drücken. Die Musik blendet sanft aus.

Kein Song wiederholt sich, bevor alle einmal gelaufen sind. Der nächste Song wird im Hintergrund schon vorgeladen, damit er ohne Wartezeit startet.

### Warm-Up-Modus

1. Setze vor dem Aufwärmen das Häkchen bei **Warm-Up-Modus**.
2. Starte die Musik mit **Num +**.
3. Alle Titel starten von Beginn an und laufen ohne Unterbrechung nacheinander.

### Durchsage

1. Wenn der Hallensprecher eine Durchsage macht, klicke auf **DURCHSAGE** oder drücke **Num 0**.
2. Die Musik wird um 80 % leiser, der Button leuchtet orange.
3. Nach der Durchsage nochmals **Num 0** drücken. Die Musik kommt auf die normale Lautstärke zurück.

### Soundeffekte (Torsounds und Jingles)

1. Klicke auf einen der Effekt-Buttons oder drücke **Num 1** bis **Num 6**.
2. Laufende Musik stoppt sofort und der Effekt wird abgespielt.
3. Läuft ein Effekt, stoppt **Num +** zuerst den Effekt.

### Lautstärke

Der Regler stellt die Gesamtlautstärke ein. ArenaFX merkt sich den Wert für den nächsten Start.

## Tastenkürzel

Standardbelegung auf dem Ziffernblock:

| Taste | Funktion |
|---|---|
| `Num +` | Musik Start / Stopp |
| `Num 0` | Durchsage ein / aus (Musik −80 %) |
| `Num 1` | Effekt 1 (z. B. Tor) |
| `Num 2` | Effekt 2 (z. B. Strafe) |
| `Num 3` | Effekt 3 |
| `Num 4` | Effekt 4 |
| `Num 5` | Effekt 5 |
| `Num 6` | Effekt 6 |

Wichtig:

- Die Tastenkürzel funktionieren nur, solange das **ArenaFX-Fenster aktiv** ist. Nach einem Klick in ein anderes Programm zuerst wieder ins ArenaFX-Fenster klicken.
- Die **Num-Taste** (Num Lock) sollte eingeschaltet sein.
- Der Admin kann jede Funktion unter **Einstellungen → Tastenkürzel** auf eine andere Taste legen. Doppelte Belegungen werden markiert und nicht gespeichert.

## Musik und Effekte verwalten

Unterstützte Formate: MP3, WAV, OGG, M4A und FLAC. Dateien, die keine Audiodateien sind, werden beim Hinzufügen erkannt und übersprungen.

### Als Team

1. Klicke in der Soundbox oben rechts auf **Einstellungen**.
2. **Musik:** Klicke auf **Musik hinzufügen …** und wähle eine oder mehrere Dateien aus. Diese Musik läuft nur für dein Team. Ohne eigene Musik läuft die allgemeine Musik des Vereins.
3. **Effekt-Buttons:** Trage bei einem Effekt eine Beschriftung ein (z. B. „Torsound“) und klicke auf **Ton wählen …**. Die Beschriftung kannst du später mit **Name speichern** ändern, ohne den Ton neu zu wählen.

### Als Admin

Die Einstellungen sind in Bereiche aufgeteilt:

| Bereich | Inhalt |
|---|---|
| **Allgemein** | Vereinsname, Sprache, Admin-Zugang, Vereinslogo |
| **Teams** | Teams anlegen, umbenennen, entfernen und Passwörter vergeben |
| **Musik** | Allgemeine Musik (für alle Teams) und Musik einzelner Teams |
| **Effekte** | Die 6 Effekt-Buttons jedes Teams |
| **Tastenkürzel** | Tastenbelegung ändern oder auf Standard zurücksetzen |
| **Backup & Daten** | Backup speichern und einspielen, alte Version übernehmen, Datenordner öffnen |
| **Update** | Nach neuen Versionen suchen und installieren |

Hinweise:

- Ein neues Team braucht ein Passwort mit mindestens 4 Zeichen. Leeres Passwortfeld bei bestehenden Teams heisst: Passwort bleibt unverändert.
- Wird ein Team entfernt, werden beim Speichern auch seine Musik und Effekte gelöscht.
- Zum Ändern des Admin-Zugangs ist das aktuelle Passwort nötig. Ein neues Admin-Passwort muss mindestens 8 Zeichen haben.

## Backup

Unter **Backup & Daten → Backup speichern …** sicherst du alle Einstellungen, Teams, Musik, Effekte und das Logo in einer einzigen ZIP-Datei, zum Beispiel auf einem USB-Stick.

Mit **Backup einspielen …** wird ein solches Backup wiederhergestellt. Dabei werden alle aktuellen Daten ersetzt. Danach meldest du dich mit den Zugangsdaten aus dem Backup an. So ziehst du ArenaFX auch auf einen anderen Computer um.

Am besten vor jedem Spieltag mit Änderungen ein Backup machen.

## Am Spieltag – kurze Checkliste

1. Laptop am Strom, Audioausgang mit der Hallenanlage verbunden, Windows-Lautstärke auf 100 %.
2. ArenaFX starten und mit dem Team anmelden.
3. Lautstärke in ArenaFX einstellen, einen Effekt und die Musik kurz testen.
4. Num Lock prüfen und ins ArenaFX-Fenster klicken, damit die Tasten wirken.
5. Keine Updates während des Spiels.
