<?php
declare(strict_types=1);

/** A problem with what the user entered; its message is safe to show them. */
final class UserError extends RuntimeException
{
}

/* ------------------------------------------------------------------ */
/*  Database                                                           */
/* ------------------------------------------------------------------ */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, db_options());
        // Plain MySQL quoting rules (some hosts default to ANSI_QUOTES), and the
        // database clock on the app's time zone so "today" means the same thing in SQL and PHP.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function db_options(): array
{
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if (DB_SSL) {
        $ca = env('DB_SSL_CA') ?: (ini_get('openssl.cafile') ?: null);
        foreach (['/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt', '/etc/ssl/cert.pem'] as $candidate) {
            if (!$ca && is_readable($candidate)) {
                $ca = $candidate;
            }
        }
        if ($ca) {
            $opts[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        }
        $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (bool)$ca;
    }
    return $opts;
}

/** True once the database has tables and an admin account. */
function app_installed(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = !IS_VERCEL && file_exists(__DIR__ . '/../install.lock');
        if (!$ok) {
            try {
                $ok = (bool)q_val('SELECT 1 FROM users WHERE role IN ("admin","super_admin") LIMIT 1');
            } catch (PDOException) {
                $ok = false;
            }
        }
    }
    return $ok;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/* ------------------------------------------------------------------ */
/*  HTTP helpers                                                       */
/* ------------------------------------------------------------------ */

function e($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

/** Send the browser to another site, e.g. a payment page. */
function redirect_external(string $absoluteUrl): never
{
    header('Location: ' . $absoluteUrl);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, $default = '')
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim(mb_scrub($v, 'UTF-8')) : $v;   // drop invalid UTF-8 the database would reject
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    if (!is_post()) {
        return;
    }
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session expired. Please go back, refresh the page and try again.');
    }
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ------------------------------------------------------------------ */
/*  Auth                                                               */
/* ------------------------------------------------------------------ */

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            try {
                $user = q_one('SELECT * FROM users WHERE id = ? AND status = "active"', [$_SESSION['uid']]);
            } catch (PDOException) {
                $user = null;   // e.g. database not installed yet
            }
            if (!$user) {
                unset($_SESSION['uid']);
            }
        }
    }
    return $user;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        flash('info', 'Please sign in to continue.');
        redirect('login.php');
    }
    return $u;
}

/* ------------------------------------------------------------------ */
/*  Staff roles & permissions                                          */
/* ------------------------------------------------------------------ */

/** What a staff member (role "admin") can be allowed to do. A super admin can do all of it, plus staff, settings and the audit log. */
const STAFF_PERMISSIONS = [
    'kyc'       => 'Verifications & home visits',
    'loans'     => 'Approve, pay out and collect loans',
    'waivers'   => 'Waive late penalties',
    'payments'  => 'Confirm deposits, repayments & withdrawals',
    'customers' => 'Suspend customers & send reset links',
    'products'  => 'Edit loan products',
    'visits'    => 'Home visits (field officers)',
    'collections' => 'Collect overdue loans',
    'reports'   => 'See reports & export data',
];

function is_staff(?array $u): bool
{
    return $u && in_array($u['role'], ['admin', 'super_admin'], true);
}

function is_super(?array $u): bool
{
    return $u && $u['role'] === 'super_admin';
}

/** Permissions of a staff member; NULL in the database means every staff permission. */
function staff_permissions(array $u): array
{
    if (is_super($u) || $u['permissions'] === null) {
        return array_keys(STAFF_PERMISSIONS);
    }
    return array_values(array_intersect(array_keys(STAFF_PERMISSIONS), explode(',', $u['permissions'])));
}

function can(string $perm, ?array $u = null): bool
{
    $u ??= current_user();
    return is_super($u) || (is_staff($u) && in_array($perm, staff_permissions($u), true));
}

function home_for(array $u): string
{
    return is_staff($u) ? 'admin/index.php' : 'dashboard.php';
}

/** Staff page guard; pass a permission to require it as well. */
function require_staff(?string $perm = null): array
{
    $u = require_login();
    if (!is_staff($u) || ($perm !== null && !can($perm, $u))) {
        http_response_code(403);
        exit("You don't have access to this page.");
    }
    if (setting('require_staff_2fa') && !$u['totp_enabled'] && !defined('SKIP_2FA_GATE')) {
        flash('info', 'Set up two-step sign-in to use the staff console.');
        redirect('admin/security.php');
    }
    return $u;
}

function require_super(): array
{
    $u = require_login();
    if (!is_super($u)) {
        http_response_code(403);
        exit('Super admins only.');
    }
    return $u;
}

/** Notify every active staff member who handles $perm. */
function notify_staff(string $perm, string $title, string $body = '', string $link = ''): void
{
    foreach (q('SELECT * FROM users WHERE role IN ("admin","super_admin") AND status = "active"')->fetchAll() as $s) {
        if (can($perm, $s)) {
            notify((int)$s['id'], $title, $body, $link);
        }
    }
}

