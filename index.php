<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/vendor/autoload.php';

try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
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

$token = $_ENV['API_TOKEN'] ?? null;

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS"); // Tambahkan OPTIONS di sini
header("Access-Control-Allow-Headers: X-API-KEY, Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = apache_request_headers();

// Ubah key header menjadi lowercase atau sesuaikan dengan case-sensitive apache
// Beberapa server membaca X-API-KEY menjadi X-Api-Key atau x-api-key
$clientToken = null;
foreach ($headers as $key => $value) {
    if (strcasecmp($key, 'X-API-KEY') === 0) {
        $clientToken = $value;
        break;
    }
}

if ($clientToken === null || isset($_SERVER['HTTP_X_API_KEY'])) {
    $clientToken = $_SERVER['HTTP_X_API_KEY'] ?? null;
}

// Validasi token
if ($clientToken === null || $clientToken !== $token) {
    http_response_code(401);
    echo json_encode([
        'status' => false,
        'message' => 'Akses ditolak! Token API tidak valid'
    ]);
    exit; // Stop proses, jangan izinkan masuk ke routing dan database
}



require_once __DIR__ . '/helpers/response.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/AbsensiController.php';
require_once __DIR__ . '/controllers/WajahController.php';
require_once __DIR__ . '/controllers/VersionController.php';
require_once __DIR__ . '/controllers/NotifikasiController.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

$requestUri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method      = $_SERVER['REQUEST_METHOD'];

//akses root domain
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');

//buang base path di index.php
$path = $requestUri;
if ($basePath !== '' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath));
}

// Hilangkan trailing slash agar "/api/auth/login/" dan "/api/auth/login"
// dianggap sama.
$path = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}

// DAFTAR ROUTE
$routes = [
    'POST /api/auth/login'           => [AuthController::class, 'login'], //Login
    'GET /api/auth/me'               => [AuthController::class, 'me'], //Profil
    'PUT /api/auth/change-password'  => [AuthController::class, 'changePassword'], //Ganti Password
    'POST /api/auth/signup'          => [AuthController::class, 'signUp'], //Daftar akun baru
    'POST /api/auth/check-nik'       => [AuthController::class, 'checkNik'],

    'POST /api/auth/forgot-password/verify' => [AuthController::class, 'forgotPasswordVerify'], //Lupa password: verifikasi wajah
    'PUT /api/auth/forgot-password/reset'   => [AuthController::class, 'forgotPasswordReset'], //Lupa password: set password baru

    'POST /api/absensi/checkin'      => [AbsensiController::class, 'checkin'], //Absensi masuk
    'PUT /api/absensi/checkout'      => [AbsensiController::class, 'checkout'], //Absensi pulang
    'GET /api/absensi/today'         => [AbsensiController::class, 'today'], //Status harian
    'GET /api/absensi/riwayat'       => [AbsensiController::class, 'riwayat'], //Riwayat presensi
    'GET /api/absensi/detail'        => [AbsensiController::class, 'detail'], //Detail presensi

    'POST /api/wajah/enroll'         => [WajahController::class, 'enroll'], //Daftarkan wajah referensi
    'POST /api/absensi/verify-face'  => [WajahController::class, 'verifikasi'], //Cocokkan wajah saat absen

    'GET /api/absensi/batas-waktu'   => [AbsensiController::class, 'batasWaktu'], //Ambil batas waktu absensi

    'GET /api/app-version'           => [AppVersionController::class, 'getVersion'], //Ambil informasi versi aplikasi
    'GET /api/absensi/lokasi' => [AbsensiController::class, 'daftarLokasi'],

    'POST /api/notifikasi/device-token' => [NotifikasiController::class, 'simpanToken'],

    'PUT /api/absensi/lokasi-rumah' => [AbsensiController::class, 'aturLokasiRumah'],
    'GET /api/absensi/lokasi-rumah' => [AbsensiController::class, 'getLokasiRumah'],
];

$routeKey = $method . ' ' . $path;

if (array_key_exists($routeKey, $routes)) {
    [$controllerClass, $methodName] = $routes[$routeKey];
    $controller = new $controllerClass();
    $controller->$methodName();
    exit;
}

jsonError('Endpoint tidak ditemukan', 404);
