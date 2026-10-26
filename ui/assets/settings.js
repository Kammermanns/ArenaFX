const $ = id => document.getElementById(id);
let view = null;
let status = null;
let teamsDraft = [];

const DEFAULT_HOTKEYS = {
    music: 'numadd', durchsage: 'num0',
    effect_1: 'num1', effect_2: 'num2', effect_3: 'num3', effect_4: 'num4', effect_5: 'num5', effect_6: 'num6',
};
const HOTKEY_ACTIONS = Object.keys(DEFAULT_HOTKEYS);

function feedback(area, text, kind = 'success') {
    const box = document.querySelector(`[data-feedback="${area}"]`);
    if (!box) return;
    box.textContent = text;
    box.className = `admin-feedback ${kind}`;
    box.hidden = !text;
}

/** Führt eine Aktion aus und zeigt Erfolg oder Fehler im passenden Bereich an. */
async function run(area, action, success) {
    feedback(area, t('working'), 'pending');
    try {
        const result = await action();
        if (result === false || result === null) {
            feedback(area, '');
            return result;
        }
        feedback(area, typeof success === 'function' ? success(result) : (success || t('saved')));
        return result;
    } catch (error) {
        if (error.code === 'err_not_logged_in') {
            go('login.html');
            return undefined;
        }
        feedback(area, errorText(error), 'error');
        return undefined;
    }
}

function showBanner(text, kind = 'info') {
    const banner = $('banner');
    banner.replaceChildren(...(Array.isArray(text) ? text : [text]).map(line => el('p', { text: line })));
    banner.className = `banner ${kind}`;
    banner.hidden = false;
}

async function refreshView() {
    view = await api('settings');
}

// ---------------------------------------------------------------------------
// Musik (gemeinsam für Admin und Team)
// ---------------------------------------------------------------------------

function skippedText(result) {
    const lines = [t('music_added', { count: result.added.length })];
    for (const item of result.skipped) lines.push(`${item.name}: ${t(item.reason)}`);
    return lines.join('\n');
}

async function renderMusicList(container, scope, teamId, area) {
    let files = [];
    try {
        files = await api('list_music', { scope, teamId });
    } catch (error) {
        feedback(area, errorText(error), 'error');
    }
    container.replaceChildren();
    if (!files.length) {
        container.append(el('p', { class: 'text-muted', text: t('no_music_present') }));
        return files;
    }
    for (const file of files) {
        container.append(el('div', { class: 'file-row' },
            el('span', { class: 'file-name', text: file.name.replace(/\.[^.]+$/, '') }),
            el('span', { class: 'text-muted file-size', text: formatBytes(file.size) }),
            el('button', {
                type: 'button',
                class: 'btn btn-danger btn-small',
                text: t('delete'),
                onclick: async () => {
                    if (!confirm(t('confirm_delete_file', { name: file.name }))) return;
                    await run(area, () => api('delete_music', { scope, teamId, name: file.name }), t('file_deleted'));
                    renderMusicList(container, scope, teamId, area);
                    if (view.role === 'admin') refreshStats();
                },
            }),
        ));
    }
    return files;
}

async function addMusic(container, scope, teamId, area) {
    const result = await run(area, () => api('add_music', { scope, teamId }), skippedText);
    if (result && result.skipped.length) feedback(area, skippedText(result), result.added.length ? 'warning' : 'error');
    await renderMusicList(container, scope, teamId, area);
    if (view.role === 'admin') refreshStats();
}

// ---------------------------------------------------------------------------
// Effekte (gemeinsam für Admin und Team)
// ---------------------------------------------------------------------------

