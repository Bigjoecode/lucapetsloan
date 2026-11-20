<?php
declare(strict_types=1);

/*
 * Messages to customers go by email (the in-app notification is always kept too).
 * Email is sent through your hosting mailbox (SMTP) or Resend, whichever is set
 * up under Integrations. Every email is logged in messages_log, which also stops
 * a reminder (kind + ref) from going out twice.
 */

/** Email alerts are on when an email service is set up and the super admin hasn't switched them off. */
function alerts_on(): bool
{
    return mail_enabled() && setting('email_alerts');
}

function message_sent(string $kind, string $ref): bool
{
    return (bool)q_val('SELECT 1 FROM messages_log WHERE kind = ? AND ref = ? AND status = "sent" LIMIT 1', [$kind, $ref]);
}

/** A branded email: title, a paragraph or two, and an optional button. */
function email_html(string $title, string $body, string $link = '', string $button = 'Open ' . APP_NAME): string
{
    $paras = '';
    foreach (preg_split('/\n{2,}/', trim($body)) ?: [] as $p) {
        $paras .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#33413b">' . nl2br(e($p)) . '</p>';
    }
    $btn = $link === '' ? '' : '<p style="margin:22px 0 6px"><a href="' . e($link) . '" style="display:inline-block;background:#0e7a55;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:12px">' . e($button) . '</a></p>';
    return '<!doctype html><html><body style="margin:0;background:#f1f5f2;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f2;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:18px;overflow:hidden">'
        . '<tr><td style="background:linear-gradient(125deg,#0b4f3c,#12a06f);background-color:#0b4f3c;padding:20px 26px;color:#ffffff;font-size:18px;font-weight:800">'
        . '<span style="display:inline-block;background:#f2b33d;color:#2b1d00;border-radius:9px;width:30px;height:30px;line-height:30px;text-align:center;margin-right:8px">₦</span>' . e(APP_NAME) . '</td></tr>'
        . '<tr><td style="padding:26px">'
        . '<h1 style="margin:0 0 14px;font-size:20px;color:#13201b">' . e($title) . '</h1>' . $paras . $btn
        . '</td></tr>'
        . '<tr><td style="padding:16px 26px;background:#f8faf8;color:#6b7a73;font-size:12px;line-height:1.5">'
        . 'Need help? ' . e(SUPPORT_PHONE) . ' · ' . e(SUPPORT_EMAIL) . '<br>We will never ask for your PIN or password by email.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * Email a customer by id. With $kind/$ref it goes out at most once (used by reminders).
 * Returns true when the email was accepted for delivery.
 */
function message_user(int $userId, string $title, string $body = '', string $link = '', string $kind = '', string $ref = ''): bool
{
    if (!alerts_on() || ($kind !== '' && message_sent($kind, $ref))) {
        return false;
    }
    $email = (string)q_val('SELECT email FROM users WHERE id = ? AND status = "active"', [$userId]);
    if ($email === '') {
        return false;
    }
    $ok = send_mail($email, $title . ' · ' . APP_NAME, email_html($title, $body, $link !== '' ? absolute_url($link) : ''));
    q('INSERT INTO messages_log (user_id, channel, recipient, kind, ref, body, status) VALUES (?, "email", ?, ?, ?, ?, ?)',
        [$userId, $email, $kind, $ref, mb_substr(preg_replace('/token=[a-f0-9]+/', 'token=(hidden)', $title . '. ' . $body), 0, 500), $ok ? 'sent' : 'failed']);
    return $ok;
}
