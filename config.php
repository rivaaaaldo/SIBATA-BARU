<?php
/* =========================================================
 * config.php — dipakai bersama oleh index.php, form.php, admin.php
 *
 * FILE INI TIDAK LAGI MENYIMPAN KREDENSIAL. Semua nilai rahasia dibaca dari:
 *   1) environment variable server (diutamakan), atau
 *   2) file privat bergaya .env DI LUAR document root.
 *
 * Lokasi file privat dicari berurutan (file yang berada di dalam document root DITOLAK):
 *   a. path pada environment variable LAB_ENV_FILE
 *   b. <folder induk aplikasi>/lab-private/.env
 *   c. <dua tingkat di atas aplikasi>/lab-private/.env
 * Lihat README.md dan .env.example untuk contoh XAMPP (Windows) dan hosting.
 * ========================================================= */
declare(strict_types=1);

/* ---------- 0. UTIL KONFIGURASI ---------- */

/** Baca nilai dari environment (tidak peduli SAPI: $_ENV, $_SERVER, atau getenv). */
function envNilai(string $kunci): ?string
{
    foreach ([$_ENV[$kunci] ?? null, $_SERVER[$kunci] ?? null, getenv($kunci)] as $v) {
        if (is_string($v) && $v !== '') return $v;
    }
    return null;
}

/** Parser .env sederhana: KUNCI=nilai, boleh diapit '...' atau "...", baris # = komentar. */
function parseEnvFile(string $path): array
{
    $out = [];
    $baris = @file($path, FILE_IGNORE_NEW_LINES);
    if ($baris === false) return $out;
    foreach ($baris as $i => $b) {
        if ($i === 0) $b = preg_replace('/^\xEF\xBB\xBF/', '', $b);   // buang BOM (Notepad Windows)
        $b = trim($b);
        if ($b === '' || $b[0] === '#') continue;
        if (!preg_match('/^([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $b, $m)) continue;
        $v = trim($m[2]);
        $n = strlen($v);
        if ($n >= 2 && (($v[0] === '"' && $v[$n - 1] === '"') || ($v[0] === "'" && $v[$n - 1] === "'"))) {
            $v = substr($v, 1, -1);
        }
        $out[$m[1]] = $v;
    }
    return $out;
}

/** True bila $path berada di dalam document root web (tidak boleh dipakai untuk menyimpan rahasia). */
function diDalamDocroot(string $path): bool
{
    $root = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $root = $root !== '' ? realpath($root) : false;
    $real = realpath($path);
    if ($root === false || $real === false) return false;
    $root = rtrim($root, '\\/') . DIRECTORY_SEPARATOR;
    return DIRECTORY_SEPARATOR === '\\'
        ? strncasecmp($real, $root, strlen($root)) === 0
        : strncmp($real, $root, strlen($root)) === 0;
}

/** Hentikan permintaan dengan aman: detail hanya ke error_log, pengguna hanya melihat pesan umum. */
function galatFatal(string $detailLog, int $kode = 500): never
{
    error_log('[peminjaman-lab] ' . $detailLog);
    http_response_code($kode);
    $pesan = 'Layanan sedang tidak dapat digunakan. Silakan hubungi administrator.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $pesan . PHP_EOL . '(detail ada di error log)' . PHP_EOL);
    } elseif (($_GET['action'] ?? '') !== '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'pesan' => $pesan], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Layanan tidak tersedia</title>'
           . '<p style="font:16px system-ui;margin:2rem">' . htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    exit(1);
}

/** Muat konfigurasi: file privat (jika ada) digabung dengan environment variable (env menang). */
function muatKonfigurasi(): array
{
    $kandidat = [];
    if (($p = envNilai('LAB_ENV_FILE')) !== null) $kandidat[] = $p;
    $kandidat[] = dirname(__DIR__) . '/lab-private/.env';
    $kandidat[] = dirname(__DIR__, 2) . '/lab-private/.env';

    $dariFile = [];
    foreach ($kandidat as $path) {
        if (!is_file($path) || !is_readable($path)) continue;
        if (diDalamDocroot($path)) {
            error_log('[peminjaman-lab] File konfigurasi DITOLAK karena berada di dalam document root: ' . $path);
            continue;
        }
        $dariFile = parseEnvFile($path);
        break;
    }

    $kunci = ['APP_ENV', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'WA_ASLAB',
              'GEMINI_API_KEY', 'GEMINI_MODEL', 'GEMINI_SSL_VERIFY', 'ADMIN_PASSWORD_HASH'];
    $cfg = [];
    foreach ($kunci as $k) {
        $v = envNilai($k);
        if ($v === null && array_key_exists($k, $dariFile)) $v = $dariFile[$k];
        $cfg[$k] = $v;                       // null = tidak diset
    }
    return $cfg;
}

/* ---------- 1. SESI & ZONA WAKTU ---------- */
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    session_start();
}
date_default_timezone_set('Asia/Jakarta');

/* ---------- 2. KONFIGURASI (dari lingkungan, tanpa fallback kredensial) ---------- */
$_cfg = muatKonfigurasi();
$_produksi = strtolower((string)($_cfg['APP_ENV'] ?? 'production')) !== 'local';

if ($_produksi) {                            // produksi = default (aman); isi APP_ENV=local hanya di XAMPP
    ini_set('display_errors', '0');
}

// Kredensial database: WAJIB. DB_PASS boleh kosong hanya jika kuncinya ada DAN APP_ENV=local.
$_hilang = [];
foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $k) {
    if (($_cfg[$k] ?? '') === '') $_hilang[] = $k;
}
if ($_cfg['DB_PASS'] === null) $_hilang[] = 'DB_PASS';
if ($_hilang) {
    galatFatal('Konfigurasi wajib belum lengkap: ' . implode(', ', $_hilang)
        . '. Siapkan file lab-private/.env (lihat .env.example) atau environment variable.');
}
if ($_produksi && $_cfg['DB_PASS'] === '') {
    galatFatal('DB_PASS kosong tidak diizinkan pada APP_ENV=production.');
}