async function renderEffects(container, teamId, area) {
    let slots = [];
    try {
        slots = await api('effects', { teamId });
    } catch (error) {
        feedback(area, errorText(error), 'error');
        return;
    }
    container.replaceChildren();
    for (const slot of slots) {
        const input = el('input', { type: 'text', maxlength: '40', value: slot.label, placeholder: t('effect_label_placeholder') });
        const save = async pickFile => {
            const label = input.value.trim() || (pickFile ? t('effect_default_label', { slot: slot.slot }) : '');
            const result = await run(area, () => api('save_effect', { teamId, slot: slot.slot, label, pickFile }), t('effect_saved', { slot: slot.slot }));
            if (result) renderEffects(container, teamId, area);
        };
        container.append(el('div', { class: 'effect-slot' },
            el('h3', { text: t('effect_slot', { slot: slot.slot }) }),
            el('div', { class: 'file-info', text: slot.hasFile ? slot.source || t('effect_has_file') : t('effect_no_file') }),
            el('label', { class: 'visually-hidden', text: t('effect_label_placeholder') }),
            input,
            el('div', { class: 'button-row' },
                el('button', { type: 'button', class: 'btn btn-small', text: t(slot.hasFile ? 'effect_replace_sound' : 'effect_pick_sound'), onclick: () => save(true) }),
                slot.hasFile ? el('button', { type: 'button', class: 'btn btn-secondary btn-small', text: t('effect_save_name'), onclick: () => save(false) }) : null,
                slot.hasFile ? el('button', {
                    type: 'button',
                    class: 'btn btn-danger btn-small',
                    text: t('remove'),
                    onclick: async () => {
                        if (!confirm(t('confirm_remove_effect', { slot: slot.slot }))) return;
                        await run(area, () => api('remove_effect', { teamId, slot: slot.slot }), t('effect_removed'));
                        renderEffects(container, teamId, area);
                    },
                }) : null,
            ),
        ));
    }
}

// ---------------------------------------------------------------------------
// Admin
// ---------------------------------------------------------------------------

function refreshStats() {
    api('settings').then(fresh => {
        view = fresh;
        $('stat-club-name').textContent = view.appName;
        $('stat-team-count').textContent = String(view.teams.length);
        $('stat-music-count').textContent = String(view.generalMusic + view.teamMusic);
    }).catch(() => {});
}

function selectTab(name) {
    document.querySelectorAll('[data-tab]').forEach(tab => {
        const active = tab.dataset.tab === name;
        tab.classList.toggle('active', active);
        tab.setAttribute('aria-selected', String(active));
    });
    document.querySelectorAll('[data-section]').forEach(section => {
        section.hidden = section.dataset.section !== name;
    });
    try { sessionStorage.setItem('arenafx-tab', name); } catch (error) { /* egal */ }
}

function fillTeamSelect(select, withGeneral) {
    const previous = select.value;
    const options = [];
    if (withGeneral) options.push(el('option', { value: 'general', text: t('general_music_option') }));
    for (const team of view.teams) {
        options.push(el('option', { value: withGeneral ? `team:${team.id}` : team.id, text: withGeneral ? t('team_music_option', { name: team.name }) : team.name }));
    }
    select.replaceChildren(...options);
    if ([...select.options].some(option => option.value === previous)) select.value = previous;
}

function musicTarget() {
    const value = $('music-target').value;
    return value === 'general' ? { scope: 'general', teamId: null } : { scope: 'team', teamId: value.slice(5) };
}

/** Markiert doppelte Teamnamen, ohne die Liste neu aufzubauen (Fokus bleibt erhalten). */
function markDuplicateTeams() {
    const names = teamsDraft.map(team => team.name.trim().toLocaleLowerCase());
    $('teams-list').querySelectorAll('.team-item').forEach((row, index) => {
        const name = names[index];
        const duplicate = Boolean(name) && names.indexOf(name) !== index;
        row.querySelector('input[type="text"]').setAttribute('aria-invalid', String(duplicate));
        row.querySelector('.duplicate-hint').hidden = !duplicate;
    });
}

