# USB-Numpad zu einem Regiepult mit grossen Tasten umbauen

So baust du ein günstiges USB-Numpad zu einem robusten Regiepult mit grossen Tasten um, zum Beispiel mit Arcade-Buttons oder Industrie-Tastern. Ideal für den schnellen Zugriff am Spieltisch, ohne hinzuschauen.

ArenaFX reagiert auf ganz normale Tastendrücke. Ein umgebautes Numpad funktioniert deshalb ohne Treiber oder Zusatzsoftware: Für Windows bleibt es eine Tastatur.

## Belegung

Mit der Standardbelegung von ArenaFX brauchst du diese 8 Tasten:

| Taster am Pult | Numpad-Taste | Funktion in ArenaFX |
|---|---|---|
| Grosser Taster (z. B. grün) | `Num +` | Musik Start / Stopp |
| Taster (z. B. gelb) | `Num 0` | Durchsage ein / aus |
| 6 Taster | `Num 1` bis `Num 6` | Effekt 1 bis 6 |

Die Belegung lässt sich in ArenaFX unter **Einstellungen → Tastenkürzel** ändern. Du kannst also auch andere Numpad-Tasten verdrahten und sie danach in ArenaFX zuordnen.

## Material und Werkzeug

**Material:**

- **USB-Numpad:** am besten ein mechanisches Numpad mit echten Schaltern (Switches). Die lassen sich deutlich einfacher löten als ein Membran-Numpad.
- **Grosse Taster:** Arcade-Buttons (z. B. 28 mm oder 30 mm Durchmesser) oder Industrie-Drucktaster.
- **Gehäuse:** Kunststoff-Schaltgehäuse, Holzbox oder ein Gehäuse aus dem 3D-Drucker.
- **Schaltlitze:** dünne Flexkabel, z. B. AWG 24 oder AWG 26.
- **Isolierband oder Schrumpfschlauch** und Heisskleber.

**Werkzeug:**

- Lötkolben und Lötzinn
- Schraubendreher
- Stufenbohrer oder Lochsäge, passend zum Durchmesser der Taster
- Abisolierzange und Seitenschneider

## Schritt für Schritt

> **Sicherheitshinweis:** Das USB-Kabel während aller Löt- und Montagearbeiten **immer** vom Computer trennen.

### Schritt 1: Gehäuse vorbereiten

1. Zeichne die Positionen der Taster auf dem Gehäusedeckel an, zum Beispiel ein grosser Button für Musik Start/Stopp, ein Button für die Durchsage und sechs Buttons für die Effekte. Den Musik-Taster so platzieren, dass er blind zu finden ist.
2. Bohre die Löcher mit dem Stufenbohrer vorsichtig auf die benötigte Grösse (meistens 28 mm oder 30 mm).
3. Entgrate die Löcher und schraube die Taster fest.

### Schritt 2: Numpad zerlegen und Platine freilegen

1. Löse die Schrauben auf der Rückseite des Numpads und öffne das Gehäuse.
2. Nimm die Platine (PCB) heraus.
3. **Mechanisches Numpad:** Auf der Rückseite der Platine hat jede Taste zwei Lötpunkte. Dort lötest du direkt deine Kabel an.
4. **Membran-Numpad:** Hier liegen zwei Kunststofffolien über der Platine. Verfolge die Leiterbahnen der gewünschten Tasten (`Num +`, `Num 0`, `Num 1` bis `Num 6`) bis zum Stecker auf der Hauptplatine und greife dort die Lötpunkte ab. Das ist deutlich kniffliger.

### Schritt 3: Kabel anlöten

1. Schneide für jeden Taster zwei Kabelstücke zu (etwa 15–20 cm).
2. Löte pro Taste zwei Kabel an die beiden Lötpunkte der entsprechenden Numpad-Taste.
3. Fixiere die Lötstellen mit etwas Heisskleber als Zugentlastung, damit die Kabel nicht abreissen.
4. Beschrifte die Kabelpaare (z. B. mit Klebeband), damit du sie beim Verdrahten nicht verwechselst.

### Schritt 4: Taster verkabeln

1. Verbinde die beiden Kabel jeder Taste mit den beiden Anschlüssen des Mikroschalters im zugehörigen Taster. Bei Arcade-Buttons sind das meistens „COM“ und „NO“ (Schliesser).
2. Die Polung spielt bei einfachen Tastern keine Rolle.

### Schritt 5: Einbau und Funktionstest

1. Schliesse das USB-Kabel an den Computer an.
2. Teste jede Taste: Öffne in ArenaFX als Admin **Einstellungen → Tastenkürzel**, klicke in ein Feld und drücke den Taster am Pult. Im Feld erscheint die erkannte Taste, z. B. „Num 1“. Danach die Änderung nicht speichern, oder mit **Standard wiederherstellen** zurücksetzen.
3. Alternativ in der Soundbox testen: Jeder Taster muss die passende Funktion auslösen.
4. Wenn alles funktioniert, fixiere die Platine im Gehäuse, z. B. mit Heisskleber oder Abstandshaltern.
5. Führe das USB-Kabel durch eine Öffnung mit Zugentlastung nach aussen und schraube das Gehäuse zu.

## Tipps für den Einsatz

- **Num Lock einschalten.** Bei einem separaten Numpad liegt die Num-Taste auf dem Pult selbst. Sie kann beim Umbau ebenfalls auf einen (kleinen, versteckten) Taster gelegt oder fest eingeschaltet bleiben.
- **ArenaFX-Fenster aktiv lassen.** Die Tasten wirken nur, solange ArenaFX im Vordergrund ist. Nach einem Klick in ein anderes Programm zuerst wieder ins ArenaFX-Fenster klicken.
- Vor dem Spiel jeden Taster einmal kurz testen.

## Alternativen ohne Löten

**USB-Tastatur-Encoder oder programmierbares Makro-Keypad.** Es gibt fertige Platinen und Makro-Tastenfelder, die sich als **USB-Tastatur** melden und frei programmierbare Tasten haben. Die Taster werden nur angesteckt oder sind schon eingebaut. Beim Kauf darauf achten, dass das Gerät als Tastatur („HID Keyboard“) arbeitet. In ArenaFX ordnest du die gesendeten Tasten dann unter **Tastenkürzel** zu.

**Achtung bei „Zero Delay USB Arcade Encodern“** (ca. 8–12 €): Diese günstigen Platinen melden sich bei Windows als **Gamecontroller, nicht als Tastatur**. ArenaFX reagiert darauf nicht direkt. Damit es trotzdem funktioniert, brauchst du ein Zusatzprogramm, das Controller-Tasten in Tastendrücke umwandelt, zum Beispiel AntiMicroX oder JoyToKey. Einfacher und zuverlässiger am Spieltag ist ein Encoder, der direkt als Tastatur arbeitet.
