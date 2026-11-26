<?php
declare(strict_types=1);

/*
 * Crypto savings through NOWPayments "custody" (customer accounts).
 * Every customer gets a NOWPayments customer account: its id is their wallet ID.
 * They fund it by sending coins to a deposit address made for them; NOWPayments
 * holds the coins and reports each customer's balance per coin.
 *
 * NOWPayments addresses are per deposit and expire, so customers make a new one
 * whenever the old one has expired. Balances always come from NOWPayments itself;
 * webhooks (IPN) only tell us to look again.
 */

function np_mode(): string
{
    return setting('np_mode') === 'live' ? 'live' : 'sandbox';
}

function np_base(): string
{
    return rtrim((string)(env('NP_BASE_URL') ?: (np_mode() === 'live' ? NP_LIVE_URL : NP_SANDBOX_URL)), '/');
}

/** Keys present: the API key works deposits and balances; account sign-in is needed to open customer accounts. */
function np_configured(): bool
{
    return int_key('np_api_key') !== '' && int_key('np_email') !== '' && int_key('np_password') !== '';
}

function crypto_on(): bool
{
    return np_configured() && setting('crypto_savings');
}

/**
 * Call the API. $auth: 'key' (x-api-key), 'jwt' (Bearer token from the account sign-in) or 'both'.
 * Returns ['ok' => bool, 'http' => int, 'body' => array].
 */
function np_request(string $method, string $path, ?array $payload = null, string $auth = 'key'): array
{
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($auth === 'key' || $auth === 'both') {
        $headers[] = 'x-api-key: ' . int_key('np_api_key');
    }
    if ($auth === 'jwt' || $auth === 'both') {
        $headers[] = 'Authorization: Bearer ' . np_jwt();
    }
    $ch = curl_init(np_base() . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    // Some endpoints (address check, payout confirmation) answer with a bare "OK" instead of JSON data.
    $ok = $http >= 200 && $http < 300 && (is_array($data) || is_string($data) || strtoupper(trim((string)$raw)) === 'OK');
    if (!$ok) {
        error_log('[lucapetloan] nowpayments ' . $method . ' ' . $path . ' failed: HTTP ' . $http . ' ' . $err . ' ' . substr((string)$raw, 0, 300));
        $msg = is_array($data) ? ($data['message'] ?? $data['msg'] ?? $data['error'] ?? '') : '';
        $GLOBALS['np_last_error'] = strtok($path, '?') . ' → ' . ($http ? 'HTTP ' . $http : 'no response' . ($err !== '' ? ' (' . $err . ')' : '')) . (is_string($msg) && $msg !== '' ? ': ' . $msg : '');
    }
    return ['ok' => $ok, 'http' => $http, 'body' => is_array($data) ? $data : []];
}

/** What NOWPayments said on the last failed call (shown to super admins only). */
function np_last_error(): string
{
    return (string)($GLOBALS['np_last_error'] ?? '');
}

/** This server's public IP, which NOWPayments must whitelist when IP whitelisting is on (cached a day). */
function server_public_ip(): string
{
    $r = np_cached('server_ip', 86400, function (): array {
        $ch = curl_init('https://api.ipify.org');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6]);
        $ip = trim((string)curl_exec($ch));
        curl_close($ch);
        return filter_var($ip, FILTER_VALIDATE_IP) ? ['ip' => $ip] : [];
    });
    return (string)($r['ip'] ?? '');
}

function np_message(array $res, string $fallback): string
{
    $m = $res['body']['message'] ?? $res['body']['msg'] ?? '';
    return is_string($m) && $m !== '' ? $m : $fallback;
}

/** Sign-in token (valid 5 minutes); reused within one request. */
function np_jwt(): string
{
    static $token = null, $at = 0;
    if ($token !== null && time() - $at < 240) {
        return $token;
    }
    $res = np_request('POST', '/auth', ['email' => int_key('np_email'), 'password' => int_key('np_password')], 'none');
    if (!$res['ok'] || empty($res['body']['token'])) {
        throw new UserError('Crypto savings are unavailable right now. Please try again shortly.');
    }
    $at = time();
    return $token = (string)$res['body']['token'];
}

/** The customer's NOWPayments wallet ID, opening their account the first time. */
function np_wallet_id(int $userId): string
{
    $id = (string)q_val('SELECT np_sub_id FROM users WHERE id = ?', [$userId]);
    if ($id !== '') {
        return $id;
    }
    if (!np_configured()) {
        throw new UserError('Crypto savings are not switched on yet.');
    }
    // The name must be unique per account and at most 30 characters (never an email).
    $res = np_request('POST', '/sub-partner/balance', ['name' => 'lpl-' . $userId . '-' . substr(md5((string)env('APP_KEY', DB_NAME)), 0, 6)], 'jwt');
    $id = (string)($res['body']['result']['id'] ?? '');
    if (!$res['ok'] || $id === '') {
        throw new UserError('We could not open your crypto wallet: ' . np_message($res, 'try again shortly') . '.');
    }
    q('UPDATE users SET np_sub_id = ? WHERE id = ? AND np_sub_id IS NULL', [$id, $userId]);
    return (string)q_val('SELECT np_sub_id FROM users WHERE id = ?', [$userId]);
}

