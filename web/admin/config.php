<?php
// config.php
$host    = getenv('DB_HOST')     ?: 'localhost';
$db      = getenv('DB_NAME')     ?: 'exam_ocr';
$user    = getenv('DB_USER')     ?: 'root';
$pass    = getenv('DB_PASSWORD') ?: '';
$charset = getenv('DB_CHARSET')  ?: 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("เชื่อมต่อฐานข้อมูลไม่ได้: " . $e->getMessage());
}
