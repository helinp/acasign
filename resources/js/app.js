import 'bootstrap';
import { icon } from './icons.js';
// Lignes de tableau cliquables (data-href), sans gêner les liens et boutons qu'elles contiennent.
document.querySelectorAll('tr[data-href]').forEach(row => row.addEventListener('click', event => {
    if (event.target.closest('a, button, input, select, form, td.w-1')) return;
    window.location.href = row.dataset.href;
}));

// Aperçu des mentions imprimées sous la signature, mis à jour à chaque choix.
const preview = document.querySelector('[data-sign-preview]');
if (preview) {
    const form = preview.closest('form');
    const lines = preview.querySelector('[data-preview-lines]');
    const render = () => {
        const checked = name => form.querySelector(`input[type=checkbox][name="${name}"]`)?.checked;
        const now = new Date().toLocaleString('fr-BE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).replace(',', '');
        const items = [
            checked('show_name') && preview.dataset.name,
            checked('show_job_title') && form.querySelector('[name="job_title"]')?.value.trim(),
            checked('show_date') && `${preview.dataset.mode === 'eid' ? 'Signé avec eID le' : 'Signé le'} ${now}`,
        ].filter(Boolean);
        lines.replaceChildren(...(items.length ? items : ['(aucune mention)']).map(text => Object.assign(document.createElement('div'), { textContent: text })));
    };
    form.addEventListener('input', render);
    // La mention de date dépend du mode choisi (bouton « Signer sans eID » ou « avec ma carte eID »).
    document.querySelectorAll('[data-open-sign]').forEach(button => button.addEventListener('click', () => { preview.dataset.mode = button.dataset.openSign; render(); }));
    render();
}

// Récapitulatif d'erreurs : focus à l'affichage ; un lien sans cible exacte (ex. documents-0) vise le champ parent.
const errorSummary = document.querySelector('[data-error-summary]');
if (errorSummary) {
    errorSummary.focus();
    errorSummary.querySelectorAll('a[href^="#"]').forEach(link => link.addEventListener('click', event => {
        const id = link.getAttribute('href').slice(1);
        const target = document.getElementById(id) ?? document.getElementById(id.split('-')[0]);
        if (!target) return;
        event.preventDefault();
        target.scrollIntoView({ block: 'center' });
        (target.matches('input, select, textarea') ? target : target.querySelector('input, select, textarea'))?.focus();
    }));
}

// Liste des PDF choisis (nom, taille), avec possibilité d'en retirer un avant l'envoi.
document.querySelectorAll('input[type=file][data-file-list]').forEach(input => {
    const output = input.parentElement.querySelector('[data-file-list-output]');
    const size = bytes => bytes > 1048576 ? `${(bytes / 1048576).toFixed(1)} Mo` : `${Math.max(1, Math.round(bytes / 1024))} Ko`;
    const render = () => {
        output.replaceChildren(...[...input.files].map((file, index) => {
            const item = document.createElement('li');
            item.append(`${file.name} · ${size(file.size)}${file.size > 25 * 1048576 ? ' — trop volumineux' : ''}`);
            const remove = Object.assign(document.createElement('button'), { type: 'button', className: 'btn btn-sm btn-ghost-danger ms-2', innerHTML: `${icon('x')}Retirer` });
            remove.setAttribute('aria-label', `Retirer ${file.name}`);
            remove.addEventListener('click', () => {
                const kept = new DataTransfer();
                [...input.files].forEach((other, otherIndex) => { if (otherIndex !== index) kept.items.add(other); });
                input.files = kept.files;
                render();
            });
            item.append(remove);
            return item;
        }));
    };
    input.addEventListener('change', render);
});

// Confirmation des actions destructives : <form data-confirm="Message">.
document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
}));

// Choix explicite du mode de signature : la fenêtre n'affiche que le bouton correspondant.
document.querySelectorAll('[data-open-sign]').forEach(button => button.addEventListener('click', () => {
    document.querySelectorAll('[data-sign-only]').forEach(element => { element.hidden = element.dataset.signOnly !== button.dataset.openSign; });
}));

if (document.querySelector('[data-eid-help], [data-eid-sign]')) {
    import('./eid.js');
}

