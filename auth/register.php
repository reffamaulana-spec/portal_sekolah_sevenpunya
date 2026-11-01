<?php
session_start();
require_once '../config/koneksi.php';
require_once '../config/helper.php';

$daftarKelasPilihan = daftarKelas($conn);
$daftarMapelPilihan = daftarMapel($conn);

$status = $_GET['status'] ?? '';
$old = $_SESSION['old'] ?? [];
unset($_SESSION['old']);

$daftarNotif = [
    'duplikat' => [
        'judul' => 'Akun Sudah Terdaftar',
        'teks'  => 'Username atau email itu sudah dipakai. Coba gunakan yang lain, atau langsung login kalau itu memang akunmu.',
        'fokus' => 'username',
        'salah' => ['username', 'email'],
        'login' => true
    ],
    'kosong' => [
        'judul' => 'Data Belum Lengkap',
        'teks'  => 'Isi semua kolom dulu ya sebelum mendaftar.',
        'fokus' => 'nama',
        'salah' => [],
        'login' => false
    ],
    'email' => [
        'judul' => 'Email Tidak Valid',
        'teks'  => 'Periksa lagi penulisan emailmu, contohnya nama@email.com.',
        'fokus' => 'email',
        'salah' => ['email'],
        'login' => false
    ],
    'pendek' => [
        'judul' => 'Password Terlalu Pendek',
        'teks'  => 'Gunakan minimal 6 karakter agar akunmu lebih aman.',
        'fokus' => 'password',
        'salah' => [],
        'login' => false
    ],
    'beda' => [
        'judul' => 'Password Tidak Cocok',
        'teks'  => 'Konfirmasi password harus sama dengan password yang kamu buat.',
        'fokus' => 'konfirmasi',
        'salah' => [],
        'login' => false
    ],
    'kelas' => [
        'judul' => 'Kelas Belum Dipilih',
        'teks'  => 'Pilih kelasmu dulu supaya jadwal dan tugas dari guru langsung masuk ke dashboardmu.',
        'fokus' => 'nama',
        'salah' => [],
        'login' => false
    ],
    'mapel' => [
        'judul' => 'Mapel Belum Dipilih',
        'teks'  => 'Pilih mata pelajaran yang kamu ajar supaya jadwal dan tugasmu tersambung ke kelas yang tepat.',
        'fokus' => 'nama',
        'salah' => [],
        'login' => false
    ],
    'gagal' => [
        'judul' => 'Terjadi Kesalahan',
        'teks'  => 'Akun belum bisa dibuat saat ini. Coba lagi sebentar lagi ya.',
        'fokus' => 'nama',
        'salah' => [],
        'login' => false
    ]
];

$notif = $daftarNotif[$status] ?? null;

function nilai($old, $kunci){
    return htmlspecialchars($old[$kunci] ?? '', ENT_QUOTES, 'UTF-8');
}

function salah($notif, $kolom){
    return ($notif && in_array($kolom, $notif['salah'], true)) ? 'salah' : '';
}

