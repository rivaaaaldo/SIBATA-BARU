<?php
require __DIR__ . '/config.php';

$pdo = pdo();
$kategori = trim((string)($_GET['kategori'] ?? ''));
$search   = trim((string)($_GET['search'] ?? ''));
if (mb_strlen($search) > 60) $search = mb_substr($search, 0, 60);

// Bangun URL katalog dengan mempertahankan filter kategori & kata kunci
function urlKatalog(string $kat, string $q): string
{
    $p = array_filter(['kategori' => $kat, 'search' => $q], static fn($v) => $v !== '');
    return 'index.php' . ($p ? '?' . http_build_query($p) : '');
}

// Daftar kategori untuk filter: dari tabel kategori, hanya yang punya alat tampil di katalog
$daftarKategori = $pdo->query(
    "SELECT k.nama_kategori
     FROM kategori k
     WHERE EXISTS (SELECT 1 FROM barang b WHERE b.id_kategori = k.id_kategori AND b.status_aktif = 1)
     ORDER BY k.nama_kategori"
)->fetchAll(PDO::FETCH_COLUMN);

// Katalog + info "sedang dipinjam sampai".
// Waktu "sekarang" dikirim dari PHP (bukan NOW() MySQL) supaya zona waktunya konsisten (Asia/Jakarta).
// Unit dianggap sedang dipinjam bila ada transaksi aktif yang sudah dimulai dan waktu_kembali > sekarang.
// (Hapus syarat "waktu_ambil <= :now1" jika ingin booking masa depan juga menonaktifkan tombol.)
$sql = "SELECT b.*, k.nama_kategori,
               (SELECT MAX(t.waktu_kembali) FROM transaksi t
                 WHERE t.id_barang = b.id_barang
                   AND t.status IN (" . STATUS_BLOKIR . ")
                   AND t.waktu_ambil <= :now1
                   AND t.waktu_kembali > :now2) AS dipinjam_sampai
        FROM barang b
        LEFT JOIN kategori k ON k.id_kategori = b.id_kategori
        WHERE b.status_aktif = 1";
$params = [':now1' => nowStr(), ':now2' => nowStr()];
if ($kategori !== '') {
    // Dicocokkan lewat tabel kategori (tidak peduli huruf besar/kecil); URL ?kategori=Nama tetap bekerja
    $sql .= " AND k.nama_kategori = :kat";
    $params[':kat'] = $kategori;
}
if ($search !== '') {
    // Escape karakter wildcard LIKE (% _ \) agar dicari sebagai teks biasa
    $like = '%' . addcslashes($search, '\\%_') . '%';
    $sql .= " AND (b.nama_barang LIKE :q1 OR b.kode_aset LIKE :q2)";
    $params[':q1'] = $like;
    $params[':q2'] = $like;
}
$sql .= " ORDER BY b.nama_barang, b.kode_aset";

$st = $pdo->prepare($sql);
$st->execute($params);
$barang = $st->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Katalog Alat &amp; Barang Laboratorium</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="topbar">
  <div class="in">
    <a class="brand" href="index.php"><span class="logo"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3h6M10 3v6L4.5 19a1.5 1.5 0 0 0 1.3 2.2h12.4a1.5 1.5 0 0 0 1.3-2.2L14 9V3"/><path d="M7.5 15h9"/></svg></span><span>Lab Inventaris</span></a>
    <nav class="nav"><a class="on" href="index.php">Katalog</a><a href="admin.php">Admin</a></nav>
  </div>
</header>

<section class="hero">
  <div class="in">
    <span class="eyebrow">Sistem Peminjaman Laboratorium</span>
    <h1>Alat &amp; Barang Laboratorium</h1>
    <p>Lihat ketersediaan alat, pilih yang kamu butuhkan, lalu ajukan peminjaman dan konfirmasi lewat WhatsApp Asisten Laboratorium.</p>
    <ol class="steps"><li><b>1</b> Pilih alat</li><li><b>2</b> Isi formulir</li><li><b>3</b> Konfirmasi via WhatsApp</li></ol>
  </div>
</section>

