<?php

/**
 * helpers/firebase.php
 *
 * Wrapper untuk kirim push notification lewat Firebase Cloud Messaging
 * (FCM), pakai library kreait/firebase-php.
 *
 * Membutuhkan file config/firebase-service-account.json (JANGAN di-commit
 * ke Git — lihat catatan keamanan di migration/README).
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\MessagingException;
use Kreait\Firebase\Messaging\AndroidConfig;

/**
 * Ambil instance Messaging Firebase (dibuat sekali, dipakai ulang).
 */
function getFirebaseMessaging(): \Kreait\Firebase\Contract\Messaging
{
    static $messaging = null;

    if ($messaging !== null) {
        return $messaging;
    }

    $factory = (new Factory)->withServiceAccount(
        __DIR__ . '/../config/firebase-service-account.json'
    );

    $messaging = $factory->createMessaging();

    return $messaging;
}

/**
 * Kirim 1 notifikasi ke 1 device token.
 *
 * @return bool true kalau berhasil terkirim, false kalau gagal
 *              (misal token sudah tidak valid/uninstall — ini WAJAR
 *              terjadi, jangan dianggap error fatal).
 */
function kirimNotifikasiFcm(string $fcmToken, string $judul, string $pesan): bool
{
    try {
        $messaging = getFirebaseMessaging();

        $message = CloudMessage::withTarget('token', $fcmToken)
            ->withNotification(Notification::create($judul, $pesan))
            ->withAndroidConfig(AndroidConfig::fromArray([
                'priority' => 'high',
                'notification' => [
                    // HARUS SAMA PERSIS dengan channel id yang dibuat
                    // di Flutter (lihat langkah 2 di bawah).
                    'channel_id'              => 'high_importance_channel',
                    'sound'                   => 'default',
                    'default_sound'           => true,
                    'default_vibrate_timings' => true,
                ],
            ]));

        $messaging->send($message);

        return true;
    } catch (MessagingException $e) {
        error_log("FCM gagal kirim ke token {$fcmToken}: " . $e->getMessage());
        return false;
    } catch (\Throwable $e) {
        error_log('FCM error tak terduga: ' . $e->getMessage());
        return false;
    }
}
