<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (!isset($_SESSION['role'])) {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SESSION['role'] != 'guru') {
    die("Akses Ditolak! Anda bukan Guru. <a href='../auth/logout.php'>Logout</a>");
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

function ambil($conn, $sql, $tipe = '', ...$param) {
    $stmt = mysqli_prepare($conn, $sql);
    if ($tipe !== '') {
        mysqli_stmt_bind_param($stmt, $tipe, ...$param);
    }
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

$hariIniTgl = date('Y-m-d');
$tgl = $_GET['tgl'] ?? $hariIniTgl;
$cekTgl = DateTime::createFromFormat('Y-m-d', $tgl);
if (!$cekTgl || $cekTgl->format('Y-m-d') !== $tgl || $tgl > $hariIniTgl) {
    $tgl = $hariIniTgl;
}

try {
    $profil  = profilPengguna($conn, $uid);
    $mapelSaya = trim((string)($profil['mapel'] ?? ''));
    $daftarKelasSemua = daftarKelas($conn);
    $daftarMapelSemua = daftarMapel($conn);
    $siswa   = ambil($conn, "SELECT id, nama, username, email, kelas FROM users WHERE role = 'murid' ORDER BY nama ASC");
    $tugasKelas = ambil($conn, "SELECT t.*, (SELECT COUNT(*) FROM pengumpulan s WHERE s.tugas_id = t.id) AS selesai_n FROM tugas_kelas t WHERE t.guru_id = ? ORDER BY t.deadline DESC, t.id DESC", "i", $uid);
    $kirimanRaw = ambil($conn, "SELECT p.* FROM pengumpulan p JOIN tugas_kelas t ON t.id = p.tugas_id WHERE t.guru_id = ?", "i", $uid);
    $nilaiGuru  = ambil($conn, "SELECT n.id, n.jenis, n.nilai, n.pengumpulan_id, u.nama, u.kelas FROM nilai n JOIN users u ON u.id = n.user_id WHERE n.guru_id = ? ORDER BY n.id DESC LIMIT 100", "i", $uid);
    $jadwal  = ambil($conn, "SELECT * FROM jadwal_guru WHERE user_id = ? ORDER BY FIELD(hari,'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'), jam_mulai", "i", $uid);
    $agenda  = ambil($conn, "SELECT * FROM agenda_guru WHERE user_id = ? ORDER BY selesai ASC, tanggal ASC, id DESC", "i", $uid);
    $catatan = ambil($conn, "SELECT * FROM catatan_guru WHERE user_id = ? ORDER BY id DESC", "i", $uid);
    $absenTgl = ambil($conn, "SELECT siswa_id, status FROM absensi WHERE guru_id = ? AND tanggal = ?", "is", $uid, $tgl);
    $rekapRaw = ambil($conn, "SELECT siswa_id, status, COUNT(*) AS jml FROM absensi WHERE guru_id = ? GROUP BY siswa_id, status", "i", $uid);
    $absenHariIniRow = ambil($conn, "SELECT COUNT(*) AS n FROM absensi WHERE guru_id = ? AND tanggal = ?", "is", $uid, $hariIniTgl);
} catch (mysqli_sql_exception $ex) {
    die("Tabel dashboard guru belum lengkap. Import database_guru_admin.sql dan database_sinkron.sql ke database kamu terlebih dahulu.");
}

$namaHari = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
$bulan    = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];
$hariIni  = $namaHari[(int)date('N')];

function tglIndo($tgl, $bulan) {
    $d = new DateTime($tgl);
    return $d->format('j') . ' ' . $bulan[(int)$d->format('n')];
}

function tglPanjang($tgl, $bulan, $namaHari) {
    $d = new DateTime($tgl);
    return $namaHari[(int)$d->format('N')] . ', ' . $d->format('j') . ' ' . $bulan[(int)$d->format('n')] . ' ' . $d->format('Y');
}

function sisaHari($tanggal) {
    $d = new DateTime($tanggal);
    $t = new DateTime('today');
    return (int)$t->diff($d)->format('%r%a');
}

function labelDeadline($tanggal, $bulan) {
    $sisa = sisaHari($tanggal);
    if ($sisa < 0)  return ['Terlewat ' . abs($sisa) . ' hari', 'dl-telat'];
    if ($sisa == 0) return ['Hari ini', 'dl-hari'];
    if ($sisa == 1) return ['Besok', 'dl-besok'];
    if ($sisa <= 7) return [$sisa . ' hari lagi', 'dl-minggu'];
    return [tglIndo($tanggal, $bulan), 'dl-santai'];
}

$statusSiswa = [];
foreach ($absenTgl as $a) {
    $statusSiswa[(int)$a['siswa_id']] = $a['status'];
}

$rekap = [];
foreach ($rekapRaw as $r) {
    $rekap[(int)$r['siswa_id']][$r['status']] = (int)$r['jml'];
}

$kelasDiajar = [];
foreach ($jadwal as $j) {
    if ($j['kelas'] !== '' && !in_array($j['kelas'], $kelasDiajar, true)) {
        $kelasDiajar[] = $j['kelas'];
    }
}
foreach ($tugasKelas as $tk) {
    if (!in_array($tk['kelas'], $kelasDiajar, true)) {
        $kelasDiajar[] = $tk['kelas'];
    }
}

$kirimanPerTugas = [];
foreach ($kirimanRaw as $kr) {
    $kirimanPerTugas[(int)$kr['tugas_id']][(int)$kr['siswa_id']] = $kr;
}

$siswaPerKelas = [];
foreach ($siswa as $sw) {
    $siswaPerKelas[(string)$sw['kelas']][] = $sw;
}

$urutanKelas = array_values(array_unique(array_merge($kelasDiajar, $daftarKelasSemua)));

$kelasAbsen = $_GET['kelas'] ?? '';
if (!in_array($kelasAbsen, $urutanKelas, true)) {
    $kelasAbsen = '';
    foreach ($jadwal as $j) {
        if ($j['hari'] === $hariIni && $j['kelas'] !== '') {
            $kelasAbsen = $j['kelas'];
            break;
        }
    }
    if ($kelasAbsen === '' && count($kelasDiajar) > 0) {
        $kelasAbsen = $kelasDiajar[0];
    }
    if ($kelasAbsen === '' && count($urutanKelas) > 0) {
        $kelasAbsen = $urutanKelas[0];
    }
}
$siswaKelas = $siswaPerKelas[$kelasAbsen] ?? [];

$jumlahSiswa  = count($siswaKelas);
$siswaDiajar  = 0;
foreach ($kelasDiajar as $kd) {
    $siswaDiajar += count($siswaPerKelas[$kd] ?? []);
}
$siswaTanpaKelas = count($siswaPerKelas['']  ?? []);
$absenHariIni = (int)$absenHariIniRow[0]['n'];

$tugasAktifN = count(array_filter($tugasKelas, fn($t) => sisaHari($t['deadline']) >= 0));

$total    = count($agenda);
$aktif    = array_values(array_filter($agenda, fn($a) => $a['selesai'] == 0));
$aktifN   = count($aktif);
$selesaiN = $total - $aktifN;
$persen   = $total > 0 ? (int)round($selesaiN / $total * 100) : 0;
$mendesak = array_slice($aktif, 0, 4);

$jadwalHariIni = array_values(array_filter($jadwal, fn($j) => $j['hari'] === $hariIni));
$jadwalPerHari = [];
foreach ($jadwal as $j) {
    $jadwalPerHari[$j['hari']][] = $j;
}

$varian = ['', 'v-pink', 'v-hijau', 'v-kuning'];

$jam = (int)date('H');
if ($jam < 11)      { $salam = 'Selamat pagi'; }
elseif ($jam < 15)  { $salam = 'Selamat siang'; }
elseif ($jam < 18)  { $salam = 'Selamat sore'; }
else                { $salam = 'Selamat malam'; }

$kutipanList = [
    'Guru yang baik menyalakan rasa ingin tahu, bukan hanya mengisi pengetahuan.',
    'Satu catatan kecil hari ini bisa jadi bahan evaluasi besar minggu depan.',
    'Kelas yang tertata dimulai dari jadwal dan agenda yang jelas.',
    'Setiap siswa punya cara belajarnya sendiri, temukan dan dukung.',
    'Konsisten hadir adalah pelajaran pertama yang dilihat murid.',
    'Apresiasi kecil bisa membuat murid belajar lebih semangat.',
    'Rencanakan hari ini agar mengajar terasa lebih ringan.'
];
$kutipan = $kutipanList[(int)date('z') % count($kutipanList)];

$namaLengkap = $_SESSION['nama'] ?? 'Guru';
$namaDepan   = explode(' ', trim($namaLengkap))[0];
$inisial     = mb_strtoupper(mb_substr($namaLengkap, 0, 1));

$labelStatus = ['H' => 'Hadir', 'I' => 'Izin', 'S' => 'Sakit', 'A' => 'Alpa'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Guru</title>
    <link href="dashboard.css?v=<?= filemtime(__DIR__ . '/dashboard.css') ?>" rel="stylesheet">
</head>
<body>
<div class="app">

    <aside class="sidebar">
        <div class="brand"> Portal Sekolah</div>

        <nav class="menu m8">
            <button type="button" class="nav aktif" data-bagian="beranda"><span class="nt">Beranda</span></button>
            <button type="button" class="nav" data-bagian="absensi"><span class="nt">Absensi</span></button>
            <button type="button" class="nav" data-bagian="siswa"><span class="nt">Siswa</span></button>
            <button type="button" class="nav" data-bagian="nilai"><span class="nt">Nilai</span></button>
            <button type="button" class="nav" data-bagian="jadwal"><span class="nt">Jadwal</span></button>
            <button type="button" class="nav" data-bagian="tugas"><span class="nt">Tugas</span><?php if ($tugasAktifN > 0): ?><em class="lencana"><?= $tugasAktifN ?></em><?php endif; ?></button>
            <button type="button" class="nav" data-bagian="agenda"><span class="nt">Agenda</span><?php if ($aktifN > 0): ?><em class="lencana"><?= $aktifN ?></em><?php endif; ?></button>
            <button type="button" class="nav" data-bagian="catatan"><span class="nt">Catatan</span></button>
        </nav>

        <div class="user-card">
            <span class="avatar"><?= e($inisial) ?></span>
            <div class="user-info">
                <b><?= e($namaLengkap) ?></b>
                <small>Guru<?php if ($mapelSaya !== ''): ?> <span class="tag-kecil"><?= e($mapelSaya) ?></span><?php endif; ?></small>
            </div>
            <button type="button" class="ubah-profil" data-profil title="Ubah mata pelajaran" aria-label="Ubah mata pelajaran">Ubah</button>
            <a href="../auth/logout.php" class="keluar" title="Keluar">Keluar</a>
        </div>
    </aside>

    <main class="main">

        <header class="topbar">
            <div>
                <h1 id="judulBagian">Beranda</h1>
                <p id="subBagian">Ringkasan mengajarmu hari ini</p>
            </div>
            <div class="topbar-kanan">
                <div class="jam">
                    <b id="jamLive">--:--</b>
                    <span id="tglLive"></span>
                </div>
                <a href="../auth/logout.php" class="keluar-mobile">Keluar</a>
            </div>
        </header>

        <?php if ($mapelSaya === ''): ?>
            <div class="tagih">
                <div class="isi">
                    <b>Kamu belum memilih mata pelajaran</b>
                    <span>Pilih mapel yang kamu ajar agar jadwal dan tugas yang kamu buat tersambung ke kelas yang tepat.</span>
                </div>
                <button type="button" class="btn" data-profil>Pilih Mapel</button>
            </div>
        <?php endif; ?>

        <section class="bagian aktif" id="beranda">
            <div class="hero-card">
                <div class="hero-teks">
                    <small><?= $salam ?></small><?php if ($mapelSaya !== ''): ?><span class="label-profil"><?= e($mapelSaya) ?></span><?php endif; ?>
                    <h2>Halo, <?= e($namaDepan) ?>!</h2>
                    <p>“<?= e($kutipan) ?>”</p>
                </div>
                <div class="cincin" style="--p: <?= $persen ?>">
                    <span><b><?= $persen ?>%</b><small>agenda selesai</small></span>
                </div>
            </div>

            <div class="stat-grid">
                <div class="stat s-ungu">
                    <div><b><?= $siswaDiajar ?></b><span>Siswa di kelasmu</span></div>
                </div>
                <div class="stat s-hijau">
                    <div><b><?= count($jadwalHariIni) ?></b><span>Mengajar hari ini</span></div>
                </div>
                <div class="stat s-pink">
                    <div><b><?= $tugasAktifN ?></b><span>Tugas kelas aktif</span></div>
                </div>
                <div class="stat s-kuning">
                    <div><b><?= $absenHariIni ?>/<?= $siswaDiajar ?></b><span>Absensi hari ini</span></div>
                </div>
            </div>

            <div class="grid-2">
                <div class="panel">
                    <div class="panel-kepala">
                        <h3>Mengajar Hari Ini <small><?= $hariIni ?></small></h3>
                        <button type="button" class="link" data-bagian="jadwal">Kelola</button>
                    </div>
                    <?php if (count($jadwalHariIni) === 0): ?>
                        <div class="kosong"><p>Tidak ada jadwal mengajar hari ini.</p></div>
                    <?php else: ?>
                        <div class="daftar">
                            <?php foreach ($jadwalHariIni as $j): ?>
                                <div class="baris-info">
                                    <span class="jam-chip"><?= e(substr($j['jam_mulai'], 0, 5)) ?><br><small><?= e(substr($j['jam_selesai'], 0, 5)) ?></small></span>
                                    <div class="isi">
                                        <b><?= e($j['mapel']) ?><?= $j['kelas'] !== '' ? ' · ' . e($j['kelas']) : '' ?></b>
                                        <span><?= $j['ruang'] !== '' ? e($j['ruang']) : 'Tanpa ruang' ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-kepala">
                        <h3>Agenda Mendesak</h3>
                        <button type="button" class="link" data-bagian="agenda">Lihat semua</button>
                    </div>
                    <?php if (count($mendesak) === 0): ?>
                        <div class="kosong"><p>Tidak ada agenda aktif. Santai dulu!</p></div>
                    <?php else: ?>
                        <div class="daftar">
                            <?php foreach ($mendesak as $a): ?>
                                <?php [$teksDl, $kelasDl] = labelDeadline($a['tanggal'], $bulan); ?>
                                <div class="baris-info">
                                    <span class="badge kat-<?= e(strtolower($a['kategori'])) ?>"><?= e($a['kategori']) ?></span>
                                    <div class="isi">
                                        <b><?= e($a['judul']) ?></b>
                                    </div>
                                    <span class="badge <?= $kelasDl ?>"><?= e($teksDl) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="bagian" id="absensi">
            <div class="panel">
                <div class="panel-kepala">
                    <h3>Absensi Harian <small>Kelas <?= e($kelasAbsen !== '' ? $kelasAbsen : '-') ?></small></h3>
                    <span class="tgl-label"><?= e(tglPanjang($tgl, $bulan, $namaHari)) ?></span>
                </div>

                <?php if (count($urutanKelas) === 0): ?>
                    <div class="kosong"><p>Belum ada kelas. Minta admin menambahkan daftar kelas.</p></div>
                <?php else: ?>
                    <div class="filter lebar" style="margin-bottom: 18px">
                        <?php foreach ($urutanKelas as $k): ?>
                            <a href="dashboard.php?tgl=<?= e($tgl) ?>&amp;kelas=<?= urlencode($k) ?>#absensi" class="<?= $k === $kelasAbsen ? 'aktif' : '' ?>"><?= in_array($k, $kelasDiajar, true) ? '' : '' ?><?= e($k) ?></a>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($jumlahSiswa === 0): ?>
                        <div class="kosong"><p>Belum ada siswa yang memilih kelas <?= e($kelasAbsen) ?>.</p></div>
                    <?php else: ?>
                        <div class="absen-atas">
                            <form method="GET" action="dashboard.php#absensi" class="tgl-form" id="formTgl">
                                <input type="hidden" name="kelas" value="<?= e($kelasAbsen) ?>">
                                <input class="input" type="date" id="tglAbsen" name="tgl" value="<?= e($tgl) ?>" max="<?= e($hariIniTgl) ?>">
                            </form>
                            <button type="button" class="link" id="semuaHadir">Tandai semua hadir</button>
                        </div>

                        <form method="POST" action="aksi.php">
                            <input type="hidden" name="csrf" value="<?= $csrf ?>">
                            <input type="hidden" name="aksi" value="simpan_absen">
                            <input type="hidden" name="tanggal" value="<?= e($tgl) ?>">
                            <input type="hidden" name="kelas" value="<?= e($kelasAbsen) ?>">

                            <div class="absen-daftar">
                                <?php foreach ($siswaKelas as $sw): ?>
                                    <?php $sid = (int)$sw['id']; $sekarang = $statusSiswa[$sid] ?? 'H'; ?>
                                    <div class="absen-baris">
                                        <span class="avatar-kecil <?= $varian[$sid % 4] ?>"><?= e(mb_strtoupper(mb_substr($sw['nama'], 0, 1))) ?></span>
                                        <div class="isi">
                                            <b><?= e($sw['nama']) ?></b>
                                            <span>@<?= e($sw['username']) ?><?= isset($statusSiswa[$sid]) ? '' : ' · belum diabsen' ?></span>
                                        </div>
                                        <div class="status-pilih">
                                            <?php foreach ($labelStatus as $kode => $nama): ?>
                                                <label class="s-<?= strtolower($kode) ?>"><input type="radio" name="status[<?= $sid ?>]" value="<?= $kode ?>" <?= $sekarang === $kode ? 'checked' : '' ?>><span><?= $nama ?></span></label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <button type="submit" class="btn">Simpan Absensi</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($jumlahSiswa > 0): ?>
                <div class="panel">
                    <div class="panel-kepala"><h3>Rekap Kehadiran <small>Kelas <?= e($kelasAbsen) ?></small></h3></div>
                    <div class="rekap-daftar">
                        <?php foreach ($siswaKelas as $s): ?>
                            <?php
                                $sid  = (int)$s['id'];
                                $data = $rekap[$sid] ?? [];
                                $h = $data['H'] ?? 0;
                                $i = $data['I'] ?? 0;
                                $sk = $data['S'] ?? 0;
                                $al = $data['A'] ?? 0;
                                $jml = $h + $i + $sk + $al;
                                $pHadir = $jml > 0 ? (int)round($h / $jml * 100) : 0;
                                $kelasBar = $pHadir >= 80 ? 'n-hijau' : ($pHadir >= 60 ? 'n-kuning' : 'n-merah');
                            ?>
                            <div class="rekap-baris">
                                <b><?= e($s['nama']) ?></b>
                                <div class="rekap-chips">
                                    <span class="rc rc-h">H <?= $h ?></span>
                                    <span class="rc rc-i">I <?= $i ?></span>
                                    <span class="rc rc-s">S <?= $sk ?></span>
                                    <span class="rc rc-a">A <?= $al ?></span>
                                </div>
                                <div class="persen-hadir">
                                    <small><?= $jml > 0 ? $pHadir . '% hadir' : 'Belum ada data' ?></small>
                                    <div class="bar"><i class="<?= $kelasBar ?>" style="width: <?= $pHadir ?>%"></i></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="bagian" id="siswa">
            <div class="panel">
                <div class="panel-kepala">
                    <h3>Daftar Siswa <small><?= count($siswa) ?> siswa</small></h3>
                </div>

                <?php if (count($siswa) === 0): ?>
                    <div class="kosong"><p>Belum ada siswa yang terdaftar.</p></div>
                <?php else: ?>
                    <div class="bar-alat">
                        <div class="cari">
                            <input class="input" type="text" id="cariSiswa" placeholder="Cari nama, username, atau email">
                        </div>
                        <div class="filter lebar">
                            <button type="button" class="aktif" data-kelasfilter="semua">Semua</button>
                            <?php foreach ($urutanKelas as $k): ?>
                                <button type="button" data-kelasfilter="<?= e($k) ?>"><?= e($k) ?></button>
                            <?php endforeach; ?>
                            <?php if ($siswaTanpaKelas > 0): ?>
                                <button type="button" data-kelasfilter="">Belum pilih kelas</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="siswa-grid">
                        <?php foreach ($siswa as $s): ?>
                            <div class="kartu-siswa" data-kelas="<?= e((string)$s['kelas']) ?>" data-cari="<?= e(mb_strtolower($s['nama'] . ' ' . $s['username'] . ' ' . $s['email'])) ?>">
                                <span class="avatar-kecil <?= $varian[(int)$s['id'] % 4] ?>"><?= e(mb_strtoupper(mb_substr($s['nama'], 0, 1))) ?></span>
                                <div class="isi">
                                    <b><?= e($s['nama']) ?></b>
                                    <span>@<?= e($s['username']) ?></span>
                                    <small><?= e($s['email']) ?></small>
                                </div>
                                <?php if ((string)$s['kelas'] !== ''): ?>
                                    <span class="badge badge-kelas"><?= e($s['kelas']) ?></span>
                                <?php else: ?>
                                    <span class="badge dl-hari">Tanpa kelas</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="kosong sembunyi" id="kosongSiswa"><p>Tidak ada siswa yang cocok.</p></div>
                <?php endif; ?>
            </div>
        </section>

        <section class="bagian" id="jadwal">
            <div class="panel">
                <div class="panel-kepala"><h3>Tambah Jadwal Mengajar <small>otomatis tampil di jadwal siswa</small></h3></div>
                <?php if ($mapelSaya === ''): ?>
                    <div class="kosong"><p>Pilih mata pelajaranmu dulu sebelum membuat jadwal.</p><button type="button" class="btn" data-profil>Pilih Mapel</button></div>
                <?php else: ?>
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
                    <div class="medan k-12">
                        <label>Kelas</label>
                        <?php if (count($daftarKelasSemua) === 0): ?>
                            <p class="pilihan-kosong">Daftar kelas belum tersedia, hubungi admin.</p>
                        <?php else: ?>
                            <div class="pilihan banyak">
                                <?php foreach ($daftarKelasSemua as $i => $k): ?>
                                    <label><input type="radio" name="kelas" value="<?= e($k) ?>" <?= $i === 0 ? 'checked' : '' ?>><span><?= e($k) ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="medan k-4">
                        <label>Mata pelajaran</label>
                        <div class="pilihan"><label><input type="radio" checked disabled><span><?= e($mapelSaya) ?></span></label></div>
                    </div>
                    <div class="medan k-3">
                        <label for="j-mulai">Mulai</label>
                        <input class="input" type="time" id="j-mulai" name="jam_mulai" value="07:00" required>
                    </div>
                    <div class="medan k-3">
                        <label for="j-akhir">Selesai</label>
                        <input class="input" type="time" id="j-akhir" name="jam_selesai" value="08:30" required>
                    </div>
                    <div class="medan k-2">
                        <label for="j-ruang">Ruang</label>
                        <input class="input" type="text" id="j-ruang" name="ruang" maxlength="50" placeholder="Lab 1">
                    </div>
                    <div class="k-12"><button type="submit" class="btn">Simpan Jadwal</button></div>
                </form>
                <?php endif; ?>
            </div>

            <div class="minggu">
                <?php foreach ($namaHari as $h): ?>
                    <div class="hari <?= $h === $hariIni ? 'hari-ini' : '' ?>">
                        <h4><?= $h ?><?php if ($h === $hariIni): ?> <small>Hari ini</small><?php endif; ?></h4>
                        <?php if (empty($jadwalPerHari[$h])): ?>
                            <p class="kosong-kecil">Kosong</p>
                        <?php else: ?>
                            <?php foreach ($jadwalPerHari[$h] as $j): ?>
                                <div class="pelajaran">
                                    <form method="POST" action="aksi.php" data-konfirm="Hapus jadwal &quot;<?= e($j['mapel']) ?>&quot;?">
                                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                        <input type="hidden" name="aksi" value="hapus_jadwal">
                                        <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
                                        <button type="submit" class="hapus-kecil" aria-label="Hapus">✕</button>
                                    </form>
                                    <b><?= e($j['mapel']) ?></b>
                                    <span><?= e(substr($j['jam_mulai'], 0, 5)) ?> - <?= e(substr($j['jam_selesai'], 0, 5)) ?></span>
                                    <?php if ($j['kelas'] !== '' || $j['ruang'] !== ''): ?>
                                        <small><?= e(trim($j['kelas'] . ($j['kelas'] !== '' && $j['ruang'] !== '' ? ' · ' : '') . $j['ruang'])) ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="bagian" id="tugas">
            <div class="panel">
                <div class="panel-kepala"><h3>Beri Tugas ke Kelas <small>siswa mengirim jawaban sebelum batas waktu</small></h3></div>
                <?php if ($mapelSaya === ''): ?>
                    <div class="kosong"><p>Pilih mata pelajaranmu dulu sebelum memberi tugas.</p><button type="button" class="btn" data-profil>Pilih Mapel</button></div>
                <?php else: ?>
                    <form method="POST" action="aksi.php" class="form-grid">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="aksi" value="tambah_tugas_kelas">

                        <div class="medan k-6">
                            <label for="tk-judul">Judul tugas</label>
                            <input class="input" type="text" id="tk-judul" name="judul" maxlength="150" placeholder="Contoh: Kerjakan LKPD Bab 3" required>
                        </div>
                        <div class="medan k-3">
                            <label for="tk-deadline">Batas tanggal</label>
                            <input class="input" type="date" id="tk-deadline" name="deadline" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+3 day')) ?>" required>
                        </div>
                        <div class="medan k-3">
                            <label for="tk-jam">Batas jam</label>
                            <input class="input" type="time" id="tk-jam" name="jam_batas" value="23:59" required>
                        </div>
                        <div class="medan k-12">
                            <label for="tk-deskripsi">Petunjuk (opsional)</label>
                            <textarea class="input" id="tk-deskripsi" name="deskripsi" rows="3" maxlength="1000" placeholder="Tulis petunjuk pengerjaan untuk siswa..."></textarea>
                        </div>
                        <div class="medan k-12">
                            <label>Untuk kelas</label>
                            <?php if (count($daftarKelasSemua) === 0): ?>
                                <p class="pilihan-kosong">Daftar kelas belum tersedia, hubungi admin.</p>
                            <?php else: ?>
                                <div class="pilihan banyak">
                                    <?php foreach ($urutanKelas as $k): ?>
                                        <label><input type="checkbox" name="kelas[]" value="<?= e($k) ?>"><span><?= e($k) ?> <small>(<?= count($siswaPerKelas[$k] ?? []) ?> siswa)</small></span></label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="k-12"><button type="submit" class="btn">Kirim Tugas</button></div>
                    </form>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-kepala"><h3>Tugas yang Kamu Berikan <small><?= count($tugasKelas) ?></small></h3></div>
                <?php if (count($tugasKelas) === 0): ?>
                    <div class="kosong"><p>Belum ada tugas. Tugas yang kamu kirim akan muncul di dashboard siswa kelas tersebut.</p></div>
                <?php else: ?>
                    <div class="daftar">
                        <?php foreach ($tugasKelas as $tk): ?>
                            <?php
                                [$teksDl, $kelasDl] = labelDeadline($tk['deadline'], $bulan);
                                $jmlKelas = count($siswaPerKelas[$tk['kelas']] ?? []);
                                $persenTk = $jmlKelas > 0 ? (int)round($tk['selesai_n'] / $jmlKelas * 100) : 0;
                            ?>
                            <div class="tugas-item punya-panel">
                                <div class="isi">
                                    <b><?= e($tk['judul']) ?></b>
                                    <span><?= e($tk['mapel']) ?> · batas <?= e(tglIndo($tk['deadline'], $bulan)) ?>, <?= e($tk['jam_batas'] ?? '23:59') ?></span>
                                    <?php if (trim($tk['deskripsi']) !== ''): ?><span class="deskripsi"><?= e($tk['deskripsi']) ?></span><?php endif; ?>
                                </div>
                                <span class="badge badge-kelas"><?= e($tk['kelas']) ?></span>
                                <div class="progres-tugas">
                                    <small><?= (int)$tk['selesai_n'] ?>/<?= $jmlKelas ?> siswa mengumpulkan</small>
                                    <div class="bar"><i class="<?= $persenTk >= 80 ? 'n-hijau' : ($persenTk >= 40 ? 'n-kuning' : 'n-merah') ?>" style="width: <?= $persenTk ?>%"></i></div>
                                </div>
                                <span class="badge <?= $kelasDl ?>"><?= e($teksDl) ?></span>
                                <button type="button" class="btn-kecil" data-periksa="<?= (int)$tk['id'] ?>">Periksa (<?= (int)$tk['selesai_n'] ?>)</button>
                                <form method="POST" action="aksi.php" data-konfirm="Hapus tugas &quot;<?= e($tk['judul']) ?>&quot; untuk kelas <?= e($tk['kelas']) ?>? Kiriman dan nilai siswa untuk tugas ini juga ikut terhapus.">
                                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                    <input type="hidden" name="aksi" value="hapus_tugas_kelas">
                                    <input type="hidden" name="id" value="<?= (int)$tk['id'] ?>">
                                    <button type="submit" class="hapus" aria-label="Hapus">✕</button>
                                </form>
                                <div class="periksa-panel sembunyi" id="periksa-<?= (int)$tk['id'] ?>">
                                    <?php $daftarKelasIni = $siswaPerKelas[$tk['kelas']] ?? []; ?>
                                    <?php if (count($daftarKelasIni) === 0): ?>
                                        <p class="periksa-kosong">Belum ada siswa di kelas <?= e($tk['kelas']) ?>.</p>
                                    <?php endif; ?>
                                    <?php foreach ($daftarKelasIni as $sw): ?>
                                        <?php $kr = $kirimanPerTugas[(int)$tk['id']][(int)$sw['id']] ?? null; ?>
                                        <div class="kiriman-baris">
                                            <div class="kiriman-kepala">
                                                <b><?= e($sw['nama']) ?></b>
                                                <?php if (!$kr): ?>
                                                    <span class="badge dl-telat">Belum mengumpulkan</span>
                                                <?php elseif ($kr['nilai'] !== null): ?>
                                                    <span class="badge dl-selesai">Dinilai <?= e(rtrim(rtrim(number_format((float)$kr['nilai'], 2), '0'), '.')) ?></span>
                                                <?php else: ?>
                                                    <span class="badge dl-minggu">Menunggu nilai</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($kr): ?>
                                                <small class="kiriman-waktu">Dikirim <?= e(tglIndo($kr['dikirim'], $bulan)) ?>, <?= e(substr($kr['dikirim'], 11, 5)) ?></small>
                                                <?php if (trim($kr['jawaban']) !== ''): ?><p class="kiriman-isi"><?= nl2br(e($kr['jawaban'])) ?></p><?php endif; ?>
                                                <?php if ($kr['tautan'] !== '' && preg_match('#^https?://#i', $kr['tautan'])): ?><a class="kiriman-tautan" href="<?= e($kr['tautan']) ?>" target="_blank" rel="noopener noreferrer"><?= e($kr['tautan']) ?></a><?php endif; ?>
                                                <form method="POST" action="aksi.php" class="kiriman-nilai">
                                                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                                    <input type="hidden" name="aksi" value="nilai_tugas">
                                                    <input type="hidden" name="id" value="<?= (int)$kr['id'] ?>">
                                                    <input class="input" type="number" name="nilai" min="0" max="100" step="0.01" placeholder="Nilai 0-100" value="<?= $kr['nilai'] !== null ? e($kr['nilai']) : '' ?>" required>
                                                    <input class="input" type="text" name="catatan" maxlength="500" placeholder="Catatan untuk siswa (opsional)" value="<?= e($kr['catatan']) ?>">
                                                    <button type="submit" class="btn">Simpan Nilai</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="bagian" id="nilai">
            <div class="panel">
                <div class="panel-kepala"><h3>Beri Nilai <small>langsung tampil di dashboard siswa</small></h3></div>
                <?php if ($mapelSaya === ''): ?>
                    <div class="kosong"><p>Pilih mata pelajaranmu dulu sebelum memberi nilai.</p><button type="button" class="btn" data-profil>Pilih Mapel</button></div>
                <?php elseif (count($siswa) === 0): ?>
                    <div class="kosong"><p>Belum ada siswa terdaftar.</p></div>
                <?php else: ?>
                    <form method="POST" action="aksi.php" class="form-grid">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="aksi" value="beri_nilai">

                        <div class="medan k-12">
                            <label>Kelas</label>
                            <div class="filter lebar" id="kelasNilai">
                                <?php foreach ($urutanKelas as $i => $k): ?>
                                    <button type="button" class="<?= $i === 0 ? 'aktif' : '' ?>" data-kelasnilai="<?= e($k) ?>"><?= e($k) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="medan k-12">
                            <label>Siswa</label>
                            <div class="pilihan banyak" id="siswaNilai">
                                <?php foreach ($siswa as $sw): ?>
                                    <label data-kelas="<?= e((string)$sw['kelas']) ?>"><input type="radio" name="siswa_id" value="<?= (int)$sw['id'] ?>"><span><?= e($sw['nama']) ?></span></label>
                                <?php endforeach; ?>
                                <p class="pilihan-kosong sembunyi" id="kosongSiswaNilai">Belum ada siswa di kelas ini.</p>
                            </div>
                        </div>
                        <div class="medan k-8">
                            <label>Jenis nilai <small>(mapel: <?= e($mapelSaya) ?>)</small></label>
                            <div class="pilihan">
                                <label><input type="radio" name="jenis" value="Harian" checked><span>Harian</span></label>
                                <label><input type="radio" name="jenis" value="Kuis"><span>Kuis</span></label>
                                <label><input type="radio" name="jenis" value="UTS"><span>UTS</span></label>
                                <label><input type="radio" name="jenis" value="UAS"><span>UAS</span></label>
                                <label><input type="radio" name="jenis" value="Praktik"><span>Praktik</span></label>
                            </div>
                        </div>
                        <div class="medan k-4">
                            <label for="bn-nilai">Nilai</label>
                            <input class="input" type="number" id="bn-nilai" name="nilai" min="0" max="100" step="0.01" placeholder="0 - 100" required>
                        </div>
                        <div class="k-12"><button type="submit" class="btn">Simpan Nilai</button></div>
                    </form>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-kepala"><h3>Nilai yang Kamu Berikan <small><?= count($nilaiGuru) ?></small></h3></div>
                <?php if (count($nilaiGuru) === 0): ?>
                    <div class="kosong"><p>Belum ada nilai. Nilai dari penilaian tugas dan input manual akan muncul di sini.</p></div>
                <?php else: ?>
                    <div class="daftar">
                        <?php foreach ($nilaiGuru as $ng): ?>
                            <?php $angkaNg = rtrim(rtrim(number_format((float)$ng['nilai'], 2), '0'), '.'); ?>
                            <div class="baris-info">
                                <div class="isi">
                                    <b><?= e($ng['nama']) ?></b>
                                    <span>Kelas <?= e((string)$ng['kelas']) ?> · <?= e($ng['jenis']) ?><?= $ng['pengumpulan_id'] ? ' dari kiriman tugas' : '' ?></span>
                                </div>
                                <span class="badge dl-selesai"><?= e($angkaNg) ?></span>
                                <form method="POST" action="aksi.php" data-konfirm="Hapus nilai <?= e($ng['jenis']) ?> <?= e($angkaNg) ?> milik <?= e($ng['nama']) ?>?">
                                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                    <input type="hidden" name="aksi" value="hapus_nilai">
                                    <input type="hidden" name="id" value="<?= (int)$ng['id'] ?>">
                                    <button type="submit" class="hapus" aria-label="Hapus">✕</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="bagian" id="agenda">
            <div class="panel">
                <div class="panel-kepala"><h3>Tambah Agenda</h3></div>
                <form method="POST" action="aksi.php" class="form-grid">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="aksi" value="tambah_agenda">

                    <div class="medan k-8">
                        <label for="a-judul">Judul agenda</label>
                        <input class="input" type="text" id="a-judul" name="judul" maxlength="150" placeholder="Contoh: Koreksi UTS kelas X" required>
                    </div>
                    <div class="medan k-4">
                        <label for="a-tanggal">Tanggal</label>
                        <input class="input" type="date" id="a-tanggal" name="tanggal" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                    </div>
                    <div class="medan k-12">
                        <label>Kategori</label>
                        <div class="pilihan">
                            <label><input type="radio" name="kategori" value="Mengajar" checked><span>Mengajar</span></label>
                            <label><input type="radio" name="kategori" value="Rapat"><span>Rapat</span></label>
                            <label><input type="radio" name="kategori" value="Koreksi"><span>Koreksi</span></label>
                            <label><input type="radio" name="kategori" value="Administrasi"><span>Administrasi</span></label>
                        </div>
                    </div>
                    <div class="k-12"><button type="submit" class="btn">Simpan Agenda</button></div>
                </form>
            </div>

            <div class="panel">
                <div class="panel-kepala"><h3>Daftar Agenda</h3></div>
                <?php if ($total === 0): ?>
                    <div class="kosong"><p>Belum ada agenda. Tambahkan agenda pertamamu di atas!</p></div>
                <?php else: ?>
                    <div class="daftar">
                        <?php foreach ($agenda as $a): ?>
                            <?php
                                $sudah = $a['selesai'] == 1;
                                [$teksDl, $kelasDl] = labelDeadline($a['tanggal'], $bulan);
                            ?>
                            <div class="tugas-item <?= $sudah ? 'selesai' : '' ?>">
                                <form method="POST" action="aksi.php">
                                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                    <input type="hidden" name="aksi" value="toggle_agenda">
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                    <button type="submit" class="cek" aria-label="Tandai selesai">✓</button>
                                </form>
                                <div class="isi">
                                    <b><?= e($a['judul']) ?></b>
                                    <span><?= e(tglIndo($a['tanggal'], $bulan)) ?></span>
                                </div>
                                <span class="badge kat-<?= e(strtolower($a['kategori'])) ?>"><?= e($a['kategori']) ?></span>
                                <?php if ($sudah): ?>
                                    <span class="badge dl-selesai">Selesai</span>
                                <?php else: ?>
                                    <span class="badge <?= $kelasDl ?>"><?= e($teksDl) ?></span>
                                <?php endif; ?>
                                <form method="POST" action="aksi.php" data-konfirm="Hapus agenda &quot;<?= e($a['judul']) ?>&quot;?">
                                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                    <input type="hidden" name="aksi" value="hapus_agenda">
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                    <button type="submit" class="hapus" aria-label="Hapus">✕</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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
                        <input class="input" type="text" id="c-judul" name="judul" maxlength="100" placeholder="Contoh: Catatan perkembangan kelas X">
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
                <div class="panel"><div class="kosong"><p>Belum ada catatan. Simpan hal penting untuk kelasmu di sini!</p></div></div>
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

    </main>
</div>

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

<div class="overlay" id="modalProfil" <?= $mapelSaya === '' ? 'data-wajib="1"' : '' ?>>
    <div class="modal profil">
        <h3>Mata Pelajaran yang Kamu Ajar</h3>
        <p>Jadwal dan tugas yang kamu buat akan memakai mata pelajaran ini.</p>
        <form method="POST" action="aksi.php">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="aksi" value="simpan_profil">
            <div class="medan">
                <?php if (count($daftarMapelSemua) === 0): ?>
                    <p class="pilihan-kosong">Daftar mata pelajaran belum tersedia, hubungi admin.</p>
                <?php else: ?>
                    <div class="pilihan banyak">
                        <?php foreach ($daftarMapelSemua as $m): ?>
                            <label><input type="radio" name="mapel" value="<?= e($m) ?>" <?= $mapelSaya === $m ? 'checked' : '' ?> required><span><?= e($m) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-aksi">
                <button type="submit" class="btn-modal utama" <?= count($daftarMapelSemua) === 0 ? 'disabled' : '' ?>>Simpan Mapel</button>
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
    beranda: ['Beranda', 'Ringkasan mengajarmu hari ini'],
    absensi: ['Absensi', 'Catat kehadiran siswa dengan cepat'],
    siswa:   ['Daftar Siswa', 'Semua siswa yang terdaftar di portal'],
    tugas:   ['Tugas Kelas', 'Beri tugas, periksa kiriman, dan nilai siswa'],
    nilai:   ['Nilai', 'Beri nilai yang langsung diterima siswa'],
    jadwal:  ['Jadwal Mengajar', 'Atur jadwal mengajar mingguanmu'],
    agenda:  ['Agenda', 'Rencanakan tugas dan kegiatan mengajarmu'],
    catatan: ['Catatan', 'Simpan catatan penting untuk kelasmu']
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
    history.replaceState(null, '', location.pathname + location.search + '#' + nama);
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

const konfirmasi     = document.getElementById('konfirmasi');
const teksKonfirmasi = document.getElementById('teksKonfirmasi');
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

const toast = document.getElementById('toast');
if (toast) { setTimeout(() => toast.remove(), 4300); }

const cariSiswa   = document.getElementById('cariSiswa');
const kartuSiswa  = document.querySelectorAll('.kartu-siswa');
const kosongSiswa = document.getElementById('kosongSiswa');
const tombolKelas = document.querySelectorAll('[data-kelasfilter]');
let kelasAktif = 'semua';

function terapkanFilterSiswa() {
    const kata = cariSiswa ? cariSiswa.value.trim().toLowerCase() : '';
    let terlihat = 0;
    kartuSiswa.forEach(kartu => {
        const cocokKata  = kartu.dataset.cari.includes(kata);
        const cocokKelas = kelasAktif === 'semua' || kartu.dataset.kelas === kelasAktif;
        const tampil = cocokKata && cocokKelas;
        kartu.classList.toggle('sembunyi', !tampil);
        if (tampil) { terlihat++; }
    });
    if (kosongSiswa) { kosongSiswa.classList.toggle('tampil', terlihat === 0); }
}

if (cariSiswa) { cariSiswa.addEventListener('input', terapkanFilterSiswa); }
tombolKelas.forEach(t => {
    t.addEventListener('click', () => {
        kelasAktif = t.dataset.kelasfilter;
        tombolKelas.forEach(x => x.classList.toggle('aktif', x === t));
        terapkanFilterSiswa();
    });
});

const modalProfil = document.getElementById('modalProfil');

function bukaProfil() { modalProfil.classList.add('tampil'); }
function tutupProfil() { modalProfil.classList.remove('tampil'); }

document.querySelectorAll('[data-profil]').forEach(t => t.addEventListener('click', bukaProfil));
document.getElementById('tutupProfil').addEventListener('click', tutupProfil);
modalProfil.addEventListener('click', e => { if (e.target === modalProfil) { tutupProfil(); } });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { tutupProfil(); } });
if (modalProfil.dataset.wajib === '1' && !document.getElementById('toast')) { bukaProfil(); }

