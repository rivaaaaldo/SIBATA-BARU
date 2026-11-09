<?php
/**
 * tools/seed_admin.php — membuat admin PERTAMA di tabel `admin` dengan MENYALIN hash password lama
 * dari konfigurasi privat (ADMIN_PASSWORD_HASH). Password asli maupun hash tidak pernah dicetak.
 *
 * Syarat : migrasi 004 sudah dijalankan; tabel admin masih kosong.
 * Pakai  : php tools/seed_admin.php <username> "<Nama Lengkap>"
 * Contoh : php tools/seed_admin.php aslab1 "Asisten Lab Utama"
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require __DIR__ . '/../config.php';

[$self, $username, $nama] = array_pad($argv, 3, '');
$username = trim($username);
$nama     = trim($nama);

if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username) || $nama === '' || mb_strlen($nama) > 100) {
    fwrite(STDERR, "Pemakaian: php tools/seed_admin.php <username 3-40 huruf/angka/._-> \"<Nama Lengkap>\"\n");
    exit(1);
}
if (ADMIN_PASSWORD_HASH === '' || password_get_info(ADMIN_PASSWORD_HASH)['algo'] === null) {
    fwrite(STDERR, "ADMIN_PASSWORD_HASH belum diisi / bukan hash password_hash() yang valid di konfigurasi privat.\n");
    exit(1);
}

$pdo = pdo();
try {
    $n = (int)$pdo->query('SELECT COUNT(*) FROM admin')->fetchColumn();
} catch (PDOException $ex) {
    fwrite(STDERR, "Tabel admin belum ada. Jalankan sql/migrations/004_admin_multi.sql terlebih dahulu.\n");
    exit(1);
}
if ($n > 0) {
    fwrite(STDERR, "Tabel admin sudah berisi akun ($n). Tambahkan admin lain lewat dashboard admin.php.\n");
    exit(1);
}

$pdo->prepare('INSERT INTO admin (username, nama_admin, password_hash, status_aktif) VALUES (:u,:n,:h,1)')
    ->execute([':u' => $username, ':n' => $nama, ':h' => ADMIN_PASSWORD_HASH]);

echo "Admin \"$username\" dibuat. Login sekarang memakai username + password LAMA Anda.\n";
echo "Mode multi-admin AKTIF. ADMIN_PASSWORD_HASH tidak dipakai lagi (boleh tetap ada untuk rollback 004).\n";
