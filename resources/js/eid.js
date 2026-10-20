/**
 * Aide contextuelle Web eID : vérifie que l'extension Firefox et l'application Web eID sont installées
 * et à jour, puis affiche l'étape à suivre. Tout élément [data-eid-help] est géré automatiquement.
 */
const MESSAGES = {
    ERR_WEBEID_EXTENSION_UNAVAILABLE: { step: 'extension', text: 'L’extension Web eID n’est pas installée ou est désactivée dans le navigateur.' },
    ERR_WEBEID_NATIVE_UNAVAILABLE: { step: 'app', text: 'L’application Web eID n’est pas installée sur cet ordinateur.' },
    ERR_WEBEID_CONTEXT_INSECURE: { step: null, text: 'La signature eID exige une connexion sécurisée (HTTPS). Prévenez l’administrateur.' },
    ERR_WEBEID_VERSION_INVALID: { step: 'app', text: 'L’application Web eID ne répond pas correctement : réinstallez-la.' },
};

const describeFailure = error => {
    if (error?.code === 'ERR_WEBEID_VERSION_MISMATCH') {
        const parts = [error.requiresUpdate?.extension && 'l’extension', error.requiresUpdate?.nativeApp && 'l’application'].filter(Boolean);
        return { step: error.requiresUpdate?.nativeApp ? 'app' : 'extension', text: `Mise à jour nécessaire : ${parts.join(' et ') || 'Web eID'}.` };
    }

    return MESSAGES[error?.code] ?? { step: null, text: `Vérification impossible${error?.code ? ` (${error.code})` : ''}. Réessayez ou contactez le support.` };
};

/**
 * @returns {Promise<{ok: boolean, step: ?string, text: string}>}
 */
export async function checkEid() {
    if (window.location.protocol !== 'https:') {
        return { ok: false, step: null, text: 'La signature eID exige une connexion HTTPS : cette page est en http://. En local, ouvrez https://localhost:8443.' };
    }
    try {
        const webeid = await import('@web-eid/web-eid-library');
        const status = await webeid.status();

        return { ok: true, step: null, text: `Web eID est prêt (extension ${status.extension}, application ${status.nativeApp}).` };
    } catch (error) {
        return { ok: false, ...describeFailure(error) };
    }
}

async function refresh(panel) {
    const status = panel.querySelector('[data-eid-status]');
    const steps = panel.querySelector('[data-eid-steps]');
    status.className = 'eid-status is-checking';
    status.textContent = 'Vérification de l’installation…';

    const result = await checkEid();
    status.className = `eid-status ${result.ok ? 'is-ok' : 'is-ko'}`;
    status.textContent = result.text;
    panel.querySelectorAll('[data-eid-step]').forEach(step => step.classList.toggle('is-todo', step.dataset.eidStep === result.step));
    if (steps) steps.hidden = result.ok && panel.dataset.eidHelp !== 'always';
    panel.querySelectorAll('[data-eid-action]').forEach(button => { button.disabled = !result.ok; });
    panel.dispatchEvent(new CustomEvent('eid:status', { detail: result, bubbles: true }));
}

const USER_ERRORS = {
    ERR_WEBEID_USER_CANCELLED: 'Signature annulée.',
    ERR_WEBEID_USER_TIMEOUT: 'Délai dépassé : le code PIN n’a pas été saisi. Réessayez.',
    ERR_WEBEID_NATIVE_FATAL: 'La carte n’a pas pu être lue. Fermez eID Viewer ou tout autre logiciel qui utilise la carte, retirez et réinsérez-la, puis réessayez.',
};

async function postJson(url, body, csrf) {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(body),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const message = payload.errors ? Object.values(payload.errors).flat()[0] : payload.message;
        throw Object.assign(new Error(message || `Erreur serveur (${response.status}).`), { server: true });
    }

    return payload;
}

/**
 * Signature eID : certificat → préparation serveur (empreinte) → signature par la carte → finalisation serveur.
 */
