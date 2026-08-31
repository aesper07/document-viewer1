<?php
declare(strict_types=1);
session_start();

const DB_HOST = '127.0.0.1';
const DB_NAME = 'arsipku';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        ensure_schema($pdo);
    }
    return $pdo;
}

// Auto-repair skema dan hash password demo jika masih placeholder
function ensure_schema(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        // 1. Pastikan kolom NIP ada
        $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'nip'");
        if (!$check->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN nip VARCHAR(30) NULL UNIQUE AFTER full_name");
        }

        // 2. Perbaiki / isi akun bawaan jika belum ada atau hash masih REPLACE_WITH_BCRYPT_HASH
        $defaultUsers = [
            [
                'name' => 'Raka Aditya',
                'nip' => '198501012010011001',
                'email' => 'admin@arsipku.id',
                'pass' => 'Arsipku2024!',
                'role' => 'admin',
                'initials' => 'RA'
            ],
            [
                'name' => 'Rani Kusuma',
                'nip' => '199002022015022002',
                'email' => 'rani@arsipku.id',
                'pass' => 'Viewer#248',
                'role' => 'editor',
                'initials' => 'RK'
            ],
            [
                'name' => 'Dimas Pratama',
                'nip' => '199503032020031003',
                'email' => 'dimas@arsipku.id',
                'pass' => 'Raka@Admin',
                'role' => 'viewer',
                'initials' => 'DP'
            ]
        ];

        foreach ($defaultUsers as $u) {
            $stmt = $pdo->prepare('SELECT id, password_hash, nip FROM users WHERE email = ? OR nip = ? LIMIT 1');
            $stmt->execute([$u['email'], $u['nip']]);
            $existing = $stmt->fetch();

            $validHash = password_hash($u['pass'], PASSWORD_DEFAULT);

            if (!$existing) {
                // Buat user jika belum ada
                $ins = $pdo->prepare('INSERT INTO users (full_name, nip, email, password_hash, role, initials, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
                $ins->execute([$u['name'], $u['nip'], $u['email'], $validHash, $u['role'], $u['initials']]);
            } else {
                // Perbaiki NIP dan password_hash jika masih placeholder REPLACE_WITH_BCRYPT_HASH
                if (str_contains((string)$existing['password_hash'], 'REPLACE_WITH_BCRYPT_HASH') || empty($existing['nip'])) {
                    $upd = $pdo->prepare('UPDATE users SET password_hash = ?, nip = ? WHERE id = ?');
                    $upd->execute([$validHash, $u['nip'], $existing['id']]);
                }
            }
        }
    } catch (\Throwable $e) {
        // Abaikan jika tabel users belum siap
    }
}

function e(string $value): string { 
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); 
}

function user(): ?array { 
    return $_SESSION['user'] ?? null; 
}

function is_admin(): bool { 
    $u = user();
    return is_array($u) && ($u['role'] ?? '') === 'admin'; 
}

function require_admin(): void { 
    if (!is_admin()) { 
        http_response_code(403); 
        exit('Akses ditolak: Hanya Administrator yang berwenang.'); 
    } 
}

function csrf_token(): string { 
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); 
}

function verify_csrf(): void { 
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { 
        http_response_code(419); 
        exit('Token keamanan tidak valid.'); 
    } 
}

function log_access(int $documentId, string $action): void { 
    try {
        $u = user();
        $userId = is_array($u) && isset($u['id']) ? (int)$u['id'] : null;
        $stmt = db()->prepare('INSERT INTO document_access_logs (document_id, user_id, action, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)'); 
        $stmt->execute([
            $documentId, 
            $userId, 
            $action, 
            $_SERVER['REMOTE_ADDR'] ?? null, 
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
        ]); 
    } catch (\Throwable $e) {
        // Abaikan jika log gagal
    }
}
