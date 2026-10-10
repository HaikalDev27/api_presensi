<?php

/**
 * cron/proses_notifikasi_manual.php
 *
 * Jalankan otomatis tiap 5 menit lewat Windows Task Scheduler.
 * Baca baris di tabel notifikasi_manual dengan status='pending'
 * (diisi LANGSUNG oleh dashboard admin ke database), resolve target
 * penerima sesuai target_type/target_value, kirim FCM, lalu update status.
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers/firebase.php';

$pdo = require __DIR__ . '/../config/database.php';

$stmt = $pdo->query("SELECT * FROM notifikasi_manual WHERE status = 'pending'");
$antrian = $stmt->fetchAll();

foreach ($antrian as $notif) {
    // Resolve daftar fcm_token sesuai target
    if ($notif['target_type'] === 'karyawan') {
        $targetStmt = $pdo->prepare(
            'SELECT fcm_token FROM device_token WHERE nik = ?'
        );
        $targetStmt->execute([$notif['target_value']]);
    } elseif ($notif['target_type'] === 'unit') {
        $targetStmt = $pdo->prepare(
            'SELECT dt.fcm_token
             FROM device_token dt
             JOIN karyawan k ON dt.nik = k.nik
             WHERE k.id_unit = ?'
        );
        $targetStmt->execute([$notif['target_value']]);
    } else {
        // 'semua'
        $targetStmt = $pdo->query('SELECT fcm_token FROM device_token');
    }

    $tokens = $targetStmt->fetchAll(PDO::FETCH_COLUMN);

    $adaGagal = false;
    foreach ($tokens as $token) {
        $ok = kirimNotifikasiFcm($token, $notif['judul'], $notif['pesan']);
        if (!$ok) {
            $adaGagal = true;
        }
    }

    $statusBaru = $adaGagal ? 'failed' : 'sent';

    $updateStmt = $pdo->prepare(
        'UPDATE notifikasi_manual SET status = ?, sent_at = NOW() WHERE id = ?'
    );
    $updateStmt->execute([$statusBaru, $notif['id']]);
}

echo date('Y-m-d H:i:s') . " - Proses " . count($antrian) . " notifikasi manual selesai.\n";
