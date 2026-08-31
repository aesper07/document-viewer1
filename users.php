<?php
require __DIR__ . '/config.php';
require_admin();

$me = user();
$pdo = db();
$error = '';
$success = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $name = trim($_POST['full_name'] ?? '');
        $nip = trim($_POST['nip'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'viewer';
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        $valid_roles = ['admin', 'editor', 'viewer'];
        if (!in_array($role, $valid_roles, true)) {
            $role = 'viewer';
        }

        if (mb_strlen($name) < 3) {
            $error = 'Nama lengkap minimal 3 karakter.';
        } elseif (strlen($nip) < 4) {
            $error = 'NIP minimal 4 karakter.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Format email tidak valid.';
        } elseif (strlen($password) < 8) {
            $error = 'Kata sandi minimal 8 karakter.';
        } else {
            $checkNip = $pdo->prepare('SELECT id FROM users WHERE nip = ? LIMIT 1');
            $checkNip->execute([$nip]);
            if ($checkNip->fetch()) {
                $error = 'NIP tersebut sudah terdaftar untuk pegawai lain.';
            } else {
                $userEmail = $email ?: ($nip . '@arsipku.id');
                $name_parts = explode(' ', $name);
                $initials = '';
                if (count($name_parts) >= 2) {
                    $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts) - 1], 0, 1));
                } else {
                    $initials = strtoupper(substr($name, 0, 2));
                }
                $initials = preg_replace('/[^A-Z]/', '', $initials) ?: 'PG';

                $stmt = $pdo->prepare('INSERT INTO users (full_name, nip, email, password_hash, role, initials, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $name,
                    $nip,
                    $userEmail,
                    password_hash($password, PASSWORD_DEFAULT),
                    $role,
                    $initials,
                    $is_active
                ]);
                $success = "Akun pegawai untuk <strong>" . e($name) . "</strong> (NIP: " . e($nip) . " · " . e($role) . ") berhasil dibuat!";
            }
        }
    } elseif ($action === 'toggle_status') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id === (int)$me['id']) {
            $error = 'Anda tidak dapat menonaktifkan akun sendiri.';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET is_active = NOT is_active WHERE id=?');
            $stmt->execute([$user_id]);
            $success = 'Status akun pegawai berhasil diperbarui.';
        }
    } elseif ($action === 'change_role') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $new_role = $_POST['new_role'] ?? '';
        if ($user_id === (int)$me['id']) {
            $error = 'Anda tidak dapat mengubah peran akun Anda sendiri.';
        } elseif (in_array($new_role, ['admin', 'editor', 'viewer'], true)) {
            $stmt = $pdo->prepare('UPDATE users SET role=? WHERE id=?');
            $stmt->execute([$new_role, $user_id]);
            $success = 'Peran akun berhasil diubah menjadi ' . e(ucfirst($new_role)) . '.';
        }
    } elseif ($action === 'reset_password') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $new_password = $_POST['new_password'] ?? '';
        if (strlen($new_password) < 8) {
            $error = 'Kata sandi baru minimal 8 karakter.';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
            $stmt->execute([password_hash($new_password, PASSWORD_DEFAULT), $user_id]);
            $success = 'Kata sandi akun berhasil diperbarui.';
        }
    } elseif ($action === 'delete_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id === (int)$me['id']) {
            $error = 'Anda tidak dapat menghapus akun sendiri.';
        } else {
            $doc_check = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE owner_id=?');
            $doc_check->execute([$user_id]);
            if ($doc_check->fetchColumn() > 0) {
                $error = 'Akun tidak dapat dihapus karena memiliki dokumen terdaftar. Silakan nonaktifkan akun saja.';
            } else {
                $stmt = $pdo->prepare('DELETE FROM users WHERE id=?');
                $stmt->execute([$user_id]);
                $success = 'Akun pegawai berhasil dihapus.';
            }
        }
    }
}

// Fetch users
$search = trim($_GET['q'] ?? '');
$sql = "SELECT id, full_name, nip, email, role, initials, is_active, last_login_at, created_at FROM users WHERE 1=1";
$args = [];

if ($search !== '') {
    $sql .= " AND (full_name LIKE ? OR nip LIKE ? OR email LIKE ? OR role LIKE ?)";
    $term = '%' . $search . '%';
    $args = [$term, $term, $term, $term];
}

$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$users_list = $stmt->fetchAll();

