<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (!isset($_SESSION['role'])) {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SESSION['role'] != 'admin') {
    die("Akses Ditolak! Anda bukan Admin. <a href='../auth/logout.php'>Logout</a>");
}

require_once '../config/koneksi.php';
require_once '../config/helper.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf  = $_SESSION['csrf'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$saya = (int)$_SESSION['id'];

function e($teks) {
    return htmlspecialchars((string)$teks, ENT_QUOTES, 'UTF-8');
}

function ambil($conn, $sql) {
    $hasil = mysqli_query($conn, $sql);
    return mysqli_fetch_all($hasil, MYSQLI_ASSOC);
}

try {
    $users = ambil($conn, "SELECT id, nama, username, email, role, kelas, mapel FROM users ORDER BY id DESC");
} catch (mysqli_sql_exception $ex) {
    die("Kolom kelas dan mapel belum ada. Import file database_sinkron.sql ke database kamu terlebih dahulu.");
}

try {
    $log = ambil($conn, "SELECT user_id, nama, role, aksi, detail, dibuat FROM log_aktivitas ORDER BY id DESC LIMIT 200");
    $masterKelas = ambil($conn, "SELECT k.nama, (SELECT COUNT(*) FROM users u WHERE u.kelas = k.nama) AS jml FROM daftar_kelas k ORDER BY k.nama ASC");
    $masterMapel = ambil($conn, "SELECT m.nama, (SELECT COUNT(*) FROM users u WHERE u.mapel = m.nama) AS jml FROM daftar_mapel m ORDER BY m.nama ASC");
    $tugasPerKelas = ambil($conn, "SELECT kelas, COUNT(*) AS jml FROM tugas_kelas GROUP BY kelas");
    $jadwalPerKelas = ambil($conn, "SELECT kelas, COUNT(*) AS jml FROM jadwal_guru GROUP BY kelas");
    $hadirPerKelas = ambil($conn, "SELECT u.kelas AS kelas, SUM(CASE WHEN a.status = 'H' THEN 1 ELSE 0 END) AS hadir, COUNT(*) AS total FROM absensi a JOIN users u ON u.id = a.siswa_id GROUP BY u.kelas");
    $logSiap = true;
} catch (mysqli_sql_exception $ex) {
    $log = [];
    $masterKelas = [];
    $masterMapel = [];
    $tugasPerKelas = [];
    $jadwalPerKelas = [];
    $hadirPerKelas = [];
    $logSiap = false;
}

$bulan = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];

function tglWaktu($tgl, $bulan) {
    $d = new DateTime($tgl);
    return $d->format('j') . ' ' . $bulan[(int)$d->format('n')] . ' ' . $d->format('H:i');
}

$labelPeran = ['admin' => 'Admin', 'guru' => 'Guru', 'murid' => 'Murid'];
$jumlahPeran = ['murid' => 0, 'guru' => 0, 'admin' => 0];
$siswaTanpaKelas = 0;
$guruTanpaMapel  = 0;
$siswaPerKelas   = [];
foreach ($users as $u) {
    if (isset($jumlahPeran[$u['role']])) {
        $jumlahPeran[$u['role']]++;
    }
    if ($u['role'] === 'murid') {
        if (trim((string)$u['kelas']) === '') {
            $siswaTanpaKelas++;
        } else {
            $siswaPerKelas[$u['kelas']] = ($siswaPerKelas[$u['kelas']] ?? 0) + 1;
        }
    }
    if ($u['role'] === 'guru' && trim((string)$u['mapel']) === '') {
        $guruTanpaMapel++;
    }
}
$totalUser   = count($users);
$persenMurid = $totalUser > 0 ? (int)round($jumlahPeran['murid'] / $totalUser * 100) : 0;
$terbaru     = array_slice($users, 0, 5);
$belumLengkap = $siswaTanpaKelas + $guruTanpaMapel;

$petaTugas  = array_column($tugasPerKelas, 'jml', 'kelas');
$petaJadwal = array_column($jadwalPerKelas, 'jml', 'kelas');
$petaHadir  = [];
foreach ($hadirPerKelas as $h) {
    $petaHadir[(string)$h['kelas']] = (int)$h['total'] > 0 ? (int)round($h['hadir'] / $h['total'] * 100) : null;
}

