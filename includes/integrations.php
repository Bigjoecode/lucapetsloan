<?php
declare(strict_types=1);

/*
 * API keys for Flutterwave, NOWPayments, Prembly and email (hosting mailbox or Resend). A super admin manages
 * them under Integrations; they're stored encrypted in the settings table.
 * Environment variables still work as a fallback for older setups.
 */

/** What can be stored, with the environment variable each one falls back to. */
const INTEGRATION_KEYS = [
    'flw_test_public' => 'FLW_PUBLIC_KEY',  'flw_test_secret' => 'FLW_SECRET_KEY', 'flw_test_hash' => 'FLW_SECRET_HASH',
    'flw_live_public' => 'FLW_PUBLIC_KEY',  'flw_live_secret' => 'FLW_SECRET_KEY', 'flw_live_hash' => 'FLW_SECRET_HASH',
    'prembly_app_id'  => 'PREMBLY_APP_ID',  'prembly_secret_key' => 'PREMBLY_SECRET_KEY',
    'resend_api_key'  => 'RESEND_API_KEY',  'mail_from' => 'MAIL_FROM',
    'smtp_host'       => 'SMTP_HOST',       'smtp_port' => 'SMTP_PORT',
    'smtp_user'       => 'SMTP_USER',       'smtp_pass' => 'SMTP_PASS',
    'np_api_key'      => 'NP_API_KEY',      'np_ipn_secret' => 'NP_IPN_SECRET',
    'np_email'        => 'NP_EMAIL',        'np_password' => 'NP_PASSWORD',
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

/* Hosting mailbox: only the password has to be entered. Server and mailbox default to this
   site's own domain (as cPanel's "Connect Devices" shows for a mailbox on the same hosting). */
function site_domain(): string
{
    $h = strtolower((string)(parse_url(absolute_url(''), PHP_URL_HOST) ?: ''));
    return str_starts_with($h, 'www.') ? substr($h, 4) : $h;
}

/** Ports that send mail. 993/995 are for reading mail (IMAP/POP3), so they never apply here. */
const SMTP_PORTS = [465, 587, 25, 2525];

function smtp_host(): string
{
    $h = int_key('smtp_host');
    // A bare number (e.g. a port typed in the wrong box) is ignored; host names and IP addresses are kept.
    return preg_match('/[a-z]/i', $h) || filter_var($h, FILTER_VALIDATE_IP) ? $h : site_domain();
}

function smtp_port(): int
{
    $p = (int)int_key('smtp_port');
    return in_array($p, SMTP_PORTS, true) ? $p : 465;
}

function smtp_user(): string
{
    return int_key('smtp_user') ?: (site_domain() !== '' ? 'no-reply@' . site_domain() : '');
}

function resend_key(): string
{
    return int_key('resend_api_key');
}

function mail_from(): string
{
    if (int_key('mail_from') !== '') {
        return int_key('mail_from');
    }
    return APP_NAME . ' <' . ((setting('mail_server') || smtp_configured()) && str_contains(smtp_user(), '@') ? smtp_user() : 'onboarding@resend.dev') . '>';
}
