<?php
declare(strict_types=1);

/* BVN checks through Dojah: does the BVN exist, and does it belong to this name? */

function dojah_on(): bool
{
    return DOJAH_APP_ID !== '' && DOJAH_SECRET_KEY !== '';
}

/**
 * Returns ['result' => match|partial|no_match|not_found|error, 'details' => text].
 * Only "not_found" should stop a customer; the others are for staff to weigh up.
 */
function bvn_check(string $bvn, string $fullName, string $dob): array
{
    $words = preg_split('/\s+/', trim($fullName));
    $query = http_build_query(['bvn' => $bvn, 'first_name' => $words[0] ?? '', 'last_name' => count($words) > 1 ? end($words) : '', 'dob' => $dob]);
    $ch = curl_init(DOJAH_BASE_URL . '/api/v1/kyc/bvn?' . $query);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['AppId: ' . DOJAH_APP_ID, 'Authorization: ' . DOJAH_SECRET_KEY, 'Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $res = is_string($raw) ? json_decode($raw, true) : null;
    $e = $res['entity'] ?? null;

    if ($http === 400 || $http === 404 || (is_array($e) && isset($e['bvn']['status']) && !$e['bvn']['status'])) {
        return ['result' => 'not_found', 'details' => 'BVN not found at the bank database'];
    }
    if ($http < 200 || $http >= 300 || !is_array($e)) {
        error_log('[lucapetloan] dojah bvn check failed: HTTP ' . $http . ' ' . substr((string)$raw, 0, 300));
        return ['result' => 'error', 'details' => 'Check unavailable (HTTP ' . $http . ')'];
    }
    $first = !empty($e['first_name']['status']);
    $last = !empty($e['last_name']['status']);
    $conf = fn($k) => isset($e[$k]['confidence_value']) ? (int)$e[$k]['confidence_value'] . '%' : '?';
    $details = 'First name ' . ($first ? 'matches' : 'differs') . ' (' . $conf('first_name') . '), last name ' . ($last ? 'matches' : 'differs') . ' (' . $conf('last_name') . ')';
    return ['result' => $first && $last ? 'match' : ($first || $last ? 'partial' : 'no_match'), 'details' => $details];
}