/** Open a wallet at sign-up without ever blocking registration. */
function np_open_wallet_quietly(int $userId): void
{
    if (!crypto_on()) {
        return;
    }
    try {
        np_wallet_id($userId);
    } catch (Throwable $e) {
        error_log('[lucapetloan] wallet for user ' . $userId . ' not opened yet: ' . $e->getMessage());
    }
}

/** A cached API answer stored in settings (for the coin list and prices). */
function np_cached(string $key, int $seconds, callable $fetch): array
{
    $row = q_one('SELECT v, updated_at FROM settings WHERE k = ?', ['cache_' . $key]);
    if ($row && strtotime($row['updated_at']) > time() - $seconds) {
        return json_decode($row['v'], true) ?: [];
    }
    $fresh = $fetch();
    if ($fresh) {
        q('REPLACE INTO settings (k, v) VALUES (?, ?)', ['cache_' . $key, json_encode($fresh)]);
        return $fresh;
    }
    return $row ? (json_decode($row['v'], true) ?: []) : [];
}

/**
 * Coins customers can save in: everything switched on in the NOWPayments
 * dashboard (Settings → Coins), with names and networks. code => [name, network, needs_memo, logo].
 */
function np_coins(): array
{
    $coins = np_cached('np_coins_' . np_mode(), 6 * 3600, function (): array {
        $mine = np_request('GET', '/merchant/coins');
        $enabled = array_map('strtolower', (array)($mine['body']['selectedCurrencies'] ?? $mine['body']['currencies'] ?? []));
        if (!$enabled) {
            return [];
        }
        $full = np_request('GET', '/full-currencies');
        $info = [];
        foreach ((array)($full['body']['currencies'] ?? []) as $c) {
            if (is_array($c) && isset($c['code'])) {
                $info[strtolower($c['code'])] = $c;
            }
        }
        $out = [];
        foreach ($enabled as $code) {
            $c = $info[$code] ?? [];
            $out[$code] = [
                (string)($c['name'] ?? strtoupper($code)),
                strtoupper((string)($c['network'] ?? '')),
                !empty($c['extra_id_exists']),
                (string)($c['logo_url'] ?? ''),
            ];
        }
        return $out;
    });
    // Popular coins first, then A–Z.
    $top = ['usdttrc20', 'usdtbsc', 'usdterc20', 'usdt', 'usdc', 'usdcbsc', 'btc', 'eth', 'bnbbsc', 'sol', 'trx', 'ltc', 'doge', 'xrp'];
    uksort($coins, function ($a, $b) use ($top) {
        $ia = array_search($a, $top, true);
        $ib = array_search($b, $top, true);
        return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib) ?: strcmp($a, $b);
    });
    return $coins;
}

function coin_label(string $code): string
{
    $c = np_coins()[$code] ?? null;
    $ticker = strtoupper($code);
    if (!$c) {
        return $ticker;
    }
    return $c[0] . ($c[1] !== '' && stripos($c[0], $c[1]) === false ? ' (' . $c[1] . ')' : '') . ' · ' . $ticker;
}

/** USD price of one coin (cached 10 minutes); null when unknown. */
function coin_usd(string $code): ?float
{
    // The live market price first, so totals, coin cards and the chart always agree.
    $id = coin_market_id($code);
    if ($id !== '' && ($m = market_data([$id])) && isset($m[$id]) && $m[$id]['price'] > 0) {
        return (float)$m[$id]['price'];
    }
    if (in_array($code, ['usdttrc20', 'usdtbsc', 'usdterc20', 'usdt', 'usdc', 'usdcbsc', 'usdcsol', 'usdtsol', 'usdtmatic', 'usdcmatic', 'dai', 'busd', 'tusd'], true)) {
        return 1.0;
    }
    $r = np_cached('np_px_' . $code, 600, function () use ($code): array {
        $res = np_request('GET', '/estimate?amount=1&currency_from=' . rawurlencode($code) . '&currency_to=usd');
        return $res['ok'] && isset($res['body']['estimated_amount']) ? ['usd' => (float)$res['body']['estimated_amount']] : [];
    });
    return isset($r['usd']) ? (float)$r['usd'] : null;
}