// Nama konstanta dipertahankan agar kompatibel dengan file lain.
define('DB_HOST', (string)$_cfg['DB_HOST']);
define('DB_NAME', (string)$_cfg['DB_NAME']);
define('DB_USER', (string)$_cfg['DB_USER']);
define('DB_PASS', (string)$_cfg['DB_PASS']);

// Nilai di bawah dicek di tempat pemakaiannya (fitur terkait berhenti dengan pesan umum bila kosong),
// sehingga katalog tetap berjalan walau mis. API key Gemini belum diisi.
define('WA_ASLAB', (string)($_cfg['WA_ASLAB'] ?? ''));
define('GEMINI_API_KEY', (string)($_cfg['GEMINI_API_KEY'] ?? ''));
define('GEMINI_MODEL', (string)($_cfg['GEMINI_MODEL'] ?? 'gemini-3.6-flash'));   // bukan rahasia; sesuaikan dengan akun Anda
define('GEMINI_SSL_VERIFY', !in_array(strtolower((string)($_cfg['GEMINI_SSL_VERIFY'] ?? '1')), ['0', 'false', 'off', 'no'], true));
define('ADMIN_PASSWORD_HASH', (string)($_cfg['ADMIN_PASSWORD_HASH'] ?? ''));     // dipakai hanya selama tabel admin kosong
unset($_cfg, $_hilang);

// Folder foto barang (harus bisa ditulis oleh web server)
const UPLOAD_DIR = __DIR__ . '/uploads/';
const UPLOAD_URL = 'uploads/';
const MAX_FOTO_BYTES = 2 * 1024 * 1024;      // 2 MB

// Status transaksi yang memblokir jadwal sebuah unit
const STATUS_BLOKIR = "'menunggu','disetujui','dipinjam'";

/* ---------- 3. KONEKSI PDO ---------- */
function pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,   // prepared statement asli (anti SQL Injection)
            ]);
        } catch (PDOException $ex) {
            // Jangan teruskan exception: stack trace PHP dapat memuat host/user/password.
            galatFatal('Koneksi database gagal (kode ' . $ex->getCode() . ').');
        }
    }
    return $pdo;
}

/* ---------- 4. HELPER ---------- */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function parseWaktu(string $tgl, string $jam): ?DateTimeImmutable
{
    $str = trim($tgl) . ' ' . trim($jam);
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i', $str);
    return ($d && $d->format('Y-m-d H:i') === $str) ? $d : null;
}

function fmt(string $dt): string
{
    return (new DateTimeImmutable($dt))->format('d/m/Y H:i');
}

function nowStr(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

/** Daftar jam 07:00 s.d. 17:00 dengan interval 15 menit (07:00, 07:15, ... 17:00). */
function daftarJam(): array
{
    $jam = [];
    for ($m = 7 * 60; $m <= 17 * 60; $m += 15) {
        $jam[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
    return $jam;
}

/** Cetak <option> untuk dropdown jam. */
function opsiJam(string $dipilih = ''): string
{
    $out = '<option value="">--:--</option>';
    foreach (daftarJam() as $j) {
        $out .= '<option value="' . $j . '"' . ($j === $dipilih ? ' selected' : '') . '>' . $j . '</option>';
    }
    return $out;
}

/** Rapikan nama kategori: buang spasi di ujung & ratakan spasi ganda (huruf besar/kecil dijaga oleh UNIQUE collation). */
function normalisasiKategori(string $nama): string
{
    $nama = preg_replace('/\s+/u', ' ', $nama) ?? $nama;
    return trim($nama);
}

/**
 * Catat satu baris audit perubahan status. WAJIB dipanggil di dalam database transaction
 * yang sama dengan perubahan statusnya, supaya keduanya berhasil/gagal bersama.
 * $statusLama = null untuk status awal (transaksi baru).
 */
function catatLogStatus(PDO $pdo, int $idTransaksi, ?string $statusLama, string $statusBaru, string $aktor): void
{
    $pdo->prepare(
        'INSERT INTO log_status_transaksi (id_transaksi, status_lama, status_baru, aktor, waktu_ubah)
         VALUES (:t, :l, :b, :a, :w)'
    )->execute([
        ':t' => $idTransaksi,
        ':l' => $statusLama,
        ':b' => $statusBaru,
        ':a' => mb_substr($aktor, 0, 60),
        ':w' => nowStr(),
    ]);
}

/* CSRF */
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function csrfValid(?string $t): bool
{
    return is_string($t) && hash_equals($_SESSION['csrf'], $t);
}

/**
 * Cek bentrok jadwal sebuah unit. Mengembalikan waktu_kembali TERAKHIR
 * dari transaksi yang beririsan, atau null jika unit tersedia.
 * Irisan:  ambil_lama < kembali_baru  DAN  kembali_lama > ambil_baru
 */
function cekBentrok(PDO $pdo, int $idBarang, string $ambil, string $kembali): ?string
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) AS jml, MAX(waktu_kembali) AS paling_akhir
         FROM transaksi
         WHERE id_barang = :id
           AND status IN (" . STATUS_BLOKIR . ")
           AND waktu_ambil   < :kembali
           AND waktu_kembali > :ambil"
    );
    $st->execute([':id' => $idBarang, ':ambil' => $ambil, ':kembali' => $kembali]);
    $r = $st->fetch();
    return ((int)$r['jml'] > 0) ? $r['paling_akhir'] : null;
}
