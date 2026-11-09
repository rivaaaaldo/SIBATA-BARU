<?php
require __DIR__ . '/config.php';

/** JSON aman untuk disisipkan di <script type="application/json"> (dibaca app.js) */
function toastJson(array $toasts): string
{
    return json_encode($toasts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

/* =========================================================
 * A. ENDPOINT AJAX: cek ketersediaan unit  (GET ?action=cek)
 * ========================================================= */
if (($_GET['action'] ?? '') === 'cek') {
    $id = (int)($_GET['id_barang'] ?? 0);
    $a  = parseWaktu($_GET['tgl_ambil'] ?? '', $_GET['jam_ambil'] ?? '');
    $k  = parseWaktu($_GET['tgl_kembali'] ?? '', $_GET['jam_kembali'] ?? '');
    if ($id <= 0 || !$a || !$k || $k <= $a) {
        json_out(['ok' => false], 400);
    }
    $bentrok = cekBentrok(pdo(), $id, $a->format('Y-m-d H:i:s'), $k->format('Y-m-d H:i:s'));
    json_out([
        'ok'            => true,
        'tersedia'      => $bentrok === null,
        'tersedia_lagi' => $bentrok ? fmt($bentrok) : null,
    ]);
}

/* =========================================================
 * B. ENDPOINT AJAX: ekstraksi KTM dengan AI Vision (POST ?action=ocr)
 *    KERANGKA / PLACEHOLDER — foto dikirim ke Gemini, TIDAK disimpan di server.
 * ========================================================= */
if (($_GET['action'] ?? '') === 'ocr' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid($_POST['csrf'] ?? null)) {
        json_out(['ok' => false, 'pesan' => 'Token tidak valid.'], 403);
    }
    if (!isset($_FILES['foto_ktm']) || $_FILES['foto_ktm']['error'] !== UPLOAD_ERR_OK) {
        json_out(['ok' => false, 'pesan' => 'Upload gagal.'], 400);
    }
    if (GEMINI_API_KEY === '') {
        error_log('[peminjaman-lab] OCR KTM dipanggil tetapi GEMINI_API_KEY belum dikonfigurasi.');
        json_out(['ok' => false, 'pesan' => 'Fitur baca KTM belum tersedia. Isi data secara manual.'], 503);
    }
    $f = $_FILES['foto_ktm'];
    if ($f['size'] > 4 * 1024 * 1024) {
        json_out(['ok' => false, 'pesan' => 'Ukuran maksimal 4 MB.'], 400);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        json_out(['ok' => false, 'pesan' => 'Format harus JPG/PNG/WEBP.'], 400);
    }

        // --- DIBAWAH INI ADALAH STRUKTUR PAYLOAD GEMINI TERBARU DI FORM.PHP ---
    $payload = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    [
                        'text' => 'Ini foto Kartu Tanda Mahasiswa (KTM). Ekstrak nama lengkap, NIM, dan jurusan/program studi dari gambar ini. '
                        .'Untuk jurusan, tulis teks di baris fakultas/jurusan tanpa awalan "FAK." dan ganti tanda strip antar kata dengan spasi, misalnya "Teknik Informatika". '
                        . 'Balas HANYA dengan format JSON mentah seperti ini: {"nama":"...","nim":"...","jurusan":"..."}. '
                        . 'Jika tidak terbaca, isi nilai properti dengan string kosong.'
                    ],
                    [
                        'inline_data' => [
                            'mime_type' => $mime,
                            'data'      => base64_encode(file_get_contents($f['tmp_name'])),
                        ]
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'responseMimeType' => 'application/json'
        ]
    ];

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent';
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => GEMINI_SSL_VERIFY,        // default AKTIF; matikan hanya lewat GEMINI_SSL_VERIFY=0 di lokal
        CURLOPT_SSL_VERIFYHOST => GEMINI_SSL_VERIFY ? 2 : 0,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . GEMINI_API_KEY // Mengambil key dari config.php
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);

    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error_msg = curl_error($ch); 
    
    // Pengecekan error koneksi cURL (Sistem lokal komputer)
    if ($resp === false) {
        error_log('[peminjaman-lab] Gemini cURL gagal: ' . $curl_error_msg);
        json_out(['ok' => false, 'pesan' => 'Tidak dapat terhubung ke layanan AI. Isi data secara manual.'], 502);
    }

    // Pengecekan status respon dari Google Server
    if ($http !== 200) {
        $error_detail = json_decode($resp, true);
        error_log('[peminjaman-lab] Gemini HTTP ' . $http . ': ' . ($error_detail['error']['message'] ?? 'tanpa pesan'));
        json_out(['ok' => false, 'pesan' => 'Layanan AI menolak permintaan (kode ' . $http . '). Isi data secara manual.'], 502);
    }

    // 🟢 2. Membongkar struktur JSON pembacaan gambar dari Google Gemini
    $data_ai = json_decode($resp, true);
    $teks_json = $data_ai['candidates'][0]['content']['parts'][0]['text'] ?? '';
    
    // Bersihkan penanda markdown seperti ```json ... ``` yang sering ikut dihasilkan oleh AI
    $teks_json = trim(preg_replace('/^```(?:json)?|```$/m', '', $teks_json));
    $hasil = json_decode($teks_json, true);

    // Bersihkan keluaran AI sebelum dikirim ke browser
    $nama = mb_substr(trim((string)($hasil['nama'] ?? '')), 0, 120);
    $nim  = substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($hasil['nim'] ?? '')), 0, 20);
    $jurusan = mb_substr(trim((string)($hasil['jurusan'] ?? '')), 0, 100); 
    
    // Kirim data sukses ke Javascript browser
    json_out(['ok' => true, 'nama' => $nama, 'nim' => $nim, 'jurusan' => $jurusan]);
}