$varian = ['', 'v-pink', 'v-hijau', 'v-kuning'];


$jam = (int)date('H');
if ($jam < 11)      { $salam = 'Selamat pagi'; }
elseif ($jam < 15)  { $salam = 'Selamat siang'; }
elseif ($jam < 18)  { $salam = 'Selamat sore'; }
else                { $salam = 'Selamat malam'; }

$namaLengkap = $_SESSION['nama'] ?? 'Admin';
$namaDepan   = explode(' ', trim($namaLengkap))[0];
$inisial     = mb_strtoupper(mb_substr($namaLengkap, 0, 1));

$daftarKelasNama = array_column($masterKelas, 'nama');
$daftarMapelNama = array_column($masterMapel, 'nama');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin</title>
    <link href="dashboard.css?v=<?= filemtime(__DIR__ . '/dashboard.css') ?>" rel="stylesheet">
</head>
<body>
<div class="app">

    <aside class="sidebar">
        <div class="brand"> Portal Sekolah</div>

        <nav class="menu m5">
            <button type="button" class="nav aktif" data-bagian="beranda"><span class="nt">Beranda</span></button>
            <button type="button" class="nav" data-bagian="pengguna"><span class="nt">Pengguna</span><?php if ($belumLengkap > 0): ?><em class="lencana"><?= $belumLengkap ?></em><?php endif; ?></button>
            <button type="button" class="nav" data-bagian="aktivitas"><span class="nt">Aktivitas</span></button>
            <button type="button" class="nav" data-bagian="master"><span class="nt">Kelas</span></button>
            <button type="button" class="nav" data-bagian="akun"><span class="nt">Akun</span></button>
        </nav>

        <div class="user-card">
            <span class="avatar"><?= e($inisial) ?></span>
            <div class="user-info">
                <b><?= e($namaLengkap) ?></b>
                <small>Admin</small>
            </div>
            <a href="../auth/logout.php" class="keluar" title="Keluar">Keluar</a>
        </div>
    </aside>

    <main class="main">

        <header class="topbar">
            <div>
                <h1 id="judulBagian">Beranda</h1>
                <p id="subBagian">Ringkasan seluruh pengguna portal</p>
            </div>
            <div class="topbar-kanan">
                <div class="jam">
                    <b id="jamLive">--:--</b>
                    <span id="tglLive"></span>
                </div>
                <a href="../auth/logout.php" class="keluar-mobile">Keluar</a>
            </div>
        </header>

        <section class="bagian aktif" id="beranda">
            <div class="hero-card">
                <div class="hero-teks">
                    <small><?= $salam ?></small>
                    <h2>Halo, <?= e($namaDepan) ?>!</h2>
                    <p>Pantau seluruh akun, kelas, dan aktivitas siswa maupun guru dari satu tempat.</p>
                </div>
                <div class="cincin" style="--p: <?= $persenMurid ?>">
                    <span><b><?= $persenMurid ?>%</b><small>pengguna murid</small></span>
                </div>
            </div>

            <div class="stat-grid">
                <div class="stat s-ungu">
                    <div><b><?= $totalUser ?></b><span>Total pengguna</span></div>
                </div>
                <div class="stat s-hijau">
                    <div><b><?= $jumlahPeran['murid'] ?></b><span>Murid</span></div>
                </div>
                <div class="stat s-kuning">
                    <div><b><?= $jumlahPeran['guru'] ?></b><span>Guru</span></div>
                </div>
                <div class="stat s-pink">
                    <div><b><?= $jumlahPeran['admin'] ?></b><span>Admin</span></div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-kepala"><h3>Perlu Perhatian</h3></div>
                <div class="perhatian">
                    <?php if ($belumLengkap === 0): ?>
                        <div class="tanda aman">Semua siswa sudah memilih kelas dan semua guru sudah memilih mapel.</div>
                    <?php else: ?>
                        <?php if ($siswaTanpaKelas > 0): ?>
                            <div class="tanda"><?= $siswaTanpaKelas ?> siswa belum memilih kelas <button type="button" data-lompat="murid">Lihat</button></div>
                        <?php endif; ?>
                        <?php if ($guruTanpaMapel > 0): ?>
                            <div class="tanda"><?= $guruTanpaMapel ?> guru belum memilih mapel <button type="button" data-lompat="guru">Lihat</button></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel">
                <div class="panel-kepala">
                    <h3>Ringkasan per Kelas</h3>
                    <button type="button" class="link" data-bagian="master">Kelola kelas</button>
                </div>
                <?php if (count($masterKelas) === 0): ?>
                    <div class="kosong"><p>Belum ada kelas. Tambahkan di menu Kelas.</p></div>
                <?php else: ?>
                    <div class="rekap-daftar">
                        <?php foreach ($masterKelas as $k): ?>
                            <?php $hadirK = $petaHadir[$k['nama']] ?? null; ?>
                            <div class="kelas-baris">
                                <b><?= e($k['nama']) ?></b>
                                <div class="sel"><small>Siswa</small><strong><?= (int)($siswaPerKelas[$k['nama']] ?? 0) ?></strong></div>
                                <div class="sel"><small>Jadwal guru</small><strong><?= (int)($petaJadwal[$k['nama']] ?? 0) ?></strong></div>
                                <div class="sel"><small>Tugas guru</small><strong><?= (int)($petaTugas[$k['nama']] ?? 0) ?></strong></div>
                                <div class="sel"><small>Kehadiran</small><strong><?= $hadirK === null ? '-' : $hadirK . '%' ?></strong></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="grid-2">
                <div class="panel">
                    <div class="panel-kepala"><h3>Komposisi Pengguna</h3></div>
                    <?php if ($totalUser === 0): ?>
                        <div class="kosong"><p>Belum ada pengguna.</p></div>
                    <?php else: ?>
                        <div class="kmp">
                            <?php
                                $barKmp = [
                                    ['Murid', $jumlahPeran['murid'], 'i-hijau'],
                                    ['Guru', $jumlahPeran['guru'], 'i-ungu'],
                                    ['Admin', $jumlahPeran['admin'], 'i-pink']
                                ];
                            ?>
                            <?php foreach ($barKmp as [$namaBar, $jmlBar, $kelasBar]): ?>
                                <div>
                                    <div class="kmp-kepala"><b><?= $namaBar ?></b><span><?= $jmlBar ?> akun · <?= (int)round($jmlBar / $totalUser * 100) ?>%</span></div>
                                    <div class="bar"><i class="<?= $kelasBar ?>" style="width: <?= round($jmlBar / $totalUser * 100) ?>%"></i></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-kepala">
                        <h3>Aktivitas Terbaru</h3>
                        <button type="button" class="link" data-bagian="aktivitas">Lihat semua</button>
                    </div>
                    <?php if (count($log) === 0): ?>
                        <div class="kosong"><p>Belum ada aktivitas tercatat.</p></div>
                    <?php else: ?>
                        <div class="daftar">
                            <?php foreach (array_slice($log, 0, 5) as $l): ?>
                                <div class="baris-info">
                                    <div class="isi">
                                        <b><?= e($l['nama']) ?> <span class="badge p-<?= e($l['role']) ?>"><?= e($labelPeran[$l['role']] ?? $l['role']) ?></span></b>
                                        <span><?= e($l['detail']) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="bagian" id="pengguna">
            <div class="panel">
                <div class="panel-kepala"><h3>Tambah Pengguna</h3></div>
                <form method="POST" action="aksi.php" class="form-grid" id="formTambah">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="aksi" value="tambah_user">

                    <div class="medan k-6">
                        <label for="u-nama">Nama lengkap</label>
                        <input class="input" type="text" id="u-nama" name="nama" maxlength="100" placeholder="Nama lengkap" required>
                    </div>
                    <div class="medan k-6">
                        <label for="u-username">Username</label>
                        <input class="input" type="text" id="u-username" name="username" maxlength="50" placeholder="Username" required>
                    </div>
                    <div class="medan k-6">
                        <label for="u-email">Email</label>
                        <input class="input" type="email" id="u-email" name="email" maxlength="100" placeholder="nama@email.com" required>
                    </div>
                    <div class="medan k-6">
                        <label for="u-password">Password</label>
                        <input class="input" type="password" id="u-password" name="password" minlength="6" placeholder="Min. 6 karakter" required>
                    </div>
                    <div class="medan k-12">
                        <label>Role</label>
                        <div class="pilihan" id="rolePilihan">
                            <label><input type="radio" name="role" value="murid" checked><span>Murid</span></label>
                            <label><input type="radio" name="role" value="guru"><span>Guru</span></label>
                            <label><input type="radio" name="role" value="admin"><span>Admin</span></label>
                        </div>
                    </div>
                    <div class="medan k-12" id="tambahKelas">
                        <label>Kelas siswa (opsional, siswa akan ditagih jika kosong)</label>
                        <div class="pilihan banyak">
                            <?php foreach ($daftarKelasNama as $k): ?>
                                <label><input type="radio" name="kelas" value="<?= e($k) ?>"><span><?= e($k) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="medan k-12" id="tambahMapel">
                        <label>Mata pelajaran guru (opsional, guru akan ditagih jika kosong)</label>
                        <div class="pilihan banyak">
                            <?php foreach ($daftarMapelNama as $m): ?>
                                <label><input type="radio" name="mapel" value="<?= e($m) ?>"><span><?= e($m) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="k-12"><button type="submit" class="btn">Simpan Pengguna</button></div>
                </form>
            </div>

            <div class="panel">
                <div class="panel-kepala"><h3>Daftar Pengguna <small><?= $totalUser ?> akun</small></h3></div>

                <?php if ($totalUser === 0): ?>
                    <div class="kosong"><p>Belum ada pengguna.</p></div>
                <?php else: ?>
                    <div class="bar-alat">
                        <div class="cari">
                            <input class="input" type="text" id="cariUser" placeholder="Cari nama, username, email, kelas, atau mapel">
                        </div>
                        <div class="filter lebar">
                            <button type="button" class="aktif" data-filter="semua">Semua</button>
                            <button type="button" data-filter="murid">Murid</button>
                            <button type="button" data-filter="guru">Guru</button>
                            <button type="button" data-filter="admin">Admin</button>
                            <button type="button" data-filter="belum">Belum lengkap <?= $belumLengkap > 0 ? '(' . $belumLengkap . ')' : '' ?></button>
                        </div>
                    </div>

                    <div class="daftar">
                        <?php foreach ($users as $u): ?>
                            <?php
                                $diriSendiri = (int)$u['id'] === $saya;
                                $kelasU = trim((string)$u['kelas']);
                                $mapelU = trim((string)$u['mapel']);
                                $lengkap = $u['role'] === 'admin' || ($u['role'] === 'murid' && $kelasU !== '') || ($u['role'] === 'guru' && $mapelU !== '');
                            ?>
                            <div class="user-baris" data-peran="<?= e($u['role']) ?>" data-lengkap="<?= $lengkap ? '1' : '0' ?>" data-cari="<?= e(mb_strtolower($u['nama'] . ' ' . $u['username'] . ' ' . $u['email'] . ' ' . $kelasU . ' ' . $mapelU)) ?>">
                                <span class="avatar-kecil <?= $varian[(int)$u['id'] % 4] ?>"><?= e(mb_strtoupper(mb_substr($u['nama'], 0, 1))) ?></span>
                                <div class="isi">
                                    <b><?= e($u['nama']) ?><?php if ($diriSendiri): ?><em class="saya">Kamu</em><?php endif; ?></b>
                                    <span>@<?= e($u['username']) ?> · <?= e($u['email']) ?></span>
                                </div>
                                <?php if ($u['role'] === 'murid'): ?>
                                    <?php if ($kelasU !== ''): ?><span class="badge badge-kelas"><?= e($kelasU) ?></span><?php else: ?><span class="badge dl-hari">Belum pilih kelas</span><?php endif; ?>
                                <?php elseif ($u['role'] === 'guru'): ?>
                                    <?php if ($mapelU !== ''): ?><span class="badge badge-mapel"><?= e($mapelU) ?></span><?php else: ?><span class="badge dl-hari">Belum pilih mapel</span><?php endif; ?>
                                <?php endif; ?>
                                <span class="badge p-<?= e($u['role']) ?>"><?= e($labelPeran[$u['role']] ?? ucfirst($u['role'])) ?></span>
                                <div class="aksi-user">
                                    <button type="button" class="ikon-btn" data-reset="<?= (int)$u['id'] ?>" data-nama="<?= e($u['nama']) ?>" title="Reset password" aria-label="Reset password">Reset</button>
                                    <button type="button" class="ikon-btn" data-tempat="<?= (int)$u['id'] ?>" data-nama="<?= e($u['nama']) ?>" data-peran="<?= e($u['role']) ?>" data-kelas="<?= e($kelasU) ?>" data-mapel="<?= e($mapelU) ?>" title="Atur kelas atau mapel" aria-label="Atur kelas atau mapel" <?= $u['role'] === 'admin' ? 'disabled' : '' ?>>Atur</button>
                                    <button type="button" class="ikon-btn" data-role="<?= (int)$u['id'] ?>" data-nama="<?= e($u['nama']) ?>" data-peran="<?= e($u['role']) ?>" title="Ubah role" aria-label="Ubah role" <?= $diriSendiri ? 'disabled' : '' ?>>Role</button>
                                    <?php if ($diriSendiri): ?>
                                        <button type="button" class="ikon-btn bahaya" title="Tidak bisa menghapus akun sendiri" aria-label="Hapus" disabled>✕</button>
                                    <?php else: ?>
                                        <form method="POST" action="aksi.php" data-konfirm="Hapus akun &quot;<?= e($u['username']) ?>&quot; beserta seluruh datanya? Tindakan ini tidak bisa dibatalkan.">
                                            <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                            <input type="hidden" name="aksi" value="hapus_user">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <button type="submit" class="ikon-btn bahaya" title="Hapus" aria-label="Hapus">✕</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="kosong sembunyi" id="kosongUser"><p>Tidak ada pengguna yang cocok.</p></div>
                <?php endif; ?>
            </div>
        </section>

        <section class="bagian" id="aktivitas">
            <div class="panel">
                <div class="panel-kepala"><h3>Aktivitas Siswa, Guru, dan Admin <small><?= count($log) ?> terbaru</small></h3></div>
                <?php if (!$logSiap): ?>
                    <div class="kosong"><p>Tabel log belum dibuat. Import file database_sinkron.sql terlebih dahulu.</p></div>
                <?php elseif (count($log) === 0): ?>
                    <div class="kosong"><p>Belum ada aktivitas tercatat.</p></div>
                <?php else: ?>
                    <div class="bar-alat">
                        <div class="cari">
                            <input class="input" type="text" id="cariLog" placeholder="Cari nama atau kegiatan">
                        </div>
                        <div class="filter lebar">
                            <button type="button" class="aktif" data-logfilter="semua">Semua</button>
                            <button type="button" data-logfilter="murid">Siswa</button>
                            <button type="button" data-logfilter="guru">Guru</button>
                            <button type="button" data-logfilter="admin">Admin</button>
                        </div>
                    </div>
                    <div class="log-daftar">
                        <?php foreach ($log as $l): ?>
                            <div class="log-item" data-peran="<?= e($l['role']) ?>" data-cari="<?= e(mb_strtolower($l['nama'] . ' ' . $l['detail'] . ' ' . $l['aksi'])) ?>">
                                <div class="isi">
                                    <b><span class="peran-tag p-<?= e($l['role']) ?>"><?= e($labelPeran[$l['role']] ?? $l['role']) ?></span><?= e($l['nama'] !== '' ? $l['nama'] : 'Akun terhapus') ?></b>
                                    <small><?= e($l['detail']) ?></small>
                                </div>
                                <time><?= e(tglWaktu($l['dibuat'], $bulan)) ?></time>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="kosong sembunyi" id="kosongLog"><p>Tidak ada aktivitas yang cocok.</p></div>
                <?php endif; ?>
            </div>
        </section>

        <section class="bagian" id="master">
            <div class="master-grid">
                <div class="panel">
                    <div class="panel-kepala"><h3>Daftar Kelas <small><?= count($masterKelas) ?></small></h3></div>
                    <form method="POST" action="aksi.php" class="form-baris">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="aksi" value="tambah_kelas">
                        <input class="input" type="text" name="nama" maxlength="30" placeholder="Contoh: X-C" required>
                        <button type="submit" class="btn">Tambah</button>
                    </form>
                    <?php if (count($masterKelas) === 0): ?>
                        <div class="kosong"><p>Belum ada kelas.</p></div>
                    <?php else: ?>
                        <div class="chip-master-daftar">
                            <?php foreach ($masterKelas as $k): ?>
                                <div class="chip-master">
                                    <?= e($k['nama']) ?> <small><?= (int)$k['jml'] ?> siswa</small>
                                    <form method="POST" action="aksi.php" data-konfirm="Hapus kelas &quot;<?= e($k['nama']) ?>&quot; dari daftar?">
                                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                        <input type="hidden" name="aksi" value="hapus_kelas">
                                        <input type="hidden" name="nama" value="<?= e($k['nama']) ?>">
                                        <button type="submit" aria-label="Hapus kelas">✕</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-kepala"><h3>Daftar Mata Pelajaran <small><?= count($masterMapel) ?></small></h3></div>
                    <form method="POST" action="aksi.php" class="form-baris">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="aksi" value="tambah_mapel">
                        <input class="input" type="text" name="nama" maxlength="80" placeholder="Contoh: Prakarya" required>
                        <button type="submit" class="btn">Tambah</button>
                    </form>
                    <?php if (count($masterMapel) === 0): ?>
                        <div class="kosong"><p>Belum ada mata pelajaran.</p></div>
                    <?php else: ?>
                        <div class="chip-master-daftar">
                            <?php foreach ($masterMapel as $m): ?>
                                <div class="chip-master">
                                    <?= e($m['nama']) ?> <small><?= (int)$m['jml'] ?> guru</small>
                                    <form method="POST" action="aksi.php" data-konfirm="Hapus mata pelajaran &quot;<?= e($m['nama']) ?>&quot; dari daftar?">
                                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                        <input type="hidden" name="aksi" value="hapus_mapel">
                                        <input type="hidden" name="nama" value="<?= e($m['nama']) ?>">
                                        <button type="submit" aria-label="Hapus mata pelajaran">✕</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="bagian" id="akun">
            <div class="panel">
                <div class="panel-kepala"><h3>Ganti Password</h3></div>
                <form method="POST" action="aksi.php" class="form-sempit" id="formAkun">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="aksi" value="ganti_password">

                    <div class="medan">
                        <label for="pwLama">Password lama</label>
                        <input class="input" type="password" id="pwLama" name="password_lama" required>
                    </div>
                    <div class="medan">
                        <label for="pwBaru">Password baru</label>
                        <input class="input" type="password" id="pwBaru" name="password_baru" minlength="6" placeholder="Min. 6 karakter" required>
                    </div>
                    <div class="medan">
                        <label for="pwKonf">Konfirmasi password baru</label>
                        <input class="input" type="password" id="pwKonf" name="konfirmasi" required>
                        <div class="cek-cocok" id="infoCocok"></div>
                    </div>
                    <button type="submit" class="btn">Simpan Password</button>
                </form>
            </div>
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

