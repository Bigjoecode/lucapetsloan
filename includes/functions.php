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
                $ok = (bool)q_val('SELECT 1 FROM users WHERE role = "admin" LIMIT 1');
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

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Admins only.');
    }
    return $u;
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
        'partial' => 'warn', 'upcoming' => 'muted',
    ];
    $cls = $map[$status] ?? 'muted';
    $text = $status === 'none' ? 'Not started' : label($status);
    return '<span class="badge badge-' . $cls . '">' . e($text) . '</span>';
}

/* ------------------------------------------------------------------ */
/*  Notifications                                                      */
/* ------------------------------------------------------------------ */

function notify(int $userId, string $title, string $body = '', string $link = ''): void
{
    q('INSERT INTO notifications (user_id, title, body, link) VALUES (?, ?, ?, ?)', [$userId, $title, $body, $link]);
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

/** Create any missing tables. */
function run_schema(): void
{
    foreach (require __DIR__ . '/schema.php' as $sql) {
        db()->exec($sql);
    }
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

/* ------------------------------------------------------------------ */
/*  Loans & interest                                                   */
/* ------------------------------------------------------------------ */

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

    $accrued = round($daily * min($days, $termDays), 2);
    $unpaid = max(0, $totalRepayable - $paid);
    $penalty = round($unpaid * ((float)$loan['late_fee_rate'] / 100) * $lateDays, 2);

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
        notify((int)$loan['user_id'], 'Loan fully repaid 🎉', 'Loan #' . $loanId . ' is closed. Thank you for paying on time.', 'loan.php?id=' . $loanId);
    }
    return $loan;
}

/* ------------------------------------------------------------------ */
/*  Admin: approve / reject pending money movements                    */
/* ------------------------------------------------------------------ */

function process_transaction(int $txId, bool $approve, int $adminId, string $note = ''): string
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
                    q('UPDATE savings_plans SET balance = balance + ? WHERE id = ?', [$amount, $tx['savings_plan_id']]);
                }
                notify($uid, $approve ? 'Withdrawal paid' : 'Withdrawal declined',
                    money($amount) . ($approve ? ' has been sent to your bank account.' : ' was returned to your savings. ' . $note), 'savings.php');
                break;

            case 'loan_repayment':
                if ($approve) {
                    q('UPDATE transactions SET status = "approved", processed_at = NOW(), processed_by = ? WHERE id = ?', [$adminId, $txId]);
                    apply_loan_payment((int)$tx['loan_id'], $amount);
                }
                notify($uid, $approve ? 'Repayment received' : 'Repayment not confirmed',
                    money($amount) . ($approve ? ' was applied to loan #' . $tx['loan_id'] . '.' : ' could not be verified. ' . $note), 'loan.php?id=' . $tx['loan_id']);
                break;
        }

        q('UPDATE transactions SET status = ?, admin_note = ?, processed_at = NOW(), processed_by = ? WHERE id = ?', [$status, $note, $adminId, $txId]);
        $pdo->commit();
        return ucfirst(label($tx['type'])) . ' #' . $txId . ' ' . $status . '.';
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
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
