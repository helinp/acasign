<?php

namespace App\Services;

use App\Models\Document;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Support\EcdsaSignature;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Signature qualifiée par carte eID (Web eID côté poste, DSS côté serveur), en deux étapes :
 *
 * - prepare() : contrôle le certificat, demande à DSS les octets à signer et renvoie leur empreinte à la carte ;
 * - finalize() : vérifie la valeur produite par la carte, la fait intégrer par DSS (révision PDF incrémentale).
 *
 * Le PDF n'est jamais réécrit après la première signature eID : l'apparence (image + mentions) est dessinée par DSS.
 */
class EidSigningService
{
    /** Préférence d'algorithme quand la carte en propose plusieurs : SHA-256 est le mieux pris en charge par les cartes. */
    private const HASH_PREFERENCE = ['SHA-256', 'SHA-384', 'SHA-512'];

    private const TTL_SECONDS = 600;

    public function __construct(
        private DssClient $dss,
        private EncryptedSignatureStorage $images,
        private AuditService $audit,
        private PdfService $pdf,
    ) {}

    /**
     * @param  list<array{cryptoAlgorithm?: string, hashFunction?: string, paddingScheme?: string}>  $supportedAlgorithms
     * @param  array<string, ?string>  $fieldValues  valeurs des champs à compléter du signataire
     * @return array{hash: string, hashFunction: string}
     *
     * @throws ValidationException
     */
    public function prepare(SignatureRequest $signatureRequest, User $user, string $certificate, array $supportedAlgorithms, array $fieldValues = []): array
    {
        $document = $signatureRequest->document;
        $info = $this->certificateInfo($certificate);
        $algorithm = $this->chooseAlgorithm($supportedAlgorithms);
        $disk = Storage::disk('local');
        $originalSha = hash('sha256', $disk->get($document->file_path));

        // Les champs complétés sont imprimés dans une copie du PDF, signée ensuite telle quelle :
        // le fichier courant ne change qu'à la finalisation.
        $basePath = null;
        if ($signatureRequest->fields->isNotEmpty()) {
            if ($document->usesEid()) {
                throw ValidationException::withMessages(['eid' => 'Ce document porte déjà une signature eID : les champs ne peuvent plus être complétés.']);
            }
            $signatureRequest->fields->each(fn ($field) => $field->value = $fieldValues[$field->id] ?? null);
            $basePath = dirname($document->file_path)."/eid-prepared-{$signatureRequest->id}.pdf";
            $disk->put($basePath, $this->pdf->fillFields($document, $signatureRequest));
        }
        $pdfBytes = $disk->get($basePath ?? $document->file_path);

        $parameters = $this->dss->parameters(
            $certificate,
            $algorithm['hashFunction'],
            $algorithm['cryptoAlgorithm'],
            (int) floor(microtime(true) * 1000),
            image: $this->appearance($document, $signatureRequest, $user),
        );
        if ($algorithm['paddingScheme'] === 'PSS') {
            $parameters['encryptionAlgorithm'] = 'RSASSA_PSS';
        }
        $dataToSign = $this->dss->dataToSign($pdfBytes, $parameters);
        Log::info('eID : préparation', [
            'signature_request_id' => $signatureRequest->id,
            'supported' => $supportedAlgorithms,
            'chosen' => $algorithm,
            'issuer' => $info['issuer'],
        ]);

        Cache::put($this->cacheKey($signatureRequest, $user), [
            'parameters' => $parameters,
            'algorithm' => $algorithm,
            'data_to_sign' => base64_encode($dataToSign),
            'document_sha256' => $originalSha,
            'base_path' => $basePath,
            'field_values' => $fieldValues,
            'certificate' => $certificate,
            'certificate_info' => $info,
        ], self::TTL_SECONDS);

        return [
            'hash' => base64_encode(hash(strtolower(str_replace('-', '', $algorithm['hashFunction'])), $dataToSign, true)),
            'hashFunction' => $algorithm['hashFunction'],
        ];
    }

