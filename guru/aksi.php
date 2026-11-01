<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once '../config/koneksi.php';
require_once '../config/helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'guru') {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

function selesai($bagian, $tipe, $teks, $query = '') {
    $_SESSION['flash'] = ['tipe' => $tipe, 'teks' => $teks];
    header("Location: dashboard.php" . $query . "#" . $bagian);
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
    catatAktivitas($conn, (int)$_SESSION['id'], $_SESSION['nama'] ?? '', 'guru', $aksi, $detail);
}

if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    selesai('beranda', 'err', 'Sesi tidak valid, silakan coba lagi');
}

$uid  = (int)$_SESSION['id'];
$aksi = $_POST['aksi'] ?? '';
$id   = (int)($_POST['id'] ?? 0);

$hariValid     = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
$kategoriValid = ['Mengajar', 'Rapat', 'Koreksi', 'Administrasi'];
$warnaValid    = ['kuning', 'pink', 'biru', 'hijau', 'ungu'];
$statusValid   = ['H', 'I', 'S', 'A'];

try {
    switch ($aksi) {

        case 'simpan_absen':
            $tanggal = $_POST['tanggal'] ?? '';
            $kelas   = bersih($_POST['kelas'] ?? '', 30);
            $status  = $_POST['status'] ?? [];

            if (!tanggalValid($tanggal) || $tanggal > date('Y-m-d') || !is_array($status) || count($status) === 0) {
                selesai('absensi', 'err', 'Data absensi tidak valid');
            }
            if (!in_array($kelas, daftarKelas($conn), true)) {
                selesai('absensi', 'err', 'Kelas tidak valid');
            }

            $valid = [];
            $stmtSiswa = mysqli_prepare($conn, "SELECT id FROM users WHERE role = 'murid' AND kelas = ?");
            mysqli_stmt_bind_param($stmtSiswa, "s", $kelas);
            mysqli_stmt_execute($stmtSiswa);
            $hasil = mysqli_stmt_get_result($stmtSiswa);
            while ($baris = mysqli_fetch_assoc($hasil)) {
                $valid[] = (int)$baris['id'];
            }

            $stmt = mysqli_prepare($conn, "INSERT INTO absensi (guru_id, siswa_id, tanggal, status) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)");
            mysqli_stmt_bind_param($stmt, "iiss", $uid, $sid, $tanggal, $st);

            $jumlah = 0;
            $hitung = ['H' => 0, 'I' => 0, 'S' => 0, 'A' => 0];
            foreach ($status as $kunci => $nilai) {
                $sid = (int)$kunci;
                $st  = (string)$nilai;
                if (!in_array($sid, $valid, true) || !in_array($st, $statusValid, true)) {
                    continue;
                }
                mysqli_stmt_execute($stmt);
                $hitung[$st]++;
                $jumlah++;
            }

            lapor($conn, 'absensi', 'Mengisi absensi kelas ' . $kelas . ' tanggal ' . $tanggal . ' (H' . $hitung['H'] . ' I' . $hitung['I'] . ' S' . $hitung['S'] . ' A' . $hitung['A'] . ')');
            selesai('absensi', 'ok', 'Absensi kelas ' . $kelas . ' tersimpan untuk ' . $jumlah . ' siswa', '?tgl=' . $tanggal . '&kelas=' . urlencode($kelas));

        case 'simpan_profil':
            $mapel = bersih($_POST['mapel'] ?? '', 80);
            if (!in_array($mapel, daftarMapel($conn), true)) {
                selesai('beranda', 'err', 'Pilih salah satu mata pelajaran yang tersedia');
            }
            jalankan($conn, "UPDATE users SET mapel = ? WHERE id = ?", "si", $mapel, $uid);
            lapor($conn, 'ubah_mapel', 'Memilih mata pelajaran ' . $mapel);
            selesai('beranda', 'ok', 'Mata pelajaran ' . $mapel . ' tersimpan');

        case 'tambah_jadwal':
            $profil = profilPengguna($conn, $uid);
            $mapel  = trim((string)($profil['mapel'] ?? ''));
            $hari   = $_POST['hari'] ?? '';
            $kelas  = bersih($_POST['kelas'] ?? '', 30);
            $mulai  = $_POST['jam_mulai'] ?? '';
            $akhir  = $_POST['jam_selesai'] ?? '';
            $ruang  = bersih($_POST['ruang'] ?? '', 50);

            if ($mapel === '') {
                selesai('jadwal', 'err', 'Pilih mata pelajaranmu dulu');
            }
            if (!in_array($kelas, daftarKelas($conn), true)) {
                selesai('jadwal', 'err', 'Pilih kelas yang tersedia');
            }
            if (!in_array($hari, $hariValid, true) || !jamValid($mulai) || !jamValid($akhir)) {
                selesai('jadwal', 'err', 'Lengkapi hari dan jam');
            }
            if ($akhir <= $mulai) {
                selesai('jadwal', 'err', 'Jam selesai harus lebih besar dari jam mulai');
            }
            if (jalankan($conn, "INSERT INTO jadwal_guru (user_id, hari, mapel, kelas, jam_mulai, jam_selesai, ruang) VALUES (?, ?, ?, ?, ?, ?, ?)", "issssss", $uid, $hari, $mapel, $kelas, $mulai, $akhir, $ruang)) {
                lapor($conn, 'tambah_jadwal', 'Menambah jadwal ' . $mapel . ' kelas ' . $kelas . ' hari ' . $hari . ' ' . $mulai . '-' . $akhir);
                selesai('jadwal', 'ok', 'Jadwal ditambahkan dan langsung tampil di siswa kelas ' . $kelas);
            }
            selesai('jadwal', 'err', 'Jadwal gagal disimpan');

        case 'hapus_jadwal':
            $target = ambilSatu($conn, "SELECT mapel, kelas, hari FROM jadwal_guru WHERE id = ? AND user_id = ?", "ii", $id, $uid);
            jalankan($conn, "DELETE FROM jadwal_guru WHERE id = ? AND user_id = ?", "ii", $id, $uid);
            if ($target) {
                lapor($conn, 'hapus_jadwal', 'Menghapus jadwal ' . $target['mapel'] . ' kelas ' . $target['kelas'] . ' hari ' . $target['hari']);
            }
            selesai('jadwal', 'ok', 'Jadwal dihapus');

        case 'tambah_tugas_kelas':
            $profil    = profilPengguna($conn, $uid);
            $mapel     = trim((string)($profil['mapel'] ?? ''));
            $judul     = bersih($_POST['judul'] ?? '', 150);
            $deskripsi = bersih($_POST['deskripsi'] ?? '', 1000);
            $deadline  = $_POST['deadline'] ?? '';
            $jamBatas  = $_POST['jam_batas'] ?? '23:59';
            $kelasList = $_POST['kelas'] ?? [];

            if ($mapel === '') {
                selesai('tugas', 'err', 'Pilih mata pelajaranmu dulu');
            }
            if ($judul === '' || !tanggalValid($deadline) || !jamValid($jamBatas)) {
                selesai('tugas', 'err', 'Judul, tanggal, dan jam batas pengumpulan wajib diisi');
            }
            if (pengumpulanDitutup($deadline, $jamBatas)) {
                selesai('tugas', 'err', 'Batas pengumpulan harus berada di waktu yang akan datang');
            }
            if (!is_array($kelasList) || count($kelasList) === 0) {
                selesai('tugas', 'err', 'Pilih minimal satu kelas tujuan');
            }
            $daftar = daftarKelas($conn);
            $terkirim = [];
            foreach ($kelasList as $k) {
                $k = bersih($k, 30);
                if (!in_array($k, $daftar, true) || in_array($k, $terkirim, true)) {
                    continue;
                }
                jalankan($conn, "INSERT INTO tugas_kelas (guru_id, kelas, mapel, judul, deskripsi, deadline, jam_batas) VALUES (?, ?, ?, ?, ?, ?, ?)", "issssss", $uid, $k, $mapel, $judul, $deskripsi, $deadline, $jamBatas);
                $terkirim[] = $k;
            }
            if (count($terkirim) === 0) {
                selesai('tugas', 'err', 'Kelas tujuan tidak valid');
            }
            lapor($conn, 'tambah_tugas_kelas', 'Memberi tugas "' . $judul . '" (' . $mapel . ') untuk kelas ' . implode(', ', $terkirim) . ', batas ' . $deadline . ' ' . $jamBatas);
            selesai('tugas', 'ok', 'Tugas terkirim ke kelas ' . implode(', ', $terkirim));

        case 'hapus_tugas_kelas':
            $target = ambilSatu($conn, "SELECT judul, kelas FROM tugas_kelas WHERE id = ? AND guru_id = ?", "ii", $id, $uid);
            if (!$target) {
                selesai('tugas', 'err', 'Tugas tidak ditemukan');
            }
            jalankan($conn, "DELETE FROM nilai WHERE pengumpulan_id IN (SELECT id FROM pengumpulan WHERE tugas_id = ?)", "i", $id);
            jalankan($conn, "DELETE FROM pengumpulan WHERE tugas_id = ?", "i", $id);
            jalankan($conn, "DELETE FROM tugas_selesai WHERE tugas_id = ?", "i", $id);
            jalankan($conn, "DELETE FROM tugas_kelas WHERE id = ? AND guru_id = ?", "ii", $id, $uid);
            lapor($conn, 'hapus_tugas_kelas', 'Menghapus tugas "' . $target['judul'] . '" kelas ' . $target['kelas']);
            selesai('tugas', 'ok', 'Tugas dihapus');

        case 'nilai_tugas':
            $angka   = $_POST['nilai'] ?? '';
            $catatan = bersih($_POST['catatan'] ?? '', 500);
            if (!is_numeric($angka) || (float)$angka < 0 || (float)$angka > 100) {
                selesai('tugas', 'err', 'Nilai harus berupa angka 0 sampai 100');
            }
            $angka = round((float)$angka, 2);
            $kiriman = ambilSatu($conn, "SELECT p.id, p.siswa_id, t.judul, t.mapel, u.nama FROM pengumpulan p JOIN tugas_kelas t ON t.id = p.tugas_id JOIN users u ON u.id = p.siswa_id WHERE p.id = ? AND t.guru_id = ?", "ii", $id, $uid);
            if (!$kiriman) {
                selesai('tugas', 'err', 'Kiriman tidak ditemukan');
            }
            $sekarang = date('Y-m-d H:i:s');
            jalankan($conn, "UPDATE pengumpulan SET nilai = ?, catatan = ?, dinilai = ? WHERE id = ?", "dssi", $angka, $catatan, $sekarang, $id);
            jalankan($conn, "DELETE FROM nilai WHERE pengumpulan_id = ?", "i", $id);
            jalankan($conn, "INSERT INTO nilai (user_id, mapel, jenis, nilai, guru_id, pengumpulan_id) VALUES (?, ?, 'Tugas', ?, ?, ?)", "isdii", $kiriman['siswa_id'], $kiriman['mapel'], $angka, $uid, $id);
            lapor($conn, 'nilai_tugas', 'Menilai tugas "' . $kiriman['judul'] . '" milik ' . $kiriman['nama'] . ': ' . $angka);
            selesai('tugas', 'ok', 'Nilai ' . $kiriman['nama'] . ' tersimpan dan langsung tampil di dashboard siswa');

        case 'beri_nilai':
            $profil = profilPengguna($conn, $uid);
            $mapel  = trim((string)($profil['mapel'] ?? ''));
            $siswaId = (int)($_POST['siswa_id'] ?? 0);
            $jenis   = $_POST['jenis'] ?? '';
            $angka   = $_POST['nilai'] ?? '';
            if ($mapel === '') {
                selesai('nilai', 'err', 'Pilih mata pelajaranmu dulu');
            }
            if (!in_array($jenis, ['Kuis', 'UTS', 'UAS', 'Praktik', 'Harian'], true)) {
                selesai('nilai', 'err', 'Jenis nilai tidak valid');
            }
            if (!is_numeric($angka) || (float)$angka < 0 || (float)$angka > 100) {
                selesai('nilai', 'err', 'Nilai harus berupa angka 0 sampai 100');
            }
            $murid = ambilSatu($conn, "SELECT nama, kelas FROM users WHERE id = ? AND role = 'murid'", "i", $siswaId);
            if (!$murid) {
                selesai('nilai', 'err', 'Pilih siswa terlebih dahulu');
            }
            $angka = round((float)$angka, 2);
            jalankan($conn, "INSERT INTO nilai (user_id, mapel, jenis, nilai, guru_id) VALUES (?, ?, ?, ?, ?)", "issdi", $siswaId, $mapel, $jenis, $angka, $uid);
            lapor($conn, 'beri_nilai', 'Memberi nilai ' . $jenis . ' ' . $mapel . ' kepada ' . $murid['nama'] . ' (' . $murid['kelas'] . '): ' . $angka);
            selesai('nilai', 'ok', 'Nilai ' . $jenis . ' untuk ' . $murid['nama'] . ' tersimpan');

        case 'hapus_nilai':
            $target = ambilSatu($conn, "SELECT n.jenis, n.nilai, n.pengumpulan_id, u.nama FROM nilai n JOIN users u ON u.id = n.user_id WHERE n.id = ? AND n.guru_id = ?", "ii", $id, $uid);
            if (!$target) {
                selesai('nilai', 'err', 'Nilai tidak ditemukan');
            }
            if ($target['pengumpulan_id']) {
                jalankan($conn, "UPDATE pengumpulan SET nilai = NULL, dinilai = NULL, catatan = '' WHERE id = ?", "i", (int)$target['pengumpulan_id']);
            }
            jalankan($conn, "DELETE FROM nilai WHERE id = ? AND guru_id = ?", "ii", $id, $uid);
            lapor($conn, 'hapus_nilai', 'Menghapus nilai ' . $target['jenis'] . ' milik ' . $target['nama']);
            selesai('nilai', 'ok', 'Nilai dihapus');

        case 'tambah_agenda':
            $judul    = bersih($_POST['judul'] ?? '', 150);
            $kategori = $_POST['kategori'] ?? 'Mengajar';
            $tanggal  = $_POST['tanggal'] ?? '';

            if ($judul === '' || !tanggalValid($tanggal)) {
                selesai('agenda', 'err', 'Judul dan tanggal wajib diisi');
            }
            if (!in_array($kategori, $kategoriValid, true)) {
                $kategori = 'Mengajar';
            }
            if (jalankan($conn, "INSERT INTO agenda_guru (user_id, judul, kategori, tanggal) VALUES (?, ?, ?, ?)", "isss", $uid, $judul, $kategori, $tanggal)) {
                lapor($conn, 'tambah_agenda', 'Menambah agenda "' . $judul . '"');
                selesai('agenda', 'ok', 'Agenda ditambahkan');
            }
            selesai('agenda', 'err', 'Agenda gagal disimpan');

        case 'toggle_agenda':
            jalankan($conn, "UPDATE agenda_guru SET selesai = 1 - selesai WHERE id = ? AND user_id = ?", "ii", $id, $uid);
            selesai('agenda', 'ok', 'Status agenda diperbarui');

        case 'hapus_agenda':
            $target = ambilSatu($conn, "SELECT judul FROM agenda_guru WHERE id = ? AND user_id = ?", "ii", $id, $uid);
            jalankan($conn, "DELETE FROM agenda_guru WHERE id = ? AND user_id = ?", "ii", $id, $uid);
            if ($target) {
                lapor($conn, 'hapus_agenda', 'Menghapus agenda "' . $target['judul'] . '"');
            }
            selesai('agenda', 'ok', 'Agenda dihapus');

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
            if (jalankan($conn, "INSERT INTO catatan_guru (user_id, judul, isi, warna) VALUES (?, ?, ?, ?)", "isss", $uid, $judul, $isi, $warna)) {
                lapor($conn, 'tambah_catatan', 'Menyimpan catatan "' . $judul . '"');
                selesai('catatan', 'ok', 'Catatan tersimpan');
            }
            selesai('catatan', 'err', 'Catatan gagal disimpan');

        case 'hapus_catatan':
            $target = ambilSatu($conn, "SELECT judul FROM catatan_guru WHERE id = ? AND user_id = ?", "ii", $id, $uid);
            jalankan($conn, "DELETE FROM catatan_guru WHERE id = ? AND user_id = ?", "ii", $id, $uid);
            if ($target) {
                lapor($conn, 'hapus_catatan', 'Menghapus catatan "' . $target['judul'] . '"');
            }
            selesai('catatan', 'ok', 'Catatan dihapus');

        default:
            selesai('beranda', 'err', 'Aksi tidak dikenal');
    }
} catch (mysqli_sql_exception $ex) {
    selesai('beranda', 'err', 'Terjadi kesalahan pada database');
}
