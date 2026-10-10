<?php

/**
 * Berisi logic untuk endpoint-endpoint terkait autentikasi:
 * - login()            /login
 * - me()               /me
 * - changePassword()    /change-password
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/face.php';
require_once __DIR__ . '/../middleware/auth.php';

class AuthController
{
    // Login
    public function login(): void
    {
        // Ambil dan decode body JSON dari request
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            jsonError('Body request tidak valid (harus JSON)', 400);
        }

        $username = trim($input['username'] ?? '');
        $password = (string) ($input['password'] ?? '');

        // Validasi input dasar
        if ($username === '' || $password === '') {
            jsonError('Username dan password wajib diisi', 400);
        }

        // Ambil koneksi database
        $pdo = require __DIR__ . '/../config/database.php';

        // Cari user berdasarkan username
        $stmt = $pdo->prepare(
            'SELECT l.password, l.username, k.nik, k.nama, k.status_aktif,
                    k.id_unit, u.nm_unit, k.id_jabatan, j.nm_jabatan
             FROM login l
             JOIN karyawan k ON l.nik = k.nik
             JOIN unit u ON k.id_unit = u.id_unit
             JOIN jabatan j ON k.id_jabatan = j.id_jabatan
             WHERE l.username = ?'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // Jika username tidak ditemukan ATAU password salah,
        if ($user === false || !password_verify($password, $user['password'])) {
            jsonError('Username atau password salah', 401);
        }

        // Validasi status aktif user
        if ($user['status_aktif'] !== 'Aktif') {
            jsonError('Akun tidak aktif. Silakan hubungi admin', 403);
        }

        // Buat payload JWT dari data user (tanpa password)
        $payload = [
            'nik'        => $user['nik'],
            'username'   => $user['username'],
            'nama'       => $user['nama'],
            'id_unit'    => $user['id_unit'],
            'nm_unit'    => $user['nm_unit'],
            'id_jabatan' => $user['id_jabatan'],
            'nm_jabatan' => $user['nm_jabatan'],
        ];

        $token = generateJWT($payload);

        // Kirim response sukses
        jsonSuccess('Login berhasil', [
            'token' => $token,
            'user'  => [
                'nik'        => $user['nik'],
                'username'   => $user['username'],
                'nama'       => $user['nama'],
                'id_unit'    => $user['id_unit'],
                'nm_unit'    => $user['nm_unit'],
                'id_jabatan' => $user['id_jabatan'],
                'nm_jabatan' => $user['nm_jabatan'],
            ],
        ]);
    }

    // Menampilkan data user
    public function me(): void
    {
        // Verifikasi token, ambil payload (berisi nik dari token)
        $authUser = requireAuth();

        // Ambil koneksi database
        $pdo = require __DIR__ . '/../config/database.php';

        // Ambil data terbaru dari database berdasarkan NIK di token
        $stmt = $pdo->prepare(
            'SELECT l.username, k.nik, k.nama,
                    k.id_unit, u.nm_unit, k.id_jabatan, j.nm_jabatan
             FROM login l
             JOIN karyawan k ON l.nik = k.nik
             JOIN unit u ON k.id_unit = u.id_unit
             JOIN jabatan j ON k.id_jabatan = j.id_jabatan
             WHERE k.nik = ?'
        );
        $stmt->execute([$authUser['nik']]);
        $user = $stmt->fetch();

        // Edge case: NIK di token valid, tapi datanya sudah tidak ada di DB
        if ($user === false) {
            jsonError('User tidak ditemukan', 404);
        }

        // Cek apakah user ini sudah punya wajah referensi tersimpan —
        // dipakai Flutter untuk menentukan label tombol di halaman Profil
        // ("DAFTARKAN WAJAH" kalau belum ada, "UPDATE WAJAH" kalau sudah ada).
        $wajahStmt = $pdo->prepare('SELECT 1 FROM wajah_referensi WHERE nik = ?');
        $wajahStmt->execute([$authUser['nik']]);
        $wajahTerdaftar = $wajahStmt->fetch() !== false;

        // Kirim response sukses
        jsonSuccess('Berhasil mengambil data user', [
            'nik'             => $user['nik'],
            'username'        => $user['username'],
            'nama'            => $user['nama'],
            'id_unit'         => $user['id_unit'],
            'nm_unit'         => $user['nm_unit'],
            'id_jabatan'      => $user['id_jabatan'],
            'nm_jabatan'      => $user['nm_jabatan'],
            'wajah_terdaftar' => $wajahTerdaftar,
        ]);
    }

    // Mengganti Password
    public function changePassword(): void
    {
        // Verifikasi token
        $authUser = requireAuth();

        // Ambil dan decode body JSON
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            jsonError('Body request tidak valid (harus JSON)', 400);
        }

        $oldPassword = (string) ($input['old_password'] ?? '');
        $newPassword = (string) ($input['new_password'] ?? '');

        // Validasi input
        if ($oldPassword === '' || $newPassword === '') {
            jsonError('Password lama dan password baru wajib diisi', 400);
        }

        if (strlen($newPassword) < 6) {
            jsonError('Password baru minimal 6 karakter', 400);
        }

        // Ambil koneksi database
        $pdo = require __DIR__ . '/../config/database.php';

        // Ambil hash password saat ini milik user (NIK dari token, bukan dari body)
        $stmt = $pdo->prepare('SELECT password FROM login WHERE nik = ?');
        $stmt->execute([$authUser['nik']]);
        $user = $stmt->fetch();

        if ($user === false) {
            jsonError('User tidak ditemukan', 404);
        }

        // Verifikasi password lama sebelum mengizinkan perubahan
        if (!password_verify($oldPassword, $user['password'])) {
            jsonError('Password lama tidak sesuai', 401);
        }

        // Hash password baru, lalu update
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $updateStmt = $pdo->prepare('UPDATE login SET password = ? WHERE nik = ?');
        $updateStmt->execute([$newHash, $authUser['nik']]);

        // Kirim response sukses
        jsonSuccess('Password berhasil diubah');
    }

    // ---------------------------------------------------------------
    // LUPA PASSWORD — via verifikasi wajah (TANPA email/OTP, karena
    // tabel karyawan/login tidak punya kolom kontak sama sekali).
    //
    // Alur:
    //   1. User masukkan NIK, lalu ambil foto wajah di app.
    //   2. forgotPasswordVerify(): cocokkan embedding wajah itu dengan
    //      wajah referensi milik NIK tsb (yang sama dipakai utk absen).
    //      Kalau cocok -> balas "reset_token" (JWT umur pendek, 5 menit,
    //      purpose khusus 'reset_password', TIDAK bisa dipakai sebagai
    //      token login biasa).
    //   3. forgotPasswordReset(): user set password baru, dikirim
    //      bareng reset_token dari langkah 2.
    //
    // Endpoint ini SENGAJA tidak pakai requireAuth() — justru dipakai
    // saat user TIDAK BISA login (lupa password).
    // ---------------------------------------------------------------

    // Langkah 1: cocokkan NIK + embedding wajah, keluarkan reset_token
    public function forgotPasswordVerify(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            jsonError('Body request tidak valid (harus JSON)', 400);
        }

        $nik = trim((string) ($input['nik'] ?? ''));
        $embedding = $input['embedding'] ?? null;

        if ($nik === '') {
            jsonError('NIK wajib diisi', 400);
        }

        $error = validasiEmbedding($embedding);
        if ($error !== null) {
            jsonError($error, 400);
        }

        $pdo = require __DIR__ . '/../config/database.php';

        // Pastikan NIK terdaftar sebagai karyawan aktif & punya akun login
        $stmt = $pdo->prepare(
            'SELECT k.nik, k.status_aktif
             FROM karyawan k
             JOIN login l ON l.nik = k.nik
             WHERE k.nik = ?'
        );
        $stmt->execute([$nik]);
        $user = $stmt->fetch();

        if ($user === false) {
            jsonError('NIK tidak ditemukan', 404);
        }

        if ($user['status_aktif'] !== 'Aktif') {
            jsonError('Akun tidak aktif. Silakan hubungi admin', 403);
        }

        // Ambil wajah referensi milik NIK ini
        $wajahStmt = $pdo->prepare('SELECT embedding FROM wajah_referensi WHERE nik = ?');
        $wajahStmt->execute([$nik]);
        $wajahRow = $wajahStmt->fetch();

        if ($wajahRow === false) {
            jsonError(
                'Wajah referensi belum terdaftar untuk NIK ini, sehingga '
                    . 'reset password lewat wajah tidak dapat dilakukan. '
                    . 'Silakan hubungi admin.',
                404
            );
        }

        $referensi = json_decode($wajahRow['embedding'], true);

        if (!is_array($referensi) || count($referensi) !== FACE_EMBEDDING_LENGTH) {
            jsonError('Data wajah referensi tidak valid, silakan hubungi admin', 500);
        }

        $score = cosineSimilarity($embedding, $referensi);
        $match = $score >= FACE_MATCH_THRESHOLD;

        if (!$match) {
            jsonError(
                'Wajah tidak cocok dengan data karyawan terdaftar. Reset '
                    . 'password dibatalkan, silakan coba lagi.',
                401
            );
        }

        // Wajah cocok -> keluarkan reset_token umur pendek (bukan token
        // login biasa — punya 'purpose' khusus supaya tidak bisa disalah-
        // gunakan untuk memanggil endpoint lain yang butuh requireAuth()).
        $resetToken = generateJWT(
            ['nik' => $nik, 'purpose' => 'reset_password'],
            RESET_PASSWORD_TOKEN_EXPIRY_SECONDS
        );

        jsonSuccess('Verifikasi wajah berhasil, silakan buat password baru', [
            'reset_token' => $resetToken,
            'score'       => round($score, 4),
        ]);
    }

    // Langkah 2: set password baru pakai reset_token dari langkah 1
    public function forgotPasswordReset(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            jsonError('Body request tidak valid (harus JSON)', 400);
        }

        $resetToken = trim((string) ($input['reset_token'] ?? ''));
        $newPassword = (string) ($input['new_password'] ?? '');

        if ($resetToken === '' || $newPassword === '') {
            jsonError('Reset token dan password baru wajib diisi', 400);
        }

        if (strlen($newPassword) < 6) {
            jsonError('Password baru minimal 6 karakter', 400);
        }

        $payload = verifyJWT($resetToken);

        if ($payload === null) {
            jsonError(
                'Sesi reset password tidak valid atau sudah kadaluarsa. '
                    . 'Silakan ulangi verifikasi wajah.',
                401
            );
        }

        if (($payload['purpose'] ?? null) !== 'reset_password') {
            jsonError('Token tidak valid untuk reset password', 400);
        }

        $nik = $payload['nik'] ?? null;
        if (!is_string($nik) || $nik === '') {
            jsonError('Token tidak valid untuk reset password', 400);
        }

        $pdo = require __DIR__ . '/../config/database.php';

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $updateStmt = $pdo->prepare('UPDATE login SET password = ? WHERE nik = ?');
        $updateStmt->execute([$newHash, $nik]);

        if ($updateStmt->rowCount() === 0) {
            jsonError('User tidak ditemukan', 404);
        }

        jsonSuccess('Password berhasil direset. Silakan login dengan password baru Anda.');
    }

    public function signUp(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if(!is_array($input)) {
            jsonError('Body request tidak valid (harus JSON)', 400);
        }

        $nik = trim((string) ($input['nik'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($nik === '' || $username === '' || $password === '') {
            jsonError('NIK, username, dan password wajib diisi', 400);
        }

        if (strlen($password) < 6) {
            jsonError('Password minimal 6 karakter', 400);
        }

        $pdo = require __DIR__ . '/../config/database.php';

        $stmt = $pdo->prepare('SELECT nik, status_aktif FROM karyawan WHERE nik = ?');
        $stmt->execute([$nik]);
        $karyawan = $stmt->fetch();

        if ($karyawan === false) {
            jsonError('NIK tidak ditemukan di tabel karyawan, hubungi admin/HR', 404);
        }

        if ($karyawan['status_aktif'] !== 'Aktif') {
            jsonError('Akun tidak aktif. Silakan hubungi admin', 403);
        }

        $stmt = $pdo->prepare('SELECT id_user FROM login WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch() !== false) {
            jsonError('Username sudah digunakan, silakan pilih username lain', 409);
        }

        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $insertStmt = $pdo->prepare('INSERT INTO login (password, username, activity, nik, hak_akses)
            VALUES (?, ?, ?, ?, ?)');
        $insertStmt->execute([$newHash, $username, 'off', $nik, 'user']);

        jsonSuccess('Akun berhasil dibuat. Silakan login dengan username dan password Anda.', null, 201);
    }

    public function checkNik(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
    
        if (!is_array($input)) {
            jsonError('Body request tidak valid (harus JSON)', 400);
        }
    
        $nik = trim((string) ($input['nik'] ?? ''));
    
        if ($nik === '') {
            jsonError('NIK wajib diisi', 400);
        }
    
        $pdo = require __DIR__ . '/../config/database.php';
    
        $stmt = $pdo->prepare('SELECT nik, nama, status_aktif FROM karyawan WHERE nik = ?');
        $stmt->execute([$nik]);
        $karyawan = $stmt->fetch();
    
        if ($karyawan === false) {
            jsonError('NIK tidak ditemukan di tabel karyawan, hubungi admin/HR', 404);
        }
    
        if ($karyawan['status_aktif'] !== 'Aktif') {
            jsonError('Akun tidak aktif. Silakan hubungi admin', 403);
        }
    
        $stmt = $pdo->prepare('SELECT id_user FROM login WHERE nik = ?');
        $stmt->execute([$nik]);
        if ($stmt->fetch() !== false) {
            jsonError('NIK ini sudah memiliki akun, silakan langsung login', 409);
        }
    
        jsonSuccess('NIK ditemukan', ['nama' => $karyawan['nama']], 200);
    }
}