/** Record who did what. $actorId NULL means the system (e.g. a payment webhook). */
function audit(string $action, string $target = '', string $details = '', ?int $actorId = -1): void
{
    if ($actorId === -1) {
        $actorId = current_user()['id'] ?? null;
    }
    q('INSERT INTO audit_log (actor_id, action, target, details, ip) VALUES (?,?,?,?,?)',
        [$actorId, $action, mb_substr($target, 0, 80), mb_substr($details, 0, 500), mb_substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
}

/* ------------------------------------------------------------------ */
/*  Transaction PIN                                                    */
/* ------------------------------------------------------------------ */

function has_pin(array $u): bool
{
    return !empty($u['pin_hash']);
}

/** Reject PINs that are easy to guess, like 1111 or 1234. */
function weak_pin(string $pin): bool
{
    return count(array_unique(str_split($pin))) === 1 || str_contains('01234567890', $pin) || str_contains('09876543210', $pin);
}

/**
 * Check the PIN before money leaves an account. Five wrong tries lock it for
 * 30 minutes and warn the customer by SMS.
 */
function check_pin(array $u, string $pin): void
{
    $row = q_one('SELECT pin_hash, pin_fails, pin_locked_until FROM users WHERE id = ?', [$u['id']]);
    if (empty($row['pin_hash'])) {
        throw new UserError('Set your 4-digit PIN in Profile first.');
    }
    if ($row['pin_locked_until'] && strtotime($row['pin_locked_until']) > time()) {
        throw new UserError('Too many wrong PINs. Try again after ' . date('g:i a', strtotime($row['pin_locked_until'])) . ', or reset your PIN in Profile.');
    }
    if (password_verify($pin, $row['pin_hash'])) {
        if ($row['pin_fails']) {
            q('UPDATE users SET pin_fails = 0 WHERE id = ?', [$u['id']]);
        }
        return;
    }
    $fails = (int)$row['pin_fails'] + 1;
    if ($fails >= 5) {
        q('UPDATE users SET pin_fails = 0, pin_locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?', [$u['id']]);
        notify((int)$u['id'], 'PIN locked', 'Someone entered a wrong PIN 5 times, so payments are locked for 30 minutes. If this wasn\'t you, change your password now.', 'profile.php', true);
        throw new UserError('Wrong PIN. Payments are locked for 30 minutes.');
    }
    q('UPDATE users SET pin_fails = ? WHERE id = ?', [$fails, $u['id']]);
    throw new UserError('Wrong PIN. ' . (5 - $fails) . ' tr' . (5 - $fails === 1 ? 'y' : 'ies') . ' left.');
}

/* ------------------------------------------------------------------ */
/*  Settings                                                           */
/* ------------------------------------------------------------------ */

/**
 * A business setting: the super admin's saved value, else an environment
 * variable of the same name in capitals (e.g. BANK_NAME), else the default
 * from SETTING_DEFAULTS. Typed like the default.
 */
function setting(string $key)
{
    static $saved = null;
    if ($saved === null) {
        try {
            $saved = q('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException) {
            $saved = [];   // table not created yet
        }
    }
    if ($key === '__reset') {
        $saved = null;
        return null;
    }
    $default = SETTING_DEFAULTS[$key];
    $v = $saved[$key] ?? env(strtoupper($key), $default);
    return match (true) {
        is_float($default) => (float)$v,
        is_int($default)   => (int)$v,
        default            => (string)$v,
    };
}

function save_setting(string $key, $value): void
{
    q('REPLACE INTO settings (k, v) VALUES (?, ?)', [$key, (string)$value]);
    setting('__reset');
}

/** Allowed loan terms in 30-day months, e.g. [1, 2] for 30 or 60 days. */
function loan_tenures(): array
{
    $t = array_values(array_unique(array_filter(array_map('intval', explode(',', setting('loan_tenures'))))));
    sort($t);
    return $t ?: [1];
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
}

/* ------------------------------------------------------------------ */
/*  Formatting                                                         */
/* ------------------------------------------------------------------ */

function money($n): string
{
    return CURRENCY . number_format((float)$n, 2);
}

function money0($n): string
{
    return CURRENCY . number_format((float)$n, 0);
}

function fdate(?string $d, string $fmt = 'M j, Y'): string
{
    return $d ? date($fmt, strtotime($d)) : '—';
}

function fdatetime(?string $d): string
{
    return fdate($d, 'M j, Y g:i A');
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $i = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $i .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $i ?: '?';
}

function label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}

function badge(string $status): string
{
    $map = [
        'none' => 'muted', 'submitted' => 'info', 'visit_scheduled' => 'warn', 'verified' => 'ok', 'rejected' => 'bad',
        'pending' => 'warn', 'approved' => 'info', 'active' => 'ok', 'overdue' => 'bad', 'repaid' => 'ok',
        'defaulted' => 'bad', 'completed' => 'ok', 'closed' => 'muted', 'suspended' => 'bad', 'paid' => 'ok',
        'partial' => 'warn', 'upcoming' => 'muted', 'processing' => 'info',
    ];
    $cls = $map[$status] ?? 'muted';
    $text = $status === 'none' ? 'Not started' : label($status);
    return '<span class="badge badge-' . $cls . '">' . e($text) . '</span>';
}

/* ------------------------------------------------------------------ */
/*  Notifications                                                      */
/* ------------------------------------------------------------------ */

/** In-app notification; with $sms the customer also gets it by text message. */
function notify(int $userId, string $title, string $body = '', string $link = '', bool $sms = false): void
{
    q('INSERT INTO notifications (user_id, title, body, link) VALUES (?, ?, ?, ?)', [$userId, $title, $body, $link]);
    if ($sms) {
        sms_user($userId, preg_replace('/[^\p{L}\p{N}\p{P}\s₦]/u', '', $title), $body);   // emoji cost extra SMS pages
    }
}

function unread_count(int $userId): int
{
    return (int)q_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$userId]);
}