    /**
     * @param  array{cryptoAlgorithm?: string, hashFunction?: string, paddingScheme?: string}  $signatureAlgorithm
     * @return bool vrai si c'était la dernière signature attendue
     *
     * @throws ValidationException
     */
    public function finalize(SignatureRequest $signatureRequest, User $user, string $signature, array $signatureAlgorithm): bool
    {
        $prepared = Cache::get($this->cacheKey($signatureRequest, $user));
        if (! $prepared) {
            throw ValidationException::withMessages(['eid' => 'La préparation a expiré. Recommencez la signature.']);
        }
        $algorithm = $prepared['algorithm'];
        if (($signatureAlgorithm['hashFunction'] ?? null) !== $algorithm['hashFunction'] || ($signatureAlgorithm['cryptoAlgorithm'] ?? null) !== $algorithm['cryptoAlgorithm']) {
            throw ValidationException::withMessages(['eid' => 'Algorithme de signature inattendu. Recommencez la signature.']);
        }

        $value = base64_decode($signature, true);
        if ($value === false || $value === '') {
            throw ValidationException::withMessages(['eid' => 'Signature de la carte illisible.']);
        }
        if ($algorithm['cryptoAlgorithm'] === 'ECC' && ! EcdsaSignature::isDer($value)) {
            $value = EcdsaSignature::toDer($value);
        }
        $this->verify(base64_decode($prepared['data_to_sign']), $value, $prepared['certificate'], $algorithm);

        $isLast = DB::transaction(function () use ($signatureRequest, $user, $prepared, $algorithm, $value) {
            $signatureRequest = SignatureRequest::lockForUpdate()->findOrFail($signatureRequest->id);
            $document = Document::lockForUpdate()->findOrFail($signatureRequest->document_id);
            abort_unless($signatureRequest->status === 'pending' && $document->status === 'pending', 409, 'Cette demande n’est plus signable.');
            abort_if($document->expires_at?->isPast(), 409, 'Cette demande a expiré.');

            $disk = Storage::disk('local');
            if (hash('sha256', $disk->get($document->file_path)) !== $prepared['document_sha256']) {
                throw ValidationException::withMessages(['eid' => 'Le document a été signé par quelqu’un d’autre entre-temps. Recommencez la signature.']);
            }
            $pdfBytes = $disk->get($prepared['base_path'] ?? $document->file_path);

            $signed = $this->dss->signDocument($pdfBytes, $prepared['parameters'], $this->dssAlgorithm($algorithm), $value);
            if (! str_starts_with($signed, '%PDF')) {
                throw new \RuntimeException('DSS n’a pas renvoyé de PDF signé.');
            }
            $temporary = $document->file_path.'.'.uniqid('eid', true);
            $disk->put($temporary, $signed);
            $disk->move($temporary, $document->file_path);
            $sha = hash('sha256', $signed);

            foreach ($signatureRequest->fields as $field) {
                $field->update(['value' => $prepared['field_values'][$field->id] ?? null, 'filled_at' => now()]);
            }
            if ($prepared['base_path']) {
                $disk->delete($prepared['base_path']);
            }
            $signatureRequest->update([
                'status' => 'signed', 'signed_at' => now(), 'signer_id' => $user->id,
                'document_sha256' => $sha, 'certificate_info' => $prepared['certificate_info'],
            ]);
            $isLast = ! $document->signatureRequests()->where('status', 'pending')->exists();
            // Après une signature eID, le PDF ne doit plus être réécrit : les signataires suivants signent aussi par eID.
            $document->update([
                'signature_method' => 'eid',
                'sha256' => $sha, 'file_size' => strlen($signed), 'lock_version' => $document->lock_version + 1,
                ...($isLast ? ['status' => 'signed', 'completed_at' => now(), 'sealed_sha256' => $sha] : []),
            ]);
            $this->audit->record('signature.signed', $document, $user, [
                'signature_request_id' => $signatureRequest->id, 'sha256' => $sha, 'method' => 'eid',
                'certificate' => $prepared['certificate_info'],
            ]);
            if ($isLast) {
                $this->audit->record('document.completed', $document, $user, ['sealed_sha256' => $sha, 'method' => 'eid']);
            }

            return $isLast;
        }, 3);

        Cache::forget($this->cacheKey($signatureRequest, $user));

        return $isLast;
    }

