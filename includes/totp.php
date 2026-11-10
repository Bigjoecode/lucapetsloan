<?php
declare(strict_types=1);

/* Time-based one-time codes (RFC 6238), as used by Google Authenticator, Authy and Microsoft Authenticator. */

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_new_secret(): string
{
    $bits = '';
    foreach (str_split(random_bytes(20)) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode_str(string $s): string
{
    $bits = '';
    foreach (str_split(strtoupper(rtrim($s, '='))) as $c) {
        $i = strpos(BASE32_ALPHABET, $c);
        if ($i === false) {
            return '';
        }
        $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_code(string $secret, int $slice): string
{
    $hash = hash_hmac('sha1', pack('J', $slice), base32_decode_str($secret), true);
    $o = ord($hash[19]) & 0xf;
    $num = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string)($num % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Accept the current code and the ones either side, for clock drift. */
function totp_verify(string $secret, string $code): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6 || $secret === '') {
        return false;
    }
    $now = intdiv(time(), 30);
    foreach ([-1, 0, 1] as $d) {
        if (hash_equals(totp_code($secret, $now + $d), $code)) {
            return true;
        }
    }
    return false;
}

function totp_uri(string $secret, string $account): string
{
    return 'otpauth://totp/' . rawurlencode(APP_NAME . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode(APP_NAME) . '&digits=6&period=30';
}