/* ------------------------------------------------------------------ */
/*  Uploads                                                            */
/* ------------------------------------------------------------------ */

/**
 * Store an uploaded document in the database (hosts like Vercel keep no files).
 * Returns a reference such as "doc:12", null when no file was sent, and throws
 * UserError on invalid files.
 */
function store_upload(string $field, int $userId): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new UserError('Upload failed for ' . label($field) . '.');
    }
    if ($f['size'] > MAX_UPLOAD_BYTES) {
        throw new UserError(label($field) . ' is larger than ' . (MAX_UPLOAD_BYTES / 1048576) . ' MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
    if (!$ext) {
        throw new UserError(label($field) . ' must be a JPG, PNG, WEBP or PDF file.');
    }
    $data = file_get_contents($f['tmp_name']);
    if ($data === false) {
        throw new UserError('Could not read ' . label($field) . '.');
    }
    $insert = function () use ($userId, $field, $mime, $data): void {
        $st = db()->prepare('INSERT INTO documents (user_id, kind, mime, size, data) VALUES (?, ?, ?, ?, ?)');
        $st->bindValue(1, $userId, PDO::PARAM_INT);
        $st->bindValue(2, $field);
        $st->bindValue(3, $mime);
        $st->bindValue(4, strlen($data), PDO::PARAM_INT);
        $st->bindValue(5, $data, PDO::PARAM_LOB);
        $st->execute();
    };
    try {
        $insert();
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) !== 1146) {
            throw $e;
        }
        run_schema();   // databases installed before documents moved into MySQL
        $insert();
    }
    return 'doc:' . db()->lastInsertId();
}

/**
 * Create any missing tables, then bring older databases up to date.
 * Returns the errors from changes that could not be applied.
 */
function run_schema(): array
{
    $errors = [];
    foreach (require __DIR__ . '/schema.php' as $sql) {
        db()->exec($sql);
    }
    $migrations = [
        'ALTER TABLE savings_plans ADD COLUMN interest_earned DECIMAL(14,2) NOT NULL DEFAULT 0',
        'ALTER TABLE savings_plans ADD COLUMN interest_accrued_on DATE NULL',
        "ALTER TABLE transactions MODIFY type ENUM('deposit','withdrawal','loan_disbursement','loan_repayment','interest') NOT NULL",
        "ALTER TABLE users MODIFY role ENUM('customer','admin','super_admin') NOT NULL DEFAULT 'customer'",
        'ALTER TABLE users ADD COLUMN permissions VARCHAR(255) NULL',
        'ALTER TABLE kyc ADD COLUMN bank_code VARCHAR(20) NULL',
        'ALTER TABLE loans ADD COLUMN penalty_waived DECIMAL(14,2) NOT NULL DEFAULT 0',
        'ALTER TABLE loans ADD COLUMN penalty_stopped_on DATE NULL',
        "ALTER TABLE transactions MODIFY status ENUM('pending','approved','rejected','processing') NOT NULL DEFAULT 'pending'",
        'ALTER TABLE transactions ADD COLUMN provider_ref VARCHAR(80) NULL',
        'ALTER TABLE transactions ADD INDEX idx_tx_reference (reference)',
        'ALTER TABLE users ADD COLUMN pin_hash VARCHAR(255) NULL',
        'ALTER TABLE users ADD COLUMN pin_fails TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'ALTER TABLE users ADD COLUMN pin_locked_until DATETIME NULL',
        'ALTER TABLE users ADD COLUMN credit_limit_override DECIMAL(14,2) NULL',
        'ALTER TABLE savings_plans ADD COLUMN auto_save TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE savings_plans ADD COLUMN auto_card_id INT UNSIGNED NULL',
        'ALTER TABLE savings_plans ADD COLUMN auto_fails TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'ALTER TABLE loans ADD COLUMN auto_repay TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE loans ADD COLUMN auto_card_id INT UNSIGNED NULL',
        'ALTER TABLE loans ADD COLUMN auto_fails TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'ALTER TABLE loans ADD COLUMN auto_approved TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE transactions ADD COLUMN save_card TINYINT(1) NOT NULL DEFAULT 0',
        "ALTER TABLE transactions MODIFY type ENUM('deposit','withdrawal','loan_disbursement','loan_repayment','interest','bonus','ajo_contribution','ajo_payout') NOT NULL",
        'ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL',
        'ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE users ADD COLUMN referral_code VARCHAR(12) NULL',
        'ALTER TABLE users ADD UNIQUE INDEX uq_referral_code (referral_code)',
        'ALTER TABLE users ADD COLUMN referred_by INT UNSIGNED NULL',
        'ALTER TABLE kyc ADD COLUMN bvn_check VARCHAR(20) NULL',
        'ALTER TABLE kyc ADD COLUMN bvn_check_details VARCHAR(255) NULL',
        'ALTER TABLE kyc ADD COLUMN officer_id INT UNSIGNED NULL',
        'ALTER TABLE kyc ADD COLUMN checkin_at DATETIME NULL',
        'ALTER TABLE kyc ADD COLUMN checkin_lat DECIMAL(10,7) NULL',
        'ALTER TABLE kyc ADD COLUMN checkin_lng DECIMAL(10,7) NULL',
        'ALTER TABLE kyc ADD COLUMN checkin_distance_m INT UNSIGNED NULL',
        'ALTER TABLE kyc ADD COLUMN visit_photo VARCHAR(80) NULL',
        'ALTER TABLE loans ADD COLUMN first_approver_id INT UNSIGNED NULL',
        'ALTER TABLE loans ADD COLUMN collector_id INT UNSIGNED NULL',
        'ALTER TABLE loans ADD COLUMN agreement_accepted_at DATETIME NULL',
        'ALTER TABLE loans ADD COLUMN agreement_ip VARCHAR(45) NULL',
    ];
    foreach ($migrations as $sql) {
        try {
            db()->exec($sql);
        } catch (PDOException $e) {
            if (!in_array($e->errorInfo[1] ?? 0, [1060, 1061], true)) {   // column / index already exists
                $errors[] = $sql . ' → ' . $e->getMessage();
                error_log('[lucapetloan] migration failed: ' . end($errors));
            }
        }
    }
    // The first admin of an older install becomes the super admin.
    if (!q_val('SELECT id FROM users WHERE role = "super_admin" LIMIT 1') && ($first = q_val('SELECT MIN(id) FROM users WHERE role = "admin"'))) {
        q('UPDATE users SET role = "super_admin" WHERE id = ?', [$first]);
    }
    // Savings that were already in a plan start earning from today.
    q('UPDATE savings_plans SET interest_accrued_on = CURDATE() WHERE interest_accrued_on IS NULL AND balance > 0');
    return $errors;
}

