<?php

/**
 * cron/reminder_checkout.php
 *
 * Jalankan otomatis tiap hari jam 16:00 lewat Windows Task Scheduler.
 * Cari karyawan yang statusnya Hadir hari ini TAPI belum check-out.
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers/firebase.php';

$pdo = require __DIR__ . '/../config/database.php';

$stmt = $pdo->query(
    "SELECT a.nik, dt.fcm_token
     FROM absensi a
     JOIN device_token dt ON dt.nik = a.nik
     WHERE a.tanggal = CURDATE()
       AND a.absensi = 'H'
       AND a.keluar IS NULL"
);

$rows = $stmt->fetchAll();
$terkirim = 0;

foreach ($rows as $row) {
    $ok = kirimNotifikasiFcm(
        $row['fcm_token'],
        'Reminder Check-Out',
        'Jangan lupa check-out sebelum pulang ya!'
    );
    if ($ok) {
        $terkirim++;
    }
}

echo date('Y-m-d H:i:s') . " - Reminder checkout: {$terkirim}/" . count($rows) . " berhasil terkirim.\n";
