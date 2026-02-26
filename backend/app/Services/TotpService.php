<?php

namespace App\Services;

class TotpService
{
    public function generateSecret(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $secret;
    }

    public function verifyCode(string $secret, string $code, int $window = 1, int $timeStep = 30, int $digits = 6): bool
    {
        $normalizedCode = preg_replace('/\D+/', '', $code) ?? '';
        if ($normalizedCode === '' || strlen($normalizedCode) !== $digits) {
            return false;
        }

        $counter = intdiv(time(), $timeStep);
        for ($i = -$window; $i <= $window; $i++) {
            $otp = $this->totp($secret, $counter + $i, $digits);
            if (hash_equals($otp, $normalizedCode)) {
                return true;
            }
        }

        return false;
    }

    public function provisioningUri(string $accountLabel, string $secret, string $issuer = 'DMS'): string
    {
        $label = rawurlencode($issuer.':'.$accountLabel);
        $issuerEncoded = rawurlencode($issuer);

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuerEncoded}&algorithm=SHA1&digits=6&period=30";
    }

    private function totp(string $secret, int $counter, int $digits): string
    {
        $secretKey = $this->base32Decode($secret);
        if ($secretKey === '') {
            return str_repeat('0', $digits);
        }

        $binaryCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $mod = 10 ** $digits;
        return str_pad((string) ($value % $mod), $digits, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $input): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $normalized = strtoupper(preg_replace('/[^A-Z2-7]/', '', $input) ?? '');
        if ($normalized === '') {
            return '';
        }

        $bits = '';
        $len = strlen($normalized);
        for ($i = 0; $i < $len; $i++) {
            $value = strpos($alphabet, $normalized[$i]);
            if ($value === false) {
                return '';
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        $bitLen = strlen($bits);
        for ($i = 0; $i + 8 <= $bitLen; $i += 8) {
            $binary .= chr(bindec(substr($bits, $i, 8)));
        }

        return $binary;
    }
}

