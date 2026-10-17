<?php
session_start();

// Database Config
define('DB_SERVER', 'localhost');
define('DB_NAME', 'family-tree');

try {

    // Windows Authentication
    $dsn = "sqlsrv:Server=" . DB_SERVER .
           ";Database=" . DB_NAME .
           ";TrustServerCertificate=yes";

    $pdo = new PDO($dsn);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    die("Lỗi kết nối SQL Server: " . $e->getMessage());

}

define('SITE_URL', 'http://localhost:8000/');
define('UPLOAD_PATH', __DIR__ . '/../uploads/');

date_default_timezone_set('Asia/Ho_Chi_Minh');
?>