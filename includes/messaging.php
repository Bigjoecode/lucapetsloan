<?php
declare(strict_types=1);

/*
 * SMS through Termii. Every message is logged in messages_log, which also
 * stops a reminder (kind + ref) from going out twice.
 */

function sms_on(): bool
{
    return TERMII_API_KEY !== '' && setting('sms_enabled');
}

/** 0803 123 4567 / +2348031234567 → 2348031234567 */
function ng_phone(string $phone): string
{
    $d = preg_replace('/\D/', '', $phone);
    if (str_starts_with($d, '0') && strlen($d) === 11) {
        return '234' . substr($d, 1);
    }
    return $d;
}

function message_sent(string $kind, string $ref): bool
{
    return (bool)q_val('SELECT 1 FROM messages_log WHERE kind = ? AND ref = ? AND status = "sent" LIMIT 1', [$kind, $ref]);
}

/** Send one SMS. Returns true when Termii accepted it. */
function send_sms(?int $userId, string $phone, string $text, string $kind = '', string $ref = ''): bool
{
    if (!sms_on()) {
        return false;
    }
    $to = ng_phone($phone);
    $text = mb_substr(str_replace('₦', 'N', $text), 0, 480);   // ₦ isn't in the GSM alphabet and would double the cost
    $payload = json_encode(['to' => $to, 'from' => TERMII_SENDER_ID, 'sms' => $text, 'type' => 'plain', 'channel' => TERMII_CHANNEL, 'api_key' => TERMII_API_KEY]);
    $ch = curl_init(TERMII_BASE_URL . '/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $res = is_string($raw) ? json_decode($raw, true) : null;
    $ok = $http >= 200 && $http < 300 && is_array($res) && (($res['code'] ?? '') === 'ok' || !empty($res['message_id']));
    if (!$ok) {
        error_log('[lucapetloan] sms to ' . $to . ' failed: HTTP ' . $http . ' ' . substr((string)$raw, 0, 300));
    }
    q('INSERT INTO messages_log (user_id, channel, recipient, kind, ref, body, status) VALUES (?, "sms", ?, ?, ?, ?, ?)',
        [$userId, $to, $kind, $ref, mb_substr(preg_replace('/token=[a-f0-9]+/', 'token=(hidden)', $text), 0, 500), $ok ? 'sent' : 'failed']);   // reset links must not sit in the log
    return $ok;
}

/** SMS a customer by id: "LucaPet Loan: <title>. <body>" */
function sms_user(int $userId, string $title, string $body = '', string $kind = '', string $ref = ''): bool
{
    if (!sms_on() || ($kind !== '' && message_sent($kind, $ref))) {
        return false;
    }
    $phone = q_val('SELECT phone FROM users WHERE id = ? AND status = "active"', [$userId]);
    return $phone ? send_sms($userId, (string)$phone, APP_NAME . ': ' . rtrim($title, '.!') . '. ' . $body, $kind, $ref) : false;
}