/** Remove a stored document (database reference or a legacy file in uploads/). */
function delete_upload(?string $ref): void
{
    if (!$ref) {
        return;
    }
    if (str_starts_with($ref, 'doc:')) {
        q('DELETE FROM documents WHERE id = ?', [(int)substr($ref, 4)]);
    } elseif (is_file(UPLOAD_DIR . '/' . basename($ref))) {
        @unlink(UPLOAD_DIR . '/' . basename($ref));
    }
}

/** [mime, bytes] for a stored document, or null. */
function load_upload(string $ref): ?array
{
    if (str_starts_with($ref, 'doc:')) {
        $row = q_one('SELECT mime, data FROM documents WHERE id = ?', [(int)substr($ref, 4)]);
        return $row ? [$row['mime'], $row['data']] : null;
    }
    $path = UPLOAD_DIR . '/' . basename($ref);
    if (!is_file($path)) {
        return null;
    }
    return [(new finfo(FILEINFO_MIME_TYPE))->file($path), file_get_contents($path)];
}

function is_pdf_ref(string $ref): bool
{
    if (str_starts_with($ref, 'doc:')) {
        return q_val('SELECT mime FROM documents WHERE id = ?', [(int)substr($ref, 4)]) === 'application/pdf';
    }
    return str_ends_with($ref, '.pdf');
}

/* ------------------------------------------------------------------ */
/*  Savings                                                            */
/* ------------------------------------------------------------------ */

function savings_total(int $userId): float
{
    return (float)q_val('SELECT COALESCE(SUM(balance),0) FROM savings_plans WHERE user_id = ? AND status <> "closed"', [$userId]);
}

/** Dates (Y-m-d => amount) on which a plan received approved deposits, for the last $days days. */
function plan_contribution_days(int $planId, int $days = 30): array
{
    $rows = q(
        'SELECT DATE(created_at) d, SUM(amount) amt FROM transactions
         WHERE savings_plan_id = ? AND type = "deposit" AND status = "approved" AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY DATE(created_at)',
        [$planId, $days]
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['d']] = (float)$r['amt'];
    }
    return $out;
}

/** Consecutive days (ending today or yesterday) with an approved deposit. */
function plan_streak(int $planId): int
{
    $days = plan_contribution_days($planId, 400);
    $d = new DateTimeImmutable('today');
    if (!isset($days[$d->format('Y-m-d')])) {
        $d = $d->modify('-1 day');
    }
    $streak = 0;
    while (isset($days[$d->format('Y-m-d')])) {
        $streak++;
        $d = $d->modify('-1 day');
    }
    return $streak;
}

/**
 * Credit a plan's savings interest (the yearly rate split into daily parts, compounded
 * daily) for every full day since it was last credited. Call it before any
 * change to the plan's balance so each day earns on the right amount.
 * Returns the interest credited.
 */