const tglAbsen = document.getElementById('tglAbsen');
if (tglAbsen) {
    tglAbsen.addEventListener('change', () => document.getElementById('formTgl').submit());
}

const semuaHadir = document.getElementById('semuaHadir');
if (semuaHadir) {
    semuaHadir.addEventListener('click', () => {
        document.querySelectorAll('.absen-baris input[value="H"]').forEach(radio => { radio.checked = true; });
    });
}

document.querySelectorAll('[data-periksa]').forEach(tombol => {
    tombol.addEventListener('click', () => {
        const panel = document.getElementById('periksa-' + tombol.dataset.periksa);
        const buka = panel.classList.contains('sembunyi');
        panel.classList.toggle('sembunyi', !buka);
        tombol.classList.toggle('aktif', buka);
    });
});

const tombolKelasNilai = document.querySelectorAll('[data-kelasnilai]');
const pilihSiswaNilai  = document.querySelectorAll('#siswaNilai label[data-kelas]');
const kosongSiswaNilai = document.getElementById('kosongSiswaNilai');

function pilihKelasNilai(kelas) {
    let terlihat = 0;
    tombolKelasNilai.forEach(t => t.classList.toggle('aktif', t.dataset.kelasnilai === kelas));
    pilihSiswaNilai.forEach(l => {
        const cocok = l.dataset.kelas === kelas;
        l.classList.toggle('sembunyi', !cocok);
        if (cocok) { terlihat++; } else { l.querySelector('input').checked = false; }
    });
    if (kosongSiswaNilai) { kosongSiswaNilai.classList.toggle('sembunyi', terlihat > 0); }
}

tombolKelasNilai.forEach(t => t.addEventListener('click', () => pilihKelasNilai(t.dataset.kelasnilai)));
if (tombolKelasNilai.length) { pilihKelasNilai(tombolKelasNilai[0].dataset.kelasnilai); }
</script>
</body>
</html>
