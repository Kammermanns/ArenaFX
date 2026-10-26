(async () => {
    const status = await api('app_status');
    await initI18n(status.language);

    if (status.status === 'setup') return go('setup.html');
    if (status.role === 'admin') return go('settings.html');
    if (status.role === 'team') return go('index.html');

    if (status.status === 'broken') {
        document.getElementById('broken-view').hidden = false;
        document.getElementById('broken-path').textContent = status.dataDir;
        document.getElementById('open-folder').addEventListener('click', async () => {
            try {
                await api('open_data_folder');
            } catch (error) {
                const message = document.getElementById('broken-message');
                message.textContent = errorText(error);
                message.hidden = false;
            }
        });
        return;
    }

    document.title = status.appName;
    document.getElementById('login-app-name').textContent = status.appName;
    document.getElementById('login-view').hidden = false;
    loadLogoInto(document.getElementById('login-logo'));

    const account = document.getElementById('account');
    const usernameBox = document.getElementById('admin-username-box');
    const username = document.getElementById('admin-username');
    const password = document.getElementById('password');
    const message = document.getElementById('login-message');
    const button = document.getElementById('login-button');

    for (const team of status.teams) {
        const label = team.locked ? `${team.name} (${t('team_locked')})` : team.name;
        account.append(el('option', { value: team.id, text: label, disabled: team.locked }));
    }
    account.append(el('option', { value: '__admin__', text: t('admin') }));
    const firstOpen = status.teams.find(team => !team.locked);
    account.value = firstOpen ? firstOpen.id : '__admin__';

    const updateAdminFields = () => {
        const isAdmin = account.value === '__admin__';
        usernameBox.hidden = !isAdmin;
        username.required = isAdmin;
    };
    account.addEventListener('change', () => {
        updateAdminFields();
        message.hidden = true;
        (account.value === '__admin__' ? username : password).focus();
    });
    updateAdminFields();
    password.focus();

    document.getElementById('login-form').addEventListener('submit', async event => {
        event.preventDefault();
        message.hidden = true;
        button.disabled = true;
        try {
            const target = await api('login', {
                account: account.value,
                username: account.value === '__admin__' ? username.value : null,
                password: password.value,
            });
            go(target);
        } catch (error) {
            message.textContent = errorText(error);
            message.hidden = false;
            password.value = '';
            password.focus();
            button.disabled = false;
        }
    });
})();
