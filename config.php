<?php
/**
 * LucaPet Loan — configuration
 * Every value can be overridden with an environment variable (used on Vercel).
 * Locally (XAMPP) the defaults below apply; open /install.php once to set up.
 */

// On shared hosting (cPanel) settings live in a private file one folder ABOVE the web root,
// e.g. /home/USER/.lucapetsloan.env next to /home/USER/lucapetsloan.com. Format: KEY=value per line.
function env_file(): array
{
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $file = dirname(__DIR__) . '/.lucapetsloan.env';
        if (is_file($file) && is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) $v = substr($v, 1, -1);
                $vars[$k] = $v;
            }
        }
    }
    return $vars;
}

function env(string $key, $default = null)
{
    $file = env_file();
    if (array_key_exists($key, $file)) return $file[$key];   // may be '' on purpose (e.g. BASE_URL at the domain root)
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
// Addresses without ".php" (.htaccess serves /crypto from crypto.php). Set CLEAN_URLS=0 where rewrites aren't available.
define('CLEAN_URLS', filter_var(env('CLEAN_URLS', '1'), FILTER_VALIDATE_BOOLEAN));
define('CURRENCY', '₦');
define('SUPPORT_PHONE', env('SUPPORT_PHONE', '+234 903 540 9081'));
define('SUPPORT_EMAIL', env('SUPPORT_EMAIL', 'support@lucapetsloan.com'));

// Business rules. These are only the starting values: the super admin changes
// them under Settings, and the saved values live in the settings table.
const SETTING_DEFAULTS = [
    'loan_monthly_rate'    => 19.8,     // percent per 30 days
    'loan_late_fee_rate'   => 2.0,      // percent of the unpaid balance per day after the due date
    'loan_tenures'         => '1,2',    // allowed terms in 30-day months: 30 or 60 days
    'savings_annual_rate'  => 12.0,     // percent a year, earned daily (like PiggyVest/Cowrywise flexible savings)
    'min_deposit'          => 100,
    'min_daily_saving'     => 500,      // smallest daily amount for a savings plan (and for paying that day's amount)
    'min_withdrawal'       => 500,
    'manual_deposits'      => 1,        // customers may pay by bank transfer and enter a reference
    'flw_deposits'         => 1,        // pay online through Flutterwave (keys under Integrations)
    'flw_payouts'          => 1,        // send approved withdrawals through Flutterwave
    'auto_payouts'         => 1,        // send withdrawals instantly through Flutterwave, without staff approval
    'auto_payout_daily_limit' => 50000, // per customer per day; above it (or 0 = no limit) staff approve
    'auto_approve_repeat'  => 1,        // approve loans instantly for customers with an on-time repayment record
    'auto_disburse'        => 1,        // pay instantly approved loans straight to the customer's bank
    'mail_server'          => 0,        // send email with the hosting server's built-in mail (no mailbox password)
    'email_alerts'         => 1,        // email important alerts and reminders (email set up under Integrations)
    'savings_email_reminders' => 0,     // daily email nudge when a plan hasn't been paid that day
    // Borrowing limit by credit level; savings add up to the same amount again.
    'limit_starter'        => 20000,
    'limit_bronze'         => 50000,
    'limit_silver'         => 100000,
    'limit_gold'           => 250000,
    'limit_platinum'       => 500000,
    'last_cron_run'        => '',       // written by the daily job
    'flw_mode'             => 'test',   // Flutterwave keys in use: test or live (keys are under Integrations)
    'np_mode'              => 'sandbox', // NOWPayments: sandbox or live (keys are under Integrations)
    'crypto_savings'       => 1,        // offer crypto savings once NOWPayments is connected
    'schema_version'       => 0,        // written after database upgrades run
    'prembly_base_url'     => 'https://api.prembly.com',          // Prembly (IdentityPass) API
    'prembly_bvn_path'     => '/verification/bvn_validation',
    'prembly_nin_path'     => '/verification/vnin-basic',
    'dual_approval_amount' => 200000,   // loans this size or bigger need two staff approvals (0 = off)
    'site_url'             => '',       // remembered from visits; used for links in emails sent by the daily job
    'login_email_code'     => 1,        // email a 6-digit code at sign-in, before the PIN
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

// NOWPayments (crypto savings). NP_BASE_URL overrides both addresses (local tests only).
define('NP_LIVE_URL', 'https://api.nowpayments.io/v1');
define('NP_SANDBOX_URL', 'https://api-sandbox.nowpayments.io/v1');
// Public crypto prices for the live market panel (MARKET_BASE_URL is for local tests only).
define('MARKET_BASE_URL', rtrim(env('MARKET_BASE_URL', 'https://api.coingecko.com/api/v3'), '/'));

// Email through Resend, if used instead of a hosting mailbox (RESEND_BASE_URL is for local tests only).
define('RESEND_BASE_URL', rtrim(env('RESEND_BASE_URL', 'https://api.resend.com'), '/'));

// BVN and NIN checks go through Prembly; keys and endpoints are under Integrations.

// Daily scheduled job (/cron.php). Vercel sends "Authorization: Bearer <CRON_SECRET>".
define('CRON_SECRET', env('CRON_SECRET', ''));

// Password reset emails go out through Resend (resend.com) when a key is set;
// without one, reset requests reach the admins, who send the customer a link.

define('UPLOAD_DIR', __DIR__ . '/uploads');     // only used for documents uploaded before they moved into the database

date_default_timezone_set(env('APP_TIMEZONE', 'Africa/Lagos'));
