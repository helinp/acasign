{{-- Aide Web eID. $mode : 'auto' (étapes seulement en cas de problème) ou 'always' ; $action : 'sign' (bouton de signature) ou 'test' (lecture de la carte). --}}
<div class="eid-help" data-eid-help="{{ $mode ?? 'auto' }}">
    <div class="band"><span class="band__title"><x-icon name="id" />Signature avec la carte eID</span></div>
    <div class="panel">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <p class="eid-status is-checking mb-0" data-eid-status role="status" aria-live="polite">Vérification de l’installation…</p>
            <button type="button" class="btn btn-sm btn-outline-primary" data-eid-recheck><x-icon name="refresh" />Vérifier à nouveau</button>
        </div>
        @if(($action ?? null) === 'sign')
            <button type="button" class="btn btn-coral btn-lg mt-3" data-eid-action data-bs-toggle="modal" data-bs-target="#signModal" disabled><x-icon name="id" />Signer avec ma carte eID</button>
        @elseif(($action ?? null) === 'test')
            <div class="d-flex flex-wrap align-items-center gap-3 mt-3">
                <button type="button" class="btn btn-primary" data-eid-action data-eid-test data-check-url="{{ route('profile.eid.check') }}" data-failure-url="{{ route('profile.eid.failure') }}" disabled><x-icon name="id" />Tester ma carte</button>
                <span class="small" data-eid-test-result role="status" aria-live="polite"></span>
            </div>
        @endif
        <ol class="eid-steps mt-3 mb-0" data-eid-steps hidden>
            <li data-eid-step="reader"><strong>Lecteur de carte et logiciel eID belge.</strong> Branchez le lecteur et installez le <a href="{{ config('services.eid.middleware_url') }}" target="_blank" rel="noopener">logiciel eID</a>.</li>
            <li data-eid-step="app"><strong>Application Web eID.</strong> Téléchargez l’installateur pour votre système sur <a href="{{ config('services.eid.help_url') }}" target="_blank" rel="noopener">web-eid.eu</a> (Windows, macOS ou Linux), puis lancez-le.</li>
            <li data-eid-step="extension"><strong>Extension Firefox.</strong> L’installateur l’ajoute : acceptez-la dans la fenêtre de Firefox, ou activez « Web eID » dans <code>about:addons</code>. Redémarrez Firefox si besoin.</li>
            <li data-eid-step="card"><strong>Au moment de signer :</strong> insérez la carte, <strong>fermez eID Viewer</strong> (il bloque l’accès à la carte), puis saisissez votre code PIN dans la fenêtre Web eID.</li>
        </ol>
    </div>
</div>
