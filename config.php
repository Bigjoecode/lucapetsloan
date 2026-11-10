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
    'savings_annual_rate'  => 12.0,     // percent a year, earned daily (like PiggyVest/Cowrywise flexible savings)
    'min_deposit'          => 100,
    'min_withdrawal'       => 500,
    'manual_deposits'      => 1,        // customers may pay by bank transfer and enter a reference
    'flw_deposits'         => 1,        // pay online through Flutterwave (keys under Integrations)
    'flw_payouts'          => 1,        // send approved withdrawals through Flutterwave
    'auto_payouts'         => 1,        // send withdrawals instantly through Flutterwave, without staff approval
    'auto_payout_daily_limit' => 50000, // per customer per day; above it (or 0 = no limit) staff approve
    'auto_approve_repeat'  => 1,        // approve loans instantly for customers with an on-time repayment record
    'auto_disburse'        => 1,        // pay instantly approved loans straight to the customer's bank
    'sms_enabled'          => 1,        // send important alerts and reminders by SMS (Termii key under Integrations)
    'savings_sms_reminders'=> 0,        // daily SMS nudge when a plan hasn't been paid that day (costs per SMS)
    // Borrowing limit by credit level; savings add up to the same amount again.
    'limit_starter'        => 20000,
    'limit_bronze'         => 50000,
    'limit_silver'         => 100000,
    'limit_gold'           => 250000,
    'limit_platinum'       => 500000,
    'last_cron_run'        => '',       // written by the daily job
    'flw_mode'             => 'test',   // Flutterwave keys in use: test or live (keys are under Integrations)
    'dojah_mode'           => 'sandbox',// Dojah sandbox (test data) or live
    'dual_approval_amount' => 200000,   // loans this size or bigger need two staff approvals (0 = off)
    'require_staff_2fa'    => 0,        // staff must use an authenticator app to sign in
    'referral_bonus'       => 500,      // paid into the inviter's savings (0 = off)
    'referral_min_savings' => 5000,     // once the invited friend has saved this much
    'visit_radius_m'       => 300,      // check-ins further than this from the address are flagged
    'bank_name'            => 'Your Bank Plc',
    'bank_account_name'    => 'LucaPet Loan Ltd',
    'bank_account_number'  => '0123456789',
];

// Vercel caps a whole request at 4.5 MB, so each of the 4 documents must stay small there.
define('MAX_UPLOAD_BYTES', IS_VERCEL ? 850 * 1024 : 5 * 1024 * 1024);
define('DAYS_PER_MONTH', 30);                   // interest month length

// Service API keys are managed by the super admin under Integrations (includes/integrations.php).
// The base URLs below are only overridden for local testing.
define('FLW_BASE_URL', rtrim(env('FLW_BASE_URL', 'https://api.flutterwave.com/v3'), '/'));

// SMS through Termii (termii.com). The sender ID must be approved by Termii;
// the "dnd" channel also reaches numbers on Do-Not-Disturb but needs that approval too.
define('TERMII_CHANNEL', env('TERMII_CHANNEL', 'generic'));
define('TERMII_BASE_URL', rtrim(env('TERMII_BASE_URL', 'https://api.ng.termii.com/api'), '/'));

// BVN checks through Dojah (dojah.io). Use https://sandbox.dojah.io while testing.
define('DOJAH_BASE_URL', rtrim((string)env('DOJAH_BASE_URL', ''), '/'));   // override only; normally set by the sandbox/live switch

// Daily scheduled job (/cron.php). Vercel sends "Authorization: Bearer <CRON_SECRET>".
define('CRON_SECRET', env('CRON_SECRET', ''));

// Password reset emails go out through Resend (resend.com) when a key is set;
// without one, reset requests reach the admins, who send the customer a link.

define('UPLOAD_DIR', __DIR__ . '/uploads');     // only used for documents uploaded before they moved into the database

date_default_timezone_set(env('APP_TIMEZONE', 'Africa/Lagos'));
