<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (!isset($_SESSION['role'])) {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SESSION['role'] != 'murid') {
    die("Akses Ditolak! Anda bukan Siswa. <a href='../auth/logout.php'>Logout</a>");
}

require_once '../config/koneksi.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf  = $_SESSION['csrf'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

require_once '../config/helper.php';

$uid = (int)$_SESSION['id'];

function e($teks) {
    return htmlspecialchars((string)$teks, ENT_QUOTES, 'UTF-8');
}

function ambil($conn, $sql, $tipe, ...$param) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $tipe, ...$param);
    mysqli_stmt_execute($stmt);
    $hasil = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_all($hasil, MYSQLI_ASSOC);
}

try {
    $profil     = profilPengguna($conn, $uid);
    $kelasSaya  = trim((string)($profil['kelas'] ?? ''));
    $jadwalSendiri = ambil($conn, "SELECT * FROM jadwal WHERE user_id = ? ORDER BY FIELD(hari,'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'), jam_mulai", "i", $uid);
    $catatan    = ambil($conn, "SELECT * FROM catatan WHERE user_id = ? ORDER BY id DESC", "i", $uid);
    $nilai      = ambil($conn, "SELECT n.*, g.nama AS guru FROM nilai n LEFT JOIN users g ON g.id = n.guru_id WHERE n.user_id = ? AND n.guru_id IS NOT NULL ORDER BY n.mapel, n.id DESC", "i", $uid);
    $tugasGuru  = [];
    $jadwalGuru = [];
    if ($kelasSaya !== '') {
        $tugasGuru  = ambil($conn, "SELECT t.id, t.judul, t.mapel, t.deskripsi, t.deadline, t.jam_batas, u.nama AS guru, p.id AS kiriman_id, p.jawaban, p.tautan, p.dikirim, p.nilai AS nilai_tugas, p.catatan FROM tugas_kelas t JOIN users u ON u.id = t.guru_id LEFT JOIN pengumpulan p ON p.tugas_id = t.id AND p.siswa_id = ? WHERE t.kelas = ? ORDER BY t.deadline ASC, t.id DESC", "is", $uid, $kelasSaya);
        $jadwalGuru = ambil($conn, "SELECT j.id, j.hari, j.mapel, j.jam_mulai, j.jam_selesai, j.ruang, u.nama AS guru FROM jadwal_guru j JOIN users u ON u.id = j.user_id WHERE j.kelas = ? ORDER BY j.jam_mulai ASC", "s", $kelasSaya);
    }
    $absensi    = ambil($conn, "SELECT a.tanggal, a.status, u.nama AS guru, u.mapel FROM absensi a JOIN users u ON u.id = a.guru_id WHERE a.siswa_id = ? ORDER BY a.tanggal DESC, a.id DESC", "i", $uid);
    $daftarKelasSemua = daftarKelas($conn);
} catch (mysqli_sql_exception $ex) {
    die("Tabel dashboard belum lengkap. Import file database_siswa.sql dan database_sinkron.sql ke database kamu terlebih dahulu.");
}

$tugas = [];
foreach ($tugasGuru as $t) {
    $sisa    = sisaHari($t['deadline']);
    $ditutup = pengumpulanDitutup($t['deadline'], $t['jam_batas'] ?? '23:59');
    $terkirim = $t['kiriman_id'] !== null;
    $tugas[] = [
        'id'          => $t['id'],
        'judul'       => $t['judul'],
        'mapel'       => $t['mapel'],
        'deadline'    => $t['deadline'],
        'jam_batas'   => $t['jam_batas'] ?? '23:59',
        'prioritas'   => $sisa <= 1 ? 'tinggi' : ($sisa <= 3 ? 'sedang' : 'rendah'),
        'selesai'     => $terkirim ? 1 : 0,
        'ditutup'     => $ditutup,
        'sumber'      => 'guru',
        'guru'        => $t['guru'],
        'deskripsi'   => $t['deskripsi'],
        'jawaban'     => (string)$t['jawaban'],
        'tautan'      => (string)$t['tautan'],
        'dikirim'     => $t['dikirim'],
        'nilai_tugas' => $t['nilai_tugas'],
        'catatan'     => (string)$t['catatan']
    ];
}
usort($tugas, function ($a, $b) {
    if ($a['selesai'] != $b['selesai']) {
        return $a['selesai'] <=> $b['selesai'];
    }
    return strcmp($a['deadline'] . $a['jam_batas'], $b['deadline'] . $b['jam_batas']);
});

$urutanHari = array_flip(['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu']);
$jadwal = [];
foreach ($jadwalSendiri as $j) {
    $j['sumber'] = 'sendiri';
    $jadwal[] = $j;
}
foreach ($jadwalGuru as $j) {
    $j['sumber'] = 'guru';
    $jadwal[] = $j;
}
usort($jadwal, function ($a, $b) use ($urutanHari) {
    if ($a['hari'] !== $b['hari']) {
        return $urutanHari[$a['hari']] <=> $urutanHari[$b['hari']];
    }
    return strcmp($a['jam_mulai'], $b['jam_mulai']);
});

