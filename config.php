<?php
/**
 * LucaPet Loan — configuration
 * Every value can be overridden with an environment variable (used on Vercel).
 * Locally (XAMPP) the defaults below apply; open /install.php once to set up.
 */

function env(string $key, $default = null)
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

define('IS_VERCEL', (bool)env('VERCEL'));

// Database. DATABASE_URL (mysql://user:pass@host:port/dbname) takes priority if set.
$dbUrl = env('DATABASE_URL') ? parse_url(env('DATABASE_URL')) : [];
define('DB_HOST', $dbUrl['host'] ?? env('DB_HOST', '127.0.0.1'));
define('DB_PORT', (int)($dbUrl['port'] ?? env('DB_PORT', 3307)));          // this XAMPP's my.ini uses 3307
define('DB_NAME', isset($dbUrl['path']) ? ltrim($dbUrl['path'], '/') : env('DB_NAME', 'lucapetloan'));
define('DB_USER', isset($dbUrl['user']) ? urldecode($dbUrl['user']) : env('DB_USER', 'root'));
define('DB_PASS', isset($dbUrl['pass']) ? urldecode($dbUrl['pass']) : env('DB_PASS', ''));
define('DB_SSL', filter_var(env('DB_SSL', IS_VERCEL ? '1' : '0'), FILTER_VALIDATE_BOOLEAN));   // cloud MySQL needs TLS
unset($dbUrl);

// Sessions: "db" keeps logins working on serverless hosts where each request may hit a fresh server.
define('SESSION_DRIVER', env('SESSION_DRIVER', IS_VERCEL ? 'db' : 'files'));
// When set, /install.php asks for this key so strangers cannot create the first admin.
define('SETUP_KEY', env('SETUP_KEY', ''));

// App
define('APP_NAME', env('APP_NAME', 'LucaPet Loan'));
define('APP_TAGLINE', 'Save daily. Borrow smart.');
define('BASE_URL', env('BASE_URL', IS_VERCEL ? '' : '/lucapetloan'));   // URL path where the app lives (no trailing slash)
define('CURRENCY', '₦');
define('SUPPORT_PHONE', env('SUPPORT_PHONE', '+234 800 000 0000'));
define('SUPPORT_EMAIL', env('SUPPORT_EMAIL', 'support@lucapetloan.test'));

// Business rules. These are only the starting values: the super admin changes
// them under Settings, and the saved values live in the settings table.
const SETTING_DEFAULTS = [
    'loan_monthly_rate'    => 19.8,     // percent per 30 days
    'loan_late_fee_rate'   => 2.0,      // percent of the unpaid balance per day after the due date
    'loan_tenures'         => '1,2',    // allowed terms in 30-day months: 30 or 60 days
    'savings_daily_rate'   => 0.2,      // percent per day, credited to each plan daily
    'min_deposit'          => 100,
    'min_withdrawal'       => 500,
    'manual_deposits'      => 1,        // customers may pay by bank transfer and enter a reference
    'flw_deposits'         => 1,        // pay online through Flutterwave (needs FLW_SECRET_KEY)
    'flw_payouts'          => 1,        // send approved withdrawals through Flutterwave
    'auto_payouts'         => 1,        // send withdrawals instantly through Flutterwave, without staff approval
    'auto_payout_daily_limit' => 50000, // per customer per day; above it (or 0 = no limit) staff approve
    'auto_approve_repeat'  => 1,        // approve loans instantly for customers with an on-time repayment record
    'auto_disburse'        => 1,        // pay instantly approved loans straight to the customer's bank
    'sms_enabled'          => 1,        // send important alerts and reminders by SMS (needs TERMII_API_KEY)
    'savings_sms_reminders'=> 0,        // daily SMS nudge when a plan hasn't been paid that day (costs per SMS)
    // Borrowing limit by credit level; savings add up to the same amount again.
    'limit_starter'        => 20000,
    'limit_bronze'         => 50000,
    'limit_silver'         => 100000,
    'limit_gold'           => 250000,
    'limit_platinum'       => 500000,
    'last_cron_run'        => '',       // written by the daily job
    'bank_name'            => 'Your Bank Plc',
    'bank_account_name'    => 'LucaPet Loan Ltd',
    'bank_account_number'  => '0123456789',
];

// Vercel caps a whole request at 4.5 MB, so each of the 3 documents must stay small there.
define('MAX_UPLOAD_BYTES', IS_VERCEL ? (int)(1.3 * 1024 * 1024) : 5 * 1024 * 1024);
define('DAYS_PER_MONTH', 30);                   // interest month length

// Flutterwave (v3 API) for online deposits, repayments and withdrawals.
// FLW_SECRET_HASH must match the "secret hash" set for the webhook in the Flutterwave dashboard.
define('FLW_SECRET_KEY', env('FLW_SECRET_KEY', ''));
define('FLW_SECRET_HASH', env('FLW_SECRET_HASH', ''));
define('FLW_BASE_URL', rtrim(env('FLW_BASE_URL', 'https://api.flutterwave.com/v3'), '/'));

// SMS through Termii (termii.com). TERMII_SENDER_ID must be approved by Termii;
// the "dnd" channel also reaches numbers on Do-Not-Disturb but needs that approval too.
define('TERMII_API_KEY', env('TERMII_API_KEY', ''));
define('TERMII_SENDER_ID', env('TERMII_SENDER_ID', 'LucaPet'));
define('TERMII_CHANNEL', env('TERMII_CHANNEL', 'generic'));
define('TERMII_BASE_URL', rtrim(env('TERMII_BASE_URL', 'https://api.ng.termii.com/api'), '/'));

// Daily scheduled job (/cron.php). Vercel sends "Authorization: Bearer <CRON_SECRET>".
define('CRON_SECRET', env('CRON_SECRET', ''));

// Password reset emails go out through Resend (resend.com) when a key is set;
// without one, reset requests reach the admins, who send the customer a link.
define('RESEND_API_KEY', env('RESEND_API_KEY', ''));
define('MAIL_FROM', env('MAIL_FROM', APP_NAME . ' <onboarding@resend.dev>'));

define('UPLOAD_DIR', __DIR__ . '/uploads');     // only used for documents uploaded before they moved into the database

date_default_timezone_set(env('APP_TIMEZONE', 'Africa/Lagos'));
