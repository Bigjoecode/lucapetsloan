<?php
declare(strict_types=1);

/*
 * BVN and NIN checks through Prembly (IdentityPass). Prembly returns the
 * name and date of birth on record; we compare them with the customer.
 */

function prembly_on(): bool
{
    return int_key('prembly_secret_key') !== '';
}

/** POST a number to a Prembly verification endpoint. Returns [http, body]. */
function prembly_post(string $path, string $number): array
{
    $headers = ['x-api-key: ' . int_key('prembly_secret_key'), 'Content-Type: application/json', 'Accept: application/json'];
    if (int_key('prembly_app_id') !== '') {
        $headers[] = 'app-id: ' . int_key('prembly_app_id');
    }
    $ch = curl_init(rtrim(setting('prembly_base_url'), '/') . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['number' => $number]),   // Prembly rejects numbers sent as integers
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => $headers,
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    if ($http < 200 || $http >= 300) {
        error_log('[lucapetloan] prembly ' . $path . ' failed: HTTP ' . $http . ' ' . substr((string)$raw, 0, 300));
    }
    return [$http, $body];
}

/** Dates come back as 01-Jan-1990, 01-01-1990 or 1990-01-01. */
function normalise_date(string $d): ?string
{
    foreach (['d-M-Y', 'd-m-Y', 'Y-m-d', 'd/m/Y'] as $f) {
        $dt = DateTime::createFromFormat('!' . $f, trim($d));
        if ($dt && $dt->format($f) === trim($d)) {
            return $dt->format('Y-m-d');
        }
    }
    return null;
}

/**
 * Check a BVN or NIN. Returns ['result' => match|partial|no_match|not_found|error, 'details' => text].
 * Only "not_found" should stop a customer; staff weigh up the others at review.
 */
function identity_check(string $kind, string $number, string $fullName, string $dob): array
{
    $label = strtoupper($kind);
    [$http, $b] = prembly_post(setting($kind === 'bvn' ? 'prembly_bvn_path' : 'prembly_nin_path'), $number);
    $code = (string)($b['response_code'] ?? '');
    if ($code === '01' || $http === 404) {
        return ['result' => 'not_found', 'details' => $label . ' not found'];
    }
    $d = $b['data'] ?? null;
    if ($http < 200 || $http >= 300 || $code !== '00' || !is_array($d)) {
        return ['result' => 'error', 'details' => $label . ' check unavailable (' . ($b['detail'] ?? $b['message'] ?? 'HTTP ' . $http) . ')'];
    }
    // BVN uses firstName/lastName, NIN uses firstname/surname.
    $first = strtolower((string)($d['firstName'] ?? $d['firstname'] ?? $d['first_name'] ?? ''));
    $last = strtolower((string)($d['lastName'] ?? $d['surname'] ?? $d['lastname'] ?? $d['last_name'] ?? ''));
    $middle = strtolower((string)($d['middleName'] ?? $d['middlename'] ?? $d['middle_name'] ?? ''));
    $mine = preg_split('/[^a-z]+/', strtolower($fullName), -1, PREG_SPLIT_NO_EMPTY);
    $firstOk = $first !== '' && in_array($first, $mine, true);
    $lastOk = $last !== '' && in_array($last, $mine, true);
    $theirDob = normalise_date((string)($d['dateOfBirth'] ?? $d['birthdate'] ?? $d['date_of_birth'] ?? $d['dob'] ?? ''));
    $dobOk = $theirDob === null ? null : $theirDob === $dob;

    $result = $firstOk && $lastOk ? 'match' : ($firstOk || $lastOk ? 'partial' : 'no_match');
    if ($result === 'match' && $dobOk === false) {
        $result = 'partial';
    }
    $details = $label . ' name on record: ' . ucwords(trim("$first $middle $last")) . ($dobOk === null ? '' : ' · date of birth ' . ($dobOk ? 'matches' : 'differs (' . $theirDob . ')'));
    return ['result' => $result, 'details' => mb_substr($details, 0, 255)];
}