$rekapAbsen = ['H' => 0, 'I' => 0, 'S' => 0, 'A' => 0];
foreach ($absensi as $a) {
    if (isset($rekapAbsen[$a['status']])) {
        $rekapAbsen[$a['status']]++;
    }
}
$totalAbsen  = array_sum($rekapAbsen);
$persenHadir = $totalAbsen > 0 ? (int)round($rekapAbsen['H'] / $totalAbsen * 100) : 0;
$labelAbsen  = ['H' => 'Hadir', 'I' => 'Izin', 'S' => 'Sakit', 'A' => 'Alpa'];
$kelasAbsen  = ['H' => 'dl-selesai', 'I' => 'dl-minggu', 'S' => 'dl-hari', 'A' => 'dl-telat'];

$namaHari = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
$bulan    = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];
$hariIni  = $namaHari[(int)date('N')];

function tglIndo($tgl, $bulan) {
    $d = new DateTime($tgl);
    return $d->format('j') . ' ' . $bulan[(int)$d->format('n')];
}

function sisaHari($deadline) {
    $d = new DateTime($deadline);
    $t = new DateTime('today');
    return (int)$t->diff($d)->format('%r%a');
}

function labelDeadline($deadline, $bulan) {
    $sisa = sisaHari($deadline);
    if ($sisa < 0)  return ['Terlambat ' . abs($sisa) . ' hari', 'dl-telat'];
    if ($sisa == 0) return ['Hari ini', 'dl-hari'];
    if ($sisa == 1) return ['Besok', 'dl-besok'];
    if ($sisa <= 7) return [$sisa . ' hari lagi', 'dl-minggu'];
    return [tglIndo($deadline, $bulan), 'dl-santai'];
}

function predikat($angka) {
    if ($angka >= 90) return 'A';
    if ($angka >= 80) return 'B';
    if ($angka >= 70) return 'C';
    if ($angka >= 60) return 'D';
    return 'E';
}

function kelasNilai($angka) {
    if ($angka >= 80) return 'n-hijau';
    if ($angka >= 70) return 'n-biru';
    if ($angka >= 60) return 'n-kuning';
    return 'n-merah';
}

$total      = count($tugas);
$aktif      = array_values(array_filter($tugas, fn($t) => $t['selesai'] == 0));
$aktifN     = count($aktif);
$selesaiN   = $total - $aktifN;
$persen     = $total > 0 ? (int)round($selesaiN / $total * 100) : 0;
$terlambatN = count(array_filter($aktif, fn($t) => $t['ditutup']));
$mendesak   = array_slice(array_values(array_filter($aktif, fn($t) => !$t['ditutup'])), 0, 4);

$jadwalHariIni = array_values(array_filter($jadwal, fn($j) => $j['hari'] === $hariIni));
$jadwalPerHari = [];
foreach ($jadwal as $j) {
    $jadwalPerHari[$j['hari']][] = $j;
}

$perMapel = [];
foreach ($nilai as $n) {
    $perMapel[$n['mapel']][] = $n;
}
$rataMapel = [];
foreach ($perMapel as $mapel => $daftar) {
    $rataMapel[$mapel] = array_sum(array_column($daftar, 'nilai')) / count($daftar);
}
$rataSemua = count($rataMapel) > 0 ? array_sum($rataMapel) / count($rataMapel) : null;

$daftarMapel = array_unique(array_merge(
    array_column($jadwal, 'mapel'),
    array_column($tugas, 'mapel'),
    array_column($nilai, 'mapel')
));
$daftarMapel = array_filter($daftarMapel, fn($m) => $m !== '');

$jam = (int)date('H');
if ($jam < 11)      { $salam = 'Selamat pagi'; }
elseif ($jam < 15)  { $salam = 'Selamat siang'; }
elseif ($jam < 18)  { $salam = 'Selamat sore'; }
else                { $salam = 'Selamat malam'; }

$kutipanList = [
    'Belajar sedikit setiap hari lebih baik daripada belajar banyak sekali saja.',
    'Kesalahan adalah bukti bahwa kamu sedang mencoba.',
    'Mulai dari yang kecil, konsisten sampai jadi kebiasaan.',
    'Fokus 25 menit hari ini bisa mengubah nilai minggu depan.',
    'Tidak harus sempurna, yang penting terus maju.',
    'Pintar itu hasil dari rajin, bukan sekadar bakat.',
    'Tugas yang dikerjakan sekarang akan membuatmu tenang nanti.'
];
$kutipan = $kutipanList[(int)date('z') % count($kutipanList)];

