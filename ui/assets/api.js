// Verbindung zum Rust-Teil und kleine Helfer für alle Seiten.

async function api(command, args = {}) {
    try {
        return await window.__TAURI__.core.invoke(command, args);
    } catch (error) {
        if (error && typeof error === 'object' && error.code) throw error;
        throw { code: 'err_unknown', detail: String(error) };
    }
}

function errorText(error) {
    const code = error && error.code ? error.code : 'err_unknown';
    const detail = error && error.detail ? error.detail : '';
    return t(code, { detail });
}

/** Element erzeugen, ohne HTML-Strings: Texte landen immer als Text, nie als Markup. */
function el(tag, props = {}, ...children) {
    const element = document.createElement(tag);
    for (const [key, value] of Object.entries(props)) {
        if (value === undefined || value === null || value === false) continue;
        if (key === 'class') element.className = value;
        else if (key === 'text') element.textContent = value;
        else if (key === 'dataset') Object.assign(element.dataset, value);
        else if (key.startsWith('on')) element.addEventListener(key.slice(2), value);
        else if (key in element && typeof value !== 'string') element[key] = value;
        else element.setAttribute(key, value === true ? '' : value);
    }
    for (const child of children.flat()) {
        if (child === null || child === undefined || child === false) continue;
        element.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return element;
}

function go(page) {
    window.location.replace(page);
}

async function loadLogoInto(image) {
    try {
        const bytes = await api('read_logo');
        if (!bytes || bytes.byteLength === 0) {
            image.hidden = true;
            return false;
        }
        if (image.dataset.objectUrl) URL.revokeObjectURL(image.dataset.objectUrl);
        const url = URL.createObjectURL(new Blob([bytes]));
        image.dataset.objectUrl = url;
        image.src = url;
        image.hidden = false;
        return true;
    } catch (error) {
        image.hidden = true;
        return false;
    }
}

function formatBytes(size) {
    if (size >= 1024 * 1024) return `${(size / 1024 / 1024).toFixed(1)} MB`;
    return `${Math.max(1, Math.round(size / 1024))} KB`;
}

/** Tastendruck in einen stabilen Namen übersetzen (unabhängig vom Tastaturlayout). */
function normalizeKey(event) {
    const code = event.code || '';
    const key = event.key || '';
    if (code.startsWith('Numpad')) {
        const value = code.slice(6);
        if (/^\d$/.test(value)) return 'num' + value;
        const names = { Add: 'numadd', Subtract: 'numsub', Multiply: 'nummult', Divide: 'numdiv', Decimal: 'numdec', Enter: 'numenter' };
        if (names[value]) return names[value];
    }
    if (code === 'Space') return 'Space';
    if (code.startsWith('Key')) return code.slice(3).toUpperCase();
    if (code.startsWith('Digit')) return code.slice(5);
    if (/^F\d{1,2}$/.test(key)) return key;
    if (['Enter', 'Backspace', 'Tab', 'Escape'].includes(key)) return '';
    return key.length === 1 ? key.toUpperCase() : key;
}

function keyLabel(value) {
    if (!value) return t('hotkey_none');
    const names = { numadd: 'Num +', numsub: 'Num −', nummult: 'Num ×', numdiv: 'Num ÷', numdec: 'Num ,', numenter: 'Num Enter', Space: t('key_space') };
    if (names[value]) return names[value];
    if (/^num\d$/.test(value)) return 'Num ' + value.slice(3);
    return value;
}