    /**
     * Identité du certificat (sans le numéro de registre national, donnée sensible).
     *
     * @return array{subject: string, issuer: string, serial: string, valid_to: string, fingerprint: string}
     *
     * @throws ValidationException
     */
    public function certificateInfo(string $certificate): array
    {
        $der = base64_decode($certificate, true);
        $x509 = $der ? openssl_x509_read("-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n") : false;
        $parsed = $x509 ? openssl_x509_parse($x509) : false;
        if (! $parsed) {
            throw ValidationException::withMessages(['eid' => 'Certificat de la carte illisible.']);
        }
        if (($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > time() || ($parsed['validTo_time_t'] ?? 0) < time()) {
            throw ValidationException::withMessages(['eid' => 'Le certificat de signature de la carte est expiré ou pas encore valide.']);
        }
        $keyUsage = (string) ($parsed['extensions']['keyUsage'] ?? '');
        if (! str_contains($keyUsage, 'Non Repudiation') && ! str_contains($keyUsage, 'Content Commitment')) {
            throw ValidationException::withMessages(['eid' => 'Ce certificat n’est pas un certificat de signature.']);
        }

        return [
            'subject' => (string) ($parsed['subject']['CN'] ?? ''),
            'issuer' => (string) ($parsed['issuer']['CN'] ?? ''),
            'serial' => (string) ($parsed['serialNumberHex'] ?? ''),
            'valid_to' => date(DATE_ATOM, (int) $parsed['validTo_time_t']),
            'fingerprint' => (string) openssl_x509_fingerprint($x509, 'sha256'),
        ];
    }

    /**
     * @param  list<array{cryptoAlgorithm?: string, hashFunction?: string, paddingScheme?: string}>  $supported
     * @return array{cryptoAlgorithm: string, hashFunction: string, paddingScheme: string}
     *
     * @throws ValidationException
     */
    public function chooseAlgorithm(array $supported): array
    {
        $usable = collect($supported)->filter(fn (array $option) => in_array($option['cryptoAlgorithm'] ?? null, ['ECC', 'RSA'], true)
            && array_key_exists($option['hashFunction'] ?? '', DssClient::DIGEST_ALGORITHMS)
            && in_array($option['paddingScheme'] ?? null, ['NONE', 'PKCS1.5', 'PSS'], true));
        foreach (self::HASH_PREFERENCE as $hash) {
            $match = $usable->first(fn (array $option) => $option['hashFunction'] === $hash && $option['paddingScheme'] !== 'PSS')
                ?? $usable->first(fn (array $option) => $option['hashFunction'] === $hash);
            if ($match) {
                return $match;
            }
        }

        throw ValidationException::withMessages(['eid' => 'Aucun algorithme de signature compatible proposé par la carte.']);
    }

    /**
     * @param  array{cryptoAlgorithm: string, hashFunction: string, paddingScheme: string}  $algorithm
     */
    private function dssAlgorithm(array $algorithm): string
    {
        $hash = str_replace('-', '', $algorithm['hashFunction']);

        return match (true) {
            $algorithm['cryptoAlgorithm'] === 'ECC' => "ECDSA_{$hash}",
            $algorithm['paddingScheme'] === 'PSS' => "RSA_SSA_PSS_{$hash}_MGF1",
            default => "RSA_{$hash}",
        };
    }

    /**
     * Vérifie la valeur de la carte avec la clé publique du certificat avant de l'intégrer au PDF.
     *
     * @param  array{cryptoAlgorithm: string, hashFunction: string, paddingScheme: string}  $algorithm
     *
     * @throws ValidationException
     */
    private function verify(string $dataToSign, string $value, string $certificate, array $algorithm): void
    {
        if ($algorithm['paddingScheme'] === 'PSS') {
            return; // openssl_verify ne gère pas RSASSA-PSS ; DSS contrôle la signature à l'intégration.
        }
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($certificate, 64, "\n")."-----END CERTIFICATE-----\n";
        $digest = ['SHA-256' => OPENSSL_ALGO_SHA256, 'SHA-384' => OPENSSL_ALGO_SHA384, 'SHA-512' => OPENSSL_ALGO_SHA512][$algorithm['hashFunction']];
        if (openssl_verify($dataToSign, $value, $pem, $digest) !== 1) {
            throw ValidationException::withMessages(['eid' => 'La signature de la carte ne correspond pas au certificat. Recommencez la signature.']);
        }
    }

    /**
     * Apparence visible dessinée par DSS : image de signature (si enregistrée) et mentions choisies.
     *
     * @return array<string, mixed>
     */
    private function appearance(Document $document, SignatureRequest $signatureRequest, User $user): array
    {
        [$pageWidth, $pageHeight] = $document->page_sizes[$signatureRequest->page - 1] ?? [595.28, 841.89];
        $lines = $signatureRequest->stampLines(now());

        return array_filter([
            'image' => $user->signature_path ? ['bytes' => base64_encode($this->images->bytes($user)), 'name' => 'signature.png'] : null,
            'imageScaling' => 'ZOOM_AND_CENTER',
            'fieldParameters' => [
                'page' => $signatureRequest->page,
                'originX' => round($signatureRequest->position_x * $pageWidth, 2),
                'originY' => round($signatureRequest->position_y * $pageHeight, 2),
                'width' => round($signatureRequest->width * $pageWidth, 2),
                'height' => round($signatureRequest->height * $pageHeight, 2),
            ],
            'textParameters' => $lines === [] ? null : [
                'text' => implode("\n", $lines),
                'signerTextPosition' => $user->signature_path ? 'BOTTOM' : 'TOP',
                'size' => 8,
                'textColor' => ['red' => 20, 'green' => 24, 'blue' => 40, 'alpha' => 255],
            ],
        ], fn ($value) => $value !== null);
    }

    private function cacheKey(SignatureRequest $signatureRequest, User $user): string
    {
        return "eid-signing:{$signatureRequest->id}:{$user->id}";
    }
}
