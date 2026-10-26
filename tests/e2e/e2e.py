"""End-to-End-Test der echten ArenaFX-App (Debug-Build) über tauri-driver.

Aufruf: tests/e2e/run.sh (startet Xvfb, tauri-driver und diesen Test).
"""
import json, math, os, shutil, struct, subprocess, sys, time, wave, zipfile
from pathlib import Path

from selenium import webdriver
from selenium.common.exceptions import TimeoutException, StaleElementReferenceException, NoSuchElementException
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.common.options import ArgOptions
from selenium.webdriver.support.ui import Select, WebDriverWait

WORK = Path(sys.argv[1])
APP = sys.argv[2]
DATA = WORK / 'data'
FILES = WORK / 'files'
DIALOGS = WORK / 'dialogs.json'
SHOTS = WORK / 'shots'
results = []


def check(name, ok, info=''):
    results.append((name, bool(ok), info))
    print(('OK   ' if ok else 'FAIL ') + name + (f'  [{info}]' if info and not ok else ''), flush=True)


def tone(path, seconds=1.0, freq=440):
    with wave.open(str(path), 'wb') as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(22050)
        w.writeframes(b''.join(struct.pack('<h', int(8000 * math.sin(2 * math.pi * freq * i / 22050))) for i in range(int(22050 * seconds))))


def dialogs(*answers):
    DIALOGS.write_text(json.dumps([str(a) if isinstance(a, Path) else ([str(x) for x in a] if isinstance(a, list) else a) for a in answers]))