const stage = document.querySelector('[data-pdf-stage]');
if (stage) {
    const pdfjsLib = await import('pdfjs-dist');
    pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).toString();
    const placements = JSON.parse(stage.dataset.placements || '[]');
    const documentUrl = stage.dataset.pdfUrl;
    const saveUrl = stage.dataset.saveUrl;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let pdf;
    try {
        // PDF.js >= 6 : getDocument() attend un objet ({ url }) et non plus une chaîne.
        pdf = await pdfjsLib.getDocument({ url: documentUrl, withCredentials: true }).promise;
    } catch (error) {
        console.error(error);
        stage.innerHTML = '<p class="text-white mb-0">Impossible d’afficher le PDF. Rechargez la page ou contactez l’administrateur.</p>';
        throw error;
    }

    // Pages créées d'emblée à leur taille (placement possible tout de suite), rendues seulement à l'approche
    // de la zone visible : un PDF de nombreuses pages reste fluide.
    const pageWrappers = [];
    const renderQueue = new Map();
    const observer = new IntersectionObserver(entries => entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        observer.unobserve(entry.target);
        renderQueue.get(entry.target)?.();
    }), { root: stage, rootMargin: '600px 0px' });
    for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
        const page = await pdf.getPage(pageNumber);
        const viewport = page.getViewport({ scale: 1.35 });
        const wrapper = document.createElement('div'); wrapper.className = 'pdf-page'; wrapper.dataset.page = pageNumber;
        wrapper.dataset.width = viewport.width; wrapper.style.width = `${viewport.width}px`; wrapper.style.aspectRatio = `${viewport.width} / ${viewport.height}`;
        wrapper.setAttribute('aria-label', `Page ${pageNumber} sur ${pdf.numPages}`); wrapper.setAttribute('role', 'region');
        const canvas = document.createElement('canvas'); canvas.width = viewport.width; canvas.height = viewport.height;
        wrapper.appendChild(canvas); stage.appendChild(wrapper); pageWrappers.push(wrapper);
        renderQueue.set(wrapper, () => page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise);
        observer.observe(wrapper);
        placements.filter(p => Number(p.page) === pageNumber).forEach(p => addZone(wrapper, p));
    }
    buildToolbar(stage, pageWrappers, pdf.numPages);

    const { makeMovable } = await import('./placement.js');
    const zonesMovable = stage.dataset.movable !== 'false';
    if (zonesMovable) {
        stage.querySelectorAll('.signature-zone').forEach(zone => makeMovable(zone, savePlacements, `Cadre de signature : ${zone.textContent}`));
    }
    const { setupFields } = await import('./fields.js');
    setupFields({ stage, pages: pageWrappers, csrf });

    function addZone(wrapper, placement) {
        const zone = document.createElement('div'); zone.className = 'signature-zone'; zone.dataset.id = placement.id;
        zone.textContent = placement.label; zone.style.left = `${placement.x * 100}%`; zone.style.top = `${placement.y * 100}%`;
        zone.style.width = `${placement.width * 100}%`; zone.style.height = `${placement.height * 100}%`; wrapper.appendChild(zone);
        if (stage.dataset.movable === 'false') { zone.classList.add('is-locked'); zone.title = 'Emplacement fixé par le demandeur'; return; }
        zone.addEventListener('pointerdown', event => {
            zone.setPointerCapture(event.pointerId); zone.classList.add('dragging');
            const bounds = wrapper.getBoundingClientRect(); const initial = zone.getBoundingClientRect();
            const offsetX = event.clientX - initial.left; const offsetY = event.clientY - initial.top;
            const move = e => {
                moved = true;
                const x = Math.max(0, Math.min(bounds.width - initial.width, e.clientX - bounds.left - offsetX));
                const y = Math.max(0, Math.min(bounds.height - initial.height, e.clientY - bounds.top - offsetY));
                zone.style.left = `${x / bounds.width * 100}%`; zone.style.top = `${y / bounds.height * 100}%`;
            };
            let moved = false;
            const up = async () => { zone.classList.remove('dragging'); zone.removeEventListener('pointermove', move); zone.removeEventListener('pointerup', up); if (moved) { (await import('./placement.js')).markDragged(zone); await savePlacements(); } };
            zone.addEventListener('pointermove', move); zone.addEventListener('pointerup', up);
        });
    }

    /** Barre d'outils du PDF : aller à une page, aller à la signature, zoom. */
    function buildToolbar(stage, pages, count) {
        const bar = document.createElement('div');
        bar.className = 'pdf-toolbar';
        bar.innerHTML = `
            <label class="small mb-0">${icon('files', 'me-1')}Page <select class="form-select form-select-sm d-inline-block w-auto" data-go-page aria-label="Aller à la page">${pages.map((_, index) => `<option value="${index + 1}">${index + 1}</option>`).join('')}</select> sur ${count}</label>
            <button type="button" class="btn btn-sm btn-outline-primary" data-go-signature>${icon('focus-2')}Aller à la signature</button>
            <span class="ms-auto d-inline-flex gap-1" role="group" aria-label="Zoom">
                <button type="button" class="btn btn-sm btn-outline-primary btn-icon" data-zoom="-0.15" aria-label="Réduire" title="Réduire">${icon('zoom-out')}</button>
                <button type="button" class="btn btn-sm btn-outline-primary" data-zoom="0" aria-label="Taille initiale" title="Taille initiale">100 %</button>
                <button type="button" class="btn btn-sm btn-outline-primary btn-icon" data-zoom="0.15" aria-label="Agrandir" title="Agrandir">${icon('zoom-in')}</button>
            </span>`;
        stage.before(bar);
        let zoom = 1;
        const goTo = element => element?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        bar.querySelector('[data-go-page]').addEventListener('change', event => goTo(pages[Number(event.target.value) - 1]));
        bar.querySelector('[data-go-signature]').addEventListener('click', () => {
            const zone = stage.querySelector('.signature-zone');
            goTo(zone);
            zone?.focus({ preventScroll: true });
        });
        bar.querySelectorAll('[data-zoom]').forEach(button => button.addEventListener('click', () => {
            const step = Number(button.dataset.zoom);
            zoom = step === 0 ? 1 : Math.min(1.6, Math.max(0.5, zoom + step));
            pages.forEach(page => { page.style.width = `${Number(page.dataset.width) * zoom}px`; });
            bar.querySelector('[data-zoom="0"]').textContent = `${Math.round(zoom * 100)} %`;
        }));
        stage.addEventListener('scroll', () => {
            const top = stage.getBoundingClientRect().top;
            const current = pages.findIndex(page => page.getBoundingClientRect().bottom > top + 40);
            if (current >= 0) bar.querySelector('[data-go-page]').value = String(current + 1);
        }, { passive: true });
    }

    async function savePlacements() {
        const current = [...stage.querySelectorAll('.signature-zone')].map(zone => {
            const page = zone.closest('.pdf-page');
            // Lecture des % posés en style : offsetWidth inclurait la bordure et ferait grossir le cadre à chaque enregistrement.
            return { id: zone.dataset.id, page: Number(page.dataset.page), x: parseFloat(zone.style.left) / 100, y: parseFloat(zone.style.top) / 100, width: parseFloat(zone.style.width) / 100, height: parseFloat(zone.style.height) / 100 };
        });
        const single = stage.dataset.single === 'true'; const body = single ? current[0] : { placements: current };
        const response = await fetch(saveUrl, { method: 'PUT', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body) });
        const message = document.querySelector('[data-save-state]');
        if (message) message.textContent = response.ok ? 'Position enregistrée' : 'Échec de l’enregistrement';
    }
}

