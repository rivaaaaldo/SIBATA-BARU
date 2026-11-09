-- =====================================================================
-- MIGRASI 005 (OPSIONAL, FINAL) — Hapus kolom lama barang.kategori
-- JANGAN dijalankan sebelum:
--   1. migrasi 002 selesai & terverifikasi,
--   2. PHP versi baru sudah dipakai beberapa hari tanpa masalah (tidak lagi membaca kolom lama),
--   3. backup database terbaru sudah dibuat.
-- Otomatis BERHENTI (tidak menghapus apa pun) bila ada barang yang belum terhubung ke kategori.
-- =====================================================================
USE peminjaman_lab;

SELECT COUNT(*) INTO @m002 FROM schema_migrations WHERE versi = '002';
SELECT COUNT(*) INTO @punya_lama FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'kategori';

SET @q := IF(@punya_lama > 0,
  "SELECT COUNT(*) INTO @belum FROM barang WHERE kategori IS NOT NULL AND TRIM(kategori) <> '' AND id_kategori IS NULL",
  "SELECT 0 INTO @belum");
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT @m002 AS migrasi_002_tercatat, @punya_lama AS kolom_lama_masih_ada, @belum AS barang_belum_terhubung_harus_0;

SET @q := IF(@m002 = 1 AND @punya_lama > 0 AND @belum = 0,
  "ALTER TABLE barang DROP COLUMN kategori",
  "SELECT 'DIBATALKAN / tidak ada yang dihapus (lihat tabel di atas)' AS info");
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

INSERT IGNORE INTO schema_migrations (versi, deskripsi)
SELECT '005', 'kolom lama barang.kategori dihapus' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'kategori');
