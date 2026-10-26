<?php
// Streams a verification document to its owner or to staff. Uploads are never served directly.
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();

$userId = (int)($_GET['u'] ?? 0);
$doc = $_GET['doc'] ?? '';
if (!in_array($doc, ['id_doc', 'photo', 'utility_bill'], true) || ($u['role'] !== 'admin' && $userId !== (int)$u['id'])) {
    http_response_code(403);
    exit('Not allowed.');
}
$ref = q_val("SELECT $doc FROM kyc WHERE user_id = ?", [$userId]);
$file = $ref ? load_upload($ref) : null;
if (!$file) {
    http_response_code(404);
    exit('File not found.');
}
[$mime, $bytes] = $file;
$ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? 'bin';
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($bytes));
header('Content-Disposition: inline; filename="' . $doc . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $bytes;
