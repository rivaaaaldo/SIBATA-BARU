-- =====================================================================
-- MIGRASI 003 (WAJIB) — Audit trail perubahan status transaksi
-- Aditif & idempoten. PHP lama tetap berjalan, tetapi perubahan status yang
-- dilakukan PHP lama TIDAK tercatat -> deploy PHP baru segera setelah migrasi ini.
-- =====================================================================
USE peminjaman_lab;

CREATE TABLE IF NOT EXISTS schema_migrations (
  versi           VARCHAR(40)  NOT NULL,
  deskripsi       VARCHAR(200) NOT NULL,
  dijalankan_pada DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (versi)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS log_status_transaksi (
  id_log        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_transaksi  INT UNSIGNED    NOT NULL,
  status_lama   ENUM('menunggu','disetujui','dipinjam','selesai','ditolak','dibatalkan') NULL DEFAULT NULL,
  status_baru   ENUM('menunggu','disetujui','dipinjam','selesai','ditolak','dibatalkan') NOT NULL,
  aktor         VARCHAR(60)     NOT NULL,
  waktu_ubah    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_log),
  KEY idx_log_transaksi (id_transaksi, waktu_ubah),
  KEY idx_log_waktu (waktu_ubah),
  -- RESTRICT: transaksi yang punya riwayat tidak bisa terhapus tanpa sengaja
  CONSTRAINT fk_log_transaksi FOREIGN KEY (id_transaksi) REFERENCES transaksi (id_transaksi)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Baseline: transaksi lama yang belum punya log dicatat sekali sebagai "NULL -> status sekarang".
-- Ini BUKAN riwayat asli (riwayat lama memang tidak pernah tersimpan); hanya titik awal audit.
INSERT INTO log_status_transaksi (id_transaksi, status_lama, status_baru, aktor, waktu_ubah)
SELECT t.id_transaksi, NULL, t.status, 'migrasi (baseline)', t.created_at
FROM transaksi t
WHERE NOT EXISTS (SELECT 1 FROM log_status_transaksi l WHERE l.id_transaksi = t.id_transaksi);

-- Verifikasi: setiap transaksi punya >= 1 log (harus 0 yang tidak punya)
SELECT COUNT(*) INTO @tanpa_log FROM transaksi t
WHERE NOT EXISTS (SELECT 1 FROM log_status_transaksi l WHERE l.id_transaksi = t.id_transaksi);
SELECT (SELECT COUNT(*) FROM transaksi) AS total_transaksi,
       (SELECT COUNT(*) FROM log_status_transaksi) AS total_log,
       @tanpa_log AS transaksi_tanpa_log_harus_0;

INSERT IGNORE INTO schema_migrations (versi, deskripsi)
SELECT '003', 'tabel log_status_transaksi + baseline' FROM DUAL WHERE @tanpa_log = 0;