<div class="overlay" id="modalRole">
    <div class="modal form">
        <h3>Ubah Role</h3>
        <p>Pilih role baru untuk <b id="roleNama"></b>. Kelas atau mapel akan ditanyakan ulang kepada pengguna.</p>
        <form method="POST" action="aksi.php">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="aksi" value="ubah_role">
            <input type="hidden" name="id" id="roleId" value="">
            <div class="medan">
                <div class="pilihan">
                    <label><input type="radio" name="role" value="murid" checked><span>Murid</span></label>
                    <label><input type="radio" name="role" value="guru"><span>Guru</span></label>
                    <label><input type="radio" name="role" value="admin"><span>Admin</span></label>
                </div>
            </div>
            <div class="modal-aksi">
                <button type="submit" class="btn-modal utama">Simpan Role</button>
                <button type="button" class="btn-modal garis" data-tutup>Batal</button>
            </div>
        </form>
    </div>
</div>

<div class="overlay" id="modalTempat">
    <div class="modal form">
        <h3 id="tempatJudul">Atur Penempatan</h3>
        <p>Atur untuk <b id="tempatNama"></b></p>
        <form method="POST" action="aksi.php">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="aksi" value="ubah_penempatan">
            <input type="hidden" name="id" id="tempatId" value="">
            <div class="medan" id="tempatKelas">
                <div class="pilihan banyak">
                    <?php foreach ($daftarKelasNama as $k): ?>
                        <label><input type="radio" name="kelas" value="<?= e($k) ?>"><span><?= e($k) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="medan" id="tempatMapel">
                <div class="pilihan banyak">
                    <?php foreach ($daftarMapelNama as $m): ?>
                        <label><input type="radio" name="mapel" value="<?= e($m) ?>"><span><?= e($m) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-aksi">
                <button type="submit" class="btn-modal utama">Simpan</button>
                <button type="button" class="btn-modal garis" data-tutup>Batal</button>
            </div>
        </form>
    </div>