$namaLengkap = $_SESSION['nama'] ?? 'Siswa';
$namaDepan   = explode(' ', trim($namaLengkap))[0];
$inisial     = mb_strtoupper(mb_substr($namaLengkap, 0, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Siswa</title>
    <link href="dashboard.css?v=<?= filemtime(__DIR__ . '/dashboard.css') ?>" rel="stylesheet">
</head>
<body>
<div class="app">

    <aside class="sidebar">
        <div class="brand"> Portal Sekolah</div>

        <nav class="menu m7">
            <button type="button" class="nav aktif" data-bagian="beranda"><span class="nt">Beranda</span></button>
            <button type="button" class="nav" data-bagian="tugas"><span class="nt">Tugas</span><?php if ($aktifN > 0): ?><em class="lencana"><?= $aktifN ?></em><?php endif; ?></button>
            <button type="button" class="nav" data-bagian="jadwal"><span class="nt">Jadwal</span></button>
            <button type="button" class="nav" data-bagian="catatan"><span class="nt">Catatan</span></button>
            <button type="button" class="nav" data-bagian="nilai"><span class="nt">Nilai</span></button>
            <button type="button" class="nav" data-bagian="kehadiran"><span class="nt">Hadir</span></button>
            <button type="button" class="nav" data-bagian="fokus"><span class="nt">Fokus</span></button>
        </nav>

        <div class="user-card">
            <span class="avatar"><?= e($inisial) ?></span>
            <div class="user-info">
                <b><?= e($namaLengkap) ?></b>
                <small>Murid<?php if ($kelasSaya !== ''): ?> <span class="tag-kecil"><?= e($kelasSaya) ?></span><?php endif; ?></small>
            </div>
            <button type="button" class="ubah-profil" data-profil title="Ubah kelas" aria-label="Ubah kelas">Ubah</button>
            <a href="../auth/logout.php" class="keluar" title="Keluar">Keluar</a>
        </div>
    </aside>

    <main class="main">

        <header class="topbar">
            <div>
                <h1 id="judulBagian">Beranda</h1>
                <p id="subBagian">Ringkasan belajarmu hari ini</p>
            </div>
            <div class="topbar-kanan">
                <div class="jam">
                    <b id="jamLive">--:--</b>
                    <span id="tglLive"></span>
                </div>
                <a href="../auth/logout.php" class="keluar-mobile">Keluar</a>
            </div>
        </header>

        <?php if ($kelasSaya === ''): ?>
            <div class="tagih">
                <div class="isi">
                    <b>Kamu belum memilih kelas</b>
                    <span>Pilih kelasmu agar jadwal pelajaran dan tugas dari guru langsung muncul di dashboard.</span>
                </div>
                <button type="button" class="btn" data-profil>Pilih Kelas</button>
            </div>
        <?php endif; ?>

        <section class="bagian aktif" id="beranda">
            <div class="hero-card">
                <div class="hero-teks">
                    <small><?= $salam ?></small><?php if ($kelasSaya !== ''): ?><span class="label-profil">Kelas <?= e($kelasSaya) ?></span><?php endif; ?>
                    <h2>Halo, <?= e($namaDepan) ?>!</h2>
                    <p>“<?= e($kutipan) ?>”</p>
                </div>
                <div class="cincin" style="--p: <?= $persen ?>">
                    <span><b><?= $persen ?>%</b><small>tugas terkirim</small></span>
                </div>
            </div>

            <div class="stat-grid">
                <div class="stat s-ungu">
                    <div><b><?= $aktifN ?></b><span>Belum dikirim</span></div>
                </div>
                <div class="stat s-hijau">
                    <div><b><?= $selesaiN ?></b><span>Sudah dikirim</span></div>
                </div>
                <div class="stat s-pink">
                    <div><b><?= $terlambatN ?></b><span>Terlambat</span></div>
                </div>
                <div class="stat s-kuning">
                    <div><b><?= $rataSemua !== null ? number_format($rataSemua, 1) : '-' ?></b><span>Rata-rata nilai</span></div>
                </div>
            </div>

            <div class="grid-2">
                <div class="panel">
                    <div class="panel-kepala">
                        <h3>Jadwal Hari Ini <small><?= $hariIni ?></small></h3>
                        <button type="button" class="link" data-bagian="jadwal">Kelola</button>
                    </div>
                    <?php if (count($jadwalHariIni) === 0): ?>
                        <div class="kosong"><p>Tidak ada pelajaran terjadwal hari ini.</p></div>
                    <?php else: ?>
                        <div class="daftar">
                            <?php foreach ($jadwalHariIni as $j): ?>
                                <div class="baris-info">
                                    <span class="jam-chip"><?= e(substr($j['jam_mulai'], 0, 5)) ?><br><small><?= e(substr($j['jam_selesai'], 0, 5)) ?></small></span>
                                    <div class="isi">
                                        <b><?= e($j['mapel']) ?></b>
                                        <span><?= $j['ruang'] !== '' ? e($j['ruang']) : 'Tanpa ruang' ?><?= $j['sumber'] === 'guru' ? ' · ' . e($j['guru']) : '' ?></span>
                                    </div>
                                    <?php if ($j['sumber'] === 'guru'): ?><span class="badge badge-guru">Guru</span><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-kepala">
                        <h3>Tugas Mendesak</h3>
                        <button type="button" class="link" data-bagian="tugas">Lihat semua</button>
                    </div>
                    <?php if (count($mendesak) === 0): ?>
                        <div class="kosong"><p>Tidak ada tugas aktif. Santai dulu!</p></div>
                    <?php else: ?>
                        <div class="daftar">
                            <?php foreach ($mendesak as $t): ?>
                                <?php [$teksDl, $kelasDl] = labelDeadline($t['deadline'], $bulan); ?>
                                <div class="baris-info">
                                    <span class="titik prio-<?= e($t['prioritas']) ?>"></span>
                                    <div class="isi">
                                        <b><?= e($t['judul']) ?></b>
                                        <span><?= $t['mapel'] !== '' ? e($t['mapel']) : 'Umum' ?><?= $t['sumber'] === 'guru' ? ' · dari ' . e($t['guru']) : '' ?></span>
                                    </div>
                                    <span class="badge <?= $kelasDl ?>"><?= e($teksDl) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="bagian" id="tugas">
            <div class="panel">
                <div class="panel-kepala">
                    <h3>Tugas dari Guru</h3>
                    <div class="filter">
                        <button type="button" class="aktif" data-filter="semua">Semua</button>
                        <button type="button" data-filter="aktif">Belum dikirim</button>
                        <button type="button" data-filter="selesai">Terkirim</button>
                    </div>
                </div>

                <?php if ($kelasSaya === ''): ?>
                    <div class="kosong"><p>Pilih kelasmu dulu supaya tugas dari guru muncul di sini.</p><button type="button" class="btn" data-profil>Pilih Kelas</button></div>
                <?php elseif ($total === 0): ?>
                    <div class="kosong"><p>Belum ada tugas dari guru. Tugas baru akan muncul otomatis di sini.</p></div>
                <?php else: ?>
                    <div class="daftar" id="daftarTugas">
                        <?php foreach ($tugas as $t): ?>
                            <?php
                                $sudah   = $t['selesai'] == 1;
                                $dinilai = $t['nilai_tugas'] !== null;
                                $angkaT  = $dinilai ? rtrim(rtrim(number_format((float)$t['nilai_tugas'], 2), '0'), '.') : '';
                                [$teksDl, $kelasDl] = labelDeadline($t['deadline'], $bulan);
                                $bisaKirim = !$t['ditutup'] && !$dinilai;
                            ?>
                            <div class="tugas-item dari-guru <?= $sudah ? 'selesai' : '' ?>" data-status="<?= $sudah ? 'selesai' : 'aktif' ?>">
                                <span class="cek" aria-hidden="true">✓</span>
                                <div class="isi">
                                    <b><?= e($t['judul']) ?></b>
                                    <span><?= $t['mapel'] !== '' ? e($t['mapel']) : 'Umum' ?></span>
                                    <span class="dari-guru-info">Dari <?= e($t['guru']) ?> · batas <?= e(tglIndo($t['deadline'], $bulan)) ?>, <?= e($t['jam_batas']) ?></span>
                                    <?php if (trim($t['deskripsi']) !== ''): ?><span class="deskripsi"><?= e($t['deskripsi']) ?></span><?php endif; ?>
                                    <?php if ($sudah): ?>
                                        <span class="info-kiriman">Terkirim <?= e(tglIndo($t['dikirim'], $bulan)) ?>, <?= e(substr($t['dikirim'], 11, 5)) ?></span>
                                    <?php endif; ?>
                                    <?php if ($dinilai && $t['catatan'] !== ''): ?>
                                        <span class="info-catatan"><?= e($t['catatan']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="badge badge-guru">Tugas Guru</span>
                                <?php if ($dinilai): ?>
                                    <span class="badge dl-selesai">Nilai <?= e($angkaT) ?></span>
                                <?php elseif ($sudah): ?>
                                    <span class="badge dl-minggu">Menunggu nilai</span>
                                <?php elseif ($t['ditutup']): ?>
                                    <span class="badge dl-telat">Ditutup</span>
                                <?php else: ?>
                                    <span class="badge <?= $kelasDl ?>"><?= e($teksDl) ?></span>
                                <?php endif; ?>
                                <?php if ($bisaKirim): ?>
                                    <button type="button" class="btn-kecil" data-kirim="<?= (int)$t['id'] ?>" data-judul="<?= e($t['judul']) ?>" data-jawaban="<?= e($t['jawaban']) ?>" data-tautan="<?= e($t['tautan']) ?>"><?= $sudah ? 'Ubah Jawaban' : 'Kirim Jawaban' ?></button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="kosong sembunyi" id="kosongFilter"><p>Tidak ada tugas di kategori ini.</p></div>
                <?php endif; ?>
            </div>
        </section>

        <section class="bagian" id="jadwal">
            <div class="panel">
                <div class="panel-kepala"><h3>Tambah Pelajaran Pribadi</h3></div>
                <form method="POST" action="aksi.php" class="form-grid">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="aksi" value="tambah_jadwal">

                    <div class="medan k-12">
                        <label>Hari</label>
                        <div class="pilihan">
                            <?php foreach ($namaHari as $h): ?>
                                <label><input type="radio" name="hari" value="<?= $h ?>" <?= $h === $hariIni ? 'checked' : '' ?>><span><?= $h ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="medan k-6">
                        <label for="j-mapel">Mata pelajaran</label>
                        <input class="input" type="text" id="j-mapel" name="mapel" maxlength="80" list="daftarMapel" placeholder="Contoh: Matematika" required>
                    </div>
                    <div class="medan k-2">
                        <label for="j-mulai">Mulai</label>
                        <input class="input" type="time" id="j-mulai" name="jam_mulai" value="07:00" required>
                    </div>
                    <div class="medan k-2">
                        <label for="j-akhir">Selesai</label>
                        <input class="input" type="time" id="j-akhir" name="jam_selesai" value="08:30" required>
                    </div>
                    <div class="medan k-2">
                        <label for="j-ruang">Ruang</label>
                        <input class="input" type="text" id="j-ruang" name="ruang" maxlength="50" placeholder="Lab 1">
                    </div>
                    <div class="k-12"><button type="submit" class="btn">Simpan Jadwal</button></div>
                </form>
            </div>

            <div class="minggu">
                <?php foreach ($namaHari as $h): ?>
                    <div class="hari <?= $h === $hariIni ? 'hari-ini' : '' ?>">
                        <h4><?= $h ?><?php if ($h === $hariIni): ?> <small>Hari ini</small><?php endif; ?></h4>
                        <?php if (empty($jadwalPerHari[$h])): ?>
                            <p class="kosong-kecil">Kosong</p>
                        <?php else: ?>
                            <?php foreach ($jadwalPerHari[$h] as $j): ?>
                                <div class="pelajaran <?= $j['sumber'] === 'guru' ? 'dari-guru' : '' ?>">
                                    <?php if ($j['sumber'] === 'sendiri'): ?>
                                        <form method="POST" action="aksi.php" data-konfirm="Hapus pelajaran &quot;<?= e($j['mapel']) ?>&quot; dari jadwal?">
                                            <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                            <input type="hidden" name="aksi" value="hapus_jadwal">
                                            <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
                                            <button type="submit" class="hapus-kecil" aria-label="Hapus">✕</button>
                                        </form>
                                    <?php endif; ?>
                                    <b><?= e($j['mapel']) ?></b>
                                    <span><?= e(substr($j['jam_mulai'], 0, 5)) ?> - <?= e(substr($j['jam_selesai'], 0, 5)) ?></span>
                                    <?php if ($j['ruang'] !== ''): ?><small><?= e($j['ruang']) ?></small><?php endif; ?>
                                    <?php if ($j['sumber'] === 'guru'): ?><span class="tag-guru"><?= e($j['guru']) ?></span><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="bagian" id="catatan">
            <div class="panel">
                <div class="panel-kepala"><h3>Catatan Baru</h3></div>
                <form method="POST" action="aksi.php" class="form-grid">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="aksi" value="tambah_catatan">

                    <div class="medan k-12">
                        <label for="c-judul">Judul</label>
                        <input class="input" type="text" id="c-judul" name="judul" maxlength="100" placeholder="Contoh: Rumus Pythagoras">
                    </div>
                    <div class="medan k-12">
                        <label for="c-isi">Isi catatan</label>
                        <textarea class="input" id="c-isi" name="isi" rows="4" maxlength="2000" placeholder="Tulis catatanmu di sini..."></textarea>
                    </div>
                    <div class="medan k-12">
                        <label>Warna</label>
                        <div class="dots">
                            <label><input type="radio" name="warna" value="kuning" checked><span class="w-kuning"></span></label>
                            <label><input type="radio" name="warna" value="pink"><span class="w-pink"></span></label>
                            <label><input type="radio" name="warna" value="biru"><span class="w-biru"></span></label>
                            <label><input type="radio" name="warna" value="hijau"><span class="w-hijau"></span></label>
                            <label><input type="radio" name="warna" value="ungu"><span class="w-ungu"></span></label>
                        </div>
                    </div>
                    <div class="k-12"><button type="submit" class="btn">Simpan Catatan</button></div>
                </form>
            </div>

            <?php if (count($catatan) === 0): ?>
                <div class="panel"><div class="kosong"><p>Belum ada catatan. Simpan ide dan materi pentingmu di sini!</p></div></div>
            <?php else: ?>
                <div class="catatan-grid">
                    <?php foreach ($catatan as $c): ?>
                        <div class="catatan c-<?= e($c['warna']) ?>">
                            <form method="POST" action="aksi.php" data-konfirm="Hapus catatan &quot;<?= e($c['judul']) ?>&quot;?">
                                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                <input type="hidden" name="aksi" value="hapus_catatan">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" class="hapus-kecil" aria-label="Hapus">✕</button>
                            </form>
                            <h4><?= e($c['judul']) ?></h4>
                            <p><?= e($c['isi']) ?></p>
                            <small><?= e(tglIndo($c['dibuat'], $bulan)) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="bagian" id="nilai">
            <div class="grid-2 nilai-atas">
                <div class="panel ringkasan-nilai">
                    <small>Rata-rata keseluruhan</small>
                    <?php if ($rataSemua === null): ?>
                        <b class="angka-besar">-</b>
                        <p>Rata-rata muncul setelah guru memberi nilai pertamamu.</p>
                    <?php else: ?>
                        <b class="angka-besar"><?= number_format($rataSemua, 1) ?></b>
                        <span class="predikat <?= kelasNilai($rataSemua) ?>">Predikat <?= predikat($rataSemua) ?></span>
                        <p>Dari <?= count($rataMapel) ?> mata pelajaran dan <?= count($nilai) ?> nilai.</p>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-kepala"><h3>Nilai Terbaru <small>diberikan guru</small></h3></div>
                    <?php $nilaiTerbaru = $nilai; usort($nilaiTerbaru, fn($a, $b) => $b['id'] <=> $a['id']); $nilaiTerbaru = array_slice($nilaiTerbaru, 0, 5); ?>
                    <?php if (count($nilaiTerbaru) === 0): ?>
                        <div class="kosong"><p>Belum ada nilai dari guru. Nilai akan muncul di sini setelah guru memberinya.</p></div>
                    <?php else: ?>
                        <div class="daftar">
                            <?php foreach ($nilaiTerbaru as $n): ?>
                                <div class="baris-info">
                                    <div class="isi">
                                        <b><?= e($n['mapel']) ?> · <?= e($n['jenis']) ?></b>
                                        <span>dari <?= e($n['guru'] ?? 'guru') ?></span>
                                    </div>
                                    <span class="predikat <?= kelasNilai((float)$n['nilai']) ?>"><?= e(rtrim(rtrim(number_format((float)$n['nilai'], 2), '0'), '.')) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (count($perMapel) > 0): ?>
                <div class="nilai-grid">
                    <?php foreach ($perMapel as $mapel => $daftar): ?>
                        <?php $rata = $rataMapel[$mapel]; ?>
                        <div class="panel mapel-kartu">
                            <div class="mapel-kepala">
                                <h4><?= e($mapel) ?></h4>
                                <span class="predikat <?= kelasNilai($rata) ?>"><?= number_format($rata, 1) ?> · <?= predikat($rata) ?></span>
                            </div>
                            <div class="bar"><i class="<?= kelasNilai($rata) ?>" style="width: <?= min(100, max(0, $rata)) ?>%"></i></div>
                            <div class="chips-nilai">
                                <?php foreach ($daftar as $n): ?>
                                    <div class="chip-nilai" title="<?= e('Dari ' . ($n['guru'] ?? 'guru')) ?>">
                                        <em><?= e($n['jenis']) ?></em>
                                        <b><?= e(rtrim(rtrim(number_format($n['nilai'], 2), '0'), '.')) ?></b>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="bagian" id="kehadiran">
            <div class="panel">
                <div class="panel-kepala"><h3>Rekap Kehadiranmu <small><?= $totalAbsen ?> pertemuan</small></h3></div>
                <?php if ($totalAbsen === 0): ?>
                    <div class="kosong"><p>Belum ada data kehadiran dari guru.</p></div>
                <?php else: ?>
                    <div class="kehadiran-ringkas">
                        <div><b><?= $rekapAbsen['H'] ?></b><span>Hadir</span></div>
                        <div><b><?= $rekapAbsen['I'] ?></b><span>Izin</span></div>
                        <div><b><?= $rekapAbsen['S'] ?></b><span>Sakit</span></div>
                        <div><b><?= $rekapAbsen['A'] ?></b><span>Alpa</span></div>
                    </div>
                    <div class="bar"><i class="<?= $persenHadir >= 80 ? 'n-hijau' : ($persenHadir >= 60 ? 'n-kuning' : 'n-merah') ?>" style="width: <?= $persenHadir ?>%"></i></div>
                    <p class="sub-info"><?= $persenHadir ?>% kehadiran</p>
                <?php endif; ?>
            </div>

            <?php if ($totalAbsen > 0): ?>
                <div class="panel">
                    <div class="panel-kepala"><h3>Riwayat Terbaru</h3></div>
                    <div class="daftar">
                        <?php foreach (array_slice($absensi, 0, 15) as $a): ?>
                            <div class="baris-info">
                                <span class="jam-chip"><?= e(tglIndo($a['tanggal'], $bulan)) ?></span>
                                <div class="isi">
                                    <b><?= e($a['mapel'] ?: 'Mata pelajaran') ?></b>
                                    <span><?= e($a['guru']) ?></span>
                                </div>
                                <span class="badge <?= $kelasAbsen[$a['status']] ?? 'dl-santai' ?>"><?= e($labelAbsen[$a['status']] ?? $a['status']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="bagian" id="fokus">
            <div class="grid-2">
                <div class="panel timer">
                    <div class="mode">
                        <button type="button" class="aktif" data-mode="fokus">Fokus 25</button>
                        <button type="button" data-mode="istirahat">Istirahat 5</button>
                        <button type="button" data-mode="panjang">Istirahat Panjang 15</button>
                    </div>

                    <div class="ring">
                        <svg viewBox="0 0 200 200">
                            <defs>
                                <linearGradient id="grad" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0" stop-color="#253745"></stop>
                                    <stop offset="1" stop-color="#ff5fa2"></stop>
                                </linearGradient>
                            </defs>
                            <circle class="dasar" cx="100" cy="100" r="90"></circle>
                            <circle class="maju" id="ringMaju" cx="100" cy="100" r="90"></circle>
                        </svg>
                        <div class="waktu">
                            <b id="waktuFokus">25:00</b>
                            <span id="labelFokus">Waktunya fokus</span>
                        </div>
                    </div>

                    <div class="kontrol">
                        <button type="button" class="btn" id="tombolMulai">Mulai</button>
                        <button type="button" class="btn-garis" id="tombolUlang">Ulangi</button>
                    </div>
                    <p class="sesi">Sesi fokus selesai hari ini: <b id="jumlahSesi">0</b></p>
                </div>

                <div class="panel">
                    <div class="panel-kepala"><h3>Tips Belajar Efektif</h3></div>
                    <div class="tips">
                        <div><span>1</span><p>Belajar fokus selama 25 menit, lalu istirahat 5 menit.</p></div>
                        <div><span>2</span><p>Simpan ponsel jauh dari meja saat sesi fokus berjalan.</p></div>
                        <div><span>3</span><p>Setelah 4 sesi fokus, ambil istirahat panjang 15 menit.</p></div>
                        <div><span>4</span><p>Tulis satu tujuan kecil sebelum mulai, misalnya selesaikan 5 soal.</p></div>
                        <div><span>5</span><p>Minum air putih dan regangkan badan saat istirahat.</p></div>
                    </div>
                </div>
            </div>
        </section>

    </main>
</div>

<datalist id="daftarMapel">
    <?php foreach ($daftarMapel as $m): ?>
        <option value="<?= e($m) ?>"></option>
    <?php endforeach; ?>
</datalist>

<div class="overlay" id="konfirmasi">
    <div class="modal">
        <h3>Yakin dihapus?</h3>
        <p id="teksKonfirmasi"></p>
        <div class="modal-aksi">
            <button type="button" class="btn-modal bahaya" id="ya">Ya, Hapus</button>
            <button type="button" class="btn-modal garis" id="batal">Batal</button>
        </div>
    </div>
</div>

<div class="overlay" id="modalKirim">
    <div class="modal profil">
        <h3>Kirim Jawaban</h3>
        <p id="kirimJudul"></p>
        <form method="POST" action="aksi.php">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="aksi" value="kirim_tugas">
            <input type="hidden" name="id" id="kirimId" value="">
            <div class="medan">
                <label for="kirimJawaban">Jawabanmu</label>
                <textarea class="input" id="kirimJawaban" name="jawaban" rows="5" maxlength="3000" placeholder="Tulis jawaban atau penjelasan pengerjaanmu"></textarea>
            </div>
            <div class="medan">
                <label for="kirimTautan">Tautan hasil kerja (opsional)</label>
                <input class="input" type="url" id="kirimTautan" name="tautan" maxlength="255" placeholder="https://drive.google.com/...">
            </div>
            <div class="modal-aksi">
                <button type="submit" class="btn-modal utama">Kirim</button>
                <button type="button" class="btn-modal garis" id="tutupKirim">Batal</button>
            </div>
        </form>
    </div>
</div>

<div class="overlay" id="modalProfil" <?= $kelasSaya === '' ? 'data-wajib="1"' : '' ?>>
    <div class="modal profil">
        <h3>Pilih Kelasmu</h3>
        <p>Jadwal pelajaran dan tugas dari guru akan menyesuaikan kelas yang kamu pilih.</p>
        <form method="POST" action="aksi.php">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="aksi" value="simpan_profil">
            <div class="medan">
                <?php if (count($daftarKelasSemua) === 0): ?>
                    <p class="pilihan-kosong">Daftar kelas belum tersedia, hubungi admin.</p>
                <?php else: ?>
                    <div class="pilihan banyak">
                        <?php foreach ($daftarKelasSemua as $k): ?>
                            <label><input type="radio" name="kelas" value="<?= e($k) ?>" <?= $kelasSaya === $k ? 'checked' : '' ?> required><span><?= e($k) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-aksi">
                <button type="submit" class="btn-modal utama" <?= count($daftarKelasSemua) === 0 ? 'disabled' : '' ?>>Simpan Kelas</button>
                <button type="button" class="btn-modal garis" id="tutupProfil">Nanti</button>
            </div>
        </form>
    </div>
</div>

<?php if ($flash): ?>
    <div class="toast <?= e($flash['tipe']) ?>" id="toast"><?= e($flash['teks']) ?></div>
<?php endif; ?>

<script>
window.onpageshow = function (event) {
    if (event.persisted) { window.location.reload(); }
};

const infoBagian = {
    beranda: ['Beranda', 'Ringkasan belajarmu hari ini'],
    tugas:   ['Tugas', 'Kirim jawaban tugas dari guru sebelum batas waktu'],
    jadwal:  ['Jadwal Pelajaran', 'Atur pelajaran mingguanmu'],
    catatan: ['Catatan', 'Simpan ide dan materi penting'],
    nilai:   ['Nilai', 'Nilai yang diberikan guru untukmu'],
    kehadiran: ['Kehadiran', 'Rekap absensi dari guru'],
    fokus:   ['Mode Fokus', 'Belajar tanpa gangguan dengan teknik Pomodoro']
};

const semuaBagian = document.querySelectorAll('.bagian');
const semuaNav    = document.querySelectorAll('.nav');
const judulBagian = document.getElementById('judulBagian');
const subBagian   = document.getElementById('subBagian');

function bukaBagian(nama) {
    if (!infoBagian[nama]) { nama = 'beranda'; }
    semuaBagian.forEach(b => b.classList.toggle('aktif', b.id === nama));
    semuaNav.forEach(n => n.classList.toggle('aktif', n.dataset.bagian === nama));
    judulBagian.textContent = infoBagian[nama][0];
    subBagian.textContent   = infoBagian[nama][1];
    history.replaceState(null, '', '#' + nama);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.querySelectorAll('[data-bagian]').forEach(tombol => {
    tombol.addEventListener('click', () => bukaBagian(tombol.dataset.bagian));
});

bukaBagian(location.hash.slice(1));
window.addEventListener('hashchange', () => bukaBagian(location.hash.slice(1)));

const jamLive = document.getElementById('jamLive');
const tglLive = document.getElementById('tglLive');

function perbaruiJam() {
    const sekarang = new Date();
    jamLive.textContent = sekarang.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).replace(/\./g, ':');
    tglLive.textContent = sekarang.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

perbaruiJam();
setInterval(perbaruiJam, 1000);

const tombolFilter = document.querySelectorAll('[data-filter]');
const itemTugas    = document.querySelectorAll('.tugas-item');
const kosongFilter = document.getElementById('kosongFilter');

tombolFilter.forEach(tombol => {
    tombol.addEventListener('click', () => {
        const filter = tombol.dataset.filter;
        tombolFilter.forEach(t => t.classList.toggle('aktif', t === tombol));
        let terlihat = 0;
        itemTugas.forEach(item => {
            const cocok = filter === 'semua' || item.dataset.status === filter;
            item.classList.toggle('sembunyi', !cocok);
            if (cocok) { terlihat++; }
        });
        if (kosongFilter) { kosongFilter.classList.toggle('tampil', terlihat === 0); }
    });
});

const konfirmasi      = document.getElementById('konfirmasi');
const teksKonfirmasi  = document.getElementById('teksKonfirmasi');
let formTertunda = null;

function tutupKonfirmasi() {
    konfirmasi.classList.remove('tampil');
    formTertunda = null;
}

document.querySelectorAll('form[data-konfirm]').forEach(form => {
    form.addEventListener('submit', e => {
        e.preventDefault();
        formTertunda = form;
        teksKonfirmasi.textContent = form.dataset.konfirm;
        konfirmasi.classList.add('tampil');
    });
});

document.getElementById('ya').addEventListener('click', () => {
    if (formTertunda) { formTertunda.submit(); }
});
document.getElementById('batal').addEventListener('click', tutupKonfirmasi);
konfirmasi.addEventListener('click', e => { if (e.target === konfirmasi) { tutupKonfirmasi(); } });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { tutupKonfirmasi(); } });

const modalProfil = document.getElementById('modalProfil');

function bukaProfil() { modalProfil.classList.add('tampil'); }
function tutupProfil() { modalProfil.classList.remove('tampil'); }

document.querySelectorAll('[data-profil]').forEach(t => t.addEventListener('click', bukaProfil));
document.getElementById('tutupProfil').addEventListener('click', tutupProfil);
modalProfil.addEventListener('click', e => { if (e.target === modalProfil) { tutupProfil(); } });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { tutupProfil(); } });
if (modalProfil.dataset.wajib === '1' && !document.getElementById('toast')) { bukaProfil(); }

const modalKirim = document.getElementById('modalKirim');

function tutupKirim() { modalKirim.classList.remove('tampil'); }

document.querySelectorAll('[data-kirim]').forEach(t => {
    t.addEventListener('click', () => {
        document.getElementById('kirimId').value = t.dataset.kirim;
        document.getElementById('kirimJudul').textContent = t.dataset.judul;
        document.getElementById('kirimJawaban').value = t.dataset.jawaban;
        document.getElementById('kirimTautan').value = t.dataset.tautan;
        modalKirim.classList.add('tampil');
        document.getElementById('kirimJawaban').focus();
    });
});
document.getElementById('tutupKirim').addEventListener('click', tutupKirim);
modalKirim.addEventListener('click', e => { if (e.target === modalKirim) { tutupKirim(); } });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { tutupKirim(); } });