const signerList = document.querySelector('[data-signer-list]');
document.querySelector('[data-add-signer]')?.addEventListener('click', () => {
    const row = document.createElement('div'); row.className = 'input-group mb-2';
    row.innerHTML = `<input class="form-control" type="email" name="signers[]" placeholder="${signerList.dataset.placeholder}" list="known-users" autocomplete="off" aria-label="Adresse d’un autre signataire"><button class="btn btn-outline-danger btn-icon" type="button" aria-label="Retirer ce signataire" title="Retirer ce signataire">${icon('x')}</button>`;
    row.querySelector('button').addEventListener('click', () => row.remove()); signerList.appendChild(row); row.querySelector('input').focus();
});
document.querySelectorAll('[data-remove-signer]').forEach(button => button.addEventListener('click', () => button.closest('.input-group').remove()));


// Filtres de la liste : les listes déroulantes s'appliquent dès le choix ; une date saisie remplace le raccourci de période.
document.querySelectorAll('[data-auto-submit]').forEach(select => select.addEventListener('change', () => select.form.requestSubmit()));
document.querySelectorAll('#filter-from, #filter-to').forEach(input => input.addEventListener('change', () => input.form.querySelector('[data-period]')?.remove()));

// Téléchargement groupé : case « tout sélectionner », compteur sur le bouton, « Tout télécharger » ignore la sélection.
const archiveForm = document.getElementById('archive-form');
if (archiveForm) {
    const rows = [...document.querySelectorAll('[data-select-row]')];
    const selectAll = document.querySelector('[data-select-all]');
    const selectedButton = archiveForm.querySelector('[data-archive-selected]');
    const update = () => {
        const count = rows.filter(row => row.checked).length;
        selectedButton.disabled = count === 0;
        archiveForm.querySelector('[data-archive-count]').textContent = count ? ` (${count})` : '';
        if (selectAll) { selectAll.checked = count > 0 && count === rows.length; selectAll.indeterminate = count > 0 && count < rows.length; }
    };
    rows.forEach(row => row.addEventListener('change', update));
    selectAll?.addEventListener('change', () => { rows.forEach(row => { row.checked = selectAll.checked; }); update(); });
    archiveForm.querySelector('[data-archive-all]')?.addEventListener('click', () => rows.forEach(row => { row.checked = false; }));
    update();
}

// Réglages : aperçu en direct des couleurs choisies.
const themePreview = document.querySelector('[data-theme-preview]');
if (themePreview) {
    document.querySelectorAll('[data-color-preview]').forEach(input => {
        const apply = () => {
            themePreview.style.setProperty(input.dataset.colorPreview === 'action' ? '--preview-action' : '--preview-brand', input.value);
            document.querySelector(`[data-color-value="${input.name}"]`).textContent = input.value.toUpperCase();
        };
        input.addEventListener('input', apply);
        apply();
    });
}
