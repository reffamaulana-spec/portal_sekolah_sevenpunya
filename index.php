<?php
session_start();

// Cek apakah user sudah memiliki session role
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] == 'admin') {
        header("Location: admin/dashboard.php");
    } elseif ($_SESSION['role'] == 'guru') {
        header("Location: guru/dashboard.php");
    } elseif ($_SESSION['role'] == 'murid') {
        header("Location: siswa/dashboard.php");
    }
    exit;
} else {
    // Jika belum login, arahkan ke halaman login
    header("Location: auth/login.php");
    exit;
}
?>