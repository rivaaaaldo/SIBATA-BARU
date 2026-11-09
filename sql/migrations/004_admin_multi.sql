-- =====================================================================
-- MIGRASI 004 (KONDISIONAL) — Tabel admin untuk multi-admin
-- Jalankan HANYA jika website akan dikelola lebih dari satu orang.
-- Sistem 1 admin: lewati file ini; login tetap memakai ADMIN_PASSWORD_HASH.
--
-- Membuat tabel KOSONG. Selama tabel kosong, admin.php tetap memakai login lama.
-- Login berpindah otomatis ke mode multi-admin setelah ada baris pertama, yang dibuat
-- dengan:  php tools/seed_admin.php <username> "<Nama Lengkap>"
-- (menyalin hash password lama dari konfigurasi privat; password tidak pernah ditampilkan)
-- =====================================================================
USE peminjaman_lab;

CREATE TABLE IF NOT EXISTS schema_migrations (
  versi           VARCHAR(40)  NOT NULL,
  deskripsi       VARCHAR(200) NOT NULL,
  dijalankan_pada DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (versi)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin (
  id_admin      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(40)  NOT NULL,
  nama_admin    VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,           -- hasil password_hash(), BUKAN teks biasa
  status_aktif  TINYINT(1)   NOT NULL DEFAULT 1, -- 0 = akun dinonaktifkan (tidak bisa login)
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_admin),
  UNIQUE KEY uq_admin_username (username)        -- collation _ci: 'Budi' = 'budi'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (versi, deskripsi)
VALUES ('004', 'tabel admin (multi-admin, kondisional)');

SELECT COUNT(*) AS jumlah_admin_di_tabel FROM admin;
