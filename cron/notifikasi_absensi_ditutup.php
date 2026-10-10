<?php

/**
 * cron/notifikasi_absen_ditutup.php
 *
 * Jalankan otomatis tiap hari jam 08:00 lewat Windows Task Scheduler.
 * Beda dengan reminder jam 07:00 (nada mengingatkan), yang ini nadanya
 * memberi tahu bahwa window absen Hadir SUDAH DITUTUP.
 *
 * CATATAN PENTING: script ini pakai jam TETAP 08:00 untuk SEMUA
 * karyawan. Kalau Anda sudah/nanti atur batas_waktu_hadir yang BEDA
 * per unit/karyawan (misal ada yang batasnya jam 10:00), notifikasi
 * jam 08:00 ini TIDAK otomatis menyesuaikan — orang yang batasnya
 * jam 10:00 akan tetap dapat notifikasi "ditutup" jam 08:00 padahal
 * window mereka belum tutup. Kalau ini masalah, beri tahu saya, saya
 * bisa buatkan versi yang cek jam batas MASING-MASING karyawan
 * (perlu cron jalan tiap beberapa menit, bukan sekali jam 08:00).
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
        'Absen Hadir Ditutup',
        'Waktu absen Hadir hari ini sudah ditutup. Kalau berhalangan, silakan ajukan Izin/Sakit lewat aplikasi.'
    );
    if ($ok) {
        $terkirim++;
    }
}

echo date('Y-m-d H:i:s') . " - Notifikasi absen ditutup: {$terkirim}/" . count($rows) . " berhasil terkirim.\n";
