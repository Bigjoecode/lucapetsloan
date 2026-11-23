<?php
declare(strict_types=1);

/*
 * Email sign-in codes: password → 6-digit code by email → PIN.
 * A device can be remembered for 30 days so the code isn't asked for every time there.
 * If email can't be sent, sign-in continues to the PIN step so nobody is locked out.
 */

const LOGIN_CODE_MINUTES = 10;
const LOGIN_CODE_TRIES = 5;
const TRUSTED_DEVICE_DAYS = 30;
const TRUSTED_COOKIE = 'lpl_td';

function login_codes_on(): bool
{
    return (bool)setting('login_email_code') && mail_enabled();
}

/** "a****@gmail.com" */
function mask_email(string $email): string
{
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($name, 0, 1) . str_repeat('*', max(3, min(6, mb_strlen($name) - 1))) . '@' . $domain;
}

/** Is this browser remembered for the user? */
function device_trusted(int $userId): bool
{
    $token = (string)($_COOKIE[TRUSTED_COOKIE] ?? '');
    if (strlen($token) !== 64 || !ctype_xdigit($token)) {
        return false;
    }
    return (bool)q_val('SELECT 1 FROM trusted_devices WHERE user_id = ? AND token_hash = ? AND expires_at > NOW()', [$userId, hash('sha256', $token)]);
}

function trust_device(int $userId): void
{
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO trusted_devices (user_id, token_hash, label, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))',
        [$userId, hash('sha256', $token), mb_substr(device_name((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 80), TRUSTED_DEVICE_DAYS]);
    setcookie(TRUSTED_COOKIE, $token, ['expires' => time() + TRUSTED_DEVICE_DAYS * 86400, 'path' => (BASE_URL ?: '/'), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

/** Forget every remembered device (after a password reset or change). */
function forget_devices(int $userId): void
{
    q('DELETE FROM trusted_devices WHERE user_id = ?', [$userId]);
}

/**
 * Email a new code. Returns '' when sent, otherwise why not.
 * At most one code a minute and five an hour per account.
 */
function send_login_code(array $user): string
{
    // Within a minute of a code that is still unused, keep that one (it still works) instead of sending another.
    $last = q_one('SELECT created_at, used_at, expires_at, attempts FROM login_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$user['id']]);
    if ($last && strtotime($last['created_at']) > time() - 60 && $last['used_at'] === null && strtotime($last['expires_at']) > time() && (int)$last['attempts'] < LOGIN_CODE_TRIES) {
        return 'We just sent you a code. Use that one, or wait a minute to ask for another.';
    }
    if ((int)q_val('SELECT COUNT(*) FROM login_codes WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$user['id']]) >= 10) {
        return 'Too many codes requested. Please wait an hour or call ' . SUPPORT_PHONE . '.';
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    q('UPDATE login_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$user['id']]);   // only the newest code works
    q('INSERT INTO login_codes (user_id, code_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
        [$user['id'], password_hash($code, PASSWORD_DEFAULT), LOGIN_CODE_MINUTES]);
    $first = explode(' ', trim($user['full_name']))[0];
    $html = email_html('Your sign-in code',
        'Hi ' . $first . ",\n\nUse this code to finish signing in to " . APP_NAME . ":\n\nCODEBOX\n\nIt works for " . LOGIN_CODE_MINUTES . " minutes. Never share it with anyone, including our staff. If you didn't try to sign in, change your password and call " . SUPPORT_PHONE . '.');
    // The code as a big box in the middle of the email.
    $html = str_replace('>CODEBOX</p>', '><span style="display:inline-block;font:800 30px/1.2 \'Courier New\',monospace;letter-spacing:8px;background:#f1f5f2;border:1px dashed #0e7a55;border-radius:12px;padding:12px 18px;color:#0b4f3c">' . $code . '</span></p>', $html);
    if (!send_mail($user['email'], $code . ' is your ' . APP_NAME . ' sign-in code', $html)) {
        q('UPDATE login_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$user['id']]);
        return 'send-failed';
    }
    return '';
}

/** Check a code. Returns '' when right, otherwise the message to show. */
function check_login_code(int $userId, string $code): string
{
    $row = q_one('SELECT * FROM login_codes WHERE user_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1', [$userId]);
    if (!$row || strtotime($row['expires_at']) < time()) {
        return 'That code has expired. Press "Send a new code".';
    }
    if ((int)$row['attempts'] >= LOGIN_CODE_TRIES) {
        return 'Too many wrong tries. Press "Send a new code".';
    }
    if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $row['code_hash'])) {
        q('UPDATE login_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
        $left = LOGIN_CODE_TRIES - (int)$row['attempts'] - 1;
        return $left > 0 ? 'That code is not right. ' . $left . ' tr' . ($left === 1 ? 'y' : 'ies') . ' left.' : 'Too many wrong tries. Press "Send a new code".';
    }
    q('UPDATE login_codes SET used_at = NOW() WHERE id = ?', [$row['id']]);
    return '';
}

/**
 * After the password is right: the next sign-in step for this user.
 * Sends the email code when needed and returns the page to go to.
 */
function after_password(array $user): string
{
    if (is_staff($user) && $user['totp_enabled']) {
        session_regenerate_id(true);
        $_SESSION['2fa_uid'] = (int)$user['id'];
        $_SESSION['2fa_at'] = time();
        return 'login_2fa.php';
    }
    if (login_codes_on() && !device_trusted((int)$user['id'])) {
        $sent = send_login_code($user);
        if ($sent === '' || $sent !== 'send-failed') {
            session_regenerate_id(true);
            $_SESSION['code_uid'] = (int)$user['id'];
            $_SESSION['code_at'] = time();
            if ($sent !== '') {
                flash('error', $sent);   // e.g. asked again too soon: the earlier code still works
            }
            return 'login_code.php';
        }
        error_log('[lucapetloan] sign-in code not sent to user ' . $user['id'] . ': ' . mail_last_error() . ' · continuing to PIN');
    }
    return after_email_code($user);
}

/** After the email code (or when it isn't needed): the PIN, or straight in for accounts without one yet. */
function after_email_code(array $user): string
{
    if (has_pin($user)) {
        session_regenerate_id(true);
        $_SESSION['pin_uid'] = (int)$user['id'];
        $_SESSION['pin_at'] = time();
        return 'login_pin.php';
    }
    login_user($user);   // no PIN yet: they're sent to create one
    return home_for($user);
}