function renderTeams() {
    const list = $('teams-list');
    list.replaceChildren();
    teamsDraft.forEach((team, index) => {
        const nameInput = el('input', { type: 'text', maxlength: '60', value: team.name });
        nameInput.addEventListener('input', () => {
            team.name = nameInput.value;
            markDuplicateTeams();
        });
        const passwordInput = el('input', {
            type: 'password',
            autocomplete: 'new-password',
            value: team.password,
            placeholder: t(team.id ? 'team_password_keep' : 'team_password_new'),
        });
        passwordInput.addEventListener('input', () => { team.password = passwordInput.value; });
        const stateText = !team.id ? t('team_new') : team.hasPassword ? t('team_password_set') : t('team_locked_hint');
        list.append(el('div', { class: 'team-item' },
            el('label', { class: 'team-field' }, el('span', { text: t('team_name') }), nameInput,
                el('small', { class: 'error-text duplicate-hint', text: t('team_name_duplicate_hint'), hidden: true })),
            el('label', { class: 'team-field' }, el('span', { text: t('password') }), passwordInput,
                el('small', { class: team.id && !team.hasPassword ? 'warning-text' : 'text-muted', text: stateText })),
            teamsDraft.length > 1 ? el('button', {
                type: 'button',
                class: 'btn btn-danger btn-small',
                text: t('remove'),
                onclick: () => {
                    if (team.id && !confirm(t('confirm_remove_team', { name: team.name }))) return;
                    teamsDraft.splice(index, 1);
                    renderTeams();
                },
            }) : null,
        ));
    });
    markDuplicateTeams();
}

function resetTeamsDraft() {
    teamsDraft = view.teams.map(team => ({ id: team.id, name: team.name, password: '', hasPassword: team.hasPassword }));
    renderTeams();
}

function renderHotkeys(values) {
    const grid = $('hotkey-grid');
    grid.replaceChildren();
    const counts = {};
    for (const action of HOTKEY_ACTIONS) {
        const value = (values[action] || '').toLowerCase();
        if (value) counts[value] = (counts[value] || 0) + 1;
    }
    for (const action of HOTKEY_ACTIONS) {
        const value = values[action] || '';
        const duplicate = value && counts[value.toLowerCase()] > 1;
        const input = el('input', {
            type: 'text',
            readonly: true,
            class: 'hotkey-input',
            value: keyLabel(value),
            'aria-invalid': duplicate ? 'true' : 'false',
            dataset: { action },
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'Tab') return;
            event.preventDefault();
            values[action] = ['Delete', 'Backspace'].includes(event.key) ? '' : normalizeKey(event);
            renderHotkeys(values);
            const next = grid.querySelector(`[data-action="${action}"]`);
            if (next) next.focus();
        });
        const label = action.startsWith('effect_') ? t('effect_slot', { slot: action.slice(7) }) : t(`hk_${action}`);
        grid.append(el('label', { class: 'hotkey-field' }, el('span', { text: label }), input,
            duplicate ? el('small', { class: 'error-text', text: t('hotkey_duplicate_hint') }) : null));
    }
}

function renderLegacyPreview(box, preview) {
    box.replaceChildren(
        el('strong', { text: preview.appName || t('legacy_unnamed') }),
        el('p', { class: 'text-muted', text: t(preview.source === 'php' ? 'legacy_source_php' : 'legacy_source_electron') }),
        el('p', { text: t('legacy_general_music', { count: preview.generalMusic }) }),
        el('ul', { class: 'plain-list' }, preview.teams.map(team => el('li', {},
            el('span', { text: team.name }),
            el('span', { class: 'text-muted', text: ` – ${t('legacy_team_summary', { music: team.music, effects: team.effects })}` }),
            team.hasPassword ? null : el('span', { class: 'warning-text', text: ` (${t('legacy_no_password')})` }),
        ))),
    );
    box.hidden = false;
}

function importSummary(result) {
    const lines = [t('import_done', { teams: result.teams, music: result.music, effects: result.effects })];
    if (result.skipped) lines.push(t('import_skipped', { count: result.skipped }));
    if (result.lockedTeams.length) lines.push(t('import_locked', { names: result.lockedTeams.join(', ') }));
    return lines;
}

async function checkUpdate(manual) {
    if (manual) feedback('update', t('update_checking'), 'pending');
    try {
        const update = await api('check_update');
        $('update-badge').hidden = !update;
        $('install-update').hidden = !update;
        $('update-details').hidden = !update;
        $('version-latest').textContent = update ? update.version : view.version;
        $('update-summary').textContent = update ? t('update_available', { version: update.version }) : t('update_none');
        $('update-notes').textContent = update ? (update.notes || t('update_no_notes')) : '';
        if (manual) feedback('update', '');
        return update;
    } catch (error) {
        if (manual) {
            $('update-summary').textContent = t('update_failed');
            feedback('update', errorText(error), 'error');
        }
        return null;
    }
}