</div>

<div class="overlay" id="modalReset">
    <div class="modal form">
        <h3>Reset Password</h3>
        <p>Buat password baru untuk <b id="resetNama"></b></p>
        <form method="POST" action="aksi.php">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="aksi" value="reset_password">
            <input type="hidden" name="id" id="resetId" value="">
            <div class="medan">
                <input class="input" type="password" id="passwordReset" name="password_baru" minlength="6" placeholder="Password baru (min. 6 karakter)" required>
            </div>
            <div class="modal-aksi">
                <button type="submit" class="btn-modal utama">Simpan Password</button>
                <button type="button" class="btn-modal garis" data-tutup>Batal</button>
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
    beranda:   ['Beranda', 'Ringkasan seluruh pengguna portal'],
    pengguna:  ['Pengguna', 'Tambah, atur kelas atau mapel, ubah role, reset password, dan hapus akun'],
    aktivitas: ['Aktivitas', 'Semua kegiatan siswa, guru, dan admin'],
    master:    ['Kelas & Mapel', 'Kelola daftar kelas dan mata pelajaran'],
    akun:      ['Akun Saya', 'Jaga keamanan akun adminmu']
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
    history.replaceState(null, '', location.pathname + '#' + nama);
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
const modalRole      = document.getElementById('modalRole');
const modalReset     = document.getElementById('modalReset');
const modalTempat    = document.getElementById('modalTempat');
let formTertunda = null;

