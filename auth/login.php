<?php
session_start();
require_once '../config/koneksi.php';
require_once '../config/helper.php';

if (isset($_POST['login'])){
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE username = ?");
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $query = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($query)===1){
        $row = mysqli_fetch_assoc($query);

        if (password_verify($password, $row['password'])){
            $_SESSION['id']=$row['id'];
            $_SESSION['nama']=$row['nama'];
            $_SESSION['username']=$row['username'];
            $_SESSION['role']=$row['role'];

            catatAktivitas($conn, (int)$row['id'], $row['nama'], $row['role'], 'login', 'Masuk ke dashboard');

            if($row['role']=='admin'){
                header("location: ../admin/dashboard.php");
            } elseif ($row ['role']=='guru') {
                header("location: ../guru/dashboard.php");
            } else {
                header("location: ../siswa/dashboard.php");
            }
            exit;
        } else {
            $error = "Password salah, coba lagi ya";
        }
    }else {
        $error = "Username tidak ditemukan";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Portal Sekolah</title>
    <link href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>" rel="stylesheet">
</head>
<body>
    <div class="wrap">
        <div class="panel-kiri">
            <div class="brand">Portal Sekolah</div>
            <div>
                <h1>Belajar dengan tenang, <br>selesai dengan rapi.</h1>
                <p>Tugas, jadwal, dan nilai dalam satu tempat yang tertata.</p>
            </div>
            <small>Portal Sekolah</small>
        </div>

        <div class="panel-kanan">
            <form class="form-login" method="POST" action="">
                <h2>Masuk</h2>
                <p class="sub">Gunakan akun sekolahmu</p>

                <?php if (($_GET['status'] ?? '') === 'berhasil') echo "<div class='sukses'>Akun berhasil dibuat. Silakan masuk.</div>"; ?>

                <?php if (isset($error)) echo "<div class='error'>$error</div>"; ?>

                <div class="grup">
                    <label for="username">Username</label>
                    <div class="kolom">
                        <input type="text" id="username" name="username" placeholder="Masukkan username" required autofocus>
                        </div>
                </div>

                <div class="grup">
                    <label for="password">Password</label>
                    <div class="kolom">
                        <input type="password" id="password" name="password" placeholder="Masukkan password" required>
                        <button type="button" class="lihat" id="lihat" aria-label="Tampilkan password">Lihat</button>
                    </div>
                </div>

                <button type="submit" name="login" class="btn-login">Masuk</button>

                <p class="daftar">Belum punya akun? <a href="register.php">Daftar</a></p>
            </form>
        </div>

    </div>

    <script>
        const pw = document.getElementById('password');
        const tombol = document.getElementById('lihat');
        tombol.addEventListener('click', () => {
            const tampil = pw.type === 'password';
            pw.type = tampil ? 'text' : 'password';
            tombol.textContent = tampil ? 'Sembunyi' : 'Lihat';
        });
    </script>
</body>
</html>
