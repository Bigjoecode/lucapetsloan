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

// Where customers send deposits / repayments by bank transfer
define('BANK_NAME', env('BANK_NAME', 'Your Bank Plc'));
define('BANK_ACCOUNT_NAME', env('BANK_ACCOUNT_NAME', 'LucaPet Loan Ltd'));
define('BANK_ACCOUNT_NUMBER', env('BANK_ACCOUNT_NUMBER', '0123456789'));

// Rules
define('MIN_DEPOSIT', 100);                     // smallest daily contribution
define('MIN_WITHDRAWAL', 500);
// Vercel caps a whole request at 4.5 MB, so each of the 3 documents must stay small there.
define('MAX_UPLOAD_BYTES', IS_VERCEL ? (int)(1.3 * 1024 * 1024) : 5 * 1024 * 1024);
define('DAYS_PER_MONTH', 30);                   // interest month length

// Loan terms, the same for every product
define('LOAN_MONTHLY_RATE', 19.8);              // percent per 30 days
define('LOAN_LATE_FEE_RATE', 2.0);              // percent of the unpaid balance per day after the due date
define('LOAN_TENURE_MONTHS', [1, 2]);           // 30 or 60 days

define('SAVINGS_DAILY_RATE', 0.2);              // percent per day, credited to each plan daily

// Password reset emails go out through Resend (resend.com) when a key is set;
// without one, reset requests reach the admins, who send the customer a link.
define('RESEND_API_KEY', env('RESEND_API_KEY', ''));
define('MAIL_FROM', env('MAIL_FROM', APP_NAME . ' <onboarding@resend.dev>'));

define('UPLOAD_DIR', __DIR__ . '/uploads');     // only used for documents uploaded before they moved into the database

date_default_timezone_set(env('APP_TIMEZONE', 'Africa/Lagos'));
