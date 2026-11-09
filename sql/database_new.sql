-- =====================================================
-- Skema Database v3: Peminjaman Alat & Barang Laboratorium
-- UNTUK INSTALASI BARU. Database yang sudah berjalan: pakai sql/migrations/ (JANGAN impor file ini).
-- Pelacakan unit fisik (aset unik): 1 baris barang = 1 unit fisik
-- Versi minimum: MySQL 8.0.16+  atau  MariaDB 10.3+
--   (CHECK constraint hanya DITEGAKKAN mulai versi tersebut; MySQL 5.7 mengabaikannya diam-diam.)
-- Verifikasi setelah impor:  php tools/cek_database.php
-- =====================================================

CREATE DATABASE IF NOT EXISTS peminjaman_lab
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE peminjaman_lab;

-- -----------------------------------------------------
-- Pencatat migrasi (database baru = semua migrasi sudah termasuk)
-- -----------------------------------------------------
CREATE TABLE schema_migrations (
  versi           VARCHAR(40)  NOT NULL,
  deskripsi       VARCHAR(200) NOT NULL,
  dijalankan_pada DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (versi)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- KATEGORI: satu nama = satu identitas. Collation _ci => 'Komputer' = 'komputer'
-- -----------------------------------------------------
CREATE TABLE kategori (
  id_kategori   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nama_kategori VARCHAR(60)  NOT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_kategori),
  UNIQUE KEY uq_nama_kategori (nama_kategori)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- BARANG: setiap unit fisik punya kode aset unik
-- (mis. LAPTOP-001, LAPTOP-002 adalah dua unit berbeda)
-- -----------------------------------------------------
CREATE TABLE barang (
  id_barang    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kode_aset    VARCHAR(30)  NOT NULL,            -- label/inventaris yang ditempel pada unit
  nama_barang  VARCHAR(120) NOT NULL,
  id_kategori  INT UNSIGNED DEFAULT NULL,        -- NULL = tanpa kategori
  foto_barang  VARCHAR(255) DEFAULT NULL,        -- nama file di folder uploads/
  kondisi      ENUM('baik','rusak ringan','rusak berat') NOT NULL DEFAULT 'baik',
  status_aktif TINYINT(1)   NOT NULL DEFAULT 1,  -- 0 = disembunyikan dari katalog
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_barang),
  UNIQUE KEY uq_kode_aset (kode_aset),
  KEY idx_barang_kategori (id_kategori),
  CONSTRAINT fk_barang_kategori
    FOREIGN KEY (id_kategori) REFERENCES kategori (id_kategori)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- PEMINJAM: NIM unik, dipakai ulang antar transaksi
-- -----------------------------------------------------
CREATE TABLE peminjam (
  id_peminjam INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nama        VARCHAR(120) NOT NULL,
  nim         VARCHAR(20)  NOT NULL,
  jurusan     VARCHAR(100) NOT NULL,
  no_hp       VARCHAR(20)  NOT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_peminjam),
  UNIQUE KEY uq_nim (nim)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- TRANSAKSI: peminjam <-> unit barang + rentang waktu
-- Status 'menunggu','disetujui','dipinjam' MEMBLOKIR jadwal unit.
-- -----------------------------------------------------
CREATE TABLE transaksi (
  id_transaksi  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_peminjam   INT UNSIGNED NOT NULL,
  id_barang     INT UNSIGNED NOT NULL,
  waktu_ambil   DATETIME     NOT NULL,
  waktu_kembali DATETIME     NOT NULL,
  status        ENUM('menunggu','disetujui','dipinjam','selesai','ditolak','dibatalkan')
                NOT NULL DEFAULT 'menunggu',
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_transaksi),
  KEY idx_barang_waktu (id_barang, waktu_ambil, waktu_kembali),
  KEY idx_status (status),
  CONSTRAINT fk_transaksi_peminjam
    FOREIGN KEY (id_peminjam) REFERENCES peminjam (id_peminjam)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_transaksi_barang
    FOREIGN KEY (id_barang) REFERENCES barang (id_barang)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_waktu CHECK (waktu_kembali > waktu_ambil)  -- ditegakkan MySQL 8.0.16+ / MariaDB 10.3+
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- LOG STATUS TRANSAKSI: audit trail (siapa, kapan, dari-ke status apa)
-- status_lama NULL = status awal saat transaksi dibuat
-- -----------------------------------------------------
CREATE TABLE log_status_transaksi (
  id_log        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_transaksi  INT UNSIGNED    NOT NULL,
  status_lama   ENUM('menunggu','disetujui','dipinjam','selesai','ditolak','dibatalkan') NULL DEFAULT NULL,
  status_baru   ENUM('menunggu','disetujui','dipinjam','selesai','ditolak','dibatalkan') NOT NULL,
  aktor         VARCHAR(60)     NOT NULL,
  waktu_ubah    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_log),
  KEY idx_log_transaksi (id_transaksi, waktu_ubah),
  KEY idx_log_waktu (waktu_ubah),
  CONSTRAINT fk_log_transaksi FOREIGN KEY (id_transaksi) REFERENCES transaksi (id_transaksi)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- ADMIN (KONDISIONAL): dibiarkan KOSONG -> admin.php memakai login tunggal (ADMIN_PASSWORD_HASH).
-- Multi-admin aktif otomatis setelah baris pertama dibuat (php tools/seed_admin.php ...).
-- Sistem 1 admin: tabel ini boleh diabaikan.
-- -----------------------------------------------------
CREATE TABLE admin (
  id_admin      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(40)  NOT NULL,
  nama_admin    VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status_aktif  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_admin),
  UNIQUE KEY uq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (versi, deskripsi) VALUES
  ('001', 'CHECK chk_waktu'),
  ('002', 'tabel kategori + barang.id_kategori'),
  ('003', 'tabel log_status_transaksi'),
  ('004', 'tabel admin (kondisional)'),
  ('005', 'tanpa kolom lama barang.kategori (instalasi baru)');

-- -----------------------------------------------------
-- Data contoh (opsional). Foto diunggah lewat admin.php.
-- -----------------------------------------------------
INSERT INTO kategori (nama_kategori) VALUES ('Komputer'), ('Multimedia'), ('Jaringan'), ('Elektronika');

INSERT INTO barang (kode_aset, nama_barang, id_kategori) VALUES
  ('LAPTOP-001',     'Laptop Asus A',      (SELECT id_kategori FROM kategori WHERE nama_kategori = 'Komputer')),
  ('LAPTOP-002',     'Laptop Asus A',      (SELECT id_kategori FROM kategori WHERE nama_kategori = 'Komputer')),
  ('PROYEKTOR-001',  'Proyektor Epson',    (SELECT id_kategori FROM kategori WHERE nama_kategori = 'Multimedia')),
  ('ROUTER-001',     'Router Cisco 2901',  (SELECT id_kategori FROM kategori WHERE nama_kategori = 'Jaringan')),
  ('SWITCH-001',     'Switch Cisco 2960',  (SELECT id_kategori FROM kategori WHERE nama_kategori = 'Jaringan')),
  ('MULTIMETER-001', 'Multimeter Digital', (SELECT id_kategori FROM kategori WHERE nama_kategori = 'Elektronika'));