/** Naira for a USD amount, at NOWPayments' rate (cached 1 hour). */
function usd_to_ngn(float $usd): ?float
{
    $r = np_cached('np_usd_ngn', 3600, function (): array {
        // How much USDT ₦100,000 buys gives naira per dollar.
        $res = np_request('GET', '/estimate?amount=100000&currency_from=ngn&currency_to=usdttrc20');
        $usdt = (float)($res['body']['estimated_amount'] ?? 0);
        return $res['ok'] && $usdt > 0 ? ['rate' => 100000 / $usdt] : [];
    });
    return isset($r['rate']) ? $usd * (float)$r['rate'] : null;
}

/** Smallest deposit NOWPayments accepts for a coin, in that coin. */
function np_min_amount(string $code): float
{
    $r = np_cached('np_min_' . $code, 3600, function () use ($code): array {
        $res = np_request('GET', '/min-amount?currency_from=' . rawurlencode($code) . '&currency_to=' . rawurlencode($code) . '&fiat_equivalent=usd');
        return $res['ok'] && isset($res['body']['min_amount']) ? ['min' => (float)$res['body']['min_amount']] : [];
    });
    return (float)($r['min'] ?? 0);
}

/** Balances: coin => [amount, pending]. Read straight from NOWPayments. */
function crypto_balances(int $userId): array
{
    $id = (string)q_val('SELECT np_sub_id FROM users WHERE id = ?', [$userId]);
    if ($id === '' || !np_configured()) {
        return [];
    }
    $res = np_request('GET', '/sub-partner/balance/' . rawurlencode($id));
    $out = [];
    foreach ((array)($res['body']['result']['balances'] ?? []) as $code => $b) {
        $amount = (float)($b['amount'] ?? 0);
        $pending = (float)($b['pendingAmount'] ?? 0);
        if ($amount > 0 || $pending > 0) {
            $out[strtolower((string)$code)] = [$amount, $pending];
        }
    }
    if (!$res['ok']) {
        throw new UserError('We could not load your crypto balance right now. Please try again shortly.');
    }
    return $out;
}

/** What the customer can still withdraw of a coin: balance minus requests not yet paid. */
function crypto_available(int $userId, string $coin, array $balances): float
{
    $held = (float)q_val('SELECT COALESCE(SUM(amount), 0) FROM crypto_withdrawals WHERE user_id = ? AND coin = ? AND status = "pending"', [$userId, $coin]);
    return max(0, ($balances[$coin][0] ?? 0) - $held);
}

/**
 * The customer's address for a coin. Each customer keeps ONE address per coin:
 * NOWPayments credits later deposits to an address that has been paid into
 * before ("repeated deposits"). Until the first deposit arrives the address is
 * only reserved for NOWPayments' payment window, so an unused one that has run
 * out is quietly swapped for a new one; after that it never changes.
 */
function crypto_wallet_address(int $userId, string $coin): array
{
    if (!isset(np_coins()[$coin])) {
        throw new UserError('Choose a coin from the list.');
    }
    $w = q_one('SELECT * FROM crypto_wallets WHERE user_id = ? AND coin = ?', [$userId, $coin]);
    if ($w && ($w['funded'] || $w['expires_at'] === null || strtotime($w['expires_at']) > time() + 600)) {
        return $w;
    }
    if ($w) {
        // Unused and about to lapse: did a deposit arrive after all?
        crypto_refresh_deposit((string)$w['payment_id']);
        $w = q_one('SELECT * FROM crypto_wallets WHERE id = ?', [$w['id']]);
        if ($w['funded']) {
            return $w;
        }
    }
    $min = np_min_amount($coin);
    $base = $min > 0 ? $min : 1;
    $sub = np_wallet_id($userId);
    // The minimum for customer deposits can be higher than /min-amount reports. Step the amount up until
    // NOWPayments accepts it, and remember the step that worked for this coin so next time it's one call.
    $saved = (float)(q_val('SELECT v FROM settings WHERE k = ?', ['cache_np_mult_' . $coin]) ?: 1);
    $steps = array_values(array_unique(array_merge([$saved], array_filter([1, 1.5, 2.5, 4, 7, 12], fn($m) => $m > $saved)), SORT_REGULAR));
    $res = ['ok' => false, 'body' => []];
    $p = [];
    foreach ($steps as $mult) {
        $amount = round($base * $mult, 8);
        $res = np_request('POST', '/sub-partner/payment', [
            'currency'            => $coin,
            'amount'              => $amount,
            'sub_partner_id'      => $sub,
            'is_fixed_rate'       => false,
            'is_fee_paid_by_user' => false,
            'ipn_callback_url'    => absolute_url('np_webhook.php'),
        ]);
        $p = $res['body']['result'] ?? [];
        if ($res['ok'] && !empty($p['pay_address']) && !empty($p['payment_id'])) {
            if ($mult != $saved) {
                q('REPLACE INTO settings (k, v) VALUES (?, ?)', ['cache_np_mult_' . $coin, (string)$mult]);
            }
            break;
        }
        if (!preg_match('/less than minimal|minimal amount|too small/i', np_message($res, ''))) {
            break;   // a different problem: stepping up won't help
        }
    }
    if (!$res['ok'] || empty($p['pay_address']) || empty($p['payment_id'])) {
        throw new UserError('We couldn\'t create your ' . strtoupper($coin) . ' address right now. Please try again shortly, or choose another coin.');
    }
    $expires = $p['expiration_estimate_date'] ?? $p['valid_until'] ?? null;
    $expires = is_string($expires) && strtotime($expires) ? date('Y-m-d H:i:s', strtotime($expires)) : date('Y-m-d H:i:s', time() + 7 * 86400);
    $vals = [(string)$p['payment_id'], (string)$p['pay_address'], ($p['payin_extra_id'] ?? null) ?: null, (float)($p['pay_amount'] ?? $min), $expires];
    if ($w) {
        q('UPDATE crypto_wallets SET payment_id = ?, address = ?, extra_id = ?, min_amount = ?, expires_at = ? WHERE id = ?', array_merge($vals, [$w['id']]));
        q('UPDATE crypto_deposits SET status = "expired" WHERE user_id = ? AND coin = ? AND status = "waiting"', [$userId, $coin]);
    } else {
        q('INSERT INTO crypto_wallets (payment_id, address, extra_id, min_amount, expires_at, user_id, coin) VALUES (?,?,?,?,?,?,?)', array_merge($vals, [$userId, $coin]));
    }
    q('INSERT INTO crypto_deposits (user_id, payment_id, coin, pay_address, extra_id, pay_amount, expires_at) VALUES (?,?,?,?,?,?,?)',
        [$userId, $vals[0], $coin, $vals[1], $vals[2], $vals[3], $expires]);
    return q_one('SELECT * FROM crypto_wallets WHERE user_id = ? AND coin = ?', [$userId, $coin]);
}

