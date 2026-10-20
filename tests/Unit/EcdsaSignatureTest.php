<?php

namespace Tests\Unit;

use App\Support\EcdsaSignature;
use PHPUnit\Framework\TestCase;

class EcdsaSignatureTest extends TestCase
{
    public function test_raw_signature_converts_to_the_der_produced_by_openssl(): void
    {
        foreach (['prime256v1' => 32, 'secp384r1' => 48] as $curve => $size) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
            for ($attempt = 0; $attempt < 20; $attempt++) {
                openssl_sign('document '.$attempt, $der, $key, OPENSSL_ALGO_SHA256);
                $raw = $this->toRaw($der, $size);

                $this->assertSame(bin2hex($der), bin2hex(EcdsaSignature::toDer($raw)), "{$curve} essai {$attempt}");
                $this->assertSame(1, openssl_verify('document '.$attempt, EcdsaSignature::toDer($raw), openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256));
            }
        }
    }

    public function test_der_detection(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
        openssl_sign('document', $der, $key, OPENSSL_ALGO_SHA384);

        $this->assertTrue(EcdsaSignature::isDer($der));
        $this->assertFalse(EcdsaSignature::isDer($this->toRaw($der, 48)));
    }

    public function test_odd_length_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EcdsaSignature::toDer(str_repeat("\x01", 63));
    }

    /**
     * Extrait r et s d'une signature DER (SEQUENCE de deux INTEGER) et les concatène sur $size octets chacun.
     */
    private function toRaw(string $der, int $size): string
    {
        $offset = ord($der[1]) & 0x80 ? 2 + (ord($der[1]) & 0x7F) : 2;
        $raw = '';
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $raw .= str_pad(ltrim(substr($der, $offset + 2, $length), "\x00"), $size, "\x00", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }
}