async function initAdmin() {
    $('admin-view').hidden = false;
    $('header-kicker').textContent = t('administration');
    $('header-title').textContent = t('settings');
    $('about-version').textContent = view.version;
    $('version-current').textContent = view.version;
    $('data-dir').textContent = view.dataDir;
    refreshStats();

    document.querySelectorAll('[data-tab]').forEach(tab => tab.addEventListener('click', () => {
        selectTab(tab.dataset.tab);
        if (tab.dataset.tab === 'music') renderMusicList($('music-list'), musicTarget().scope, musicTarget().teamId, 'music');
        if (tab.dataset.tab === 'effects') renderEffects($('effects-builder'), $('effect-team').value, 'effects');
    }));
    let remembered = null;
    try { remembered = sessionStorage.getItem('arenafx-tab'); } catch (error) { /* egal */ }
    selectTab(document.querySelector(`[data-tab="${remembered}"]`) ? remembered : 'general');

    // Allgemein
    $('app-name').value = view.appName;
    $('app-language').value = view.language;
    $('admin-username').value = view.adminUsername;
    $('form-app-name').addEventListener('submit', async event => {
        event.preventDefault();
        const name = await run('general', () => api('save_app_name', { name: $('app-name').value }));
        if (name) {
            $('app-name').value = name;
            refreshStats();
        }
    });
    $('form-language').addEventListener('submit', async event => {
        event.preventDefault();
        const ok = await run('general', () => api('save_language', { language: $('app-language').value }).then(() => true));
        if (ok) window.location.reload();
    });
    $('form-admin').addEventListener('submit', async event => {
        event.preventDefault();
        const ok = await run('general', () => api('save_admin', {
            currentPassword: $('admin-current').value,
            username: $('admin-username').value,
            newPassword: $('admin-new').value,
        }).then(() => true), t('admin_saved'));
        if (ok) {
            $('admin-current').value = '';
            $('admin-new').value = '';
        }
    });
    const refreshLogo = async () => {
        const has = await loadLogoInto($('logo-preview'));
        $('logo-empty').hidden = has;
        $('remove-logo').hidden = !has;
    };
    refreshLogo();
    $('pick-logo').addEventListener('click', async () => {
        if (await run('general', () => api('pick_logo'), t('logo_saved'))) refreshLogo();
    });
    $('remove-logo').addEventListener('click', async () => {
        await run('general', () => api('remove_logo').then(() => true), t('logo_removed'));
        refreshLogo();
    });

    // Teams
    resetTeamsDraft();
    $('add-team').addEventListener('click', () => {
        teamsDraft.push({ id: null, name: '', password: '', hasPassword: false });
        renderTeams();
        const inputs = $('teams-list').querySelectorAll('.team-item:last-child input');
        if (inputs[0]) inputs[0].focus();
    });
    $('save-teams').addEventListener('click', async () => {
        const teams = teamsDraft.map(team => ({ id: team.id, name: team.name, password: team.password || null }));
        const ok = await run('teams', () => api('save_teams', { teams }).then(() => true), t('teams_saved'));
        if (ok) {
            await refreshView();
            resetTeamsDraft();
            fillTeamSelect($('music-target'), true);
            fillTeamSelect($('effect-team'), false);
            refreshStats();
        }
    });

    // Musik
    fillTeamSelect($('music-target'), true);
    $('music-target').addEventListener('change', () => {
        feedback('music', '');
        renderMusicList($('music-list'), musicTarget().scope, musicTarget().teamId, 'music');
    });
    $('add-music').addEventListener('click', () => addMusic($('music-list'), musicTarget().scope, musicTarget().teamId, 'music'));
    renderMusicList($('music-list'), musicTarget().scope, musicTarget().teamId, 'music');

    // Effekte
    fillTeamSelect($('effect-team'), false);
    $('effect-team').addEventListener('change', () => renderEffects($('effects-builder'), $('effect-team').value, 'effects'));
    renderEffects($('effects-builder'), $('effect-team').value, 'effects');

    // Tastenkürzel
    let hotkeys = { ...DEFAULT_HOTKEYS, ...view.hotkeys };
    renderHotkeys(hotkeys);
    $('form-hotkeys').addEventListener('submit', async event => {
        event.preventDefault();
        await run('hotkeys', () => api('save_hotkeys', { hotkeys }).then(() => true));
    });
    $('reset-hotkeys').addEventListener('click', () => {
        hotkeys = { ...DEFAULT_HOTKEYS };
        renderHotkeys(hotkeys);
    });

    // Backup & Daten
    $('export-backup').addEventListener('click', () =>
        run('data', () => api('export_backup'), path => t('backup_saved', { path })));
    $('import-backup').addEventListener('click', async () => {
        if (!confirm(t('confirm_import_backup'))) return;
        const ok = await run('data', () => api('import_backup'), t('backup_restored'));
        if (ok) setTimeout(() => go('login.html'), 1500);
    });
    $('pick-legacy').addEventListener('click', async () => {
        const preview = await run('data', () => api('pick_legacy_folder'), t('legacy_found'));
        if (preview) {
            renderLegacyPreview($('legacy-preview'), preview);
            $('run-legacy').hidden = false;
        }
    });
    $('run-legacy').addEventListener('click', async () => {
        if (!confirm(t('confirm_legacy_import'))) return;
        $('run-legacy').disabled = true;
        const result = await run('data', () => api('import_legacy'), result => importSummary(result).join('\n'));
        $('run-legacy').disabled = false;
        if (result) {
            $('run-legacy').hidden = true;
            $('legacy-preview').hidden = true;
            sessionStorage.setItem('arenafx-import-result', JSON.stringify(result));
            window.location.reload();
        }
    });
    $('open-data-folder').addEventListener('click', () => run('data', () => api('open_data_folder').then(() => null)));

    // Update
    $('check-update').addEventListener('click', () => checkUpdate(true));
    $('install-update').addEventListener('click', async () => {
        if (!confirm(t('confirm_update'))) return;
        $('install-update').disabled = true;
        $('check-update').disabled = true;
        await run('update', () => api('install_update').then(() => true), t('update_restarting'));
        $('install-update').disabled = false;
        $('check-update').disabled = false;
    });
    checkUpdate(false);
}

