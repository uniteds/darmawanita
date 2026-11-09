CREATE TABLE IF NOT EXISTS absensi (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kunci_form BINARY(32) NOT NULL,
    nama_lengkap VARCHAR(120) NOT NULL,
    utusan_opd VARCHAR(150) NOT NULL,
    no_whatsapp VARCHAR(16) NOT NULL,
    nama_suami VARCHAR(120) NOT NULL,
    waktu_hadir DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY absensi_kunci_form (kunci_form)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
