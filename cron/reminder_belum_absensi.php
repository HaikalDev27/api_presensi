<?php

/**
 * cron/reminder_belum_absen.php
 *
 * Jalankan otomatis tiap hari jam 07:00 lewat Windows Task Scheduler.
 * Cari semua karyawan aktif yang BELUM punya baris absensi hari ini,
 * kirim reminder via FCM.
 *
 * INI SCRIPT CLI — dijalankan lewat `php.exe`, BUKAN diakses lewat
 * browser/HTTP. Tidak butuh JWT/X-API-KEY karena tidak lewat index.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers/firebase.php';

$pdo = require __DIR__ . '/../config/database.php';

$stmt = $pdo->query(
    "SELECT k.nik, dt.fcm_token
     FROM karyawan k
     JOIN device_token dt ON dt.nik = k.nik
     WHERE k.status_aktif = 'Aktif'
       AND NOT EXISTS (
           SELECT 1 FROM absensi a
           WHERE a.nik = k.nik AND a.tanggal = CURDATE()
       )"
);

$rows = $stmt->fetchAll();
$terkirim = 0;

foreach ($rows as $row) {
    $ok = kirimNotifikasiFcm(
        $row['fcm_token'],
        'Reminder Absensi',
        'Anda belum melakukan absensi hari ini. Yuk segera check-in!'
    );
    if ($ok) {
        $terkirim++;
    }
}

echo date('Y-m-d H:i:s') . " - Reminder belum absen: {$terkirim}/" . count($rows) . " berhasil terkirim.\n";
