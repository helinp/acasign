/**
 * Déplacement des cadres (signature, champs) sans glisser-déposer (WCAG 2.2 — 2.5.7) :
 * - clavier : flèches (Maj = pas plus grand), Page précédente / suivante pour changer de page ;
 * - pointeur simple : cliquer le cadre pour le sélectionner, puis cliquer à l'endroit voulu sur une page.
 */
let selected = null;
const pages = () => [...document.querySelectorAll('.pdf-page')];
const percent = value => parseFloat(value) || 0;
const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

function announce(text) {
    const status = document.querySelector('[data-save-state]');
    if (status) status.textContent = text;
}

function select(zone) {
    if (selected && selected !== zone) selected.classList.remove('is-selected');
    selected = zone;
    zone.classList.add('is-selected');
    zone.setAttribute('aria-pressed', 'true');
    announce('Cadre sélectionné : cliquez à l’endroit voulu sur une page, ou utilisez les flèches.');
}

function deselect() {
    if (!selected) return;
    selected.classList.remove('is-selected');
    selected.setAttribute('aria-pressed', 'false');
    selected = null;
}

function moveBy(zone, dx, dy) {
    zone.style.left = `${clamp(percent(zone.style.left) + dx, 0, 100 - percent(zone.style.width))}%`;
    zone.style.top = `${clamp(percent(zone.style.top) + dy, 0, 100 - percent(zone.style.height))}%`;
}

function moveToPage(zone, offset) {
    const all = pages();
    const target = all[all.indexOf(zone.closest('.pdf-page')) + offset];
    if (!target) return false;
    target.appendChild(zone);
    target.scrollIntoView({ block: 'center' });
    zone.focus();
    return true;
}

/**
 * @param {HTMLElement} zone
 * @param {() => void|Promise<void>} onMoved  enregistre la nouvelle position
 * @param {string} label  nom lisible du cadre (lecteurs d'écran)
 */
export function makeMovable(zone, onMoved, label) {
    let timer;
    const saveSoon = () => { clearTimeout(timer); timer = setTimeout(onMoved, 400); };
    zone.tabIndex = 0;
    zone.setAttribute('role', 'button');
    zone.setAttribute('aria-pressed', 'false');
    zone.setAttribute('aria-label', `${label}. Flèches pour déplacer, Page précédente ou suivante pour changer de page, Entrée pour sélectionner puis cliquer sur la page.`);

    zone.addEventListener('keydown', event => {
        if (event.target.closest('input, select, textarea, button')) return;
        const step = event.shiftKey ? 5 : 0.5;
        const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        if (moves[event.key]) {
            event.preventDefault();
            moveBy(zone, ...moves[event.key]);
            saveSoon();
        } else if (event.key === 'PageDown' || event.key === 'PageUp') {
            event.preventDefault();
            if (moveToPage(zone, event.key === 'PageDown' ? 1 : -1)) saveSoon();
        } else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            selected === zone ? deselect() : select(zone);
        } else if (event.key === 'Escape') {
            deselect();
        }
    });

    zone.addEventListener('click', event => {
        if (zone.dataset.dragged === '1' || event.target.closest('input, select, textarea, button')) return;
        event.stopPropagation();
        selected === zone ? deselect() : select(zone);
    });

    zone.addEventListener('zone:moved', saveSoon);
}

/** Signale qu'un glisser-déposer vient d'avoir lieu (le clic qui suit ne doit pas sélectionner). */
export function markDragged(zone) {
    zone.dataset.dragged = '1';
    setTimeout(() => { delete zone.dataset.dragged; }, 50);
}

document.addEventListener('click', event => {
    if (!selected) return;
    const page = event.target.closest('.pdf-page');
    if (!page || event.target.closest('.signature-zone, .field-zone')) return;
    const bounds = page.getBoundingClientRect();
    const zone = selected;
    page.appendChild(zone);
    zone.style.left = `${clamp((event.clientX - bounds.left) / bounds.width * 100, 0, 100 - percent(zone.style.width))}%`;
    zone.style.top = `${clamp((event.clientY - bounds.top) / bounds.height * 100, 0, 100 - percent(zone.style.height))}%`;
    deselect();
    zone.focus();
    zone.dispatchEvent(new CustomEvent('zone:moved'));
});

document.addEventListener('keydown', event => { if (event.key === 'Escape') deselect(); });
