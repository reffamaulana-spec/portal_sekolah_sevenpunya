<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once '../config/koneksi.php';
require_once '../config/helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

function selesai($bagian, $tipe, $teks) {
    $_SESSION['flash'] = ['tipe' => $tipe, 'teks' => $teks];
    header("Location: dashboard.php#" . $bagian);
    exit;
}

function bersih($teks, $maks) {
    return mb_substr(trim((string)$teks), 0, $maks);
}

function jalankan($conn, $sql, $tipe, ...$param) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $tipe, ...$param);
    return mysqli_stmt_execute($stmt);
}

function ambilSatu($conn, $sql, $tipe, ...$param) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $tipe, ...$param);
    mysqli_stmt_execute($stmt);
    $baris = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $baris ?: null;
}

function catat($conn, $adminId, $aksi, $detail) {
    catatAktivitas($conn, $adminId, $_SESSION['nama'] ?? '', 'admin', $aksi, $detail);
}

function bersihkanData($conn, $id) {
    $tabel = ['tugas', 'jadwal', 'catatan', 'nilai', 'jadwal_guru', 'agenda_guru', 'catatan_guru'];
    try {
        jalankan($conn, "DELETE FROM nilai WHERE pengumpulan_id IN (SELECT id FROM pengumpulan WHERE siswa_id = ? OR tugas_id IN (SELECT id FROM tugas_kelas WHERE guru_id = ?))", "ii", $id, $id);
        jalankan($conn, "DELETE FROM pengumpulan WHERE siswa_id = ? OR tugas_id IN (SELECT id FROM tugas_kelas WHERE guru_id = ?)", "ii", $id, $id);
        jalankan($conn, "DELETE FROM nilai WHERE guru_id = ?", "i", $id);
        jalankan($conn, "DELETE FROM tugas_selesai WHERE siswa_id = ? OR tugas_id IN (SELECT id FROM tugas_kelas WHERE guru_id = ?)", "ii", $id, $id);
        jalankan($conn, "DELETE FROM tugas_kelas WHERE guru_id = ?", "i", $id);
    } catch (Throwable $ex) {
    }
    foreach ($tabel as $t) {
        try {
            jalankan($conn, "DELETE FROM " . $t . " WHERE user_id = ?", "i", $id);
        } catch (Throwable $ex) {
        }
    }
    try {
        jalankan($conn, "DELETE FROM absensi WHERE guru_id = ? OR siswa_id = ?", "ii", $id, $id);
    } catch (Throwable $ex) {
    }
}

if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    selesai('beranda', 'err', 'Sesi tidak valid, silakan coba lagi');
}

$uid  = (int)$_SESSION['id'];
$aksi = $_POST['aksi'] ?? '';
$id   = (int)($_POST['id'] ?? 0);

$roleValid = ['murid', 'guru', 'admin'];