function accrue_plan_interest(int $planId): float
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $plan = q_one('SELECT * FROM savings_plans WHERE id = ? FOR UPDATE', [$planId]);
        $credited = 0.0;
        $today = new DateTimeImmutable('today');
        if ($plan && $plan['status'] !== 'closed') {
            $balance = (float)$plan['balance'];
            $from = $plan['interest_accrued_on'] ? new DateTimeImmutable($plan['interest_accrued_on']) : null;
            $days = $from ? (int)$from->diff($today)->format('%r%a') : 0;
            if ($balance <= 0 || !$from) {
                q('UPDATE savings_plans SET interest_accrued_on = CURDATE() WHERE id = ?', [$planId]);
            } elseif ($days > 0) {
                $credited = round($balance * ((1 + savings_daily_rate() / 100) ** $days - 1), 2);
                if ($credited >= 0.01) {   // tiny balances keep counting days until they earn a kobo
                    q('UPDATE savings_plans SET balance = balance + ?, interest_earned = interest_earned + ?, interest_accrued_on = CURDATE() WHERE id = ?',
                        [$credited, $credited, $planId]);
                    q('INSERT INTO transactions (user_id, type, amount, method, status, savings_plan_id, note, processed_at) VALUES (?, "interest", ?, "system", "approved", ?, ?, NOW())',
                        [$plan['user_id'], $credited, $planId, $days . ' day' . ($days === 1 ? '' : 's') . ' at ' . setting('savings_annual_rate') . '% a year']);
                } else {
                    $credited = 0.0;
                }
            }
        }
        if ($own) {
            $pdo->commit();
        }
        return $credited;
    } catch (Throwable $e) {
        if ($own) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** The yearly savings rate as a daily percentage, e.g. 12% a year → 0.0329% a day. */
function savings_daily_rate(): float
{
    return setting('savings_annual_rate') / 365;
}

/** Bring all of a customer's plans up to date with their savings interest. */
function accrue_user_interest(int $userId): void
{
    foreach (q('SELECT id FROM savings_plans WHERE user_id = ? AND status <> "closed"', [$userId])->fetchAll() as $p) {
        accrue_plan_interest((int)$p['id']);
    }
}

/* ------------------------------------------------------------------ */
/*  Loans & interest                                                   */
/* ------------------------------------------------------------------ */

function tenure_label(int $months): string
{
    return ($months * DAYS_PER_MONTH) . ' days';
}

/** e.g. "30 or 60 days" */
function tenure_choices(): string
{
    $d = array_map(fn($m) => $m * DAYS_PER_MONTH, loan_tenures());
    $last = array_pop($d);
    return ($d ? implode(', ', $d) . ' or ' : '') . $last . ' days';
}

/**
 * Live picture of a loan. Interest is simple interest at the product's monthly
 * rate, accrued daily from disbursement and capped at the loan term. After the
 * due date a daily late fee applies to the unpaid balance. Paying off early only
 * costs the interest accrued so far.
 */
function loan_snapshot(array $loan, ?string $asOf = null): array
{
    $P = (float)$loan['amount'];
    $rate = (float)$loan['interest_rate'] / 100;        // per month
    $months = (int)$loan['tenure_months'];
    $termDays = $months * DAYS_PER_MONTH;
    $daily = $P * $rate / DAYS_PER_MONTH;
    $totalInterest = round($P * $rate * $months, 2);
    $totalRepayable = $P + $totalInterest;
    $paid = (float)$loan['amount_paid'];

    $s = [
        'principal'        => $P,
        'monthly_rate'     => (float)$loan['interest_rate'],
        'daily_interest'   => round($daily, 2),
        'term_days'        => $termDays,
        'total_interest'   => $totalInterest,
        'total_repayable'  => $totalRepayable,
        'installment'      => round($totalRepayable / max(1, $months), 2),
        'paid'             => $paid,
        'days_elapsed'     => 0,
        'days_left'        => $termDays,
        'accrued_interest' => 0.0,
        'late_days'        => 0,
        'penalty'          => 0.0,
        'penalty_raw'      => 0.0,
        'penalty_waived'   => 0.0,
        'penalty_stopped'  => !empty($loan['penalty_stopped_on']),
        'payoff'           => $P,
        'balance_at_maturity' => $totalRepayable,
        'progress'         => 0,
        'is_overdue'       => false,
        'display_status'   => $loan['status'],
    ];

    if (empty($loan['disbursed_at']) || !in_array($loan['status'], ['active', 'repaid', 'defaulted'], true)) {
        return $s;
    }

    $start = new DateTimeImmutable(substr($loan['disbursed_at'], 0, 10));
    $end = new DateTimeImmutable($asOf ?? 'today');
    if ($loan['status'] === 'repaid' && !empty($loan['closed_at'])) {
        $end = new DateTimeImmutable(substr($loan['closed_at'], 0, 10));
    }
    $days = max(0, (int)$start->diff($end)->format('%r%a'));
    $due = new DateTimeImmutable($loan['due_date']);
    $lateDays = max(0, (int)$due->diff($end)->format('%r%a'));
    // Staff can stop the penalty clock; days after that add nothing.
    $penaltyEnd = !empty($loan['penalty_stopped_on']) ? min($end, new DateTimeImmutable($loan['penalty_stopped_on'])) : $end;
    $penaltyDays = max(0, (int)$due->diff($penaltyEnd)->format('%r%a'));

    $accrued = round($daily * min($days, $termDays), 2);
    $unpaid = max(0, $totalRepayable - $paid);
    $rawPenalty = round($unpaid * ((float)$loan['late_fee_rate'] / 100) * $penaltyDays, 2);
    $waived = min($rawPenalty, (float)($loan['penalty_waived'] ?? 0));
    $penalty = round($rawPenalty - $waived, 2);
    $s['penalty_raw'] = $rawPenalty;
    $s['penalty_waived'] = $waived;

    $s['days_elapsed'] = $days;
    $s['days_left'] = max(0, $termDays - $days);
    $s['accrued_interest'] = $accrued;
    $s['late_days'] = $lateDays;
    $s['penalty'] = $penalty;
    $s['payoff'] = max(0, round($P + $accrued + $penalty - $paid, 2));
    $s['balance_at_maturity'] = max(0, round($totalRepayable + $penalty - $paid, 2));
    $s['progress'] = $termDays ? min(100, (int)round($days / $termDays * 100)) : 0;
    $s['is_overdue'] = $loan['status'] === 'active' && $lateDays > 0;
    if ($s['is_overdue']) {
        $s['display_status'] = 'overdue';
    }
    if ($loan['status'] === 'repaid') {
        $s['payoff'] = 0;
        $s['balance_at_maturity'] = 0;
    }
    return $s;
}

/** Monthly installment plan with paid / partial / overdue flags. */
function loan_schedule(array $loan): array
{
    $snap = loan_snapshot($loan);
    $months = (int)$loan['tenure_months'];
    $start = !empty($loan['disbursed_at']) ? new DateTimeImmutable(substr($loan['disbursed_at'], 0, 10)) : new DateTimeImmutable('today');
    $remaining = (float)$loan['amount_paid'];
    $today = new DateTimeImmutable('today');
    $rows = [];
    $cum = 0.0;
    for ($i = 1; $i <= $months; $i++) {
        $amt = $i === $months ? round($snap['total_repayable'] - $cum, 2) : $snap['installment'];
        $cum += $amt;
        $due = $start->modify('+' . ($i * DAYS_PER_MONTH) . ' days');
        $covered = min($amt, max(0, $remaining));
        $remaining -= $covered;
        if ($covered >= $amt - 0.009) {
            $state = 'paid';
        } elseif ($covered > 0) {
            $state = 'partial';
        } elseif (!empty($loan['disbursed_at']) && $due < $today) {
            $state = 'overdue';
        } else {
            $state = 'upcoming';
        }
        if ($loan['status'] === 'repaid') {
            $state = 'paid';
        }
        $rows[] = ['no' => $i, 'due' => $due->format('Y-m-d'), 'amount' => $amt, 'covered' => $covered, 'state' => $state];
    }
    return $rows;
}

/** Daily owed-balance series for the interest chart. */
function loan_series(array $loan): array
{
    if (empty($loan['disbursed_at'])) {
        return [];
    }
    $payments = q(
        'SELECT DATE(processed_at) d, SUM(amount) amt FROM transactions
         WHERE loan_id = ? AND type = "loan_repayment" AND status = "approved" GROUP BY DATE(processed_at)',
        [$loan['id']]
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $snap = loan_snapshot($loan);
    $P = $snap['principal'];
    $daily = $P * ($snap['monthly_rate'] / 100) / DAYS_PER_MONTH;
    $start = new DateTimeImmutable(substr($loan['disbursed_at'], 0, 10));
    $span = max($snap['term_days'], $snap['days_elapsed']);
    $step = max(1, (int)ceil($span / 120));   // keep the chart to ~120 points
    $out = [];
    $paid = 0.0;
    for ($d = 0; $d <= $span; $d++) {
        $date = $start->modify("+$d days")->format('Y-m-d');
        $paid += (float)($payments[$date] ?? 0);
        if ($d % $step !== 0 && $d !== $span && $d !== $snap['days_elapsed']) {
            continue;
        }
        $interest = $daily * min($d, $snap['term_days']);
        $out[] = [
            'date'      => $date,
            'interest'  => round($interest, 2),
            'owed'      => $d <= $snap['days_elapsed'] ? round(max(0, $P + $interest - $paid), 2) : null,
            'projected' => round($P + $interest, 2),
        ];
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/*  Credit levels                                                      */
/* ------------------------------------------------------------------ */

/** Straight-line distance in metres between two GPS points. */
function distance_m(float $lat1, float $lng1, float $lat2, float $lng2): int
{
    $r = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return (int)round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
}

/** How a collections contact went. */
const COLLECTION_OUTCOMES = [
    'promised'   => 'Promised to pay',
    'no_answer'  => 'No answer',
    'unreachable'=> 'Phone switched off / unreachable',
    'paid'       => 'Says they have paid',
    'hardship'   => 'Hardship (illness, lost income)',
    'dispute'    => 'Disputes the amount',
    'visit'      => 'Visited in person',
    'sms'        => 'Reminder SMS',
    'other'      => 'Other',
];

/** Levels and the on-time repayments needed to reach each one. */
const CREDIT_LEVELS = [['Starter', 0], ['Bronze', 1], ['Silver', 2], ['Gold', 4], ['Platinum', 6]];

/**
 * Where a customer stands: level from on-time repayments (each late
 * repayment drops a level, any default blocks borrowing), and a limit of the
 * level's base amount plus their savings, up to the base again.
 */
function credit_profile(array $u): array
{
    $closed = q('SELECT status, due_date, closed_at FROM loans WHERE user_id = ? AND status IN ("repaid","defaulted") ORDER BY closed_at', [$u['id']])->fetchAll();
    $onTime = $late = $defaults = 0;
    $lastOnTime = null;
    foreach ($closed as $l) {
        if ($l['status'] === 'defaulted') {
            $defaults++;
        } elseif ($l['due_date'] && substr((string)$l['closed_at'], 0, 10) <= $l['due_date']) {
            $onTime++;
            $lastOnTime = true;
        } else {
            $late++;
            $lastOnTime = false;
        }
    }
    $level = 0;
    foreach (CREDIT_LEVELS as $i => [, $need]) {
        if ($onTime >= $need) {
            $level = $i;
        }
    }
    $level = max(0, $level - $late);
    [$name] = CREDIT_LEVELS[$level];
    $base = (float)setting('limit_' . strtolower($name));
    $savings = savings_total((int)$u['id']);
    $boost = min($savings, $base);
    $limit = $u['credit_limit_override'] !== null ? (float)$u['credit_limit_override'] : $base + $boost;
    $next = CREDIT_LEVELS[$level + 1] ?? null;
    return [
        'level'        => $level,
        'name'         => $name,
        'base'         => $base,
        'savings_boost'=> $boost,
        'limit'        => $defaults || $u['kyc_status'] !== 'verified' ? 0.0 : $limit,
        'override'     => $u['credit_limit_override'] !== null,
        'on_time'      => $onTime,
        'late'         => $late,
        'defaults'     => $defaults,
        'blocked'      => $defaults > 0,
        'trusted'      => $onTime > 0 && $lastOnTime === true && !$defaults,   // may get instant approval
        'next_name'    => $next[0] ?? null,
        'needed'       => $next ? max(1, $next[1] - $onTime + $late) : 0,
    ];
}

/**
 * Start a loan's clock: mark it active, set the due date and record the
 * pay-out. Used for manual pay-outs and when a Flutterwave transfer lands.
 */
function activate_loan(int $loanId, string $date, ?int $staffId, string $ref, string $method, ?int $txId = null): void
{
    $loan = q_one('SELECT * FROM loans WHERE id = ?', [$loanId]);
    $when = $date . ' ' . date('H:i:s');
    $due = date('Y-m-d', strtotime($date . ' +' . ((int)$loan['tenure_months'] * DAYS_PER_MONTH) . ' days'));
    q('UPDATE loans SET status = "active", disbursed_at = ?, due_date = ? WHERE id = ?', [$when, $due, $loanId]);
    if ($txId) {
        q('UPDATE transactions SET status = "approved", processed_at = NOW() WHERE id = ?', [$txId]);
    } else {
        q('INSERT INTO transactions (user_id, type, amount, method, reference, status, loan_id, note, processed_by, processed_at, created_at) VALUES (?, "loan_disbursement", ?, ?, ?, "approved", ?, ?, ?, NOW(), ?)',
            [$loan['user_id'], $loan['amount'], $method, $ref, $loanId, 'Paid to customer bank account', $staffId, $when]);
    }
    notify((int)$loan['user_id'], 'Loan paid out 💸', money0($loan['amount']) . ' has been sent to your bank account. Repay ' . money0((float)$loan['amount'] * (1 + (float)$loan['interest_rate'] / 100 * (int)$loan['tenure_months'])) . ' by ' . fdate($due) . '.', 'loan.php?id=' . $loanId, true);
}

function has_open_loan(int $userId): ?array
{
    return q_one('SELECT * FROM loans WHERE user_id = ? AND status IN ("pending","approved","active") ORDER BY id DESC LIMIT 1', [$userId]);
}

/**
 * Apply a confirmed repayment to a loan (caller holds the transaction).
 * Closes the loan once the payoff amount is covered.
 */
function apply_loan_payment(int $loanId, float $amount): array
{
    $loan = q_one('SELECT * FROM loans WHERE id = ? FOR UPDATE', [$loanId]);
    $loan['amount_paid'] = (float)$loan['amount_paid'] + $amount;
    q('UPDATE loans SET amount_paid = ? WHERE id = ?', [$loan['amount_paid'], $loanId]);
    $snap = loan_snapshot($loan);
    if ($snap['payoff'] <= 0.009) {
        q('UPDATE loans SET status = "repaid", closed_at = NOW() WHERE id = ?', [$loanId]);
        $loan['status'] = 'repaid';
        notify((int)$loan['user_id'], 'Loan fully repaid 🎉', 'Loan #' . $loanId . ' is closed. Thank you for paying on time.', 'loan.php?id=' . $loanId, true);
    }
    return $loan;
}

/* ------------------------------------------------------------------ */
/*  Admin: approve / reject pending money movements                    */
/* ------------------------------------------------------------------ */

function process_transaction(int $txId, bool $approve, ?int $adminId, string $note = ''): string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $tx = q_one('SELECT * FROM transactions WHERE id = ? FOR UPDATE', [$txId]);
        if (!$tx || $tx['status'] !== 'pending') {
            throw new UserError('That transaction has already been processed.');
        }
        $status = $approve ? 'approved' : 'rejected';
        $amount = (float)$tx['amount'];
        $uid = (int)$tx['user_id'];

        switch ($tx['type']) {
            case 'deposit':
                if ($approve) {
                    accrue_plan_interest((int)$tx['savings_plan_id']);
                    q('UPDATE savings_plans SET balance = balance + ? WHERE id = ?', [$amount, $tx['savings_plan_id']]);
                    $plan = q_one('SELECT * FROM savings_plans WHERE id = ?', [$tx['savings_plan_id']]);
                    if ($plan && (float)$plan['target_amount'] > 0 && (float)$plan['balance'] >= (float)$plan['target_amount'] && $plan['status'] === 'active') {
                        q('UPDATE savings_plans SET status = "completed" WHERE id = ?', [$plan['id']]);
                        notify($uid, 'Savings target reached 🎯', 'Your plan "' . $plan['name'] . '" hit its target.', 'savings.php?plan=' . $plan['id']);
                    }
                }
                notify($uid, $approve ? 'Deposit confirmed' : 'Deposit not confirmed',
                    money($amount) . ($approve ? ' was added to your savings.' : ' could not be verified. ' . $note), 'savings.php');
                break;

            case 'withdrawal':
                // Funds were held when the request was made; return them on rejection.
                if (!$approve) {
                    accrue_plan_interest((int)$tx['savings_plan_id']);
                    q('UPDATE savings_plans SET balance = balance + ? WHERE id = ?', [$amount, $tx['savings_plan_id']]);
                }
                notify($uid, $approve ? 'Withdrawal paid' : 'Withdrawal declined',
                    money($amount) . ($approve ? ' has been sent to your bank account.' : ' was returned to your savings. ' . $note), 'savings.php', true);
                break;

            case 'loan_repayment':
                if ($approve) {
                    q('UPDATE transactions SET status = "approved", processed_at = NOW(), processed_by = ? WHERE id = ?', [$adminId, $txId]);
                    apply_loan_payment((int)$tx['loan_id'], $amount);
                }
                notify($uid, $approve ? 'Repayment received' : 'Repayment not confirmed',
                    money($amount) . ($approve ? ' was applied to loan #' . $tx['loan_id'] . '.' : ' could not be verified. ' . $note), 'loan.php?id=' . $tx['loan_id'], true);
                break;
        }

        q('UPDATE transactions SET status = ?, admin_note = ?, processed_at = NOW(), processed_by = ? WHERE id = ?', [$status, $note, $adminId, $txId]);
        if ($approve && $tx['type'] === 'deposit') {
            check_referral_reward($uid);   // after the status change, so this deposit counts
        }
        $pdo->commit();
        return ucfirst(label($tx['type'])) . ' #' . $txId . ' ' . $status . '.';
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ------------------------------------------------------------------ */
/*  Password reset & email                                             */
/* ------------------------------------------------------------------ */

/** Issue a one-time reset token; only its hash is stored. */
function create_reset_token(int $userId, int $minutes): string
{
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
        [$userId, hash('sha256', $token), $minutes]);
    return $token;
}

/** The unused, unexpired reset row for a token, with its user. */
function find_reset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    return q_one('SELECT r.id reset_id, u.* FROM password_resets r JOIN users u ON u.id = r.user_id
                  WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()', [hash('sha256', $token)]);
}

/** Absolute link, for emails and links shared outside the app. */
function absolute_url(string $path): string
{
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (is_https() ? 'https://' : 'http://') . $host . url($path);
}

function mail_enabled(): bool
{
    return resend_key() !== '';
}

/** Send an email through Resend. Returns false if it could not be sent. */
function send_mail(string $to, string $subject, string $html): bool
{
    if (!mail_enabled()) {
        return false;
    }
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Authorization: Bearer " . resend_key() . "\r\nContent-Type: application/json\r\n",
        'content'       => json_encode(['from' => mail_from(), 'to' => [$to], 'subject' => $subject, 'html' => $html]),
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents('https://api.resend.com/emails', false, $ctx);
    $ok = $res !== false && preg_match('#^HTTP/\S+ 2\d\d#', $http_response_header[0] ?? '');
    if (!$ok) {
        error_log('[lucapetloan] email to ' . $to . ' failed: ' . ($http_response_header[0] ?? 'no response') . ' ' . substr((string)$res, 0, 300));
    }
    return (bool)$ok;
}

/* ------------------------------------------------------------------ */
/*  Reference data                                                     */
/* ------------------------------------------------------------------ */

function payment_methods(): array
{
    return ['bank_transfer' => 'Bank transfer', 'card' => 'Card', 'ussd' => 'USSD', 'agent_cash' => 'Cash to field agent'];
}

function nigerian_states(): array
{
    return ['Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 'Cross River', 'Delta', 'Ebonyi',
        'Edo', 'Ekiti', 'Enugu', 'FCT - Abuja', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara',
        'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara'];
}