$roleTerpilih = $old['role'] ?? 'murid';
$kelasTerpilih = $old['kelas'] ?? '';
$mapelTerpilih = $old['mapel'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar - Portal Sekolah</title>
    <link href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>" rel="stylesheet">
    <link href="register.css?v=<?= filemtime(__DIR__ . '/register.css') ?>" rel="stylesheet">
</head>
<body>
    <div class="wrap wrap-daftar">
        <div class="panel-kiri">
            <div class="brand">Portal Sekolah</div>
            <div>
                <h1>Mulai dari <br>satu akun.</h1>
                <p>Pilih kelas atau mata pelajaranmu, lalu semuanya tersambung otomatis.</p>
            </div>
            <small>Portal Sekolah</small>
        </div>

        <div class="panel-kanan">
            <form class="form-login" method="POST" action="proses_register.php">
                <h2>Buat akun</h2>
                <p class="sub">Lengkapi data berikut</p>

                <div class="grup">
                    <label for="nama">Nama Lengkap</label>
                    <div class="kolom <?= salah($notif, 'nama') ?>">
                        <input type="text" id="nama" name="nama" placeholder="Nama lengkapmu" value="<?= nilai($old, 'nama') ?>" required>
                        </div>
                </div>

                <div class="grup">
                    <label for="username">Username</label>
                    <div class="kolom <?= salah($notif, 'username') ?>">
                        <input type="text" id="username" name="username" placeholder="Pilih username" value="<?= nilai($old, 'username') ?>" required>
                        </div>
                </div>

                <div class="grup">
                    <label for="email">Email</label>
                    <div class="kolom <?= salah($notif, 'email') ?>">
                        <input type="email" id="email" name="email" placeholder="nama@email.com" value="<?= nilai($old, 'email') ?>" required>
                        </div>
                </div>

                <div class="baris">
                    <div class="grup">
                        <label for="password">Password</label>
                        <div class="kolom">
                            <input type="password" id="password" name="password" placeholder="Min. 6 karakter" minlength="6" required>
                            <button type="button" class="lihat" data-target="password" aria-label="Tampilkan password">Lihat</button>
                        </div>
                        <div class="kekuatan">
                            <div class="bar"><i id="isiKekuatan"></i></div>
                            <small id="tekKekuatan"></small>
                        </div>
                    </div>

                    <div class="grup">
                        <label for="konfirmasi">Konfirmasi</label>
                        <div class="kolom">
                            <input type="password" id="konfirmasi" name="konfirmasi" placeholder="Ulangi password" required>
                            <button type="button" class="lihat" data-target="konfirmasi" aria-label="Tampilkan password">Lihat</button>
                        </div>
                        <div class="cek-cocok" id="cekCocok"></div>
                    </div>
                </div>

                <div class="grup">
                    <label>Daftar sebagai</label>
                    <div class="pilih-role">
                        <label class="kartu-role">
                            <input type="radio" name="role" value="murid" <?= $roleTerpilih !== 'guru' ? 'checked' : '' ?>>
                            <span class="isi">
                                <b>Murid</b>
                                <small>Belajar &amp; kerjakan tugas</small>
                            </span>
                        </label>

                        <label class="kartu-role">
                            <input type="radio" name="role" value="guru" <?= $roleTerpilih === 'guru' ? 'checked' : '' ?>>
                            <span class="isi">
                                <b>Guru</b>
                                <small>Mengajar &amp; kelola kelas</small>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="grup" id="grupKelas">
                    <label>Kelasmu</label>
                    <div class="pilih-chip">
                        <?php foreach ($daftarKelasPilihan as $k): ?>
                            <label class="chip">
                                <input type="radio" name="kelas" value="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= $kelasTerpilih === $k ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        <?php endforeach; ?>
                        <?php if (count($daftarKelasPilihan) === 0): ?>
                            <p class="info-kosong">Belum ada kelas. Hubungi admin sekolah.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grup" id="grupMapel" hidden>
                    <label>Mata pelajaran yang kamu ajar</label>
                    <div class="pilih-chip">
                        <?php foreach ($daftarMapelPilihan as $m): ?>
                            <label class="chip">
                                <input type="radio" name="mapel" value="<?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?>" <?= $mapelTerpilih === $m ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        <?php endforeach; ?>
                        <?php if (count($daftarMapelPilihan) === 0): ?>
                            <p class="info-kosong">Belum ada mapel. Hubungi admin sekolah.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <button type="submit" name="daftar" class="btn-login">Daftar</button>

                <p class="daftar">Sudah punya akun? <a href="login.php">Masuk</a></p>
            </form>
        </div>

    </div>

    <?php if ($notif): ?>
    <div class="overlay" id="overlay" data-fokus="<?= $notif['fokus'] ?>">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="judulNotif">
            <h3 id="judulNotif"><?= $notif['judul'] ?></h3>
            <p><?= $notif['teks'] ?></p>
            <div class="modal-aksi">
                <button type="button" class="btn-modal utama" id="tutupNotif">Coba lagi</button>
                <?php if ($notif['login']): ?>
                <a href="login.php" class="btn-modal garis">Ke halaman masuk</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        const pw    = document.getElementById('password');
        const konf  = document.getElementById('konfirmasi');
        const isi   = document.getElementById('isiKekuatan');
        const tek   = document.getElementById('tekKekuatan');
        const cek   = document.getElementById('cekCocok');

        document.querySelectorAll('.lihat').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.target);
                const tampil = input.type === 'password';
                input.type = tampil ? 'text' : 'password';
                btn.textContent = tampil ? 'Sembunyi' : 'Lihat';
            });
        });

        pw.addEventListener('input', () => {
            const v = pw.value;
            let skor = 0;
            if (v.length >= 6)  skor++;
            if (v.length >= 10) skor++;
            if (/[A-Z]/.test(v) && /[a-z]/.test(v)) skor++;
            if (/\d/.test(v) && /[^A-Za-z0-9]/.test(v)) skor++;

            const level = [
                { w: '0%',   c: '#b42318', t: '' },
                { w: '25%',  c: '#b42318', t: 'Lemah' },
                { w: '50%',  c: '#a15c07', t: 'Cukup' },
                { w: '75%',  c: '#253745', t: 'Bagus' },
                { w: '100%', c: '#1a7f4b', t: 'Kuat' }
            ][v ? Math.max(skor, 1) : 0];

            isi.style.width = level.w;
            isi.style.background = level.c;
            tek.textContent = level.t;
            cekKecocokan();
        });

        function cekKecocokan() {
            if (!konf.value) { cek.textContent = ''; cek.className = 'cek-cocok'; return; }
            const cocok = konf.value === pw.value;
            cek.textContent = cocok ? 'Password cocok' : 'Belum cocok';
            cek.className = 'cek-cocok ' + (cocok ? 'ya' : 'tdk');
        }
        konf.addEventListener('input', cekKecocokan);

        document.querySelector('form').addEventListener('submit', e => {
            if (pw.value !== konf.value) {
                e.preventDefault();
                cek.textContent = 'Password belum cocok';
                cek.className = 'cek-cocok tdk';
                konf.focus();
            }
        });

        const grupKelas = document.getElementById('grupKelas');
        const grupMapel = document.getElementById('grupMapel');
        function tampilkanPilihan() {
            const guru = document.querySelector('input[name="role"]:checked').value === 'guru';
            grupKelas.hidden = guru;
            grupMapel.hidden = !guru;
        }
        document.querySelectorAll('input[name="role"]').forEach(r => r.addEventListener('change', tampilkanPilihan));
        tampilkanPilihan();

        document.querySelector('form').addEventListener('submit', e => {
            const guru = document.querySelector('input[name="role"]:checked').value === 'guru';
            const nama = guru ? 'mapel' : 'kelas';
            if (!document.querySelector('input[name="' + nama + '"]:checked')) {
                e.preventDefault();
                const grup = guru ? grupMapel : grupKelas;
                grup.classList.add('goyang');
                grup.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(() => grup.classList.remove('goyang'), 600);
            }
        });

        document.querySelectorAll('.kolom.salah input').forEach(input => {
            input.addEventListener('input', () => {
                input.closest('.kolom').classList.remove('salah');
            });
        });

        const overlay = document.getElementById('overlay');
        if (overlay) {
            const tutup = () => {
                overlay.classList.add('keluar');
                setTimeout(() => overlay.remove(), 250);
                const target = document.getElementById(overlay.dataset.fokus);
                if (target) target.focus();
            };
            document.getElementById('tutupNotif').addEventListener('click', tutup);
            overlay.addEventListener('click', e => { if (e.target === overlay) tutup(); });
            document.addEventListener('keydown', e => { if (e.key === 'Escape') tutup(); });
            history.replaceState(null, '', location.pathname);
        }
    </script>
</body>
</html>
