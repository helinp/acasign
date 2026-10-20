import 'bootstrap';
// Lignes de tableau cliquables (data-href), sans gêner les liens et boutons qu'elles contiennent.
document.querySelectorAll('tr[data-href]').forEach(row => row.addEventListener('click', event => {
    if (event.target.closest('a, button, input, select, form')) return;
    window.location.href = row.dataset.href;
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
                const x = Math.max(0, Math.min(bounds.width - initial.width, e.clientX - bounds.left - offsetX));
                const y = Math.max(0, Math.min(bounds.height - initial.height, e.clientY - bounds.top - offsetY));
                zone.style.left = `${x / bounds.width * 100}%`; zone.style.top = `${y / bounds.height * 100}%`;
            };
            const up = async () => { zone.classList.remove('dragging'); zone.removeEventListener('pointermove', move); zone.removeEventListener('pointerup', up); await savePlacements(); };
            zone.addEventListener('pointermove', move); zone.addEventListener('pointerup', up);
        });
    }

    async function savePlacements() {
        const current = [...stage.querySelectorAll('.signature-zone')].map(zone => {
            const page = zone.closest('.pdf-page');
            return { id: zone.dataset.id, page: Number(page.dataset.page), x: zone.offsetLeft / page.clientWidth, y: zone.offsetTop / page.clientHeight, width: zone.offsetWidth / page.clientWidth, height: zone.offsetHeight / page.clientHeight };
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

