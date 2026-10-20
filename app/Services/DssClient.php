<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Client du service REST DSS (« one-document ») : signature PAdES en deux temps avec une clé externe (carte eID).
 *
 * 1. dataToSign() : DSS prépare la révision PDF et renvoie les octets à signer ;
 * 2. la carte signe l'empreinte de ces octets (Web eID, sur le poste) ;
 * 3. signDocument() : DSS intègre la valeur de signature et renvoie le PDF signé.
 *
 * Les paramètres (dont la date de signature) doivent être identiques entre les deux appels.
 */
class DssClient
{
    /**
     * Algorithmes de hachage acceptés : nom Web eID => nom DSS.
     *
     * @var array<string, string>
     */
    public const DIGEST_ALGORITHMS = ['SHA-256' => 'SHA256', 'SHA-384' => 'SHA384', 'SHA-512' => 'SHA512'];

    /**
     * Paramètres de signature PAdES communs aux deux appels.
     *
     * @param  string  $certificate  certificat de signature, DER encodé en base64
     * @param  list<string>  $chain  certificats intermédiaires, DER encodés en base64
     * @param  array<string, mixed>|null  $image  apparence visible (paramètres « imageParameters » de DSS)
     * @return array<string, mixed>
     */
    public function parameters(string $certificate, string $hashFunction, string $cryptoAlgorithm, int $signingDateMs, array $chain = [], ?array $image = null): array
    {
        return [
            'signingCertificate' => ['encodedCertificate' => $certificate],
            'certificateChain' => array_map(fn (string $der) => ['encodedCertificate' => $der], $chain),
            'signatureLevel' => (string) config('services.dss.signature_level'),
            'signaturePackaging' => 'ENVELOPED',
            'digestAlgorithm' => self::DIGEST_ALGORITHMS[$hashFunction] ?? throw new \InvalidArgumentException("Hachage non pris en charge : {$hashFunction}"),
            'encryptionAlgorithm' => $cryptoAlgorithm === 'ECC' ? 'ECDSA' : 'RSA',
            'imageParameters' => $image,
            'blevelParams' => ['signingDate' => $signingDateMs, 'trustAnchorBPPolicy' => true],
        ];
    }

    /**
     * Octets à signer par la carte (avant hachage).
     *
     * @param  array<string, mixed>  $parameters
     */
    public function dataToSign(string $pdfBytes, array $parameters): string
    {
        $response = $this->http()->post('/services/rest/signature/one-document/getDataToSign', [
            'parameters' => $parameters,
            'toSignDocument' => ['bytes' => base64_encode($pdfBytes), 'name' => 'document.pdf'],
        ])->throw();

        return base64_decode((string) $response->json('bytes'), true) ?: throw new \RuntimeException('Réponse DSS invalide (getDataToSign).');
    }

    /**
     * PDF signé intégrant la valeur de signature produite par la carte.
     *
     * @param  array<string, mixed>  $parameters
     * @param  string  $signatureAlgorithm  ex. « ECDSA_SHA384 », « RSA_SHA256 »
     */
    public function signDocument(string $pdfBytes, array $parameters, string $signatureAlgorithm, string $signatureValue): string
    {
        $response = $this->http()->post('/services/rest/signature/one-document/signDocument', [
            'parameters' => $parameters,
            'signatureValue' => ['algorithm' => $signatureAlgorithm, 'value' => base64_encode($signatureValue)],
            'toSignDocument' => ['bytes' => base64_encode($pdfBytes), 'name' => 'document.pdf'],
        ])->throw();

        return base64_decode((string) $response->json('bytes'), true) ?: throw new \RuntimeException('Réponse DSS invalide (signDocument).');
    }

    /**
     * Vérifie que le service DSS répond (pour l'administration et le diagnostic).
     */
    public function isReachable(): bool
    {
        try {
            return Http::baseUrl((string) config('services.dss.url'))->connectTimeout(3)->timeout(5)->get('/')->status() < 500;
        } catch (ConnectionException) {
            return false;
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('services.dss.url'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout((int) config('services.dss.timeout'));
    }
}
