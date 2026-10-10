<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php'; 

use Dotenv\Dotenv;

try {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__)); 
    $dotenv->load();
} catch (Exception $e) {
    error_log('Failed to load .env file: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Gagal memuat konfigurasi. Silakan coba lagi nanti.',
        'data'    => null,
    ]);
    exit;
}

$DB_HOST = 'localhost';
$DB_NAME = $_ENV['DB_NAME'] ?? '';
$DB_USER = $_ENV['DB_USN'] ?? '';
$DB_PASS = $_ENV['DB_PWD'] ?? '';
$DB_CHARSET = 'utf8mb4';

$dsn = 'mysql:host=' . $DB_HOST . ';dbname=' . $DB_NAME . ';charset=' . $DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Koneksi database gagal. Silakan coba lagi nanti.',
        'data'    => null,
    ]);
    exit;
}

return $pdo;
