<?php
session_start();
require_once '../config/koneksi.php';
require_once '../config/helper.php';

function kembali($kode, $tujuan = 'register.php'){
    header("location: " . $tujuan . "?status=" . $kode);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST"){
    $nama       = trim($_POST['nama'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $konfirmasi = $_POST['konfirmasi'] ?? '';
    $role       = $_POST['role'] ?? '';

    if (!in_array($role, ['murid', 'guru'], true)){
        $role = 'murid';
    }

    $kelas = trim($_POST['kelas'] ?? '');
    $mapel = trim($_POST['mapel'] ?? '');

    $_SESSION['old'] = [
        'nama'     => $nama,
        'username' => $username,
        'email'    => $email,
        'role'     => $role,
        'kelas'    => $kelas,
        'mapel'    => $mapel
    ];

    if ($nama === '' || $username === '' || $email === ''){
        kembali('kosong');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)){
        kembali('email');
    }
    if (strlen($password) < 6){
        kembali('pendek');
    }
    if ($password !== $konfirmasi){
        kembali('beda');
    }

    if ($role === 'murid'){
        if (!in_array($kelas, daftarKelas($conn), true)){
            kembali('kelas');
        }
        $mapel = null;
    } else {
        if (!in_array($mapel, daftarMapel($conn), true)){
            kembali('mapel');
        }
        $kelas = null;
    }

    $cek = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ? OR email = ?");
    mysqli_stmt_bind_param($cek, "ss", $username, $email);
    mysqli_stmt_execute($cek);
    mysqli_stmt_store_result($cek);

    if (mysqli_stmt_num_rows($cek) > 0){
        kembali('duplikat');
    }
    mysqli_stmt_close($cek);

    $password_hashed = password_hash($password, PASSWORD_DEFAULT);

    $stmt = mysqli_prepare($conn, "INSERT INTO users (nama, username, email, password, role, kelas, mapel) VALUES (?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "sssssss", $nama, $username, $email, $password_hashed, $role, $kelas, $mapel);

    if (mysqli_stmt_execute($stmt)){
        $detail = $role === 'murid' ? 'Mendaftar sebagai murid kelas ' . $kelas : 'Mendaftar sebagai guru ' . $mapel;
        catatAktivitas($conn, (int)mysqli_insert_id($conn), $nama, $role, 'daftar', $detail);
        unset($_SESSION['old']);
        kembali('berhasil', 'login.php');
    } else {
        kembali('gagal');
    }
}

header("location: register.php");
exit;
?>