// Fetch folders for sidebar consistency
$folders = $pdo->query('SELECT f.name, f.color, COUNT(d.id) total FROM folders f LEFT JOIN documents d ON d.folder_id=f.id AND d.is_active=1 GROUP BY f.id ORDER BY f.name')->fetchAll();

// Fetch documents count
$doc_count_stmt = $pdo->query("SELECT COUNT(id) FROM documents WHERE is_active=1");
$total_documents = (int)$doc_count_stmt->fetchColumn();

// Stats
$total_users = count($users_list);
$active_users = count(array_filter($users_list, fn($u) => $u['is_active']));
$admin_count = count(array_filter($users_list, fn($u) => $u['role'] === 'admin'));
?><!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Kelola Pegawai (NIP) — Arsipku</title>
    <link rel="stylesheet" href="styles.css">
</head>

<body>
    <div class="app-shell php-app">
        <aside class="sidebar">
            <div class="brand"><span class="brand-mark">A</span><span>arsipku</span></div>
            <div class="workspace-label">WORKSPACE</div>
            <nav class="main-nav">
                <a class="nav-item" href="index.php">▦ Semua dokumen <span class="nav-count"><?= $total_documents ?></span></a>
                <a class="nav-item active" href="users.php">👥 Kelola Pegawai <span class="nav-count"><?= $total_users ?></span></a>
            </nav>
            <div class="workspace-label folders-label">FOLDER</div>
            <div class="folder-list">
                <?php foreach ($folders as $folder): ?>
                    <span>
                        <i class="folder-dot <?= e($folder['color']) ?>"></i>
                        <?= e($folder['name']) ?>
                        <b><?= $folder['total'] ?></b>
                    </span>
                <?php endforeach; ?>
            </div>
            <div class="profile">
                <div class="avatar"><?= e($me['initials']) ?></div>
                <div style="overflow: hidden; text-overflow: ellipsis;">
                    <strong style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px;"><?= e($me['full_name']) ?></strong>
                    <small><?= !empty($me['nip']) ? 'NIP: ' . e($me['nip']) : e(ucfirst($me['role'])) ?></small>
                </div>
                <a class="tiny-button" href="logout.php" title="Keluar" onclick="return confirm('Apakah Anda yakin ingin keluar?');">↪</a>
            </div>
        </aside>

        <main class="main-content">
            <header class="topbar">
                <div>
                    <p class="eyebrow">ADMINISTRASI KEPEGAWAIAN</p>
                    <h1>Kelola Akun Pegawai & NIP</h1>
                </div>
            </header>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>

            <section class="stats-grid" style="grid-template-columns: repeat(3, 1fr);">
                <div class="stat-card">
                    <div class="stat-icon indigo">👥</div>
                    <div>
                        <span>Total Pegawai</span>
                        <strong><?= $total_users ?></strong>
                        <small class="neutral">Terdaftar dalam sistem</small>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon mint">✓</div>
                    <div>
                        <span>Akun Aktif</span>
                        <strong><?= $active_users ?></strong>
                        <small class="positive">Bisa masuk dengan NIP</small>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon peach">★</div>
                    <div>
                        <span>Administrator</span>
                        <strong><?= $admin_count ?></strong>
                        <small class="neutral">Akses penuh sistem</small>
                    </div>
                </div>
            </section>

            <!-- Form Buat Akun Baru -->
            <section class="content-card user-create-card">
                <div class="card-heading">
                    <div>
                        <h2>➕ Daftarkan Akun Pegawai Baru</h2>
                        <p>Tambahkan pegawai baru ke database dengan NIP dan kata sandi</p>
                    </div>
                </div>
                <form method="post" class="user-create-form">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_user">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="full_name">Nama Lengkap</label>
                            <input id="full_name" type="text" name="full_name" placeholder="Contoh: Budi Santoso, S.Kom" required>
                        </div>
                        <div class="form-group">
                            <label for="nip">Nomor Induk Pegawai (NIP)</label>
                            <input id="nip" type="text" name="nip" placeholder="Contoh: 198801012015011005" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="email">Alamat Email (Opsional)</label>
                            <input id="email" type="email" name="email" placeholder="budi@instansi.go.id">
                        </div>
                        <div class="form-group">
                            <label for="password">Kata Sandi</label>
                            <input id="password" type="password" name="password" placeholder="Minimal 8 karakter" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="role">Peran Pegawai (Role)</label>
                            <select id="role" name="role" required>
                                <option value="viewer" selected>Viewer (Hanya lihat dokumen)</option>
                                <option value="editor">Editor (Unggah & kelola dokumen)</option>
                                <option value="admin">Admin (Akses penuh kepegawaian & dokumen)</option>
                            </select>
                        </div>
                        <div class="form-group" style="justify-content: flex-end; display: flex; align-items: flex-end; padding-bottom: 4px;">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_active" value="1" checked>
                                <span>Akun langsung aktif</span>
                            </label>
                        </div>
                    </div>

                    <div style="margin-top: 10px; display: flex; justify-content: flex-end;">
                        <button type="submit" class="upload-button btn-create-user">
                            <span>+</span> Daftarkan Akun Pegawai
                        </button>
                    </div>
                </form>
            </section>

            <!-- Daftar Pegawai -->
            <section class="toolbar" style="margin-top: 24px;">
                <form class="search-box" method="get">
                    <span>⌕</span>
                    <input name="q" value="<?= e($search) ?>" type="search" placeholder="Cari nama, NIP, email, atau peran...">
                </form>
            </section>

            <section class="content-card">
                <div class="card-heading">
                    <div>
                        <h2>Daftar Pegawai Terdaftar</h2>
                        <p><?= count($users_list) ?> pegawai terdaftar di sistem</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>Pegawai</th>
                                <th>NIP</th>
                                <th>Email</th>
                                <th>Peran</th>
                                <th>Status</th>
                                <th>Terakhir Masuk</th>
                                <th style="text-align: right; padding-right: 20px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$users_list): ?>
                                <tr>
                                    <td colspan="7" class="empty-state">Tidak ada pegawai yang sesuai dengan pencarian.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($users_list as $u): ?>
                                <tr>
                                    <td>
                                        <div class="user-cell">
                                            <div class="avatar-sm"><?= e($u['initials']) ?></div>
                                            <strong><?= e($u['full_name']) ?></strong>
                                            <?php if ((int)$u['id'] === (int)$me['id']): ?>
                                                <span class="badge-me">(Anda)</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <code><?= !empty($u['nip']) ? e($u['nip']) : '-' ?></code>
                                    </td>
                                    <td class="muted"><?= e($u['email']) ?></td>
                                    <td>
                                        <form method="post" class="inline-role-form">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="change_role">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <select name="new_role" onchange="this.form.submit()" class="role-select role-<?= e($u['role']) ?>" <?= (int)$u['id'] === (int)$me['id'] ? 'disabled' : '' ?>>
                                                <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                <option value="editor" <?= $u['role'] === 'editor' ? 'selected' : '' ?>>Editor</option>
                                                <option value="viewer" <?= $u['role'] === 'viewer' ? 'selected' : '' ?>>Viewer</option>
                                            </select>
                                        </form>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $u['is_active'] ? 'active' : 'inactive' ?>">
                                            <?= $u['is_active'] ? '● Aktif' : '○ Nonaktif' ?>
                                        </span>
                                    </td>
                                    <td class="muted">
                                        <?= $u['last_login_at'] ? e(date('d M Y H:i', strtotime($u['last_login_at']))) : 'Belum pernah' ?>
                                    </td>
                                    <td style="text-align: right; padding-right: 16px;">
                                        <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                                            <div class="action-buttons">
                                                <!-- Toggle Active -->
                                                <form method="post" style="display:inline;">
                                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <button type="submit" class="btn-sm <?= $u['is_active'] ? 'btn-warn' : 'btn-ok' ?>" title="<?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                        <?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>
                                                    </button>
                                                </form>

                                                <!-- Reset Password -->
                                                <form method="post" style="display:inline;" onsubmit="let p=prompt('Masukkan kata sandi baru untuk <?= e(addslashes($u['full_name'])) ?> (min. 8 karakter):'); if(!p) return false; if(p.length < 8){ alert('Kata sandi minimal 8 karakter!'); return false; }; this.new_password.value=p; return true;">
                                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="reset_password">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <input type="hidden" name="new_password" value="">
                                                    <button type="submit" class="btn-sm btn-warn" title="Reset Sandi">Reset Sandi</button>
                                                </form>

                                                <!-- Delete -->
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus akun pegawai ini?');">
                                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <button type="submit" class="btn-sm btn-danger" title="Hapus Akun">Hapus</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="muted" style="font-size: 11px;">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</body>

</html>