/* =========================================================
 * C. PEMROSESAN FORM (POST) -> SIMPAN -> REDIRECT WHATSAPP
 * ========================================================= */
$errors = [];
$old = [
    'nama' => '', 'nim' => '', 'jurusan' => '', 'no_hp' => '',
    'id_barang' => (string)(int)($_GET['id_barang'] ?? 0),   // otomatis terpilih dari ?id_barang=X
    'tgl_ambil' => '', 'jam_ambil' => '', 'tgl_kembali' => '', 'jam_kembali' => '',
];
if ($old['id_barang'] === '0') $old['id_barang'] = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action'])) {
    foreach ($old as $k => $_) {
        if (isset($_POST[$k])) $old[$k] = trim((string)$_POST[$k]);
    }

    if (!csrfValid($_POST['csrf'] ?? null)) $errors[] = 'Sesi tidak valid, muat ulang halaman.';

    if ($old['nama'] === '' || mb_strlen($old['nama']) > 120)       $errors[] = 'Nama wajib diisi (maks 120 karakter).';
    if (!preg_match('/^[A-Za-z0-9]{5,20}$/', $old['nim']))          $errors[] = 'NIM harus 5-20 karakter huruf/angka.';
    if ($old['jurusan'] === '' || mb_strlen($old['jurusan']) > 100) $errors[] = 'Jurusan wajib diisi.';
    if (!preg_match('/^(\+62|62|0)8[0-9]{8,12}$/', $old['no_hp']))  $errors[] = 'No. HP tidak valid (contoh: 081234567890).';

    $idBarang = (int)$old['id_barang'];
    if ($idBarang <= 0) $errors[] = 'Pilih alat yang akan dipinjam.';

    // --- VALIDASI BACKEND jam: harus salah satu dari 07:00..17:00 interval 15 menit ---
    $jamValid = daftarJam();
    if (!in_array($old['jam_ambil'], $jamValid, true))   $errors[] = 'Jam pengambilan harus 07:00-17:00 (interval 15 menit).';
    if (!in_array($old['jam_kembali'], $jamValid, true)) $errors[] = 'Jam pengembalian harus 07:00-17:00 (interval 15 menit).';

    $ambil   = parseWaktu($old['tgl_ambil'], $old['jam_ambil']);
    $kembali = parseWaktu($old['tgl_kembali'], $old['jam_kembali']);
    if (!$ambil)   $errors[] = 'Tanggal/jam pengambilan tidak valid.';
    if (!$kembali) $errors[] = 'Tanggal/jam pengembalian tidak valid.';
    if ($ambil && $ambil < new DateTimeImmutable('now')) $errors[] = 'Waktu pengambilan tidak boleh di masa lalu.';
    if ($ambil && $kembali && $kembali <= $ambil)        $errors[] = 'Waktu pengembalian harus setelah waktu pengambilan.';

    if (!$errors && WA_ASLAB === '') {
        error_log('[peminjaman-lab] WA_ASLAB belum dikonfigurasi.');
        $errors[] = 'Layanan pengajuan belum siap. Hubungi Asisten Laboratorium.';
    }

    if (!$errors) {
        $pdo = pdo();
        try {
            $pdo->beginTransaction();

            // Kunci baris unit agar dua orang tidak memesan slot yang sama bersamaan
            $st = $pdo->prepare("SELECT nama_barang, kode_aset FROM barang
                                 WHERE id_barang = :id AND status_aktif = 1 AND kondisi = 'baik' FOR UPDATE");
            $st->execute([':id' => $idBarang]);
            $barang = $st->fetch();
            if (!$barang) throw new RuntimeException('Alat tidak ditemukan atau sedang tidak dapat dipinjam.');

            $sAmbil   = $ambil->format('Y-m-d H:i:s');
            $sKembali = $kembali->format('Y-m-d H:i:s');

            $bentrok = cekBentrok($pdo, $idBarang, $sAmbil, $sKembali);
            if ($bentrok !== null) {
                throw new RuntimeException('Alat tidak tersedia pada waktu tersebut. Peminjam sebelumnya mengembalikan pada ' . fmt($bentrok) . '.');
            }

            // Upsert peminjam berdasarkan NIM
            $st = $pdo->prepare('SELECT id_peminjam FROM peminjam WHERE nim = :nim');
            $st->execute([':nim' => $old['nim']]);
            $idPeminjam = $st->fetchColumn();
            if ($idPeminjam) {
                $pdo->prepare('UPDATE peminjam SET nama=:n, jurusan=:j, no_hp=:h WHERE id_peminjam=:id')
                    ->execute([':n' => $old['nama'], ':j' => $old['jurusan'], ':h' => $old['no_hp'], ':id' => $idPeminjam]);
            } else {
                $pdo->prepare('INSERT INTO peminjam (nama, nim, jurusan, no_hp) VALUES (:n,:nim,:j,:h)')
                    ->execute([':n' => $old['nama'], ':nim' => $old['nim'], ':j' => $old['jurusan'], ':h' => $old['no_hp']]);
                $idPeminjam = (int)$pdo->lastInsertId();
            }

            $pdo->prepare("INSERT INTO transaksi (id_peminjam, id_barang, waktu_ambil, waktu_kembali, status)
                           VALUES (:p,:b,:a,:k,'menunggu')")
                ->execute([':p' => $idPeminjam, ':b' => $idBarang, ':a' => $sAmbil, ':k' => $sKembali]);
            $idTransaksi = (int)$pdo->lastInsertId();

            // Audit trail: status awal NULL -> menunggu. Bila gagal, seluruh pengajuan ikut dibatalkan.
            catatLogStatus($pdo, $idTransaksi, null, 'menunggu', 'mahasiswa:' . $old['nim']);

            $pdo->commit();

            // --- Draf pesan WhatsApp ---
            $pesan  = "Halo Asisten Lab, saya ingin mengajukan peminjaman alat:\n\n";
            $pesan .= "No. Pengajuan : #{$idTransaksi}\n";
            $pesan .= "Nama          : {$old['nama']}\n";
            $pesan .= "NIM           : {$old['nim']}\n";
            $pesan .= "Jurusan       : {$old['jurusan']}\n";
            $pesan .= "No. HP        : {$old['no_hp']}\n";
            $pesan .= "Alat          : {$barang['nama_barang']} ({$barang['kode_aset']})\n";
            $pesan .= "Ambil         : " . $ambil->format('d/m/Y H:i') . "\n";
            $pesan .= "Kembali       : " . $kembali->format('d/m/Y H:i') . "\n\n";
            $pesan .= 'Mohon balas "Ya" jika disetujui. Terima kasih.';

            // urlencode() mengubah spasi menjadi "+", yang kadang tampil sebagai tanda + di WhatsApp.
            // str_replace di bawah mengubahnya menjadi %20 agar pesan tampil rapi.
            $waUrl = 'https://wa.me/' . WA_ASLAB . '?text=' . str_replace('+', '%20', urlencode($pesan));

            header('Location: ' . $waUrl);
            exit;

        } catch (RuntimeException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $ex->getMessage();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($ex->getMessage());
            $errors[] = 'Terjadi kesalahan sistem. Coba lagi.';
        }
    }
}

/* =========================================================
 * D. DATA UNTUK FORM
 * ========================================================= */
$st = pdo()->prepare(
    "SELECT b.id_barang, b.kode_aset, b.nama_barang, b.foto_barang,
            (SELECT MAX(t.waktu_kembali) FROM transaksi t
              WHERE t.id_barang = b.id_barang
                AND t.status IN (" . STATUS_BLOKIR . ")
                AND t.waktu_ambil <= :now1 AND t.waktu_kembali > :now2) AS dipinjam_sampai
     FROM barang b
     WHERE b.status_aktif = 1 AND b.kondisi = 'baik'
     ORDER BY b.nama_barang, b.kode_aset"
);
$st->execute([':now1' => nowStr(), ':now2' => nowStr()]);
$daftarBarang = $st->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Form Peminjaman Alat Laboratorium</title>
<link rel="stylesheet" href="assets/css/style.css">
<script src="assets/js/script.js" defer></script>
</head>
<body>
<header class="topbar">
  <div class="in">
    <a class="brand" href="index.php"><span class="logo"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3h6M10 3v6L4.5 19a1.5 1.5 0 0 0 1.3 2.2h12.4a1.5 1.5 0 0 0 1.3-2.2L14 9V3"/><path d="M7.5 15h9"/></svg></span><span>Lab Inventaris</span></a>
    <nav class="nav"><a href="index.php">Katalog</a><a class="on" href="form.php">Form Peminjaman</a></nav>
  </div>
</header>

<div class="page">
  <a class="back" href="index.php">&larr; Kembali ke katalog</a>
  <div class="head">
    <h1>Form Peminjaman Alat</h1>
    <p>Lengkapi data berikut. Setelah dikirim, kamu akan diarahkan ke WhatsApp Asisten Laboratorium.</p>
  </div>

  <?php if ($errors): ?>
    <script type="application/json" id="toast-data"><?= toastJson([['type' => 'err', 'title' => 'Periksa kembali:', 'msg' => array_values($errors)]]) ?></script>
  <?php endif; ?>

  <!-- Blok AI: unggah KTM -->
  <div class="card form-card ai">
    <div class="sec-h"><div><h2>Isi otomatis dari KTM <small style="display:inline">(opsional)</small></h2></div></div>
    <p class="ai-note">Foto dikirim ke layanan AI untuk dibaca dan tidak disimpan di server ini. Periksa hasilnya sebelum mengirim.</p>
    <label class="drop" for="fotoKtm">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M14 10h4M14 14h4M6 16c.6-1.4 1.7-2 3-2s2.4.6 3 2"/></svg>
      <div><b>Pilih foto KTM</b><span>JPG, PNG, atau WEBP &middot; maks 4 MB</span></div>
      <input type="file" id="fotoKtm" accept="image/jpeg,image/png,image/webp">
    </label>
    <div id="ocrStatus" class="ocr-status" role="status" aria-live="polite" hidden></div>
  </div>

  <form method="post" action="" id="formPinjam">
    <input type="hidden" name="csrf" id="csrf" value="<?= e($_SESSION['csrf']) ?>">

    <div class="card form-card">
      <div class="sec-h"><span class="num">1</span><div><h2>Data Peminjam</h2><small>Sesuai identitas mahasiswa</small></div></div>

      <label class="field">Nama Peminjam
        <input type="text" name="nama" id="nama" required maxlength="120" value="<?= e($old['nama']) ?>">
      </label>
      <label class="field">NIM
        <input type="text" name="nim" id="nim" required maxlength="20" value="<?= e($old['nim']) ?>">
      </label>
      <label class="field">Jurusan
        <input type="text" name="jurusan" id="jurusan" required maxlength="100" value="<?= e($old['jurusan']) ?>">
      </label>
      <label class="field">No. HP
        <input type="tel" name="no_hp" required placeholder="081234567890" value="<?= e($old['no_hp']) ?>">
      </label>
    </div>

    <div class="card form-card">
      <div class="sec-h"><span class="num">2</span><div><h2>Detail Peminjaman</h2><small>Pilih alat dan jadwal pengambilan &amp; pengembalian</small></div></div>

      <label class="field">Pilihan Alat
        <select name="id_barang" id="id_barang" required>
          <option value="">-- pilih alat --</option>
          <?php foreach ($daftarBarang as $b): ?>
            <option value="<?= (int)$b['id_barang'] ?>"
                    data-foto="<?= e($b['foto_barang'] ? UPLOAD_URL . $b['foto_barang'] : '') ?>"
                    <?= $old['id_barang'] === (string)$b['id_barang'] ? 'selected' : '' ?>>
              <?= e($b['nama_barang']) ?> — <?= e($b['kode_aset']) ?>
              <?= $b['dipinjam_sampai'] ? ' (dipinjam s/d ' . e(fmt($b['dipinjam_sampai'])) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="preview" id="preview"><img id="previewImg" alt=""><small id="previewNama"></small></div>

      <span class="lbl">Tanggal &amp; Jam Pengambilan <small>(07:00–17:00)</small></span>
      <div class="row">
        <div><input type="date" name="tgl_ambil" id="tgl_ambil" required min="<?= date('Y-m-d') ?>" value="<?= e($old['tgl_ambil']) ?>"></div>
        <div><select name="jam_ambil" id="jam_ambil" required><?= opsiJam($old['jam_ambil']) ?></select></div>
      </div>

      <span class="lbl">Tanggal &amp; Jam Pengembalian <small>(07:00–17:00)</small></span>
      <div class="row">
        <div><input type="date" name="tgl_kembali" id="tgl_kembali" required min="<?= date('Y-m-d') ?>" value="<?= e($old['tgl_kembali']) ?>"></div>
        <div><select name="jam_kembali" id="jam_kembali" required><?= opsiJam($old['jam_kembali']) ?></select></div>
      </div>

      <div id="statusAlat"></div>
    </div>

    <button type="submit" class="btn block submit primary">Kirim &amp; Lanjut ke WhatsApp Aslab</button>
    <p class="hint">Pengajuan baru berstatus &ldquo;menunggu&rdquo; sampai disetujui Asisten Laboratorium.</p>
  </form>
</div>

<script>
const $ = id => document.getElementById(id);
const FIELDS = ['id_barang','tgl_ambil','jam_ambil','tgl_kembali','jam_kembali'];

/* Pratinjau foto alat terpilih */
function updatePreview() {
  const opt = $('id_barang').selectedOptions[0];
  const box = $('preview');
  if (!opt || !opt.value) { box.style.display = 'none'; return; }
  const foto = opt.dataset.foto;
  $('previewImg').style.display = foto ? 'block' : 'none';
  if (foto) $('previewImg').src = foto;
  $('previewNama').textContent = opt.textContent.trim();
  box.style.display = 'flex';
}

/* Cek ketersediaan (AJAX) */
async function cekAlat() {
  const box = $('statusAlat');
  const v = FIELDS.map(i => $(i).value);
  if (v.some(x => !x)) { box.textContent = ''; return; }
  const q = new URLSearchParams({
    action:'cek', id_barang:v[0], tgl_ambil:v[1], jam_ambil:v[2], tgl_kembali:v[3], jam_kembali:v[4]
  });
  try {
    const d = await (await fetch('?' + q)).json();
    if (!d.ok) { box.textContent = ''; return; }
    if (d.tersedia) {
      box.className = 'ok'; box.textContent = '✔ Alat tersedia pada waktu tersebut.';
    } else {
      box.className = 'no';
      box.textContent = '✖ Alat tidak tersedia. Peminjam sebelumnya mengembalikan pada ' + d.tersedia_lagi + '.';
    }
  } catch (e) { box.textContent = ''; }
}
FIELDS.forEach(i => $(i).addEventListener('change', cekAlat));
$('id_barang').addEventListener('change', updatePreview);
updatePreview();

/* Ekstraksi KTM dengan AI Vision + visual loading state */
const ocrBox  = $('ocrStatus');
const dropBox = document.querySelector('label.drop');

/* state: 'loading' | 'success' | 'error' | null (sembunyikan) */
function setOcr(state, text) {
  dropBox.classList.toggle('busy', state === 'loading');
  ocrBox.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
  if (!state) { ocrBox.hidden = true; ocrBox.replaceChildren(); return; }

  const row  = document.createElement('div');
  row.className = 'ocr-row';
  const ico  = document.createElement('span');
  ico.className = state === 'loading' ? 'spinner' : 'ocr-ico';
  if (state === 'success') ico.textContent = '✓';
  if (state === 'error')   ico.textContent = '!';
  const msg  = document.createElement('span');
  msg.textContent = text;
  row.append(ico, msg);
  ocrBox.replaceChildren(row);

  if (state === 'loading') {            /* progress bar indeterminate */
    const bar = document.createElement('span');
    bar.className = 'ocr-bar';
    bar.appendChild(document.createElement('i'));
    ocrBox.appendChild(bar);
  }
  ocrBox.className = 'ocr-status is-' + state;
  ocrBox.hidden = false;
}

$('fotoKtm').addEventListener('change', async ev => {
  const input = ev.target;
  const file = input.files[0];
  if (!file) return;

  setOcr('loading', 'Membaca KTM dengan AI… mohon tunggu sebentar.');
  const fd = new FormData();
  fd.append('foto_ktm', file);
  fd.append('csrf', $('csrf').value);
  try {
    const d = await (await fetch('?action=ocr', { method:'POST', body:fd })).json();
    if (d.ok && (d.nama || d.nim || d.jurusan)) {
      if (d.nama)    $('nama').value    = d.nama;
      if (d.nim)     $('nim').value     = d.nim;
      if (d.jurusan) $('jurusan').value = d.jurusan;
      setOcr('success', 'Terisi otomatis. Mohon periksa kembali Nama, NIM & Jurusan.');
    } else if (d.ok) {
      setOcr('error', 'Data pada KTM tidak terbaca. Coba foto yang lebih jelas atau isi manual.');
    } else {
      setOcr('error', d.pesan || 'Gagal membaca KTM. Isi manual.');
    }
  } catch (e) {
    setOcr('error', 'Gagal menghubungi server. Isi manual.');
  } finally {
    input.value = '';   /* agar file yang sama bisa dipilih ulang */
  }
});
</script>
</body>
</html>