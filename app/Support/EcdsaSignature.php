<?php

namespace App\Support;

/**
 * Conversion d'une signature ECDSA « brute » (r || s, format IEEE P1363 renvoyé par les cartes à puce)
 * vers l'encodage DER (SEQUENCE { INTEGER r, INTEGER s }) attendu dans une signature CMS/PAdES.
 */
final class EcdsaSignature
{
    public static function toDer(string $raw): string
    {
        if ($raw === '' || strlen($raw) % 2 !== 0) {
            throw new \InvalidArgumentException('Signature ECDSA brute de longueur invalide.');
        }
        [$r, $s] = str_split($raw, intdiv(strlen($raw), 2));
        $body = self::integer($r).self::integer($s);

        return "\x30".self::length(strlen($body)).$body;
    }

    /**
     * Vrai si la valeur ressemble déjà à une SEQUENCE DER valide.
     */
    public static function isDer(string $value): bool
    {
        return strlen($value) > 8 && $value[0] === "\x30" && self::decodedLength($value) === strlen($value);
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::length(strlen($bytes)).$bytes;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $encoded = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($encoded)).$encoded;
    }

    private static function decodedLength(string $der): ?int
    {
        $first = ord($der[1]);
        if ($first < 0x80) {
            return 2 + $first;
        }
        $count = $first & 0x7F;
        if ($count < 1 || $count > 2 || strlen($der) < 2 + $count) {
            return null;
        }

        return 2 + $count + (int) hexdec(bin2hex(substr($der, 2, $count)));
    }
}
