(async () => {
    const status = await api('app_status');
    if (status.status !== 'setup') return go('login.html');

    const language = document.getElementById('setup-language');
    const browserLanguage = (navigator.language || 'de').slice(0, 2);
    language.value = ['de', 'en', 'fr'].includes(browserLanguage) ? browserLanguage : 'de';
    await initI18n(language.value);
    language.addEventListener('change', () => initI18n(language.value).then(renderPreview));

    const form = document.getElementById('setup-form');
    const message = document.getElementById('setup-message');
    const button = document.getElementById('setup-button');
    const newFields = document.getElementById('new-fields');
    const legacyFields = document.getElementById('legacy-fields');
    const previewBox = document.getElementById('legacy-preview');
    let mode = 'new';
    let preview = null;

    const showMessage = text => {
        message.textContent = text;
        message.hidden = !text;
    };

    function setMode(next) {
        mode = next;
        for (const [id, active] of [['mode-new', mode === 'new'], ['mode-legacy', mode === 'legacy']]) {
            const tab = document.getElementById(id);
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', String(active));
        }
        newFields.hidden = mode !== 'new';
        legacyFields.hidden = mode !== 'legacy';
        newFields.querySelectorAll('input').forEach(input => { input.required = mode === 'new'; });
        showMessage('');
    }
    document.getElementById('mode-new').addEventListener('click', () => setMode('new'));
    document.getElementById('mode-legacy').addEventListener('click', () => setMode('legacy'));

    function renderPreview() {
        previewBox.replaceChildren();
        if (!preview) {
            previewBox.hidden = true;
            return;
        }
        previewBox.hidden = false;
        previewBox.append(
            el('strong', { text: preview.appName || t('legacy_unnamed') }),
            el('p', { class: 'text-muted', text: t(preview.source === 'php' ? 'legacy_source_php' : 'legacy_source_electron') }),
            el('p', { text: t('legacy_general_music', { count: preview.generalMusic }) }),
            el('ul', { class: 'plain-list' },
                preview.teams.map(team => el('li', {},
                    el('span', { text: team.name }),
                    el('span', { class: 'text-muted', text: ` – ${t('legacy_team_summary', { music: team.music, effects: team.effects })}` }),
                    team.hasPassword ? null : el('span', { class: 'warning-text', text: ` (${t('legacy_no_password')})` }),
                ))),
        );
        if (preview.teams.some(team => !team.hasPassword)) {
            previewBox.append(el('p', { class: 'warning-text', text: t('legacy_locked_hint') }));
        }
    }

    document.getElementById('pick-legacy').addEventListener('click', async () => {
        showMessage('');
        try {
            const result = await api('pick_legacy_folder');
            if (result) {
                preview = result;
                renderPreview();
            }
        } catch (error) {
            preview = null;
            renderPreview();
            showMessage(errorText(error));
        }
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        showMessage('');
        const password = document.getElementById('admin-password').value;
        if (password !== document.getElementById('admin-password-repeat').value) {
            return showMessage(t('err_password_mismatch'));
        }
        if (mode === 'legacy' && !preview) return showMessage(t('legacy_pick_first'));

        button.disabled = true;
        button.textContent = t(mode === 'legacy' ? 'legacy_importing' : 'setup_saving');
        try {
            if (mode === 'new') {
                await api('setup', {
                    input: {
                        adminUsername: document.getElementById('admin-username').value,
                        adminPassword: password,
                        appName: document.getElementById('app-name').value,
                        language: language.value,
                        teamName: document.getElementById('team-name').value,
                        teamPassword: document.getElementById('team-password').value,
                    },
                });
            } else {
                const result = await api('setup_from_legacy', {
                    adminUsername: document.getElementById('admin-username').value,
                    adminPassword: password,
                });
                sessionStorage.setItem('arenafx-import-result', JSON.stringify(result));
            }
            go('settings.html');
        } catch (error) {
            showMessage(errorText(error));
            button.disabled = false;
            button.textContent = t('setup_finish');
        }
    });
})();