def prepare():
    shutil.rmtree(WORK / 'data', ignore_errors=True)
    shutil.rmtree(FILES, ignore_errors=True)
    FILES.mkdir(parents=True)
    SHOTS.mkdir(exist_ok=True)
    tone(FILES / 'Song Eins.wav', 30)
    tone(FILES / "Don't Stop.wav", 25, 660)
    tone(FILES / 'Horn.wav', 1.0, 880)
    (FILES / 'fake.mp3').write_bytes(b'MZ das ist keine Musik')
    import base64
    (FILES / 'logo.png').write_bytes(base64.b64decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='))
    # Alte Electron-Installation
    old = FILES / 'Arenafx-alt' / 'media'
    (old / 'allgemein/musik').mkdir(parents=True)
    (old / 'teams/Standard/musik').mkdir(parents=True)
    (old / 'teams/EHC/effekte').mkdir(parents=True)
    (old / 'teams/EHC/musik').mkdir(parents=True)
    (old / 'config.json').write_text(json.dumps({
        'app_name': 'Alte Arena', 'language': 'de', 'admin': {'username': 'admin', 'password': 'admin123'},
        'teams': [{'id': 'Standard', 'name': 'Standard', 'password': ''}, {'id': 'EHC', 'name': 'EHC Alt', 'password': 'altpass'}],
        'hotkeys': {'music': 'Space', 'durchsage': 'num0', 'effect_1': 'num1', 'effect_2': 'num2', 'effect_3': 'num3',
                    'effect_4': 'num4', 'effect_5': 'num5', 'effect_6': 'num6'}}))
    shutil.copy(FILES / 'Song Eins.wav', old / 'allgemein/musik/Alt Hymne.wav')
    shutil.copy(FILES / 'Horn.wav', old / 'teams/EHC/effekte/02_Tor_Horn.wav')


class App:
    def __init__(self):
        opts = ArgOptions()
        opts.set_capability('tauri:options', {'application': APP})
        opts.set_capability('browserName', 'wry')
        self.d = webdriver.Remote('http://127.0.0.1:4444', options=opts)
        self.d.set_window_size(1280, 900)

    def wait(self, cond, timeout=15):
        return WebDriverWait(self.d, timeout, poll_frequency=0.2, ignored_exceptions=(StaleElementReferenceException, NoSuchElementException)).until(lambda d: cond())

    def page(self):
        return self.d.execute_script('return location.pathname.split("/").pop()')

    def wait_page(self, name, timeout=15):
        self.wait(lambda: self.page() == name, timeout)
        self.wait(lambda: self.d.execute_script('return document.readyState') == 'complete')
        time.sleep(0.6)
        self.d.execute_script('window.confirm = () => true;')

    def q(self, css):
        return self.d.find_element(By.CSS_SELECTOR, css)

    def qa(self, css):
        return self.d.find_elements(By.CSS_SELECTOR, css)

    def text(self, css):
        return self.q(css).text

    def type(self, css, value):
        element = self.q(css)
        element.clear()
        element.send_keys(value)

    def feedback(self, area, timeout=15):
        def done():
            box = self.q(f'[data-feedback="{area}"]')
            cls = box.get_attribute('class')
            return box.is_displayed() and 'pending' not in cls and box.text
        try:
            self.wait(done, timeout)
        except TimeoutException:
            pass
        box = self.q(f'[data-feedback="{area}"]')
        return box.get_attribute('class'), box.text

    def tab(self, name):
        self.q(f'[data-tab="{name}"]').click()
        time.sleep(0.5)

    def invoke(self, command, args):
        return self.d.execute_async_script(
            'const done = arguments[arguments.length - 1];'
            'window.__TAURI__.core.invoke(arguments[0], arguments[1]).then(r => done({ok: r}), e => done({err: e}));',
            command, args)

    def shot(self, name):
        self.d.save_screenshot(str(SHOTS / f'{name}.png'))

    def quit(self):
        self.d.quit()


def main():
    prepare()

    # 1) Erster Start -> Einrichtung
    app = App()
    app.wait_page('setup.html')
    check('Erster Start öffnet die Einrichtung', True)
    Select(app.q('#setup-language')).select_by_value('de')
    time.sleep(0.5)
    app.shot('01-setup')
    app.type('#app-name', 'EHC Testverein')
    app.type('#team-name', 'EHC Zürich')
    app.type('#team-password', 'team1')
    app.type('#admin-username', 'Stefan')
    app.type('#admin-password', 'SicheresPW1')
    app.type('#admin-password-repeat', 'anderes')
    app.q('#setup-button').click()
    time.sleep(0.5)
    check('Passwort-Wiederholung wird geprüft', 'stimmen nicht' in app.text('#setup-message'), app.text('#setup-message'))
    app.type('#admin-password', 'kurz')
    app.type('#admin-password-repeat', 'kurz')
    app.q('#setup-button').click()
    app.wait(lambda: app.q('#setup-message').is_displayed() and app.text('#setup-message'))
    check('Zu kurzes Admin-Passwort abgelehnt', '8 Zeichen' in app.text('#setup-message'), app.text('#setup-message'))
    app.type('#admin-password', 'SicheresPW1')
    app.type('#admin-password-repeat', 'SicheresPW1')
    app.q('#setup-button').click()
    app.wait_page('settings.html')
    check('Einrichtung abgeschlossen, Admin angemeldet', 'administration' in app.text('#header-kicker').lower())
    raw = (DATA / 'config.json').read_text()
    check('Keine Klartext-Passwörter in config.json', 'SicheresPW1' not in raw and 'team1' not in raw and '$argon2id$' in raw)

    # 2) Allgemein
    app.type('#app-name', 'EHC Arena 2026')
    app.q('#form-app-name button').click()
    cls, txt = app.feedback('general')
    check('Vereinsname gespeichert', 'success' in cls and app.text('#stat-club-name') == 'EHC Arena 2026', txt)
    app.type('#admin-current', 'falsch')
    app.q('#form-admin button').click()
    cls, txt = app.feedback('general')
    check('Admin ändern mit falschem Passwort abgelehnt', 'error' in cls and 'aktuelle Passwort' in txt, txt)
    dialogs(FILES / 'logo.png')
    app.q('#pick-logo').click()
    cls, txt = app.feedback('general')
    app.wait(lambda: app.q('#logo-preview').is_displayed())
    check('Logo über Dialog gesetzt und angezeigt', 'success' in cls, txt)

    # 3) Teams
    app.tab('teams')
    app.q('#add-team').click()
    rows = app.qa('#teams-list .team-item')
    inputs = rows[-1].find_elements(By.CSS_SELECTOR, 'input')
    inputs[0].send_keys('ehc zürich')
    inputs[1].send_keys('jun1')
    app.q('#save-teams').click()
    cls, txt = app.feedback('teams')
    check('Doppelter Teamname abgelehnt (Gross-/Kleinschreibung egal)', 'error' in cls and 'mehrfach' in txt, txt)
    rows = app.qa('#teams-list .team-item')
    name = rows[-1].find_elements(By.CSS_SELECTOR, 'input')[0]
    name.clear(); name.send_keys('Junioren')
    app.q('#save-teams').click()
    cls, txt = app.feedback('teams')
    check('Neues Team gespeichert', 'success' in cls and app.text('#stat-team-count') == '2', txt)

    # 4) Musik
    app.tab('music')
    dialogs([FILES / 'Song Eins.wav', FILES / "Don't Stop.wav", FILES / 'fake.mp3'])
    app.q('#add-music').click()
    cls, txt = app.feedback('music')
    check('Musik hinzugefügt, Fälschung erkannt', '2 Dateien' in txt and 'fake.mp3' in txt and 'keine Audiodatei' in txt, txt)
    names = [e.text for e in app.qa('#music-list .file-name')]
    check('Musikliste zeigt beide Songs', names == ["Don't Stop", 'Song Eins'], str(names))
    app.qa('#music-list .file-row')[0].find_element(By.CSS_SELECTOR, 'button').click()
    cls, txt = app.feedback('music')
    time.sleep(0.5)
    names = [e.text for e in app.qa('#music-list .file-name')]
    check("Song mit Apostroph lässt sich löschen", names == ['Song Eins'] and 'success' in cls, f'{names} {txt}')
    app.shot('04-musik')

    # 5) Effekte
    app.tab('effects')
    Select(app.q('#effect-team')).select_by_visible_text('EHC Zürich')
    time.sleep(0.8)
    slot = app.qa('#effects-builder .effect-slot')[0]
    label = slot.find_element(By.CSS_SELECTOR, 'input')
    label.clear(); label.send_keys('Tor für Zürich!')
    dialogs(FILES / 'Horn.wav')
    slot.find_element(By.CSS_SELECTOR, 'button').click()
    cls, txt = app.feedback('effects')
    time.sleep(0.8)
    slot = app.qa('#effects-builder .effect-slot')[0]
    check('Effekt mit Umlauten gespeichert', 'success' in cls and slot.find_element(By.CSS_SELECTOR, 'input').get_attribute('value') == 'Tor für Zürich!'
          and 'Horn.wav' in slot.text, f'{txt} | {slot.text}')

    # 6) Tastenkürzel
    app.tab('hotkeys')
    fields = app.qa('#hotkey-grid input')
    fields[2].click()
    fields[2].send_keys(Keys.SPACE)
    time.sleep(0.3)
    fields = app.qa('#hotkey-grid input')
    fields[3].click(); fields[3].send_keys(Keys.SPACE)
    time.sleep(0.3)
    dup = [f.get_attribute('aria-invalid') for f in app.qa('#hotkey-grid input')]
    check('Doppelte Taste wird markiert', dup.count('true') == 2, str(dup))
    app.q('#form-hotkeys button[type=submit]').click()
    cls, txt = app.feedback('hotkeys')
    check('Doppelte Taste wird beim Speichern abgelehnt', 'error' in cls, txt)
    app.q('#reset-hotkeys').click()
    app.q('#form-hotkeys button[type=submit]').click()
    cls, txt = app.feedback('hotkeys')
    check('Standard-Tastenkürzel gespeichert', 'success' in cls, txt)

    # 7) Backup
    app.tab('data')
    backup_path = WORK / 'sicherung.zip'
    dialogs(backup_path)
    app.q('#export-backup').click()
    cls, txt = app.feedback('data', 30)
    entries = zipfile.ZipFile(backup_path).namelist() if backup_path.exists() else []
    check('Backup als ZIP gespeichert', 'success' in cls and 'config.json' in entries and any('effekte/1.wav' in e for e in entries), f'{txt} {entries}')
    app.shot('07-backup')
    app.quit()

    # 8) Team-Anmeldung und Soundbox
    app = App()
    app.wait_page('login.html')
    options = [o.text for o in app.qa('#account option')]
    check('Login-Auswahl enthält Teams und Admin', options == ['EHC Zürich', 'Junioren', 'Admin'], str(options))
    app.type('#password', 'falsch')
    app.q('#login-button').click()
    app.wait(lambda: app.q('#login-message').is_displayed())
    check('Falsches Team-Passwort abgelehnt', 'fehlgeschlagen' in app.text('#login-message'))
    app.type('#password', 'team1')
    app.q('#login-button').click()
    app.wait_page('index.html')
    app.wait(lambda: 'Tor für Zürich!' in app.text('#effects-grid'), 20)
    check('Soundbox zeigt Team und Effekt', app.text('#display-team') == 'EHC Zürich')
    check('Allgemeine Musik als Rückfall', 'Allgemeine Musik' in app.text('#music-source'), app.text('#music-source'))
    effect_button = app.qa('#effects-grid .btn-effect')[0]
    app.wait(lambda: effect_button.is_enabled(), 20)
    check('Effekt dekodiert und spielbereit', effect_button.is_enabled())
    effect_button.click()
    time.sleep(0.6)
    check('Effekt startet', 'Tor für Zürich!' in app.text('#display-status'), app.text('#display-status'))
    app.wait(lambda: app.text('#display-status') == 'Bereit', 10)
    check('Effekt endet von selbst', True)
    app.q('body').send_keys(Keys.ADD)
    try:
        app.wait(lambda: app.text('#display-status').startswith('PLAY:'), 20)
    except TimeoutException:
        pass
    check('Musik startet per Tastenkürzel (Num +)', app.text('#display-status').startswith('PLAY: Song Eins'), app.text('#display-status'))
    app.shot('08-soundbox-play')
    app.q('#btn-durchsage').click()
    time.sleep(0.4)
    check('Durchsage aktiv', 'AKTIV' in app.text('#btn-durchsage'))
    app.q('#btn-main').click()
    time.sleep(1.3)
    check('Stopp mit Ausblenden', app.text('#display-status') == 'Bereit', app.text('#display-status'))

    # 9) Rechte: Team darf keine Admin-Befehle
    r = app.invoke('save_admin', {'currentPassword': 'x', 'username': 'hacker', 'newPassword': 'hackhackhack'})
    check('Team kann Admin-Zugang nicht ändern', r.get('err', {}).get('code') == 'err_forbidden', str(r))
    r = app.invoke('settings', {})
    check('Team sieht keine Passwort-Infos', 'ok' in r and r['ok']['adminUsername'] is None and r['ok']['teams'] == [], str(r)[:200])
    teams = json.loads((DATA / 'config.json').read_text())['teams']
    other = [t['id'] for t in teams if t['name'] == 'Junioren'][0]
    r = app.invoke('list_music', {'scope': 'team', 'teamId': other})
    check('Team sieht keine Musik fremder Teams', r.get('err', {}).get('code') == 'err_forbidden', str(r))
    r = app.invoke('read_music', {'scope': 'general', 'teamId': 'x', 'name': '../config.json'})
    check('Pfad-Tricks beim Lesen abgelehnt', r.get('err', {}).get('code') == 'err_invalid_file_name', str(r))
    r = app.invoke('plugin:dialog|open', {'options': {}})
    check('Oberfläche hat keinen direkten Dateidialog-Zugriff', 'err' in r, str(r)[:150])
    app.q('a[href="settings.html"]').click()
    app.wait_page('settings.html')
    check('Team-Bereich zeigt nur eigenes Team', app.q('#team-view').is_displayed() and not app.q('#admin-view').is_displayed())
    app.q('#logout').click()
    app.wait_page('login.html')

    # 10) Sperre nach Fehlversuchen
    Select(app.q('#account')).select_by_visible_text('Admin')
    app.type('#admin-username', 'stefan')
    for _ in range(5):
        app.type('#password', 'falsch')
        app.q('#login-button').click()
        time.sleep(0.4)
    app.type('#password', 'SicheresPW1')
    app.q('#login-button').click()
    time.sleep(0.5)
    check('Nach 5 Fehlversuchen 30 s gesperrt', 'warten' in app.text('#login-message'), app.text('#login-message'))
    app.quit()

    # 11) Backup einspielen + alte Version übernehmen
    app = App()
    app.wait_page('login.html')
    Select(app.q('#account')).select_by_visible_text('Admin')
    # Neustart der App hebt die Sperre auf (sie gilt pro Programmstart)
    app.type('#admin-username', 'STEFAN')
    app.type('#password', 'SicheresPW1')
    app.q('#login-button').click()
    app.wait_page('settings.html')
    check('Admin-Login mit Benutzername (Gross/klein egal)', True)
    app.tab('data')
    dialogs(FILES / 'Arenafx-alt')
    app.q('#pick-legacy').click()
    app.wait(lambda: app.q('#legacy-preview').is_displayed())
    check('Alte Installation erkannt', 'Alte Arena' in app.text('#legacy-preview') and 'ArenaFX 1.x' in app.text('#legacy-preview'), app.text('#legacy-preview'))
    app.d.execute_script('window.__beforeReload = true;')
    app.q('#run-legacy').click()
    app.wait(lambda: app.d.execute_script('return !window.__beforeReload'), 30)
    app.wait_page('settings.html', 30)
    app.wait(lambda: app.q('#banner').is_displayed())
    banner = app.text('#banner')
    check('Alte Version übernommen, gesperrtes Team gemeldet', '2 Teams' in banner and 'Standard' in banner, banner)
    app.tab('hotkeys')
    check('Tastenkürzel übernommen', 'Leertaste' in app.qa('#hotkey-grid input')[0].get_attribute('value'))
    app.tab('data')
    dialogs(WORK / 'sicherung.zip')
    app.q('#import-backup').click()
    app.wait_page('login.html', 30)
    options = [o.text for o in app.qa('#account option')]
    check('Backup eingespielt (Teams wieder wie vorher)', options == ['EHC Zürich', 'Junioren', 'Admin'], str(options))
    app.shot('11-login')
    app.quit()

    # 12) Beschädigte Konfiguration
    cfg = DATA / 'config.json'
    cfg.write_text('{kaputt')
    app = App()
    app.wait_page('login.html')
    Select(app.q('#account')).select_by_visible_text('Admin')
    app.type('#admin-username', 'stefan')
    app.type('#password', 'SicheresPW1')
    app.q('#login-button').click()
    app.wait_page('settings.html')
    app.wait(lambda: app.q('#banner').is_displayed())
    check('Kaputte config.json: Sicherung geladen, Hinweis angezeigt', 'Sicherung' in app.text('#banner'), app.text('#banner'))
    app.quit()
    cfg.write_text('{kaputt'); (DATA / 'config.json.bak').write_text('auch kaputt')
    app = App()
    app.wait_page('login.html')
    app.wait(lambda: app.q('#broken-view').is_displayed())
    check('Ohne lesbare Sicherung: klare Fehlerseite statt admin123', 'nicht lesbar' in app.text('#broken-view'))
    app.shot('12-broken')
    app.quit()

    failed = [r for r in results if not r[1]]
    print(f'\n{len(results) - len(failed)}/{len(results)} bestanden')
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