const toast = document.getElementById('toast');
if (toast) { setTimeout(() => toast.remove(), 4300); }

const durasi = { fokus: 25, istirahat: 5, panjang: 15 };
const label  = { fokus: 'Waktunya fokus', istirahat: 'Istirahat sebentar', panjang: 'Istirahat panjang' };
const KELILING = 565.49;

const waktuEl    = document.getElementById('waktuFokus');
const labelEl    = document.getElementById('labelFokus');
const ringMaju   = document.getElementById('ringMaju');
const tombolMulai = document.getElementById('tombolMulai');
const tombolUlang = document.getElementById('tombolUlang');
const jumlahSesi = document.getElementById('jumlahSesi');
const tombolMode = document.querySelectorAll('[data-mode]');
const judulAsli  = document.title;

let modeAktif = 'fokus';
let total = durasi.fokus * 60;
let sisa = total;
let jalan = null;

const kunciSesi = 'sesiFokus-' + new Date().toDateString();

function bacaSesi() {
    try { return parseInt(localStorage.getItem(kunciSesi) || '0', 10); } catch (e) { return 0; }
}

function tambahSesi() {
    try { localStorage.setItem(kunciSesi, String(bacaSesi() + 1)); } catch (e) {}
    jumlahSesi.textContent = bacaSesi();
}

