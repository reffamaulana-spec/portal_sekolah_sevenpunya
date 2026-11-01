<?php
function catatAktivitas($conn, $userId, $nama, $role, $aksi, $detail)
{
    try {
        $stmt = mysqli_prepare($conn, "INSERT INTO log_aktivitas (user_id, nama, role, aksi, detail) VALUES (?, ?, ?, ?, ?)");
        $nama   = mb_substr((string)$nama, 0, 100);
        $detail = mb_substr((string)$detail, 0, 255);
        mysqli_stmt_bind_param($stmt, "issss", $userId, $nama, $role, $aksi, $detail);
        mysqli_stmt_execute($stmt);
    } catch (Throwable $ex) {
    }
}

function daftarKelas($conn)
{
    try {
        $hasil = mysqli_query($conn, "SELECT nama FROM daftar_kelas ORDER BY nama ASC");
        return array_column(mysqli_fetch_all($hasil, MYSQLI_ASSOC), 'nama');
    } catch (Throwable $ex) {
        return [];
    }
}

function daftarMapel($conn)
{
    try {
        $hasil = mysqli_query($conn, "SELECT nama FROM daftar_mapel ORDER BY nama ASC");
        return array_column(mysqli_fetch_all($hasil, MYSQLI_ASSOC), 'nama');
    } catch (Throwable $ex) {
        return [];
    }
}