/** A deposit NOWPayments told us about that we haven't seen (a repeat deposit to a saved address): record it. */
function crypto_adopt_deposit(array $p): ?array
{
    $id = (string)($p['payment_id'] ?? '');
    if ($id === '') {
        return null;
    }
    if ($row = q_one('SELECT * FROM crypto_deposits WHERE payment_id = ?', [$id])) {
        return $row;
    }
    $parent = (string)($p['parent_payment_id'] ?? '');
    $address = (string)($p['pay_address'] ?? '');
    $w = null;
    if ($parent !== '') {
        $w = q_one('SELECT w.* FROM crypto_wallets w WHERE w.payment_id = ?', [$parent])
            ?: q_one('SELECT w.* FROM crypto_deposits d JOIN crypto_wallets w ON w.user_id = d.user_id AND w.coin = d.coin WHERE d.payment_id = ?', [$parent]);
    }
    if (!$w && $address !== '') {
        $w = q_one('SELECT * FROM crypto_wallets WHERE address = ?', [$address]);
    }
    if (!$w) {
        return null;
    }
    q('INSERT IGNORE INTO crypto_deposits (user_id, payment_id, coin, pay_address, extra_id, pay_amount) VALUES (?,?,?,?,?,?)',
        [$w['user_id'], $id, $w['coin'], $w['address'], $w['extra_id'], (float)($p['pay_amount'] ?? 0)]);
    return q_one('SELECT * FROM crypto_deposits WHERE payment_id = ?', [$id]);
}

/** Pull the customer's recent deposits from NOWPayments, so nothing is missed if a webhook was. */
function crypto_sync(int $userId): void
{
    $sub = (string)q_val('SELECT np_sub_id FROM users WHERE id = ?', [$userId]);
    if ($sub === '') {
        return;
    }
    $res = np_request('GET', '/sub-partner/payments?sub_partner_id=' . rawurlencode($sub) . '&limit=50&page=0&orderBy=desc&sortBy=created_at', null, 'jwt');
    $list = $res['body']['result'] ?? $res['body']['data'] ?? $res['body']['payments'] ?? [];
    if (isset($list['data']) && is_array($list['data'])) {
        $list = $list['data'];
    }
    foreach ((array)$list as $p) {
        if (is_array($p) && !empty($p['payment_id']) && crypto_adopt_deposit($p)) {
            crypto_refresh_deposit((string)$p['payment_id']);
        }
    }
    foreach (q('SELECT payment_id FROM crypto_deposits WHERE user_id = ? AND status IN ("waiting","confirming","confirmed","sending") AND created_at > NOW() - INTERVAL 8 DAY', [$userId])->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        crypto_refresh_deposit((string)$pid);
    }
}

