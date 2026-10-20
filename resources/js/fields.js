import { makeMovable, markDragged } from './placement.js';

/**
 * Champs à compléter (date, coche, texte) superposés au PDF.
 * - mode « design » (demandeur) : ajout, déplacement et suppression des champs attribués aux signataires ;
 * - mode « fill » (signataire) : saisie des valeurs + ajout/déplacement/suppression de ses propres champs.
 */
const DEFAULT_SIZES = {
    date: { width: 0.16, height: 0.03 },
    checkbox: { width: 0.03, height: 0.022 },
    text: { width: 0.3, height: 0.03 },
};
const TYPE_LABELS = { date: 'Date', checkbox: 'Coche', text: 'Texte' };

export function setupFields({ stage, pages, csrf }) {
    const mode = stage.dataset.fieldMode;
    if (!mode) return;
    const fields = JSON.parse(stage.dataset.fields || '[]');
    const createUrl = stage.dataset.fieldCreateUrl;
    const fieldUrl = id => stage.dataset.fieldUrlTemplate.replace('__ID__', id);
    const formId = stage.dataset.fieldForm;
    const status = document.querySelector('[data-save-state]');
    const setStatus = text => { if (status) status.textContent = text; };

    const request = (url, method, body) => fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: body ? JSON.stringify(body) : undefined,
    });

    const pageFor = number => pages[number - 1] ?? pages[0];

    /** Page la plus visible dans la zone de défilement. */
    const visiblePage = () => {
        const top = stage.getBoundingClientRect().top;
        let best = pages[0];
        let bestDistance = Infinity;
        pages.forEach(page => {
            const distance = Math.abs(page.getBoundingClientRect().top - top);
            if (distance < bestDistance) { best = page; bestDistance = distance; }
        });
        return Number(best.dataset.page);
    };

    /** Géométrie lue sur les styles en % (offsetWidth inclurait la bordure et ferait grossir le champ à chaque enregistrement). */
    const geometry = zone => ({
        page: Number(zone.closest('.pdf-page').dataset.page),
        x: parseFloat(zone.style.left) / 100,
        y: parseFloat(zone.style.top) / 100,
        width: parseFloat(zone.style.width) / 100,
        height: parseFloat(zone.style.height) / 100,
    });

    /**
     * Glisser-déposer depuis n'importe quel point du champ, y compris sa zone de saisie :
     * le déplacement ne démarre qu'au-delà de 4 px, un simple clic garde donc la saisie (ou la coche).
     */
    const makeDraggable = (zone, field) => {
        zone.addEventListener('pointerdown', event => {
            if (event.button !== 0 || event.target.closest('.field-remove')) return;
            const startX = event.clientX;
            const startY = event.clientY;
            const bounds = zone.parentElement.getBoundingClientRect();
            const initial = zone.getBoundingClientRect();
            const offsetX = startX - initial.left;
            const offsetY = startY - initial.top;
            let dragging = false;

            const move = e => {
                if (!dragging) {
                    if (Math.hypot(e.clientX - startX, e.clientY - startY) < 4) return;
                    dragging = true;
                    zone.classList.add('dragging');
                    document.activeElement?.blur();
                }
                e.preventDefault();
                const x = Math.max(0, Math.min(bounds.width - initial.width, e.clientX - bounds.left - offsetX));
                const y = Math.max(0, Math.min(bounds.height - initial.height, e.clientY - bounds.top - offsetY));
                zone.style.left = `${x / bounds.width * 100}%`;
                zone.style.top = `${y / bounds.height * 100}%`;
            };
            const up = async () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                window.removeEventListener('pointercancel', up);
                if (!dragging) return;
                zone.classList.remove('dragging');
                markDragged(zone);
                // Empêche le clic de fin de glissement de cocher/décocher la case.
                zone.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); }, { capture: true, once: true });
                await saveGeometry(zone, field);
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
            window.addEventListener('pointercancel', up);
        });
    };

    const saveGeometry = async (zone, field) => {
        const response = await request(fieldUrl(field.id), 'PUT', geometry(zone));
        setStatus(response.ok ? 'Champ enregistré' : 'Échec de l’enregistrement');
    };

    const removeButton = (zone, field) => {
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'field-remove'; button.textContent = '×';
        button.setAttribute('aria-label', 'Supprimer le champ');
        button.addEventListener('click', async () => {
            const response = await request(fieldUrl(field.id), 'DELETE');
            if (response.ok) { zone.remove(); setStatus('Champ supprimé'); } else { setStatus('Suppression impossible'); }
        });
        return button;
    };

    const input = field => {
        let element;
        if (field.type === 'checkbox') {
            element = document.createElement('input'); element.type = 'checkbox'; element.value = '1';
            element.checked = field.value === '1'; element.className = 'form-check-input m-0';
        } else {
            element = document.createElement('input'); element.type = field.type === 'date' ? 'date' : 'text';
            element.value = field.value ?? ''; element.className = 'field-input';
            if (field.type === 'text') { element.maxLength = 200; element.placeholder = field.label || 'Texte'; }
        }
        element.name = `fields[${field.id}]`;
        element.setAttribute('form', formId);
        element.required = field.required;
        element.title = field.label || TYPE_LABELS[field.type];
        return element;
    };

    const render = field => {
        const page = pageFor(field.page);
        const zone = document.createElement('div');
        zone.className = `field-zone field-${field.type}${field.required ? ' field-required' : ''}`;
        zone.dataset.id = field.id;
        zone.style.left = `${field.x * 100}%`; zone.style.top = `${field.y * 100}%`;
        zone.style.width = `${field.width * 100}%`; zone.style.height = `${field.height * 100}%`;

        if (mode === 'design') {
            zone.textContent = `${TYPE_LABELS[field.type]}${field.label ? ` · ${field.label}` : ''}${field.required ? ' *' : ''}`;
            zone.title = `${field.signer ?? ''}${field.required ? ' (obligatoire)' : ''}`;
            zone.appendChild(removeButton(zone, field));
            makeDraggable(zone, field);
            makeMovable(zone, () => saveGeometry(zone, field), `Champ ${TYPE_LABELS[field.type]}${field.label ? ` « ${field.label} »` : ''}`);
        } else {
            zone.appendChild(input(field));
            if (field.addedBySigner) {
                zone.classList.add('field-movable');
                zone.title = 'Glisser pour déplacer';
                zone.appendChild(removeButton(zone, field));
                makeDraggable(zone, field);
                makeMovable(zone, () => saveGeometry(zone, field), `Mon champ ${TYPE_LABELS[field.type]}`);
            }
        }
        page.appendChild(zone);
    };

    fields.forEach(render);

    document.querySelectorAll('[data-add-field]').forEach(button => button.addEventListener('click', async () => {
        const type = button.dataset.addField;
        const size = DEFAULT_SIZES[type];
        const body = { type, page: visiblePage(), x: 0.1, y: 0.1, ...size };
        if (mode === 'design') {
            body.signature_request_id = document.querySelector('[data-field-signer]')?.value;
            body.label = document.querySelector('[data-field-label]')?.value || null;
            body.required = document.querySelector('[data-field-required]')?.checked ?? false;
        }
        const response = await request(createUrl, 'POST', body);
        if (!response.ok) { setStatus('Ajout impossible'); return; }
        render(await response.json());
        setStatus('Champ ajouté : faites-le glisser à sa place');
    }));
}
