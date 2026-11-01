<?php
$host = "sql104.infinityfree.com";
$user = "if0_43276107";
$pass = "Reffa1234";
$db   = "if0_43276107_portal";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Koneksi database gagal");
}
mysqli_set_charset($conn, "utf8mb4");