<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once '../config/koneksi.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'murid') {
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

function tanggalValid($tgl) {
    $d = DateTime::createFromFormat('Y-m-d', $tgl);
    return $d && $d->format('Y-m-d') === $tgl;
}

function jamValid($jam) {
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $jam);
}

function jalankan($conn, $sql, $tipe, ...$param) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $tipe, ...$param);
    return mysqli_stmt_execute($stmt);
}

if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    selesai('beranda', 'err', 'Sesi tidak valid, silakan coba lagi');
}

$uid  = (int)$_SESSION['id'];
$aksi = $_POST['aksi'] ?? '';
$id   = (int)($_POST['id'] ?? 0);

$hariValid   = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
$prioValid   = ['rendah', 'sedang', 'tinggi'];
$warnaValid  = ['kuning', 'pink', 'biru', 'hijau', 'ungu'];
$jenisValid  = ['Tugas', 'Kuis', 'UTS', 'UAS', 'Praktik'];

switch ($aksi) {

    case 'tambah_tugas':
        $judul    = bersih($_POST['judul'] ?? '', 150);
        $mapel    = bersih($_POST['mapel'] ?? '', 80);
        $deadline = $_POST['deadline'] ?? '';
        $prio     = $_POST['prioritas'] ?? 'sedang';

        if ($judul === '' || !tanggalValid($deadline)) {
            selesai('tugas', 'err', 'Judul dan tanggal deadline wajib diisi');
        }
        if (!in_array($prio, $prioValid, true)) {
            $prio = 'sedang';
        }
        if (jalankan($conn, "INSERT INTO tugas (user_id, judul, mapel, deadline, prioritas) VALUES (?, ?, ?, ?, ?)", "issss", $uid, $judul, $mapel, $deadline, $prio)) {
            selesai('tugas', 'ok', 'Tugas berhasil ditambahkan');
        }
        selesai('tugas', 'err', 'Tugas gagal disimpan');

    case 'toggle_tugas':
        jalankan($conn, "UPDATE tugas SET selesai = 1 - selesai WHERE id = ? AND user_id = ?", "ii", $id, $uid);
        selesai('tugas', 'ok', 'Status tugas diperbarui');

    case 'hapus_tugas':
        jalankan($conn, "DELETE FROM tugas WHERE id = ? AND user_id = ?", "ii", $id, $uid);
        selesai('tugas', 'ok', 'Tugas dihapus');

    case 'tambah_jadwal':
        $hari  = $_POST['hari'] ?? '';
        $mapel = bersih($_POST['mapel'] ?? '', 80);
        $mulai = $_POST['jam_mulai'] ?? '';
        $akhir = $_POST['jam_selesai'] ?? '';
        $ruang = bersih($_POST['ruang'] ?? '', 50);

        if (!in_array($hari, $hariValid, true) || $mapel === '' || !jamValid($mulai) || !jamValid($akhir)) {
            selesai('jadwal', 'err', 'Lengkapi hari, mata pelajaran, dan jam');
        }
        if ($akhir <= $mulai) {
            selesai('jadwal', 'err', 'Jam selesai harus lebih besar dari jam mulai');
        }
        if (jalankan($conn, "INSERT INTO jadwal (user_id, hari, mapel, jam_mulai, jam_selesai, ruang) VALUES (?, ?, ?, ?, ?, ?)", "isssss", $uid, $hari, $mapel, $mulai, $akhir, $ruang)) {
            selesai('jadwal', 'ok', 'Jadwal berhasil ditambahkan');
        }
        selesai('jadwal', 'err', 'Jadwal gagal disimpan');

    case 'hapus_jadwal':
        jalankan($conn, "DELETE FROM jadwal WHERE id = ? AND user_id = ?", "ii", $id, $uid);
        selesai('jadwal', 'ok', 'Jadwal dihapus');

    case 'tambah_catatan':
        $judul = bersih($_POST['judul'] ?? '', 100);
        $isi   = bersih($_POST['isi'] ?? '', 2000);
        $warna = $_POST['warna'] ?? 'kuning';

        if ($judul === '' && $isi === '') {
            selesai('catatan', 'err', 'Catatan tidak boleh kosong');
        }
        if ($judul === '') {
            $judul = 'Catatan';
        }
        if (!in_array($warna, $warnaValid, true)) {
            $warna = 'kuning';
        }
        if (jalankan($conn, "INSERT INTO catatan (user_id, judul, isi, warna) VALUES (?, ?, ?, ?)", "isss", $uid, $judul, $isi, $warna)) {
            selesai('catatan', 'ok', 'Catatan tersimpan');
        }
        selesai('catatan', 'err', 'Catatan gagal disimpan');

    case 'hapus_catatan':
        jalankan($conn, "DELETE FROM catatan WHERE id = ? AND user_id = ?", "ii", $id, $uid);
        selesai('catatan', 'ok', 'Catatan dihapus');

    case 'tambah_nilai':
        $mapel = bersih($_POST['mapel'] ?? '', 80);
        $jenis = $_POST['jenis'] ?? '';
        $angka = str_replace(',', '.', trim($_POST['nilai'] ?? ''));

        if ($mapel === '' || !in_array($jenis, $jenisValid, true) || !is_numeric($angka)) {
            selesai('nilai', 'err', 'Lengkapi mata pelajaran, jenis, dan nilai');
        }
        $angka = (float)$angka;
        if ($angka < 0 || $angka > 100) {
            selesai('nilai', 'err', 'Nilai harus antara 0 sampai 100');
        }
        if (jalankan($conn, "INSERT INTO nilai (user_id, mapel, jenis, nilai) VALUES (?, ?, ?, ?)", "issd", $uid, $mapel, $jenis, $angka)) {
            selesai('nilai', 'ok', 'Nilai berhasil ditambahkan');
        }
        selesai('nilai', 'err', 'Nilai gagal disimpan');

    case 'hapus_nilai':
        jalankan($conn, "DELETE FROM nilai WHERE id = ? AND user_id = ?", "ii", $id, $uid);
        selesai('nilai', 'ok', 'Nilai dihapus');

    default:
        selesai('beranda', 'err', 'Aksi tidak dikenal');
}