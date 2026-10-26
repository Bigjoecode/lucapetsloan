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

define('UPLOAD_DIR', __DIR__ . '/uploads');     // only used for documents uploaded before they moved into the database

date_default_timezone_set(env('APP_TIMEZONE', 'Africa/Lagos'));