function setupEidSign(form) {
    const progress = form.querySelector('[data-eid-progress]');
    const submit = form.querySelector('[data-sign-submit]');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const say = (text, failed = false) => { progress.textContent = text; progress.classList.toggle('text-danger', failed); };

    form.addEventListener('submit', async event => {
        if (event.submitter?.dataset.signMode !== 'eid') return; // signature interne : envoi classique du formulaire
        event.preventDefault();
        submit.disabled = true;
        // Champs du formulaire (dont fields[id] des champs à compléter, rattachés via l'attribut form).
        const options = { fields: {} };
        for (const [key, value] of new FormData(form)) {
            const field = key.match(/^fields\[(.+)\]$/);
            if (field) { options.fields[field[1]] = value; } else { options[key] = value; }
        }
        let step = 'certificate';
        try {
            const webeid = await import('@web-eid/web-eid-library');
            say('Lecture du certificat de la carte…');
            const { certificate, supportedSignatureAlgorithms } = await webeid.getSigningCertificate({ lang: 'fr' });
            say('Préparation du document…');
            step = 'prepare';
            const { hash, hashFunction } = await postJson(form.dataset.prepareUrl, { ...options, certificate, supportedSignatureAlgorithms }, csrf);
            say('Saisissez votre code PIN de signature dans la fenêtre Web eID.');
            step = 'sign';
            const { signature, signatureAlgorithm } = await webeid.sign(certificate, hash, hashFunction, { lang: 'fr' });
            say('Intégration de la signature dans le PDF…');
            step = 'finalize';
            const { redirect } = await postJson(form.dataset.finalizeUrl, { signature, signatureAlgorithm }, csrf);
            window.location.href = redirect;
        } catch (error) {
            const text = error.server ? error.message : (USER_ERRORS[error?.code] ?? describeFailure(error).text);
            const stepLabel = { certificate: 'lecture du certificat', prepare: 'préparation', sign: 'signature par la carte', finalize: 'intégration' }[step];
            say(`${text} (étape : ${stepLabel}${error?.code ? `, code ${error.code}` : ''})`, true);
            submit.disabled = false;
            if (!error.server && error?.code !== 'ERR_WEBEID_USER_CANCELLED') {
                postJson(form.dataset.failureUrl, { step, code: error?.code ?? null, message: String(error?.message ?? '').slice(0, 500) }, csrf).catch(() => {});
            }
        }
    });
}

document.querySelectorAll('form[data-eid-sign]').forEach(setupEidSign);

/**
 * « Tester ma carte » : lit le certificat de signature et le fait contrôler par le serveur, sans rien signer.
 */
function setupCardTest(button) {
    const output = button.parentElement.querySelector('[data-eid-test-result]');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    button.addEventListener('click', async () => {
        button.disabled = true;
        output.className = 'small';
        output.textContent = 'Lecture de la carte…';
        try {
            const webeid = await import('@web-eid/web-eid-library');
            const { certificate } = await webeid.getSigningCertificate({ lang: 'fr' });
            const info = await postJson(button.dataset.checkUrl, { certificate }, csrf);
            output.className = 'small text-success fw-semibold';
            output.textContent = `Carte lue : ${info.subject} — certificat valable jusqu’au ${info.valid_to}.`;
        } catch (error) {
            output.className = 'small text-danger fw-semibold';
            output.textContent = error.server ? error.message : (USER_ERRORS[error?.code] ?? describeFailure(error).text);
            if (!error.server && error?.message) {
                const detail = document.createElement('span');
                detail.className = 'd-block fw-normal text-muted';
                detail.textContent = `Détail technique : ${error.message}${error.code ? ` (${error.code})` : ''}`;
                output.appendChild(detail);
            }
            if (!error.server && error?.code !== 'ERR_WEBEID_USER_CANCELLED') {
                postJson(button.dataset.failureUrl, { code: error?.code ?? null, message: String(error?.message ?? '').slice(0, 500) }, csrf).catch(() => {});
            }
        } finally {
            button.disabled = false;
        }
    });
}

document.querySelectorAll('[data-eid-help]').forEach(panel => {
    panel.querySelector('[data-eid-recheck]')?.addEventListener('click', () => refresh(panel));
    panel.querySelectorAll('[data-eid-test]').forEach(setupCardTest);
    refresh(panel);
});
