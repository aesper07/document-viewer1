<?php
require __DIR__ . '/config.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT d.*, 
                              COALESCE(u.full_name, 'Admin') as owner_name,
                              COALESCE(f.name, 'Umum') as folder_name
                       FROM documents d 
                       LEFT JOIN users u ON u.id = d.owner_id
                       LEFT JOIN folders f ON f.id = d.folder_id
                       WHERE d.id = ? AND d.is_active = 1 
                       LIMIT 1");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    exit('Dokumen tidak ditemukan atau telah dinonaktifkan.');
}

log_access($id, 'view_granted');

$origName = (string)($doc['original_name'] ?? '');
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$storageKey = (string)($doc['storage_key'] ?? '');
$storagePath = $storageKey !== '' ? __DIR__ . '/' . $storageKey : '';

if ($storageKey === '' || !file_exists($storagePath) || is_dir($storagePath)) {
    $storagePath = __DIR__ . '/uploads/' . basename($storageKey);
}

$hasRealFile = ($storageKey !== '') && file_exists($storagePath) && is_file($storagePath);
$isPdf = ($ext === 'pdf');
$isImage = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true);
$isText = in_array($ext, ['txt', 'md', 'json', 'csv', 'log'], true);
$textContent = ($isText && $hasRealFile) ? htmlspecialchars((string)file_get_contents($storagePath)) : '';
?><!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($origName) ?> — Arsipku</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .viewer-embed-container {
            width: min(900px, 100%);
            height: calc(100vh - 110px);
            background: #fff;
            box-shadow: 0 6px 30px rgba(29, 37, 64, 0.15);
            border-radius: 8px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: relative;
        }
        .viewer-embed-container iframe, 
        .viewer-embed-container embed, 
        .viewer-embed-container object {
            width: 100%;
            height: 100%;
            border: 0;
        }
        .image-preview-box {
            max-width: 100%;
            max-height: 80vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .image-preview-box img {
            max-width: 100%;
            max-height: 75vh;
            border-radius: 4px;
            object-fit: contain;
            user-select: none;
            -webkit-user-drag: none;
        }
        .text-preview-box {
            width: min(820px, 100%);
            min-height: 400px;
            background: #fff;
            padding: 30px 40px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
            color: #2b354f;
            white-space: pre-wrap;
            word-break: break-all;
            user-select: none;
        }
    </style>
</head>

<body class="viewer-page">
    <header class="viewer-bar">
        <a href="index.php">← Kembali</a>
        <div style="overflow: hidden; max-width: 60%;">
            <strong style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;">
                <?= e($origName) ?>
            </strong>
            <small>Folder: <?= e((string)($doc['folder_name'] ?? 'Umum')) ?> · Mode Hanya Lihat (Unduhan Dibatasi)</small>
        </div>

        <div style="margin-left: auto; display: flex; align-items: center; gap: 12px;">
            <span class="security-status">✓ Terproteksi</span>
        </div>
    </header>

    <main class="pdf-stage">
        <?php if ($hasRealFile && $isPdf): ?>
            <!-- Mode Tampilan PDF Asli Tersemat (Toolbar unduh dimatikan) -->
            <div class="viewer-embed-container">
                <iframe src="serve_file.php?id=<?= $doc['id'] ?>#toolbar=0&navpanes=0" title="<?= e($origName) ?>"></iframe>
            </div>

        <?php elseif ($hasRealFile && $isImage): ?>
            <!-- Mode Tampilan Gambar Asli -->
            <div class="image-preview-box">
                <img src="serve_file.php?id=<?= $doc['id'] ?>" alt="<?= e($origName) ?>" oncontextmenu="return false;">
            </div>

        <?php elseif ($hasRealFile && $isText): ?>
            <!-- Mode Tampilan Berkas Teks -->
            <div class="text-preview-box" oncopy="return false;"><?= $textContent ?></div>

        <?php else: ?>
            <!-- Mode Pratinjau Standar Sheet -->
            <div class="pdf-sheet">
                <div class="fake-logo"><?= strtoupper(substr($ext ?: 'A', 0, 1)) ?></div>
                <h1><?= e(pathinfo($origName, PATHINFO_FILENAME)) ?></h1>
                <p>Dokumen berformat <strong>.<?= strtoupper(e($ext)) ?></strong> tersedia dalam mode pratinjau terlindungi.</p>
                <div class="preview-line wide"></div>
                <div class="preview-line wide"></div>
                <div class="preview-line medium"></div>
                <div class="preview-rule"></div>
                <div class="preview-chart">
                    <i></i><i></i><i></i><i></i><i></i>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <script>
        // Perlindungan dokumen: cegah klik kanan & shortcut copy / print / save
        document.addEventListener('contextmenu', e => {
            e.preventDefault();
        });
        document.addEventListener('copy', e => {
            e.preventDefault();
            alert('Penyalinan isi dokumen ini dibatasi.');
        });
        document.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && ['s', 'p'].includes(e.key.toLowerCase())) {
                e.preventDefault();
                alert('Pencetakan atau pengunduhan dibatasi dalam mode pratinjau aman.');
            }
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c') {
                e.preventDefault();
            }
        });
    </script>
</body>

</html>
