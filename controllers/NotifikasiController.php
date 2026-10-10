<?php

/**
 * controllers/NotifikasiController.php
 *
 * - simpanToken() -> POST /api/notifikasi/device-token
 *
 * Flutter memanggil ini SEKALI setiap habis login (dan idealnya juga
 * setiap kali token FCM berubah, walau itu jarang terjadi), supaya
 * backend selalu punya device token TERBARU untuk kirim notifikasi.
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../middleware/auth.php';

class NotifikasiController
{
    /**
     * POST /api/notifikasi/device-token
     * Membutuhkan header: Authorization: Bearer <token>
     *
     * Request body:
     * { "fcm_token": "xxxxxxxxxxxx..." }
     */
    public function simpanToken(): void
    {
        $authUser = requireAuth();

        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            jsonError('Body request tidak valid (harus JSON)', 400);
        }

        $fcmToken = trim((string) ($input['fcm_token'] ?? ''));

        if ($fcmToken === '') {
            jsonError('fcm_token wajib diisi', 400);
        }

        $pdo = require __DIR__ . '/../config/database.php';

        // Satu NIK = satu device token aktif (device terbaru yang login).
        // Kalau user ganti HP, baris lama otomatis ketimpa (UPDATE),
        // bukan menumpuk banyak baris per NIK.
        $stmt = $pdo->prepare(
            'INSERT INTO device_token (nik, fcm_token)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE fcm_token = VALUES(fcm_token), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$authUser['nik'], $fcmToken]);

        jsonSuccess('Token berhasil disimpan');
    }
}
