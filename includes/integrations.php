<?php
declare(strict_types=1);

/*
 * API keys for Flutterwave, Termii, Prembly and Resend. A super admin manages
 * them under Integrations; they're stored encrypted in the settings table.
 * Environment variables still work as a fallback for older setups.
 */

/** What can be stored, with the environment variable each one falls back to. */
const INTEGRATION_KEYS = [
    'flw_test_public' => 'FLW_PUBLIC_KEY',  'flw_test_secret' => 'FLW_SECRET_KEY', 'flw_test_hash' => 'FLW_SECRET_HASH',
    'flw_live_public' => 'FLW_PUBLIC_KEY',  'flw_live_secret' => 'FLW_SECRET_KEY', 'flw_live_hash' => 'FLW_SECRET_HASH',
    'termii_api_key'  => 'TERMII_API_KEY',  'termii_sender_id' => 'TERMII_SENDER_ID',
    'prembly_app_id'  => 'PREMBLY_APP_ID',  'prembly_secret_key' => 'PREMBLY_SECRET_KEY',
    'resend_api_key'  => 'RESEND_API_KEY',  'mail_from' => 'MAIL_FROM',
];

/** Encryption key: APP_KEY if set, otherwise derived from the database password (both are server-side secrets). */
function secrets_key(): string
{
    return hash('sha256', (string)(env('APP_KEY') ?: DB_PASS . '|' . DB_USER . '|' . DB_HOST . '|lucapetloan-secrets'), true);
}

function encrypt_secret(string $plain): string
{
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', secrets_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:' . base64_encode($iv . $tag . $cipher);
}

function decrypt_secret(string $stored): string
{
    $raw = base64_decode(substr($stored, 4), true);
    if ($raw === false || strlen($raw) < 28) {
        return '';
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secrets_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

/** A stored key, or its environment fallback. Flutterwave env keys only fill the matching test/live slot. */
function int_key(string $name): string
{
    static $cache = null;
    if ($name === '__reset') {
        $cache = null;
        return '';
    }
    if ($cache === null) {
        try {
            $cache = q('SELECT k, v FROM settings WHERE k LIKE "sec\\_%"')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException) {
            $cache = [];
        }
    }
    if (isset($cache['sec_' . $name])) {
        return decrypt_secret($cache['sec_' . $name]);
    }
    $env = (string)env(INTEGRATION_KEYS[$name] ?? '', '');
    if (str_starts_with($name, 'flw_') && $env !== '' && !str_ends_with($name, '_hash')) {
        $isTest = str_contains($env, '_TEST');
        return (str_starts_with($name, 'flw_test_') === $isTest) ? $env : '';
    }
    return $env;
}

function save_int_key(string $name, ?string $value): void
{
    if ($value === null || $value === '') {
        q('DELETE FROM settings WHERE k = ?', ['sec_' . $name]);
    } else {
        q('REPLACE INTO settings (k, v) VALUES (?, ?)', ['sec_' . $name, encrypt_secret($value)]);
    }
    int_key('__reset');
}

/** "••••1a2b" so staff can tell keys apart without seeing them. */
function mask_key(string $v): string
{
    return $v === '' ? '' : '••••' . substr($v, -4);
}

/* ---------- Per-service accessors ---------- */

function flw_mode(): string
{
    return setting('flw_mode') === 'live' ? 'live' : 'test';
}

function flw_secret(): string
{
    return int_key('flw_' . flw_mode() . '_secret');
}

function flw_hash(): string
{
    return int_key('flw_' . flw_mode() . '_hash');
}

function termii_key(): string
{
    return int_key('termii_api_key');
}

function termii_sender(): string
{
    return int_key('termii_sender_id') ?: 'LucaPet';
}

function resend_key(): string
{
    return int_key('resend_api_key');
}

function mail_from(): string
{
    return int_key('mail_from') ?: APP_NAME . ' <onboarding@resend.dev>';
}
