import 'bootstrap';
// Lignes de tableau cliquables (data-href), sans gêner les liens et boutons qu'elles contiennent.
document.querySelectorAll('tr[data-href]').forEach(row => row.addEventListener('click', event => {
    if (event.target.closest('a, button, input, select, form')) return;
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
            checked('show_date') && `Signé le ${now}`,
        ].filter(Boolean);
        lines.replaceChildren(...(items.length ? items : ['(aucune mention)']).map(text => Object.assign(document.createElement('div'), { textContent: text })));
    };
    form.addEventListener('input', render);
    render();
}

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

    const pageWrappers = [];
    for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
        const page = await pdf.getPage(pageNumber);
        const viewport = page.getViewport({ scale: 1.35 });
        const wrapper = document.createElement('div'); wrapper.className = 'pdf-page'; wrapper.dataset.page = pageNumber;
        const canvas = document.createElement('canvas'); const context = canvas.getContext('2d');
        canvas.width = viewport.width; canvas.height = viewport.height; wrapper.appendChild(canvas); stage.appendChild(wrapper); pageWrappers.push(wrapper);
        await page.render({ canvasContext: context, viewport }).promise;
        placements.filter(p => Number(p.page) === pageNumber).forEach(p => addZone(wrapper, p));
    }

    const { makeMovable } = await import('./placement.js');
    stage.querySelectorAll('.signature-zone').forEach(zone => makeMovable(zone, savePlacements, `Cadre de signature : ${zone.textContent}`));
    const { setupFields } = await import('./fields.js');
    setupFields({ stage, pages: pageWrappers, csrf });

    function addZone(wrapper, placement) {
        const zone = document.createElement('div'); zone.className = 'signature-zone'; zone.dataset.id = placement.id;
        zone.textContent = placement.label; zone.style.left = `${placement.x * 100}%`; zone.style.top = `${placement.y * 100}%`;
        zone.style.width = `${placement.width * 100}%`; zone.style.height = `${placement.height * 100}%`; wrapper.appendChild(zone);
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
    row.innerHTML = '<input class="form-control" type="email" name="signers[]" placeholder="prenom.nom@acarts.be" list="known-users" autocomplete="off" aria-label="Adresse d’un autre signataire"><button class="btn btn-outline-danger" type="button" aria-label="Retirer">×</button>';
    row.querySelector('button').addEventListener('click', () => row.remove()); signerList.appendChild(row); row.querySelector('input').focus();
});
document.querySelectorAll('[data-remove-signer]').forEach(button => button.addEventListener('click', () => button.closest('.input-group').remove()));

