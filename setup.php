<?php
declare(strict_types=1);

// Import configurations
require __DIR__ . '/config.php';

echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Database Setup — Arsipku</title>';
echo '<style>body { font-family: sans-serif; line-height: 1.6; max-width: 600px; margin: 40px auto; padding: 20px; background: #f6f7fb; color: #1d2540; }';
echo '.card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }';
echo 'h1 { border-bottom: 2px solid #eeeff5; padding-bottom: 10px; }';
echo '.success { color: #259c73; font-weight: bold; }';
echo '.error { color: #d75f65; font-weight: bold; }';
echo 'pre { background: #f3f4fa; padding: 10px; border-radius: 4px; overflow-x: auto; }</style></head><body>';
echo '<div class="card"><h1>Arsipku Database Setup</h1>';

try {
    // 1. Establish connection to MySQL (without DB name first, in case DB does not exist)
    $dsnWithoutDb = 'mysql:host=' . DB_HOST . ';charset=utf8mb4';
    $pdo = new PDO($dsnWithoutDb, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // 2. Create the Database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<p class='success'>✓ Database <strong>" . DB_NAME . "</strong> berhasil dibuat atau sudah ada.</p>";

    // 3. Connect to the specific Database
    $pdo->exec("USE `" . DB_NAME . "`");

    // 4. Read schema.sql
    $schemaFile = __DIR__ . '/database/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("File schema.sql tidak ditemukan di folder database!");
    }

    $sql = file_get_contents($schemaFile);

    // 5. Execute schema by splitting into statements
    $sqlClean = preg_replace('/--.*\n/', '', $sql);
    $queries = array_filter(array_map('trim', explode(';', $sqlClean)));

    $queriesRun = 0;
    foreach ($queries as $query) {
        if ($query !== '') {
            $pdo->exec($query);
            $queriesRun++;
        }
    }
    echo "<p class='success'>✓ Skema database berhasil diimpor ($queriesRun kueri dijalankan).</p>";

    // 6. Generate valid bcrypt hashes for the default users
    $defaultUsers = [
        'admin@arsipku.id' => ['nip' => '198501012010011001', 'pass' => 'Arsipku2024!', 'role' => 'Admin'],
        'rani@arsipku.id'  => ['nip' => '199002022015022002', 'pass' => 'Viewer#248', 'role' => 'Editor'],
        'dimas@arsipku.id' => ['nip' => '199503032020031003', 'pass' => 'Raka@Admin', 'role' => 'Viewer']
    ];

    $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ?, nip = ? WHERE email = ?");
    foreach ($defaultUsers as $email => $data) {
        $hash = password_hash($data['pass'], PASSWORD_DEFAULT);
        $updateStmt->execute([$hash, $data['nip'], $email]);
        echo "<li>Pegawai <strong>{$data['role']}</strong> ($email) &rarr; NIP: <code>{$data['nip']}</code> | Sandi: <code>{$data['pass']}</code></li>";
    }

    echo "<p class='success'>✓ Password dan NIP akun bawaan berhasil diatur!</p>";
    echo "<hr><p>Siap digunakan! Anda sekarang dapat masuk menggunakan NIP di <a href='login.php'>Halaman Login NIP</a>.</p>";

} catch (PDOException $e) {
    echo "<p class='error'>Kesalahan Database: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Silakan periksa kredensial database Anda di file <code>config.php</code>:</p>";
    echo "<pre>DB_HOST = '" . DB_HOST . "'\nDB_USER = '" . DB_USER . "'\nDB_PASS = '" . DB_PASS . "'\nDB_NAME = '" . DB_NAME . "'</pre>";
} catch (Exception $e) {
    echo "<p class='error'>Kesalahan: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo '</div></body></html>';