/** Logo for a coin from NOWPayments, or '' when unknown. */
function coin_logo(string $code): string
{
    $logo = (string)(np_coins()[$code][3] ?? '');
    if ($logo === '') {
        return '';
    }
    return str_starts_with($logo, 'http') ? $logo : 'https://nowpayments.io' . $logo;
}

/** Coin amounts without trailing zeros: 0.00012 rather than 0.0001200000. */
function coin_amount($n): string
{
    $s = rtrim(rtrim(number_format((float)$n, 8, '.', ','), '0'), '.');
    return $s === '' || $s === '-0' ? '0' : $s;
}

/** Check an IPN body's x-nowpayments-sig: HMAC-SHA512 of the body with keys sorted, using the IPN secret. */
function np_ipn_valid(string $raw, string $sig): bool
{
    $secret = int_key('np_ipn_secret');
    $data = json_decode($raw, true);
    if ($secret === '' || $sig === '' || !is_array($data)) {
        return false;
    }
    $sort = function (array $a) use (&$sort): array {
        ksort($a);
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = $sort($v);
            }
        }
        return $a;
    };
    $expected = hash_hmac('sha512', json_encode($sort($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $secret);
    return hash_equals($expected, strtolower($sig));
}

/** Bring a deposit up to date from NOWPayments' own record, and tell the customer once it lands. */
function crypto_refresh_deposit(string $paymentId): ?array
{
    $row = q_one('SELECT * FROM crypto_deposits WHERE payment_id = ?', [$paymentId]);
    if (!$row) {
        return null;
    }
    $res = np_request('GET', '/payment/' . rawurlencode($paymentId));
    if (!$res['ok'] || empty($res['body']['payment_status'])) {
        return $row;
    }
    $p = $res['body'];
    $status = (string)$p['payment_status'];
    $paid = (float)($p['actually_paid'] ?? 0);
    q('UPDATE crypto_deposits SET status = ?, actually_paid = ? WHERE id = ?', [$status, $paid, $row['id']]);
    if (in_array($status, ['finished', 'partially_paid'], true) && !$row['credited']) {
        $n = q('UPDATE crypto_deposits SET credited = 1 WHERE id = ? AND credited = 0', [$row['id']])->rowCount();
        q('UPDATE crypto_wallets SET funded = 1, expires_at = NULL WHERE user_id = ? AND coin = ? AND (payment_id = ? OR address = ?)', [$row['user_id'], $row['coin'], $row['payment_id'], $row['pay_address']]);
        if ($n) {
            notify((int)$row['user_id'], 'Crypto received', coin_amount($paid) . ' ' . strtoupper($row['coin']) . ' has arrived in your crypto savings.', 'crypto.php', true);
        }
    } elseif ($status === 'confirming' && $row['status'] === 'waiting') {
        notify((int)$row['user_id'], 'Crypto on its way', 'We can see your ' . strtoupper($row['coin']) . ' deposit on the blockchain. It shows in your balance once it is confirmed.', 'crypto.php', true);
    }
    return q_one('SELECT * FROM crypto_deposits WHERE id = ?', [$row['id']]);
}

/**
 * Staff approve a withdrawal: the coins move from the customer's NOWPayments
 * account to the company's main balance, ready to be sent from the NOWPayments
 * dashboard (Payouts). Staff then record the blockchain transaction hash.
 */
function crypto_take_for_payout(int $wid, ?int $staffId): void
{
    $w = q_one('SELECT w.*, u.np_sub_id FROM crypto_withdrawals w JOIN users u ON u.id = w.user_id WHERE w.id = ? AND w.status = "pending"', [$wid]);
    if (!$w) {
        throw new UserError('That request was already handled.');
    }
    $bal = crypto_balances((int)$w['user_id']);
    if (($bal[$w['coin']][0] ?? 0) + 1e-12 < (float)$w['amount']) {
        throw new UserError('The customer only has ' . coin_amount($bal[$w['coin']][0] ?? 0) . ' ' . strtoupper($w['coin']) . ' now.');
    }
    $res = np_request('POST', '/sub-partner/write-off', ['currency' => $w['coin'], 'amount' => (float)$w['amount'], 'sub_partner_id' => $w['np_sub_id']], 'jwt');
    if (!$res['ok'] || empty($res['body']['result']['id'])) {
        throw new UserError('NOWPayments refused to move the coins: ' . np_message($res, 'HTTP ' . $res['http']) . '.');
    }
    q('UPDATE crypto_withdrawals SET status = "processing", writeoff_id = ?, staff_id = ? WHERE id = ?', [(string)$res['body']['result']['id'], $staffId, $wid]);
}

/* ---------- Automatic crypto payouts (like Flutterwave's automatic withdrawals) ---------- */

/** Automatic sending is possible: switched on, NOWPayments connected, and the 2FA secret saved. */
function crypto_auto_on(): bool
{
    return setting('crypto_auto_payouts') && np_configured() && np_2fa_secret() !== '';
}

function np_2fa_secret(): string
{
    return strtoupper(preg_replace('/[\s-]+/', '', int_key('np_2fa_secret')));
}

/** The current 6-digit code from the NOWPayments 2FA secret (same codes as the authenticator app). */
function np_2fa_code(int $offset = 0): string
{
    require_once __DIR__ . '/totp.php';
    return totp_code(np_2fa_secret(), intdiv(time(), 30) + $offset);
}

/** Why a withdrawal must wait for staff instead of going out automatically, or null when it can go now. */
function crypto_auto_hold(array $w): ?string
{
    if (!crypto_auto_on()) {
        return 'automatic sending is off';
    }
    if (!q_val('SELECT 1 FROM crypto_withdrawals WHERE user_id = ? AND address = ? AND status = "sent" AND id <> ? LIMIT 1', [$w['user_id'], $w['address'], $w['id']])) {
        return 'first withdrawal to this address';
    }
    $px = coin_usd($w['coin']);
    if ($px === null) {
        return 'no price for ' . strtoupper($w['coin']);
    }
    $today = 0.0;
    foreach (q('SELECT coin, amount FROM crypto_withdrawals WHERE user_id = ? AND auto = 1 AND status IN ("processing","sent") AND created_at >= CURDATE()', [$w['user_id']])->fetchAll() as $o) {
        $today += (float)$o['amount'] * (coin_usd($o['coin']) ?? 0);
    }
    $limit = (float)setting('crypto_auto_daily_usd');
    if ($today + (float)$w['amount'] * $px > $limit) {
        return 'over the $' . number_format($limit) . ' daily automatic limit';
    }
    return null;
}

/**
 * Send a pending withdrawal automatically: move the coins to the main balance, create the payout
 * (amount after the fee) and confirm it with the 2FA code. Returns '' when sent, or why it is left for staff.
 */
function crypto_auto_payout(int $wid, bool $approvedByStaff = false): string
{
    $w = q_one('SELECT * FROM crypto_withdrawals WHERE id = ? AND status = "pending"', [$wid]);
    if (!$w) {
        return 'already handled';
    }
    if (!crypto_auto_on()) {
        return 'automatic sending is off';
    }
    if (!$approvedByStaff && ($why = crypto_auto_hold($w))) {
        return $why;
    }
    $check = np_request('POST', '/payout/validate-address', ['address' => $w['address'], 'currency' => $w['coin'], 'extra_id' => $w['extra_id'] ?: null]);
    if (!$check['ok']) {
        return 'address not accepted by NOWPayments (' . np_message($check, 'HTTP ' . $check['http']) . ')';
    }
    try {
        crypto_take_for_payout($wid, null);
    } catch (UserError $e) {
        return $e->getMessage();
    }
    $net = round((float)$w['amount'] - (float)$w['fee'], 8);
    $item = ['address' => $w['address'], 'currency' => $w['coin'], 'amount' => $net, 'unique_external_id' => 'lpl-cw-' . $wid, 'ipn_callback_url' => absolute_url('np_webhook.php')];
    if ($w['extra_id']) {
        $item['extra_id'] = $w['extra_id'];
    }
    $res = np_request('POST', '/payout', ['ipn_callback_url' => absolute_url('np_webhook.php'), 'withdrawals' => [$item]], 'both');
    $batch = (string)($res['body']['id'] ?? '');
    $pid = (string)($res['body']['withdrawals'][0]['id'] ?? '');
    if (!$res['ok'] || $batch === '') {
        crypto_return_coins($w, 'Automatic payout could not be created: ' . np_message($res, 'HTTP ' . $res['http']));
        return 'NOWPayments did not create the payout (' . np_message($res, 'HTTP ' . $res['http']) . ')';
    }
    q('UPDATE crypto_withdrawals SET auto = 1, payout_batch_id = ?, payout_id = ?, payout_status = "creating" WHERE id = ?', [$batch, $pid ?: null, $wid]);
    // Confirm with the 2FA code; try the neighbouring 30-second window too in case the clocks differ slightly.
    $ok = false;
    foreach ([0, -1, 1] as $off) {
        $v = np_request('POST', '/payout/' . rawurlencode($batch) . '/verify', ['verification_code' => np_2fa_code($off)], 'both');
        if ($v['ok']) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        q('UPDATE crypto_withdrawals SET note = ? WHERE id = ?', ['Payout created but the 2FA code was refused: confirm it in NOWPayments → Payouts within an hour', $wid]);
        notify_staff('payments', 'Crypto payout needs confirming', 'Withdrawal #' . $wid . ' was created in NOWPayments but the 2FA code was refused. Confirm it in NOWPayments → Payouts within an hour, or check the 2FA secret under Integrations.', 'admin/crypto.php');
        return 'payout created but the 2FA code was refused';
    }
    q('UPDATE crypto_withdrawals SET payout_status = "verified", note = NULL WHERE id = ?', [$wid]);
    return '';
}

/** Coins already moved to the main balance go back to the customer's wallet (payout failed or was rejected). */
function crypto_return_coins(array $w, string $reason): void
{
    $sub = (string)q_val('SELECT np_sub_id FROM users WHERE id = ?', [$w['user_id']]);
    $res = np_request('POST', '/sub-partner/deposit', ['currency' => $w['coin'], 'amount' => (float)$w['amount'], 'sub_partner_id' => $sub], 'jwt');
    $back = $res['ok'];
    q('UPDATE crypto_withdrawals SET status = "rejected", note = ? WHERE id = ?', [mb_substr($reason . ($back ? '' : ' · coins NOT returned automatically, return them in NOWPayments'), 0, 255), $w['id']]);
    if ($back) {
        notify((int)$w['user_id'], 'Crypto withdrawal not sent', 'Your withdrawal of ' . coin_amount($w['amount']) . ' ' . strtoupper($w['coin']) . ' could not be sent, so the coins are back in your crypto savings. Please check the address or call ' . SUPPORT_PHONE . '.', 'crypto.php', true);
    } else {
        notify_staff('payments', 'Return crypto to a customer', 'Withdrawal #' . $w['id'] . ' failed after the coins were moved. Return ' . coin_amount($w['amount']) . ' ' . strtoupper($w['coin']) . ' to the customer in NOWPayments.', 'admin/crypto.php');
    }
}

/** Bring an automatic payout up to date from NOWPayments: finished → sent (with the hash), rejected → coins returned. */
function crypto_refresh_payout(int $wid): void
{
    $w = q_one('SELECT * FROM crypto_withdrawals WHERE id = ? AND status = "processing" AND auto = 1', [$wid]);
    if (!$w || !$w['payout_id']) {
        return;
    }
    $res = np_request('GET', '/payout/' . rawurlencode($w['payout_id']));
    $p = $res['body']['withdrawals'][0] ?? $res['body'];
    $status = strtolower((string)($p['status'] ?? ''));
    if (!$res['ok'] || $status === '') {
        return;
    }
    q('UPDATE crypto_withdrawals SET payout_status = ? WHERE id = ?', [$status, $wid]);
    $net = coin_amount((float)$w['amount'] - (float)$w['fee']) . ' ' . strtoupper($w['coin']);
    if ($status === 'finished') {
        $n = q('UPDATE crypto_withdrawals SET status = "sent", tx_hash = ? WHERE id = ? AND status = "processing"', [mb_substr((string)($p['hash'] ?? ''), 0, 255) ?: null, $wid])->rowCount();
        if ($n) {
            notify((int)$w['user_id'], 'Crypto sent', $net . ' has been sent to your wallet' . ((float)$w['fee'] > 0 ? ' (' . coin_amount($w['amount']) . ' less the ' . coin_amount($w['fee']) . ' withdrawal fee).' : '.'), 'crypto.php', true);
            audit('crypto.withdraw_auto_sent', 'Crypto withdrawal #' . $wid, $net . ' · tx ' . ($p['hash'] ?? ''), null);
        }
    } elseif ($status === 'rejected') {
        crypto_return_coins($w, 'Payout rejected by NOWPayments' . (!empty($p['error']) ? ': ' . $p['error'] : ''));
    }
}

/** Check every automatic payout still on its way (daily job and admin page). */
function crypto_refresh_payouts(): int
{
    $n = 0;
    foreach (q('SELECT id FROM crypto_withdrawals WHERE status = "processing" AND auto = 1 AND created_at > NOW() - INTERVAL 7 DAY')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        crypto_refresh_payout((int)$id);
        $n++;
    }
    return $n;
}

/* ---------- Live market (public prices from CoinGecko; no key, cached on the server) ---------- */

/** CoinGecko id for a coin code; several networks of one coin share an id. '' when unknown. */
function coin_market_id(string $code): string
{
    $code = strtolower($code);
    $map = [
        'btc' => 'bitcoin', 'eth' => 'ethereum', 'sol' => 'solana', 'trx' => 'tron', 'ltc' => 'litecoin', 'doge' => 'dogecoin',
        'xrp' => 'ripple', 'ada' => 'cardano', 'ton' => 'the-open-network', 'dot' => 'polkadot', 'avax' => 'avalanche-2',
        'bch' => 'bitcoin-cash', 'xlm' => 'stellar', 'link' => 'chainlink', 'shib' => 'shiba-inu', 'dai' => 'dai',
        'matic' => 'matic-network', 'pol' => 'polygon-ecosystem-token', 'near' => 'near', 'atom' => 'cosmos', 'xmr' => 'monero',
        'etc' => 'ethereum-classic', 'not' => 'notcoin', 'pepe' => 'pepe', 'arb' => 'arbitrum', 'op' => 'optimism',
    ];
    if (str_starts_with($code, 'usdt')) return 'tether';
    if (str_starts_with($code, 'usdc')) return 'usd-coin';
    if (str_starts_with($code, 'bnb')) return 'binancecoin';
    if (isset($map[$code])) return $map[$code];
    foreach (['trc20', 'erc20', 'bsc', 'sol', 'matic', 'arb', 'op', 'base', 'ton', 'mainnet'] as $net) {   // e.g. "shibbsc" → shib
        if (str_ends_with($code, $net) && isset($map[substr($code, 0, -strlen($net))])) {
            return $map[substr($code, 0, -strlen($net))];
        }
    }
    return '';
}

/**
 * Price, 24h change and 7-day hourly prices for coins, keyed by market id.
 * One request for all coins, cached 2 minutes so every customer shares it.
 */
function market_data(array $ids): array
{
    $ids = array_values(array_unique(array_filter($ids)));
    sort($ids);
    if (!$ids) {
        return [];
    }
    return np_cached('mkt_' . substr(md5(implode(',', $ids)), 0, 12), 120, function () use ($ids): array {
        $ch = curl_init(MARKET_BASE_URL . '/coins/markets?vs_currency=usd&sparkline=true&price_change_percentage=24h&ids=' . rawurlencode(implode(',', $ids)));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => APP_NAME . ' market widget']);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $rows = is_string($raw) ? json_decode($raw, true) : null;
        if ($http !== 200 || !is_array($rows)) {
            error_log('[lucapetloan] market data failed: HTTP ' . $http . ' ' . substr((string)$raw, 0, 200));
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || empty($r['id'])) continue;
            $spark = array_map('floatval', (array)($r['sparkline_in_7d']['price'] ?? []));
            // Keep every 2nd hourly point: 84 points draw the same week at half the size.
            $spark = array_values(array_filter($spark, fn($k) => $k % 2 === 0, ARRAY_FILTER_USE_KEY));
            $out[$r['id']] = [
                'name'   => (string)($r['name'] ?? $r['id']),
                'symbol' => strtoupper((string)($r['symbol'] ?? '')),
                'price'  => (float)($r['current_price'] ?? 0),
                'change' => round((float)($r['price_change_percentage_24h'] ?? 0), 2),
                'spark'  => $spark,
            ];
        }
        return $out;
    });
}

