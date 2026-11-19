<?php
// Salin file ini menjadi config.php lalu isi dengan pengaturan database kamu.
define('DB_HOST', 'localhost');   // Docker: nama service database, mis. 'db'
define('DB_NAME', 'nama_database');
define('DB_USER', 'user_database');
define('DB_PASS', 'password_database');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Koneksi database gagal. Pastikan database sudah dibuat dari schema.sql. Detail: ' . $e->getMessage());
}

// API key asisten AI (opsional). Bisa juga lewat environment variable GROQ_API_KEY.
// define('GROQ_API_KEY', 'isi_api_key_di_sini');