<main class="container">
  <div class="toolbar">
    <div class="filter">
      <a class="chip <?= $kategori === '' ? 'aktif' : '' ?>" href="<?= e(urlKatalog('', $search)) ?>">Semua</a>
      <?php foreach ($daftarKategori as $k): ?>
        <a class="chip <?= mb_strtolower($kategori) === mb_strtolower($k) ? 'aktif' : '' ?>" href="<?= e(urlKatalog($k, $search)) ?>"><?= e($k) ?></a>
      <?php endforeach; ?>
    </div>

    <form class="search" method="get" action="index.php" role="search">
      <?php if ($kategori !== ''): ?><input type="hidden" name="kategori" value="<?= e($kategori) ?>"><?php endif; ?>
      <input type="search" name="search" value="<?= e($search) ?>" maxlength="60" placeholder="Cari nama atau kode aset…" aria-label="Cari alat">
      <button type="submit" class="btn">Cari</button>
    </form>
  </div>

  <p class="count">
    <?= count($barang) ?> alat ditampilkan<?php if ($search !== ''): ?>
      untuk &ldquo;<?= e($search) ?>&rdquo; &middot; <a href="<?= e(urlKatalog($kategori, '')) ?>">Hapus pencarian</a>
    <?php endif; ?>
  </p>

  <?php if (!$barang): ?>
    <div class="kosong">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/></svg>
      <?php if ($search !== ''): ?>
        <h2>Tidak ada hasil</h2>
        <div>Tidak ada alat yang cocok dengan &ldquo;<?= e($search) ?>&rdquo;.</div>
      <?php else: ?>
        <h2>Belum ada alat</h2>
        <div>Belum ada alat pada kategori ini.</div>
      <?php endif; ?>
      <a class="btn sec" href="index.php">Lihat semua alat</a>
    </div>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($barang as $b):
        $dipinjam = $b['dipinjam_sampai'] !== null;
        $rusak    = $b['kondisi'] !== 'baik';
        $kls = $rusak ? 'bad' : ($dipinjam ? 'warn' : 'ok');
        $lbl = $rusak ? 'Tidak tersedia' : ($dipinjam ? 'Sedang dipinjam' : 'Tersedia');
        $kondKls = $b['kondisi'] === 'baik' ? 'b-baik' : ($b['kondisi'] === 'rusak ringan' ? 'b-rusak-ringan' : 'b-rusak-berat');
    ?>
      <article class="card item">
        <div class="media">
          <?php if ($b['foto_barang']): ?>
            <img class="foto" src="<?= e(UPLOAD_URL . $b['foto_barang']) ?>" alt="<?= e($b['nama_barang']) ?>" loading="lazy">
          <?php else: ?>
            <div class="noimg">Tidak ada foto</div>
          <?php endif; ?>
          <span class="badge pill b-<?= $kls ?>"><?= $lbl ?></span>
        </div>
        <div class="info">
          <?php if ($b['nama_kategori']): ?><span class="tag"><?= e($b['nama_kategori']) ?></span><?php endif; ?>
          <span class="nama"><?= e($b['nama_barang']) ?></span>
          <span class="kode">Kode aset: <?= e($b['kode_aset']) ?></span>
          <span class="kondisi">Kondisi: <span class="badge <?= $kondKls ?>"><?= e($b['kondisi']) ?></span></span>

          <div class="status <?= $rusak || $dipinjam ? $kls : '' ?>">
            <?php if ($rusak): ?>
              Tidak dapat dipinjam (kondisi: <?= e($b['kondisi']) ?>)
            <?php elseif ($dipinjam): ?>
              Sedang dipinjam, kembali pada <?= e(fmt($b['dipinjam_sampai'])) ?>
            <?php else: ?>
              Siap dipinjam
            <?php endif; ?>
          </div>

          <?php if (!$dipinjam && !$rusak): ?>
            <a class="btn" href="form.php?id_barang=<?= (int)$b['id_barang'] ?>">Pinjam</a>
          <?php else: ?>
            <span class="btn off">Pinjam</span>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</main>
<div class="footer">Sistem Peminjaman Alat &amp; Barang Laboratorium</div>
</body>
</html>