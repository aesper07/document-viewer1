<?php
require __DIR__ . '/config.php';

$id = (int)($_GET['id'] ?? 0);
$download = isset($_GET['download']) && $_GET['download'] === '1';

$stmt = db()->prepare("SELECT * FROM documents WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    exit('Dokumen tidak ditemukan.');
}

$storagePath = __DIR__ . '/' . $doc['storage_key'];

// Jika file fisik tidak ditemukan di path relatif, coba cek langsung di uploads/
if (!file_exists($storagePath)) {
    $storagePath = __DIR__ . '/uploads/' . basename($doc['storage_key']);
}

if (!file_exists($storagePath) || is_dir($storagePath)) {
    http_response_code(404);
    exit('File fisik dokumen belum tersedia di server.');
}

if ($download) {
    if (!$doc['allow_download'] && !is_admin()) {
        http_response_code(403);
        log_access($id, 'download_blocked');
        exit('Pengunduhan dokumen ini dibatasi oleh pemilik.');
    }
    log_access($id, 'download_granted');
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($doc['original_name']) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($storagePath));
    readfile($storagePath);
    exit;
}

// Mode Stream / Tampilkan Inline (Viewer)
log_access($id, 'view_granted');
$mime = $doc['mime_type'] ?: mime_content_type($storagePath) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode($doc['original_name']) . '"');
header('Content-Length: ' . filesize($storagePath));
header('Cache-Control: private, max-age=3600');
readfile($storagePath);
exit;