function tutupSemua() {
    konfirmasi.classList.remove('tampil');
    modalRole.classList.remove('tampil');
    modalReset.classList.remove('tampil');
    modalTempat.classList.remove('tampil');
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
document.getElementById('batal').addEventListener('click', tutupSemua);

[konfirmasi, modalRole, modalReset, modalTempat].forEach(overlay => {
    overlay.addEventListener('click', e => { if (e.target === overlay) { tutupSemua(); } });
});

document.querySelectorAll('[data-tutup]').forEach(tombol => tombol.addEventListener('click', tutupSemua));
document.addEventListener('keydown', e => { if (e.key === 'Escape') { tutupSemua(); } });

document.querySelectorAll('[data-role]').forEach(tombol => {
    tombol.addEventListener('click', () => {
        document.getElementById('roleId').value = tombol.dataset.role;
        document.getElementById('roleNama').textContent = tombol.dataset.nama;
        const radio = modalRole.querySelector('input[name="role"][value="' + tombol.dataset.peran + '"]');
        if (radio) { radio.checked = true; }
        modalRole.classList.add('tampil');
    });
});

document.querySelectorAll('[data-reset]').forEach(tombol => {
    tombol.addEventListener('click', () => {
        document.getElementById('resetId').value = tombol.dataset.reset;
        document.getElementById('resetNama').textContent = tombol.dataset.nama;
        document.getElementById('passwordReset').value = '';
        modalReset.classList.add('tampil');
        document.getElementById('passwordReset').focus();
    });
});

document.querySelectorAll('[data-tempat]').forEach(tombol => {
    tombol.addEventListener('click', () => {
        const murid = tombol.dataset.peran === 'murid';
        document.getElementById('tempatId').value = tombol.dataset.tempat;
        document.getElementById('tempatNama').textContent = tombol.dataset.nama;
        document.getElementById('tempatJudul').textContent = murid ? 'Atur Kelas Siswa' : 'Atur Mapel Guru';
        document.getElementById('tempatKelas').style.display = murid ? '' : 'none';
        document.getElementById('tempatMapel').style.display = murid ? 'none' : '';
        document.querySelectorAll('#tempatKelas input').forEach(i => { i.disabled = !murid; i.checked = murid && i.value === tombol.dataset.kelas; });
        document.querySelectorAll('#tempatMapel input').forEach(i => { i.disabled = murid; i.checked = !murid && i.value === tombol.dataset.mapel; });
        modalTempat.classList.add('tampil');
    });
});

const toast = document.getElementById('toast');
if (toast) { setTimeout(() => toast.remove(), 4300); }

const cariUser   = document.getElementById('cariUser');
const barisUser  = document.querySelectorAll('.user-baris');
const kosongUser = document.getElementById('kosongUser');
const tombolFilter = document.querySelectorAll('[data-filter]');
let peranAktif = 'semua';

function terapkanFilter() {
    const kata = cariUser.value.trim().toLowerCase();
    let terlihat = 0;
    barisUser.forEach(baris => {
        let cocokPeran = peranAktif === 'semua' || baris.dataset.peran === peranAktif;
        if (peranAktif === 'belum') { cocokPeran = baris.dataset.lengkap === '0'; }
        const cocokKata = baris.dataset.cari.includes(kata);
        const tampil = cocokPeran && cocokKata;
        baris.classList.toggle('sembunyi', !tampil);
        if (tampil) { terlihat++; }
    });
    if (kosongUser) { kosongUser.classList.toggle('tampil', terlihat === 0); }
}

function setFilter(nama) {
    peranAktif = nama;
    tombolFilter.forEach(t => t.classList.toggle('aktif', t.dataset.filter === nama));
    terapkanFilter();
}

if (cariUser) {
    cariUser.addEventListener('input', terapkanFilter);
    tombolFilter.forEach(tombol => {
        tombol.addEventListener('click', () => setFilter(tombol.dataset.filter));
    });
}

document.querySelectorAll('[data-lompat]').forEach(tombol => {
    tombol.addEventListener('click', () => {
        bukaBagian('pengguna');
        setFilter('belum');
    });
});

const cariLog   = document.getElementById('cariLog');
const itemLog   = document.querySelectorAll('.log-item');
const kosongLog = document.getElementById('kosongLog');
const tombolLog = document.querySelectorAll('[data-logfilter]');
let peranLog = 'semua';

function terapkanLog() {
    const kata = cariLog ? cariLog.value.trim().toLowerCase() : '';
    let terlihat = 0;
    itemLog.forEach(item => {
        const tampil = (peranLog === 'semua' || item.dataset.peran === peranLog) && item.dataset.cari.includes(kata);
        item.classList.toggle('sembunyi', !tampil);
        if (tampil) { terlihat++; }
    });
    if (kosongLog) { kosongLog.classList.toggle('tampil', terlihat === 0); }
}

if (cariLog) {
    cariLog.addEventListener('input', terapkanLog);
    tombolLog.forEach(tombol => {
        tombol.addEventListener('click', () => {
            peranLog = tombol.dataset.logfilter;
            tombolLog.forEach(t => t.classList.toggle('aktif', t === tombol));
            terapkanLog();
        });
    });
}

const rolePilihan = document.getElementById('rolePilihan');
const tambahKelas = document.getElementById('tambahKelas');
const tambahMapel = document.getElementById('tambahMapel');

function aturFormTambah() {
    const role = document.querySelector('#formTambah input[name="role"]:checked').value;
    tambahKelas.style.display = role === 'murid' ? '' : 'none';
    tambahMapel.style.display = role === 'guru' ? '' : 'none';
    tambahKelas.querySelectorAll('input').forEach(i => { i.disabled = role !== 'murid'; });
    tambahMapel.querySelectorAll('input').forEach(i => { i.disabled = role !== 'guru'; });
}

rolePilihan.addEventListener('change', aturFormTambah);
aturFormTambah();

const formAkun = document.getElementById('formAkun');
if (formAkun) {
    formAkun.addEventListener('submit', e => {
        const baru = document.getElementById('pwBaru').value;
        const konf = document.getElementById('pwKonf').value;
        const info = document.getElementById('infoCocok');
        if (baru !== konf) {
            e.preventDefault();
            info.textContent = 'Konfirmasi password belum cocok';
            info.className = 'cek-cocok tdk';
        }
    });
}
</script>
</body>
</html>
