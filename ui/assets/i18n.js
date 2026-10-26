// Übersetzungen: Deutsch ist immer geladen und dient als Rückfallebene.
const I18N = { language: 'de', base: {}, dict: {} };

async function loadLocale(language) {
    const response = await fetch(`locales/${language}.json`);
    if (!response.ok) throw new Error(`Sprachdatei ${language} fehlt`);
    return response.json();
}

async function initI18n(language) {
    I18N.language = ['de', 'en', 'fr'].includes(language) ? language : 'de';
    try {
        if (!Object.keys(I18N.base).length) I18N.base = await loadLocale('de');
        I18N.dict = I18N.language === 'de' ? I18N.base : await loadLocale(I18N.language);
    } catch (error) {
        console.error(error);
        I18N.dict = I18N.base;
    }
    document.documentElement.lang = I18N.language;
    applyTranslations();
}

/** Übersetzt einen Schlüssel; `{name}` im Text wird durch vars.name ersetzt. */
function t(key, vars = {}) {
    let text = I18N.dict[key] ?? I18N.base[key] ?? key;
    for (const [name, value] of Object.entries(vars)) {
        text = text.split(`{${name}}`).join(String(value));
    }
    return text;
}

function applyTranslations(root = document) {
    root.querySelectorAll('[data-i18n]').forEach(element => {
        element.textContent = t(element.dataset.i18n);
    });
    root.querySelectorAll('[data-i18n-placeholder]').forEach(element => {
        element.placeholder = t(element.dataset.i18nPlaceholder);
    });
    root.querySelectorAll('[data-i18n-title]').forEach(element => {
        element.title = t(element.dataset.i18nTitle);
    });
    root.querySelectorAll('[data-i18n-aria]').forEach(element => {
        element.setAttribute('aria-label', t(element.dataset.i18nAria));
    });
}
