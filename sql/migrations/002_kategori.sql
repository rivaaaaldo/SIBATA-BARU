-- =====================================================================
-- MIGRASI 002 (WAJIB) — Tabel kategori + relasi barang.id_kategori
-- Bertahap & aditif: kolom lama barang.kategori TIDAK dihapus di sini
-- (penghapusan ada di 005, hanya setelah verifikasi). PHP lama tetap berjalan.
-- Idempoten selama kolom lama barang.kategori masih ada (sebelum 005).
-- PERLU: MySQL >= 8.0.16 / MariaDB >= 10.3 (memakai REGEXP_REPLACE & window function).
-- =====================================================================
USE peminjaman_lab;

CREATE TABLE IF NOT EXISTS schema_migrations (
  versi           VARCHAR(40)  NOT NULL,
  deskripsi       VARCHAR(200) NOT NULL,
  dijalankan_pada DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (versi)
) ENGINE=InnoDB;

-- 1. Tabel kategori. Collation *_unicode_ci => 'Komputer' = 'komputer' (unik tanpa peduli huruf besar/kecil)
--    dan spasi di ujung diabaikan. Spasi ganda/depan dinormalisasi oleh migrasi ini & oleh aplikasi.
CREATE TABLE IF NOT EXISTS kategori (
  id_kategori   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nama_kategori VARCHAR(60)  NOT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_kategori),
  UNIQUE KEY uq_nama_kategori (nama_kategori)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Kolom relasi di barang (nullable: barang boleh tanpa kategori)
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'id_kategori') = 0,
  "ALTER TABLE barang ADD COLUMN id_kategori INT UNSIGNED NULL AFTER kategori, ADD KEY idx_barang_kategori (id_kategori)",
  "SELECT 'kolom id_kategori sudah ada' AS info");
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Kumpulkan nama unik (trim + ratakan spasi ganda). Bila ada varian huruf
--    (komputer/Komputer/KOMPUTER) dipakai penulisan yang PALING SERING muncul.
INSERT INTO kategori (nama_kategori)
SELECT z.nama
FROM (
  SELECT v.nama,
         ROW_NUMBER() OVER (PARTITION BY v.nama ORDER BY v.jml DESC, BINARY v.nama) AS rn
  FROM (
    SELECT MIN(x.nama) AS nama, COUNT(*) AS jml
    FROM (SELECT REGEXP_REPLACE(TRIM(kategori), '[[:space:]]+', ' ') AS nama
          FROM barang WHERE kategori IS NOT NULL) x
    WHERE x.nama <> ''
    GROUP BY BINARY x.nama
  ) v
) z
WHERE z.rn = 1
  AND NOT EXISTS (SELECT 1 FROM kategori k WHERE k.nama_kategori = z.nama COLLATE utf8mb4_unicode_ci)
ORDER BY z.nama;

-- 4. Hubungkan setiap barang ke id_kategori
UPDATE barang b
JOIN kategori k
  ON k.nama_kategori = REGEXP_REPLACE(TRIM(b.kategori), '[[:space:]]+', ' ') COLLATE utf8mb4_unicode_ci
SET b.id_kategori = k.id_kategori
WHERE b.kategori IS NOT NULL AND b.id_kategori IS NULL;

-- 5. Foreign key (RESTRICT: kategori yang dipakai barang tidak bisa dihapus)
SET @q := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'barang'
                AND CONSTRAINT_NAME = 'fk_barang_kategori') = 0,
  "ALTER TABLE barang ADD CONSTRAINT fk_barang_kategori FOREIGN KEY (id_kategori) REFERENCES kategori (id_kategori) ON UPDATE CASCADE ON DELETE RESTRICT",
  "SELECT 'FK fk_barang_kategori sudah ada' AS info");
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 6. VERIFIKASI — barang yang punya kategori lama (tidak kosong) tetapi belum terhubung HARUS 0
SELECT COUNT(*) INTO @belum_terhubung
FROM barang WHERE kategori IS NOT NULL AND TRIM(kategori) <> '' AND id_kategori IS NULL;

SELECT (SELECT COUNT(*) FROM kategori)                      AS jumlah_kategori,
       (SELECT COUNT(*) FROM barang)                        AS total_barang,
       (SELECT COUNT(*) FROM barang WHERE id_kategori IS NOT NULL) AS barang_berkategori,
       @belum_terhubung                                     AS barang_belum_terhubung_harus_0;

INSERT IGNORE INTO schema_migrations (versi, deskripsi)
SELECT '002', 'tabel kategori + barang.id_kategori (kolom lama dipertahankan)' FROM DUAL WHERE @belum_terhubung = 0;

SELECT k.id_kategori, k.nama_kategori, COUNT(b.id_barang) AS jumlah_barang
FROM kategori k LEFT JOIN barang b ON b.id_kategori = k.id_kategori
GROUP BY k.id_kategori, k.nama_kategori ORDER BY k.nama_kategori;