function profilPengguna($conn, $userId)
{
    $stmt = mysqli_prepare($conn, "SELECT id, nama, username, email, role, kelas, mapel FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $baris = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $baris ?: null;
}

function jalankanAman($conn, $sql)
{
    try {
        return mysqli_query($conn, $sql) !== false;
    } catch (Throwable $ex) {
        return false;
    }
}

function pastikanSkema($conn)
{
    if (!empty($_SESSION['skema_v3'])) {
        return;
    }

    $tabel = [
        "CREATE TABLE IF NOT EXISTS tugas (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, judul VARCHAR(150) NOT NULL, mapel VARCHAR(80) NOT NULL DEFAULT '', deadline DATE NOT NULL, prioritas ENUM('rendah','sedang','tinggi') NOT NULL DEFAULT 'sedang', selesai TINYINT(1) NOT NULL DEFAULT 0, dibuat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_tugas_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS jadwal (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, hari VARCHAR(10) NOT NULL, mapel VARCHAR(80) NOT NULL, jam_mulai TIME NOT NULL, jam_selesai TIME NOT NULL, ruang VARCHAR(50) NOT NULL DEFAULT '', INDEX idx_jadwal_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS catatan (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, judul VARCHAR(100) NOT NULL, isi TEXT NOT NULL, warna VARCHAR(10) NOT NULL DEFAULT 'kuning', dibuat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_catatan_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS nilai (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, mapel VARCHAR(80) NOT NULL, jenis VARCHAR(20) NOT NULL, nilai DECIMAL(5,2) NOT NULL, dibuat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_nilai_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS jadwal_guru (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, hari VARCHAR(10) NOT NULL, mapel VARCHAR(80) NOT NULL, kelas VARCHAR(40) NOT NULL DEFAULT '', jam_mulai TIME NOT NULL, jam_selesai TIME NOT NULL, ruang VARCHAR(50) NOT NULL DEFAULT '', INDEX idx_jadwal_guru_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS agenda_guru (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, judul VARCHAR(150) NOT NULL, kategori VARCHAR(20) NOT NULL DEFAULT 'Mengajar', tanggal DATE NOT NULL, selesai TINYINT(1) NOT NULL DEFAULT 0, dibuat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_agenda_guru_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS catatan_guru (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, judul VARCHAR(100) NOT NULL, isi TEXT NOT NULL, warna VARCHAR(10) NOT NULL DEFAULT 'kuning', dibuat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_catatan_guru_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS absensi (id INT AUTO_INCREMENT PRIMARY KEY, guru_id INT NOT NULL, siswa_id INT NOT NULL, tanggal DATE NOT NULL, status CHAR(1) NOT NULL, UNIQUE KEY uniq_absensi (guru_id, siswa_id, tanggal), INDEX idx_absensi_siswa (siswa_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS daftar_kelas (id INT AUTO_INCREMENT PRIMARY KEY, nama VARCHAR(30) NOT NULL, UNIQUE KEY uniq_daftar_kelas (nama)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS daftar_mapel (id INT AUTO_INCREMENT PRIMARY KEY, nama VARCHAR(80) NOT NULL, UNIQUE KEY uniq_daftar_mapel (nama)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS tugas_kelas (id INT AUTO_INCREMENT PRIMARY KEY, guru_id INT NOT NULL, kelas VARCHAR(30) NOT NULL, mapel VARCHAR(80) NOT NULL DEFAULT '', judul VARCHAR(150) NOT NULL, deskripsi TEXT NOT NULL, deadline DATE NOT NULL, dibuat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_tugas_kelas_guru (guru_id), INDEX idx_tugas_kelas_kelas (kelas)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS tugas_selesai (tugas_id INT NOT NULL, siswa_id INT NOT NULL, dibuat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (tugas_id, siswa_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS pengumpulan (id INT AUTO_INCREMENT PRIMARY KEY, tugas_id INT NOT NULL, siswa_id INT NOT NULL, jawaban TEXT NOT NULL, tautan VARCHAR(255) NOT NULL DEFAULT '', dikirim DATETIME NOT NULL, nilai DECIMAL(5,2) NULL DEFAULT NULL, catatan VARCHAR(500) NOT NULL DEFAULT '', dinilai DATETIME NULL DEFAULT NULL, UNIQUE KEY uniq_pengumpulan (tugas_id, siswa_id), INDEX idx_pengumpulan_siswa (siswa_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS log_aktivitas (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, nama VARCHAR(100) NOT NULL DEFAULT '', role VARCHAR(10) NOT NULL DEFAULT '', aksi VARCHAR(40) NOT NULL, detail VARCHAR(255) NOT NULL DEFAULT '', dibuat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_log_aktivitas_user (user_id), INDEX idx_log_aktivitas_waktu (dibuat)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
    foreach ($tabel as $sql) {
        jalankanAman($conn, $sql);
    }

    $tambahan = [
        ['users', 'kelas', 'VARCHAR(30) NULL DEFAULT NULL'],
        ['users', 'mapel', 'VARCHAR(80) NULL DEFAULT NULL'],
        ['tugas_kelas', 'jam_batas', "VARCHAR(5) NOT NULL DEFAULT '23:59'"],
        ['nilai', 'guru_id', 'INT NULL DEFAULT NULL'],
        ['nilai', 'pengumpulan_id', 'INT NULL DEFAULT NULL']
    ];
    foreach ($tambahan as [$tbl, $kolom, $tipe]) {
        if (!jalankanAman($conn, "SELECT $kolom FROM $tbl LIMIT 1")) {
            jalankanAman($conn, "ALTER TABLE $tbl ADD COLUMN $kolom $tipe");
        }
    }

    try {
        $hasil = mysqli_query($conn, "SELECT COUNT(*) AS n FROM daftar_kelas");
        $baris = mysqli_fetch_assoc($hasil);
        if ((int)($baris['n'] ?? 0) === 0) {
            jalankanAman($conn, "INSERT IGNORE INTO daftar_kelas (nama) VALUES ('X-A'), ('X-B'), ('XI-A'), ('XI-B'), ('XII-A'), ('XII-B')");
        }
        $hasil = mysqli_query($conn, "SELECT COUNT(*) AS n FROM daftar_mapel");
        $baris = mysqli_fetch_assoc($hasil);
        if ((int)($baris['n'] ?? 0) === 0) {
            jalankanAman($conn, "INSERT IGNORE INTO daftar_mapel (nama) VALUES ('Matematika'), ('Bahasa Indonesia'), ('Bahasa Inggris'), ('IPA'), ('IPS'), ('Informatika'), ('PPKn'), ('Pendidikan Agama'), ('Seni Budaya'), ('PJOK')");
        }
    } catch (Throwable $ex) {
    }

    $_SESSION['skema_v3'] = true;
}

if (isset($conn) && session_status() === PHP_SESSION_ACTIVE) {
    pastikanSkema($conn);
}


function batasPengumpulan($deadline, $jamBatas)
{
    $jam = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$jamBatas) ? $jamBatas : '23:59';
    return $deadline . ' ' . $jam . ':00';
}

function pengumpulanDitutup($deadline, $jamBatas)
{
    return date('Y-m-d H:i:s') > batasPengumpulan($deadline, $jamBatas);
}
