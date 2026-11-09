-- =====================================================================
-- MIGRASI 001 (WAJIB) — Pastikan CHECK (waktu_kembali > waktu_ambil) benar-benar aktif
-- Aman dijalankan berulang (idempoten). TIDAK menghapus/mengubah data.
-- Syarat: MySQL >= 8.0.16  ATAU  MariaDB >= 10.3
-- Jalankan dengan database peminjaman_lab terpilih (phpMyAdmin tab SQL / mysql CLI).
-- =====================================================================
USE peminjaman_lab;

CREATE TABLE IF NOT EXISTS schema_migrations (
  versi           VARCHAR(40)  NOT NULL,
  deskripsi       VARCHAR(200) NOT NULL,
  dijalankan_pada DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (versi)
) ENGINE=InnoDB;

-- ---- A. Pemeriksaan versi & mesin ------------------------------------
SET @base    := SUBSTRING_INDEX(@@version, '-', 1);
SET @mariadb := (@@version LIKE '%MariaDB%');
SET @ver     := SUBSTRING_INDEX(@base, '.', 1) * 10000
              + SUBSTRING_INDEX(SUBSTRING_INDEX(@base, '.', 2), '.', -1) * 100
              + SUBSTRING_INDEX(@base, '.', -1);
SET @ok      := IF(@mariadb, @ver >= 100300, @ver >= 80016);

-- ---- B. Data lama yang melanggar aturan -------------------------------
SELECT COUNT(*) INTO @invalid FROM transaksi WHERE waktu_kembali <= waktu_ambil;

-- ---- C. Apakah chk_waktu sudah terdaftar? (tabel ini tidak ada di MySQL 5.7) ----
SET @q := IF(@ok,
  "SELECT COUNT(*) INTO @ada FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_waktu'",
  "SELECT 0 INTO @ada");
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT @@version                                         AS versi_server,
       IF(@mariadb, 'MariaDB', 'MySQL')                  AS mesin,
       IF(@ok, 'MEMENUHI', 'TIDAK MEMENUHI - UPGRADE DULU') AS persyaratan_versi,
       @invalid                                          AS baris_data_tidak_valid,
       @ada                                              AS chk_waktu_sudah_ada;

-- ---- D. Tambahkan constraint hanya bila aman -----------------------------
-- Dilewati bila: versi belum memenuhi, ada data tidak valid (perbaiki manual dulu), atau sudah ada.
SET @q := IF(@ok AND @invalid = 0 AND @ada = 0,
  "ALTER TABLE transaksi ADD CONSTRAINT chk_waktu CHECK (waktu_kembali > waktu_ambil)",
  "SELECT 'ALTER dilewati (lihat tabel hasil di atas)' AS info");
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- E. Catat migrasi hanya jika syarat terpenuhi ---------------------------
INSERT IGNORE INTO schema_migrations (versi, deskripsi)
SELECT '001', 'CHECK chk_waktu dipastikan aktif' FROM DUAL WHERE @ok AND @invalid = 0;

SELECT versi, deskripsi, dijalankan_pada FROM schema_migrations ORDER BY versi;
