<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once '../config/koneksi.php';
require_once '../config/helper.php';

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

function ambilSatu($conn, $sql, $tipe, ...$param) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $tipe, ...$param);
    mysqli_stmt_execute($stmt);
    $baris = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $baris ?: null;
}

function lapor($conn, $aksi, $detail) {
    catatAktivitas($conn, (int)$_SESSION['id'], $_SESSION['nama'] ?? '', 'murid', $aksi, $detail);
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

    case 'kirim_tugas':
        $profil    = profilPengguna($conn, $uid);
        $kelasSaya = trim((string)($profil['kelas'] ?? ''));
        $jawaban   = bersih($_POST['jawaban'] ?? '', 3000);
        $tautan    = bersih($_POST['tautan'] ?? '', 255);

        $target = ambilSatu($conn, "SELECT id, judul, kelas, deadline, jam_batas FROM tugas_kelas WHERE id = ?", "i", $id);
        if (!$target || $kelasSaya === '' || $target['kelas'] !== $kelasSaya) {
            selesai('tugas', 'err', 'Tugas tidak ditemukan untuk kelasmu');
        }
        if ($jawaban === '' && $tautan === '') {
            selesai('tugas', 'err', 'Isi jawaban atau tautan hasil kerjamu');
        }
        if ($tautan !== '' && !preg_match('#^https?://[^\s]+$#i', $tautan)) {
            selesai('tugas', 'err', 'Tautan harus diawali http:// atau https://');
        }
        if (pengumpulanDitutup($target['deadline'], $target['jam_batas'])) {
            selesai('tugas', 'err', 'Batas pengumpulan sudah lewat, tugas ini sudah ditutup');
        }

        $lama     = ambilSatu($conn, "SELECT id, nilai FROM pengumpulan WHERE tugas_id = ? AND siswa_id = ?", "ii", $id, $uid);
        $sekarang = date('Y-m-d H:i:s');
        if ($lama) {
            if ($lama['nilai'] !== null) {
                selesai('tugas', 'err', 'Tugas ini sudah dinilai guru dan tidak bisa diubah lagi');
            }
            jalankan($conn, "UPDATE pengumpulan SET jawaban = ?, tautan = ?, dikirim = ? WHERE id = ?", "sssi", $jawaban, $tautan, $sekarang, $lama['id']);
            lapor($conn, 'kirim_tugas', 'Memperbarui jawaban tugas "' . $target['judul'] . '" kelas ' . $kelasSaya);
            selesai('tugas', 'ok', 'Jawabanmu diperbarui');
        }
        jalankan($conn, "INSERT INTO pengumpulan (tugas_id, siswa_id, jawaban, tautan, dikirim) VALUES (?, ?, ?, ?, ?)", "iisss", $id, $uid, $jawaban, $tautan, $sekarang);
        lapor($conn, 'kirim_tugas', 'Mengirim jawaban tugas "' . $target['judul'] . '" kelas ' . $kelasSaya);
        selesai('tugas', 'ok', 'Jawaban terkirim ke ' . 'guru');

    case 'simpan_profil':
        $kelas  = bersih($_POST['kelas'] ?? '', 30);
        $daftar = daftarKelas($conn);
        if (!in_array($kelas, $daftar, true)) {
            selesai('beranda', 'err', 'Pilih salah satu kelas yang tersedia');
        }
        jalankan($conn, "UPDATE users SET kelas = ? WHERE id = ?", "si", $kelas, $uid);
        lapor($conn, 'ubah_kelas', 'Memilih kelas ' . $kelas);
        selesai('beranda', 'ok', 'Kelas ' . $kelas . ' tersimpan, jadwal dan tugas guru sudah disinkronkan');

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
            lapor($conn, 'tambah_jadwal', 'Menambah jadwal pribadi ' . $mapel . ' hari ' . $hari);
            selesai('jadwal', 'ok', 'Jadwal berhasil ditambahkan');
        }
        selesai('jadwal', 'err', 'Jadwal gagal disimpan');

    case 'hapus_jadwal':
        $target = ambilSatu($conn, "SELECT mapel, hari FROM jadwal WHERE id = ? AND user_id = ?", "ii", $id, $uid);
        jalankan($conn, "DELETE FROM jadwal WHERE id = ? AND user_id = ?", "ii", $id, $uid);
        if ($target) {
            lapor($conn, 'hapus_jadwal', 'Menghapus jadwal pribadi ' . $target['mapel'] . ' hari ' . $target['hari']);
        }
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
            lapor($conn, 'tambah_catatan', 'Menyimpan catatan "' . $judul . '"');
            selesai('catatan', 'ok', 'Catatan tersimpan');
        }
        selesai('catatan', 'err', 'Catatan gagal disimpan');

    case 'hapus_catatan':
        $target = ambilSatu($conn, "SELECT judul FROM catatan WHERE id = ? AND user_id = ?", "ii", $id, $uid);
        jalankan($conn, "DELETE FROM catatan WHERE id = ? AND user_id = ?", "ii", $id, $uid);
        if ($target) {
            lapor($conn, 'hapus_catatan', 'Menghapus catatan "' . $target['judul'] . '"');
        }
        selesai('catatan', 'ok', 'Catatan dihapus');

    default:
        selesai('beranda', 'err', 'Aksi tidak dikenal');
}