/**
 * What the live market panel needs for one customer: their coins first, then popular ones,
 * plus their crypto value over the last 7 days (today's holdings at each hour's price).
 */
function crypto_market_view(array $balances): array
{
    $popular = ['btc', 'eth', 'usdttrc20', 'sol', 'bnbbsc', 'trx'];
    $codes = array_values(array_unique(array_merge(array_keys($balances), $popular)));
    $ids = [];
    foreach ($codes as $c) {
        if (($id = coin_market_id($c)) !== '') $ids[$c] = $id;
    }
    $data = market_data(array_values($ids));
    $coins = [];
    $seen = [];
    foreach ($ids as $code => $id) {
        if (!isset($data[$id]) || (isset($seen[$id]) && !isset($balances[$code]))) continue;   // one row per coin unless they hold several networks
        $seen[$id] = true;
        $d = $data[$id];
        $coins[] = ['code' => strtoupper($code), 'name' => $d['name'], 'symbol' => $d['symbol'], 'price' => $d['price'], 'change' => $d['change'],
            'held' => isset($balances[$code]) ? (float)$balances[$code][0] : 0.0, 'spark' => $d['spark']];
    }
    // Portfolio over time: current holdings × each point's price. Coins without market data count at today's price.
    $points = [];
    $n = 0;
    foreach ($balances as $code => [$amt]) {
        $id = coin_market_id($code);
        if ($id !== '' && isset($data[$id])) $n = max($n, count($data[$id]['spark']));
    }
    for ($i = 0; $i < $n; $i++) {
        $v = 0.0;
        foreach ($balances as $code => [$amt]) {
            $id = coin_market_id($code);
            $s = $id !== '' && isset($data[$id]) ? $data[$id]['spark'] : [];
            $px = $s ? ($s[(int)round($i * (count($s) - 1) / max(1, $n - 1))] ?? end($s)) : (coin_usd($code) ?? 0);
            $v += $amt * $px;
        }
        $points[] = round($v, 2);
    }
    return ['coins' => $coins, 'portfolio' => $points, 'hours' => 168, 'updated' => date('c'), 'ok' => (bool)$data];
}