// ---------------------------------------------------------------------------
// Team
// ---------------------------------------------------------------------------

function initTeam() {
    $('team-view').hidden = false;
    $('header-kicker').textContent = t('team_area');
    $('header-title').textContent = view.teamName;
    $('about-version').textContent = view.version;
    const list = $('team-music-list');
    renderMusicList(list, 'team', view.teamId, 'team');
    $('team-add-music').addEventListener('click', () => addMusic(list, 'team', view.teamId, 'team'));
    renderEffects($('team-effects-builder'), view.teamId, 'team');
}

// ---------------------------------------------------------------------------

(async () => {
    status = await api('app_status');
    if (status.status !== 'ready' || !status.role) return go('login.html');
    try {
        view = await api('settings');
    } catch (error) {
        return go('login.html');
    }
    await initI18n(view.language);
    document.title = `${view.appName} – ${t('settings')}`;

    document.querySelectorAll('[data-open]').forEach(button =>
        button.addEventListener('click', () => $(button.dataset.open).showModal()));
    document.querySelectorAll('[data-close]').forEach(button =>
        button.addEventListener('click', () => button.closest('dialog').close()));
    $('logout').addEventListener('click', async () => {
        await api('logout');
        go('login.html');
    });

    const banners = [];
    if (status.recovered) banners.push(t('config_recovered'));
    const imported = sessionStorage.getItem('arenafx-import-result');
    if (imported) {
        sessionStorage.removeItem('arenafx-import-result');
        try { banners.push(...importSummary(JSON.parse(imported))); } catch (error) { /* egal */ }
    }
    if (banners.length) showBanner(banners, status.recovered ? 'warning' : 'info');

    if (view.role === 'admin') await initAdmin();
    else initTeam();
})();