jumlahSesi.textContent = bacaSesi();

function tampilkan() {
    const menit = String(Math.floor(sisa / 60)).padStart(2, '0');
    const detik = String(sisa % 60).padStart(2, '0');
    waktuEl.textContent = menit + ':' + detik;
    ringMaju.style.strokeDashoffset = KELILING * (1 - sisa / total);
    document.title = jalan ? menit + ':' + detik + ' - ' + label[modeAktif] : judulAsli;
}

function bunyi() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        [0, 0.3, 0.6].forEach(jeda => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 880;
            gain.gain.setValueAtTime(0.15, ctx.currentTime + jeda);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + jeda + 0.22);
            osc.start(ctx.currentTime + jeda);
            osc.stop(ctx.currentTime + jeda + 0.22);
        });
    } catch (e) {}
}

function berhenti() {
    clearInterval(jalan);
    jalan = null;
    tombolMulai.textContent = 'Mulai';
}

function aturMode(nama) {
    berhenti();
    modeAktif = nama;
    total = durasi[nama] * 60;
    sisa = total;
    labelEl.textContent = label[nama];
    tombolMode.forEach(t => t.classList.toggle('aktif', t.dataset.mode === nama));
    tampilkan();
}

function selesai() {
    berhenti();
    bunyi();
    if (modeAktif === 'fokus') {
        tambahSesi();
        aturMode('istirahat');
    } else {
        aturMode('fokus');
    }
}

tombolMulai.addEventListener('click', () => {
    if (jalan) {
        berhenti();
        tampilkan();
        return;
    }
    tombolMulai.textContent = 'Jeda';
    jalan = setInterval(() => {
        sisa--;
        tampilkan();
        if (sisa <= 0) { selesai(); }
    }, 1000);
});

tombolUlang.addEventListener('click', () => aturMode(modeAktif));
tombolMode.forEach(t => t.addEventListener('click', () => aturMode(t.dataset.mode)));

tampilkan();
</script>
</body>
</html>