try {
    switch ($aksi) {

        case 'tambah_user':
            $nama     = bersih($_POST['nama'] ?? '', 100);
            $username = bersih($_POST['username'] ?? '', 50);
            $email    = bersih($_POST['email'] ?? '', 100);
            $password = $_POST['password'] ?? '';
            $role     = $_POST['role'] ?? '';
            $kelasIn  = bersih($_POST['kelas'] ?? '', 30);
            $mapelIn  = bersih($_POST['mapel'] ?? '', 80);

            if ($nama === '' || $username === '' || $email === '') {
                selesai('pengguna', 'err', 'Nama, username, dan email wajib diisi');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                selesai('pengguna', 'err', 'Format email tidak valid');
            }
            if (strlen($password) < 6) {
                selesai('pengguna', 'err', 'Password minimal 6 karakter');
            }
            if (!in_array($role, $roleValid, true)) {
                selesai('pengguna', 'err', 'Role tidak valid');
            }
            if (ambilSatu($conn, "SELECT id FROM users WHERE username = ? OR email = ?", "ss", $username, $email)) {
                selesai('pengguna', 'err', 'Username atau email sudah terdaftar');
            }

            $kelasDb = null;
            $mapelDb = null;
            if ($role === 'murid' && in_array($kelasIn, daftarKelas($conn), true)) {
                $kelasDb = $kelasIn;
            }
            if ($role === 'guru' && in_array($mapelIn, daftarMapel($conn), true)) {
                $mapelDb = $mapelIn;
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            if (jalankan($conn, "INSERT INTO users (nama, username, email, password, role, kelas, mapel) VALUES (?, ?, ?, ?, ?, ?, ?)", "sssssss", $nama, $username, $email, $hash, $role, $kelasDb, $mapelDb)) {
                catat($conn, $uid, 'tambah_user', 'Menambah akun ' . $username . ' sebagai ' . $role . ($kelasDb ? ' kelas ' . $kelasDb : '') . ($mapelDb ? ' mapel ' . $mapelDb : ''));
                selesai('pengguna', 'ok', 'Pengguna ' . $username . ' berhasil ditambahkan');
            }
            selesai('pengguna', 'err', 'Pengguna gagal disimpan');

        case 'ubah_role':
            $role = $_POST['role'] ?? '';
            if (!in_array($role, $roleValid, true)) {
                selesai('pengguna', 'err', 'Role tidak valid');
            }
            if ($id === $uid) {
                selesai('pengguna', 'err', 'Kamu tidak bisa mengubah role akunmu sendiri');
            }
            $target = ambilSatu($conn, "SELECT username, role FROM users WHERE id = ?", "i", $id);
            if (!$target) {
                selesai('pengguna', 'err', 'Pengguna tidak ditemukan');
            }
            if ($target['role'] === $role) {
                selesai('pengguna', 'ok', 'Role ' . $target['username'] . ' tidak berubah');
            }
            jalankan($conn, "UPDATE users SET role = ?, kelas = NULL, mapel = NULL WHERE id = ?", "si", $role, $id);
            catat($conn, $uid, 'ubah_role', 'Mengubah role ' . $target['username'] . ' dari ' . $target['role'] . ' menjadi ' . $role);
            selesai('pengguna', 'ok', 'Role ' . $target['username'] . ' diubah menjadi ' . $role);

        case 'ubah_penempatan':
            $target = ambilSatu($conn, "SELECT username, role FROM users WHERE id = ?", "i", $id);
            if (!$target) {
                selesai('pengguna', 'err', 'Pengguna tidak ditemukan');
            }
            if ($target['role'] === 'murid') {
                $kelas = bersih($_POST['kelas'] ?? '', 30);
                if (!in_array($kelas, daftarKelas($conn), true)) {
                    selesai('pengguna', 'err', 'Pilih kelas yang tersedia');
                }
                jalankan($conn, "UPDATE users SET kelas = ? WHERE id = ?", "si", $kelas, $id);
                catat($conn, $uid, 'ubah_penempatan', 'Memindahkan ' . $target['username'] . ' ke kelas ' . $kelas);
                selesai('pengguna', 'ok', $target['username'] . ' sekarang di kelas ' . $kelas);
            }
            if ($target['role'] === 'guru') {
                $mapel = bersih($_POST['mapel'] ?? '', 80);
                if (!in_array($mapel, daftarMapel($conn), true)) {
                    selesai('pengguna', 'err', 'Pilih mata pelajaran yang tersedia');
                }
                jalankan($conn, "UPDATE users SET mapel = ? WHERE id = ?", "si", $mapel, $id);
                catat($conn, $uid, 'ubah_penempatan', 'Mengatur mapel ' . $target['username'] . ' menjadi ' . $mapel);
                selesai('pengguna', 'ok', 'Mapel ' . $target['username'] . ' diatur ke ' . $mapel);
            }
            selesai('pengguna', 'err', 'Admin tidak memiliki kelas atau mapel');

        case 'tambah_kelas':
            $nama = bersih($_POST['nama'] ?? '', 30);
            if ($nama === '') {
                selesai('master', 'err', 'Nama kelas wajib diisi');
            }
            if (ambilSatu($conn, "SELECT id FROM daftar_kelas WHERE nama = ?", "s", $nama)) {
                selesai('master', 'err', 'Kelas ' . $nama . ' sudah ada');
            }
            jalankan($conn, "INSERT INTO daftar_kelas (nama) VALUES (?)", "s", $nama);
            catat($conn, $uid, 'tambah_kelas', 'Menambah kelas ' . $nama);
            selesai('master', 'ok', 'Kelas ' . $nama . ' ditambahkan');

        case 'hapus_kelas':
            $nama = bersih($_POST['nama'] ?? '', 30);
            $dipakai = ambilSatu($conn, "SELECT (SELECT COUNT(*) FROM users WHERE kelas = ?) + (SELECT COUNT(*) FROM jadwal_guru WHERE kelas = ?) + (SELECT COUNT(*) FROM tugas_kelas WHERE kelas = ?) AS n", "sss", $nama, $nama, $nama);
            if ($dipakai && (int)$dipakai['n'] > 0) {
                selesai('master', 'err', 'Kelas ' . $nama . ' masih dipakai siswa, jadwal, atau tugas');
            }
            jalankan($conn, "DELETE FROM daftar_kelas WHERE nama = ?", "s", $nama);
            catat($conn, $uid, 'hapus_kelas', 'Menghapus kelas ' . $nama);
            selesai('master', 'ok', 'Kelas ' . $nama . ' dihapus');

        case 'tambah_mapel':
            $nama = bersih($_POST['nama'] ?? '', 80);
            if ($nama === '') {
                selesai('master', 'err', 'Nama mata pelajaran wajib diisi');
            }
            if (ambilSatu($conn, "SELECT id FROM daftar_mapel WHERE nama = ?", "s", $nama)) {
                selesai('master', 'err', 'Mata pelajaran ' . $nama . ' sudah ada');
            }
            jalankan($conn, "INSERT INTO daftar_mapel (nama) VALUES (?)", "s", $nama);
            catat($conn, $uid, 'tambah_mapel', 'Menambah mata pelajaran ' . $nama);
            selesai('master', 'ok', 'Mata pelajaran ' . $nama . ' ditambahkan');

        case 'hapus_mapel':
            $nama = bersih($_POST['nama'] ?? '', 80);
            $dipakai = ambilSatu($conn, "SELECT COUNT(*) AS n FROM users WHERE mapel = ?", "s", $nama);
            if ($dipakai && (int)$dipakai['n'] > 0) {
                selesai('master', 'err', 'Mata pelajaran ' . $nama . ' masih dipilih guru');
            }
            jalankan($conn, "DELETE FROM daftar_mapel WHERE nama = ?", "s", $nama);
            catat($conn, $uid, 'hapus_mapel', 'Menghapus mata pelajaran ' . $nama);
            selesai('master', 'ok', 'Mata pelajaran ' . $nama . ' dihapus');

        case 'reset_password':
            $baru = $_POST['password_baru'] ?? '';
            if (strlen($baru) < 6) {
                selesai('pengguna', 'err', 'Password baru minimal 6 karakter');
            }
            $target = ambilSatu($conn, "SELECT username FROM users WHERE id = ?", "i", $id);
            if (!$target) {
                selesai('pengguna', 'err', 'Pengguna tidak ditemukan');
            }
            $hash = password_hash($baru, PASSWORD_DEFAULT);
            jalankan($conn, "UPDATE users SET password = ? WHERE id = ?", "si", $hash, $id);
            catat($conn, $uid, 'reset_password', 'Mereset password ' . $target['username']);
            selesai('pengguna', 'ok', 'Password ' . $target['username'] . ' berhasil direset');

        case 'hapus_user':
            if ($id === $uid) {
                selesai('pengguna', 'err', 'Kamu tidak bisa menghapus akunmu sendiri');
            }
            $target = ambilSatu($conn, "SELECT username FROM users WHERE id = ?", "i", $id);
            if (!$target) {
                selesai('pengguna', 'err', 'Pengguna tidak ditemukan');
            }
            bersihkanData($conn, $id);
            jalankan($conn, "DELETE FROM users WHERE id = ?", "i", $id);
            catat($conn, $uid, 'hapus_user', 'Menghapus akun ' . $target['username']);
            selesai('pengguna', 'ok', 'Pengguna ' . $target['username'] . ' dihapus');

        case 'ganti_password':
            $lama       = $_POST['password_lama'] ?? '';
            $baru       = $_POST['password_baru'] ?? '';
            $konfirmasi = $_POST['konfirmasi'] ?? '';

            if (strlen($baru) < 6) {
                selesai('akun', 'err', 'Password baru minimal 6 karakter');
            }
            if ($baru !== $konfirmasi) {
                selesai('akun', 'err', 'Konfirmasi password tidak cocok');
            }
            $saya = ambilSatu($conn, "SELECT password FROM users WHERE id = ?", "i", $uid);
            if (!$saya || !password_verify($lama, $saya['password'])) {
                selesai('akun', 'err', 'Password lama salah');
            }
            $hash = password_hash($baru, PASSWORD_DEFAULT);
            jalankan($conn, "UPDATE users SET password = ? WHERE id = ?", "si", $hash, $uid);
            catat($conn, $uid, 'ganti_password', 'Mengganti password akun sendiri');
            selesai('akun', 'ok', 'Password berhasil diganti');

        default:
            selesai('beranda', 'err', 'Aksi tidak dikenal');
    }
} catch (mysqli_sql_exception $ex) {
    selesai('beranda', 'err', 'Terjadi kesalahan pada database');
}
