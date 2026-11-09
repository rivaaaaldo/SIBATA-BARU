<?php
/**
 * tools/cek_database.php — pemeriksaan kesehatan database (hanya CLI, tidak mengubah data permanen).
 * Pakai: php tools/cek_database.php
 *
 * Bagian "uji penegakan CHECK" benar-benar mencoba INSERT transaksi dengan waktu kembali <= waktu ambil
 * di dalam transaksi database yang SELALU di-ROLLBACK. (Efek samping: nomor AUTO_INCREMENT maju.)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require __DIR__ . '/../config.php';
$pdo = pdo();
$gagal = 0;

function hasil(string $label, bool $ok, string $info = ''): void
{
    global $gagal;
    if (!$ok) $gagal++;
    printf("[%s] %s%s\n", $ok ? ' OK ' : 'GAGAL', $label, $info !== '' ? " — $info" : '');
}
function q(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s; }

/* 1. Versi & mesin */
$versi   = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
$mariadb = stripos($versi, 'mariadb') !== false;
preg_match('/(\d+)\.(\d+)\.(\d+)/', preg_replace('/^5\.5\.5-/', '', $versi), $m);
$angka   = ((int)$m[1]) * 10000 + ((int)$m[2]) * 100 + (int)$m[3];
$cukup   = $mariadb ? $angka >= 100300 : $angka >= 80016;
hasil('Versi database', $cukup, ($mariadb ? 'MariaDB' : 'MySQL') . " $versi (butuh MySQL >= 8.0.16 atau MariaDB >= 10.3)");

/* 2. Keberadaan & jumlah CHECK pada transaksi */
$adaTabelCheck = (int)q($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'CHECK_CONSTRAINTS'")->fetchColumn() > 0
              || (int)q($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'information_schema' AND TABLE_NAME = 'CHECK_CONSTRAINTS'")->fetchColumn() > 0;
if ($adaTabelCheck) {
    $daftar = q($pdo, "SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
                       WHERE CONSTRAINT_SCHEMA = DATABASE() AND CHECK_CLAUSE LIKE '%waktu_kembali%'")->fetchAll();
    hasil('chk_waktu terdaftar', in_array('chk_waktu', array_column($daftar, 'CONSTRAINT_NAME'), true));
    hasil('Tidak ada constraint waktu ganda', count($daftar) === 1, count($daftar) . ' constraint terkait waktu_kembali');
} else {
    hasil('Katalog CHECK_CONSTRAINTS tersedia', false, 'server ini tidak mendukung penegakan CHECK');
}
if (!$mariadb && $adaTabelCheck) {   // kolom ENFORCED hanya ada di MySQL
    $enf = q($pdo, "SELECT ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'transaksi' AND CONSTRAINT_NAME = 'chk_waktu'")->fetchColumn();
    hasil('chk_waktu berstatus ENFORCED', $enf === 'YES', (string)$enf);
}

/* 3. Data lama yang melanggar aturan */
$tidakValid = (int)$pdo->query('SELECT COUNT(*) FROM transaksi WHERE waktu_kembali <= waktu_ambil')->fetchColumn();
hasil('Tidak ada data dengan waktu_kembali <= waktu_ambil', $tidakValid === 0, "$tidakValid baris");

/* 4. UJI PERILAKU: apakah database benar-benar menolak data tidak valid? */
$ditolak = false; $pesanDb = '';
try {
    $pdo->beginTransaction();
    $tag = 'CEK' . substr(bin2hex(random_bytes(4)), 0, 8);
    $pdo->prepare('INSERT INTO peminjam (nama, nim, jurusan, no_hp) VALUES (:n,:nim,:j,:h)')
        ->execute([':n' => 'uji cek', ':nim' => $tag, ':j' => 'uji', ':h' => '0800000000']);
    $idP = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO barang (kode_aset, nama_barang) VALUES (:k, 'uji cek')")->execute([':k' => $tag]);
    $idB = (int)$pdo->lastInsertId();
    try {
        $pdo->prepare("INSERT INTO transaksi (id_peminjam, id_barang, waktu_ambil, waktu_kembali)
                       VALUES (:p,:b,'2030-01-01 10:00:00','2030-01-01 09:00:00')")->execute([':p' => $idP, ':b' => $idB]);
    } catch (PDOException $ex) {
        $ditolak = true;
        $pesanDb = 'kode ' . ($ex->errorInfo[1] ?? '?');
    }
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
hasil('UJI: INSERT dengan waktu kembali < waktu ambil DITOLAK database', $ditolak, $ditolak ? $pesanDb : 'database MENERIMA data tidak valid -> CHECK tidak ditegakkan!');

/* 5. Struktur hasil migrasi */
$tabel = fn(string $t) => (int)q($pdo, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', [':t' => $t])->fetchColumn() > 0;
$kolom = fn(string $t, string $c) => (int)q($pdo, 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c', [':t' => $t, ':c' => $c])->fetchColumn() > 0;
$fk    = fn(string $t, string $n) => (int)q($pdo, "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = :n AND CONSTRAINT_TYPE = 'FOREIGN KEY'", [':t' => $t, ':n' => $n])->fetchColumn() > 0;

hasil('Tabel kategori', $tabel('kategori'));
hasil('barang.id_kategori + FK fk_barang_kategori', $kolom('barang', 'id_kategori') && $fk('barang', 'fk_barang_kategori'));
if ($kolom('barang', 'id_kategori')) {
    if ($kolom('barang', 'kategori')) {
        $belum = (int)$pdo->query("SELECT COUNT(*) FROM barang WHERE kategori IS NOT NULL AND TRIM(kategori) <> '' AND id_kategori IS NULL")->fetchColumn();
        hasil('Semua barang berkategori sudah terhubung ke id_kategori', $belum === 0, "$belum belum terhubung");
    }
    $dup = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT nama_kategori FROM kategori GROUP BY nama_kategori HAVING COUNT(*) > 1) d')->fetchColumn();
    hasil('Nama kategori tidak duplikat', $dup === 0);
}
hasil('Tabel log_status_transaksi + FK', $tabel('log_status_transaksi') && $fk('log_status_transaksi', 'fk_log_transaksi'));
if ($tabel('log_status_transaksi')) {
    $tanpaLog = (int)$pdo->query('SELECT COUNT(*) FROM transaksi t WHERE NOT EXISTS (SELECT 1 FROM log_status_transaksi l WHERE l.id_transaksi = t.id_transaksi)')->fetchColumn();
    hasil('Setiap transaksi punya minimal 1 log', $tanpaLog === 0, "$tanpaLog tanpa log");
}
if ($tabel('admin')) {
    $jml = (int)$pdo->query('SELECT COUNT(*) FROM admin')->fetchColumn();
    $akt = (int)$pdo->query('SELECT COUNT(*) FROM admin WHERE status_aktif = 1')->fetchColumn();
    hasil('Tabel admin (kondisional)', true, "$jml akun, $akt aktif — mode " . ($jml > 0 ? 'MULTI-ADMIN' : 'login tunggal (tabel kosong)'));
    if ($jml > 0) hasil('Minimal satu admin aktif', $akt >= 1);
} else {
    echo "[ -- ] Tabel admin belum dibuat (migrasi 004 kondisional) — mode login tunggal\n";
}

echo $gagal === 0 ? "\nSEMUA PEMERIKSAAN LULUS\n" : "\n$gagal PEMERIKSAAN GAGAL\n";
exit($gagal === 0 ? 0 : 1);
