<?php

/**
 * - checkin()      -> POST /api/absensi/checkin
 * - checkout()     -> PUT  /api/absensi/checkout
 * - today()        -> GET  /api/absensi/today
 * - riwayat()      -> GET  /api/absensi/riwayat
 * - detail()       -> GET  /api/absensi/detail
 * - batasWaktu()   -> GET  /api/absensi/batas-waktu
 * - daftarLokasi() -> GET  /api/absensi/lokasi          [BARU]
 *
 * Semua endpoint di sini membutuhkan login (JWT).
 * NIK, ID_unit, ID_jabatan SELALU diambil dari payload token
 * requireAuth()
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../middleware/auth.php';

class AbsensiController
{
    // Absen Masuk
    public function checkin(): void
    {
        $authUser = requireAuth();

        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            jsonError('Body request tidak valid (harus JSON)', 400);
        }

        $latitude   = trim((string) ($input['latitude'] ?? ''));
        $longitude  = trim((string) ($input['longitude'] ?? ''));
        $status     = trim((string) ($input['status'] ?? ''));
        $keterangan = $input['keterangan'] ?? null;
        $fotoBase64 = $input['foto_base64'] ?? null;

        if ($latitude === '' || $longitude === '') {
            jsonError('Latitude dan longitude wajib diisi', 400);
        }

        $statusValid = ['H', 'I', 'S'];
        if (!in_array($status, $statusValid, true)) {
            jsonError('Status tidak valid (harus H, I, atau S)', 400);
        }

        $pdo = require __DIR__ . '/../config/database.php';

        // Validasi batas waktu Hadir (fitur sebelumnya, tidak berubah)
        if ($status === 'H') {
            $jamBatas = $this->resolveBatasWaktu($pdo, $authUser['nik'], $authUser['id_unit']);
            if ($jamBatas !== null && date('H:i:s') > $jamBatas) {
                jsonError(
                    "Sudah melewati batas waktu absen Hadir ({$jamBatas}). Silakan pilih Izin/Sakit.",
                    400
                );
            }
        }

        $cekStmt = $pdo->prepare(
            'SELECT id_absensi FROM absensi WHERE nik = ? AND tanggal = CURDATE()'
        );
        $cekStmt->execute([$authUser['nik']]);

        if ($cekStmt->fetch() !== false) {
            jsonError('Anda sudah melakukan check-in hari ini', 409);
        }

        // [BARU] Cek apakah lokasi user di dalam radius titik resmi mana pun
        // (kantor, kandang, dll) ATAU di dalam radius rumah pribadinya.
        // Ini dihitung ULANG di server (tidak percaya klaim dari Flutter),
        // supaya tidak bisa dimanipulasi.
        $latF = (float) $latitude;
        $lonF = (float) $longitude;
        $dalamRadius = $this->cekDalamRadius($pdo, $authUser['nik'], $latF, $lonF);

        // Kalau di LUAR semua radius, foto bukti WAJIB (apa pun statusnya),
        // sebagai bukti tambahan untuk direview admin.
        if (!$dalamRadius && (!is_string($fotoBase64) || $fotoBase64 === '')) {
            jsonError(
                'Anda terdeteksi di luar radius lokasi resmi. Foto bukti wajib dilampirkan.',
                400
            );
        }

        $fotoPath = null;
        if (is_string($fotoBase64) && $fotoBase64 !== '') {
            $fotoPath = $this->simpanFotoBukti($fotoBase64);

            if ($fotoPath === null) {
                jsonError('Gagal menyimpan foto bukti (format tidak didukung)', 400);
            }
        }

        $masuk = ($status === 'H') ? date('H:i:s') : null;

        // [BARU] status_approval selalu 'pending' (default kolom), semua
        // jenis absensi butuh di-approve admin. diluar_radius disimpan
        // sesuai hasil cek server tadi.
        $insertStmt = $pdo->prepare(
            'INSERT INTO absensi
                (tanggal, nik, id_unit, id_jabatan, masuk, absensi, ket, foto_bukti, longitude, latitude, diluar_radius)
             VALUES
                (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertStmt->execute([
            $authUser['nik'],
            $authUser['id_unit'],
            $authUser['id_jabatan'],
            $masuk,
            $status,
            $keterangan,
            $fotoPath,
            $longitude,
            $latitude,
            $dalamRadius ? 0 : 1,
        ]);

        $idAbsensi = (int) $pdo->lastInsertId();

        $dataStmt = $pdo->prepare(
            'SELECT id_absensi, tanggal, masuk, absensi, foto_bukti, status_approval, diluar_radius
             FROM absensi
             WHERE id_absensi = ?'
        );
        $dataStmt->execute([$idAbsensi]);
        $data = $dataStmt->fetch();

        jsonSuccess('Absensi berhasil dikirim, menunggu persetujuan admin', [
            'id_absensi'      => (int) $data['id_absensi'],
            'tanggal'         => $data['tanggal'],
            'masuk'           => $data['masuk'],
            'absensi'         => $data['absensi'],
            'foto_bukti'      => $data['foto_bukti'],
            'status_approval' => $data['status_approval'],
            'diluar_radius'   => (bool) $data['diluar_radius'],
        ], 201);
    }

    /**
     * [BARU] Hitung jarak antara 2 koordinat pakai formula Haversine.
     * @return float Jarak dalam METER.
     */
    private function jarakMeter(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // radius bumi dalam meter

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * [BARU] Cek apakah koordinat (lat, lon) berada di dalam radius SALAH
     * SATU titik lokasi_absensi yang aktif, ATAU di dalam radius rumah
     * pribadi milik NIK ini (kalau sudah diisi).
     */
    private function cekDalamRadius(PDO $pdo, string $nik, float $lat, float $lon): bool
    {
        $stmt = $pdo->query(
            'SELECT latitude, longitude, radius_meter FROM lokasi_absensi WHERE aktif = 1'
        );
        foreach ($stmt->fetchAll() as $titik) {
            $jarak = $this->jarakMeter($lat, $lon, (float) $titik['latitude'], (float) $titik['longitude']);
            if ($jarak <= (float) $titik['radius_meter']) {
                return true;
            }
        }

        $stmt = $pdo->prepare(
            'SELECT latitude_rumah, longitude_rumah, radius_rumah_meter
             FROM karyawan WHERE nik = ?'
        );
        $stmt->execute([$nik]);
        $rumah = $stmt->fetch();

        if ($rumah !== false && $rumah['latitude_rumah'] !== null && $rumah['longitude_rumah'] !== null) {
            $jarak = $this->jarakMeter(
                $lat,
                $lon,
                (float) $rumah['latitude_rumah'],
                (float) $rumah['longitude_rumah']
            );
            $radiusRumah = $rumah['radius_rumah_meter'] !== null ? (float) $rumah['radius_rumah_meter'] : 100.0;
            if ($jarak <= $radiusRumah) {
                return true;
            }
        }

        return false;
    }

    /**
     * [BARU] GET /api/absensi/lokasi
     * Daftar semua titik lokasi resmi yang aktif + titik rumah pribadi
     * user (kalau ada). Dipakai Flutter untuk tampilkan di map dan
     * hitung jarak di sisi client (cuma untuk UX/preview, keputusan
     * FINAL tetap dihitung ulang di server saat checkin()).
     */
    public function daftarLokasi(): void
    {
        $authUser = requireAuth();

        $pdo = require __DIR__ . '/../config/database.php';

        $stmt = $pdo->query(
            'SELECT id, nama_lokasi, latitude, longitude, radius_meter
             FROM lokasi_absensi WHERE aktif = 1'
        );
        $lokasi = $stmt->fetchAll();

        $stmt2 = $pdo->prepare(
            'SELECT latitude_rumah, longitude_rumah, radius_rumah_meter
             FROM karyawan WHERE nik = ?'
        );
        $stmt2->execute([$authUser['nik']]);
        $rumah = $stmt2->fetch();

        if ($rumah !== false && $rumah['latitude_rumah'] !== null) {
            $lokasi[] = [
                'id'           => null,
                'nama_lokasi'  => 'Rumah Saya',
                'latitude'     => $rumah['latitude_rumah'],
                'longitude'    => $rumah['longitude_rumah'],
                'radius_meter' => $rumah['radius_rumah_meter'] ?? 100,
            ];
        }

        jsonSuccess('Berhasil mengambil daftar lokasi', $lokasi);
    }

    /**
     * [DIUBAH] Simpan foto ke folder PER HARI:
     * uploads/absensi/{YYYY-MM-DD}/{nama_acak}.jpg
     * (sebelumnya semua foto ditumpuk jadi 1 folder flat)
     */
    private function simpanFotoBukti(string $base64): ?string
    {
        $extension = 'jpg';
        if (preg_match('/^data:image\/(\w+);base64,/', $base64, $matches)) {
            $extension = strtolower($matches[1]);
            $base64 = substr($base64, strpos($base64, ',') + 1);
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png'];
        if (!in_array($extension, $allowedExtensions, true)) {
            return null;
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            return null;
        }

        $folderTanggal = date('Y-m-d');
        $uploadDir = __DIR__ . '/../uploads/absensi/' . $folderTanggal . '/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $namaAcak = bin2hex(random_bytes(16));
        $filename = $namaAcak . '.' . $extension;
        $fullPath = $uploadDir . $filename;

        if (file_put_contents($fullPath, $decoded) === false) {
            return null;
        }

        return 'uploads/absensi/' . $folderTanggal . '/' . $filename;
    }

    // Absen Keluar
    public function checkout(): void
    {
        $authUser = requireAuth();

        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            jsonError('Body request tidak valid (harus JSON)', 400);
        }

        $latitude  = trim((string) ($input['latitude'] ?? ''));
        $longitude = trim((string) ($input['longitude'] ?? ''));

        if ($latitude === '' || $longitude === '') {
            jsonError('Latitude dan longitude wajib diisi', 400);
        }

        $pdo = require __DIR__ . '/../config/database.php';

        $cekStmt = $pdo->prepare(
            'SELECT id_absensi, keluar, absensi FROM absensi WHERE nik = ? AND tanggal = CURDATE()'
        );
        $cekStmt->execute([$authUser['nik']]);
        $record = $cekStmt->fetch();

        if ($record === false) {
            jsonError('Anda belum melakukan check-in hari ini', 404);
        }

        if ($record['absensi'] !== 'H') {
            jsonError('Absensi hari ini berstatus Izin/Sakit, tidak perlu check-out', 400);
        }

        if ($record['keluar'] !== null) {
            jsonError('Anda sudah melakukan check-out hari ini', 409);
        }

        $updateStmt = $pdo->prepare(
            'UPDATE absensi
             SET keluar = CURTIME(), longitude = ?, latitude = ?
             WHERE nik = ? AND tanggal = CURDATE()'
        );
        $updateStmt->execute([$longitude, $latitude, $authUser['nik']]);

        $dataStmt = $pdo->prepare(
            'SELECT id_absensi, tanggal, masuk, keluar
             FROM absensi
             WHERE id_absensi = ?'
        );
        $dataStmt->execute([$record['id_absensi']]);
        $data = $dataStmt->fetch();

        jsonSuccess('Check-out berhasil', [
            'id_absensi' => (int) $data['id_absensi'],
            'tanggal'    => $data['tanggal'],
            'masuk'      => $data['masuk'],
            'keluar'     => $data['keluar'],
        ]);
    }

    // Data absensi hari ini
    public function today(): void
    {
        $authUser = requireAuth();

        $pdo = require __DIR__ . '/../config/database.php';

        $stmt = $pdo->prepare(
            'SELECT id_absensi, tanggal, masuk, keluar, absensi, ket, foto_bukti,
                    longitude, latitude, status_approval, catatan_approval, diluar_radius
             FROM absensi
             WHERE nik = ? AND tanggal = CURDATE()'
        );
        $stmt->execute([$authUser['nik']]);
        $data = $stmt->fetch();

        if ($data === false) {
            jsonSuccess('Belum ada absensi hari ini', null);
        }

        jsonSuccess('Data absensi hari ini ditemukan', [
            'id_absensi'       => (int) $data['id_absensi'],
            'tanggal'          => $data['tanggal'],
            'masuk'            => $data['masuk'],
            'keluar'           => $data['keluar'],
            'absensi'          => $data['absensi'],
            'keterangan'       => $data['ket'],
            'foto_bukti'       => $data['foto_bukti'],
            'longitude'        => $data['longitude'],
            'latitude'         => $data['latitude'],
            'status_approval'  => $data['status_approval'],
            'catatan_approval' => $data['catatan_approval'],
            'diluar_radius'    => (bool) $data['diluar_radius'],
        ]);
    }

    // Riwayat absensi
    public function riwayat(): void
    {
        $authUser = requireAuth();

        $dari   = $_GET['dari'] ?? null;
        $sampai = $_GET['sampai'] ?? null;

        if (($dari !== null && $sampai === null) || ($dari === null && $sampai !== null)) {
            jsonError('Parameter dari dan sampai harus diisi bersamaan', 400);
        }

        if ($dari !== null && !$this->isValidDate($dari)) {
            jsonError('Format tanggal dari tidak valid (harus YYYY-MM-DD)', 400);
        }
        if ($sampai !== null && !$this->isValidDate($sampai)) {
            jsonError('Format tanggal sampai tidak valid (harus YYYY-MM-DD)', 400);
        }

        $pdo = require __DIR__ . '/../config/database.php';

        if ($dari !== null && $sampai !== null) {
            $stmt = $pdo->prepare(
                'SELECT id_absensi, tanggal, masuk, keluar, absensi, ket, status_approval
                 FROM absensi
                 WHERE nik = ? AND tanggal BETWEEN ? AND ?
                 ORDER BY tanggal DESC'
            );
            $stmt->execute([$authUser['nik'], $dari, $sampai]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id_absensi, tanggal, masuk, keluar, absensi, ket, status_approval
                 FROM absensi
                 WHERE nik = ?
                 ORDER BY tanggal DESC'
            );
            $stmt->execute([$authUser['nik']]);
        }

        $rows = $stmt->fetchAll();

        $data = array_map(static function (array $row): array {
            return [
                'id_absensi'      => (int) $row['id_absensi'],
                'tanggal'         => $row['tanggal'],
                'masuk'           => $row['masuk'],
                'keluar'          => $row['keluar'],
                'absensi'         => $row['absensi'],
                'keterangan'      => $row['ket'],
                'status_approval' => $row['status_approval'],
            ];
        }, $rows);

        jsonSuccess('Berhasil mengambil riwayat absensi', $data);
    }

    // Detail absensi
    public function detail(): void
    {
        $authUser = requireAuth();

        $id = $_GET['id'] ?? null;

        if ($id === null || !ctype_digit((string) $id)) {
            jsonError('ID absensi tidak valid', 400);
        }

        $pdo = require __DIR__ . '/../config/database.php';

        $stmt = $pdo->prepare(
            'SELECT id_absensi, tanggal, masuk, keluar, absensi, ket, foto_bukti,
                    longitude, latitude, status_approval, catatan_approval, diluar_radius
             FROM absensi
             WHERE id_absensi = ? AND nik = ?'
        );
        $stmt->execute([(int) $id, $authUser['nik']]);
        $data = $stmt->fetch();

        if ($data === false) {
            jsonError('Data absensi tidak ditemukan', 404);
        }

        jsonSuccess('Berhasil mengambil detail absensi', [
            'id_absensi'       => (int) $data['id_absensi'],
            'tanggal'          => $data['tanggal'],
            'masuk'            => $data['masuk'],
            'keluar'           => $data['keluar'],
            'absensi'          => $data['absensi'],
            'keterangan'       => $data['ket'],
            'foto_bukti'       => $data['foto_bukti'],
            'longitude'        => $data['longitude'],
            'latitude'         => $data['latitude'],
            'status_approval'  => $data['status_approval'],
            'catatan_approval' => $data['catatan_approval'],
            'diluar_radius'    => (bool) $data['diluar_radius'],
        ]);
    }

    public function batasWaktu(): void
    {
        $authUser = requireAuth();

        $pdo = require __DIR__ . '/../config/database.php';

        $jamBatas = $this->resolveBatasWaktu($pdo, $authUser['nik'], $authUser['id_unit']);

        jsonSuccess('Berhasil mengambil batas waktu', [
            'jam_batas' => $jamBatas,
        ]);
    }

    private function resolveBatasWaktu(PDO $pdo, string $nik, string $idUnit): ?string
    {
        $stmt = $pdo->prepare(
            "SELECT jam_batas FROM batas_waktu_hadir
             WHERE scope_type = 'karyawan' AND scope_value = ?
             LIMIT 1"
        );
        $stmt->execute([$nik]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row['jam_batas'];
        }

        $stmt = $pdo->prepare(
            "SELECT jam_batas FROM batas_waktu_hadir
             WHERE scope_type = 'unit' AND scope_value = ?
             LIMIT 1"
        );
        $stmt->execute([$idUnit]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row['jam_batas'];
        }

        $stmt = $pdo->prepare(
            "SELECT jam_batas FROM batas_waktu_hadir
             WHERE scope_type = 'semua'
             LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();

        return $row !== false ? $row['jam_batas'] : null;
    }

    private function isValidDate(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    public function aturLokasiRumah(): void
    {
        $authUser = requireAuth();

        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            jsonError('Body request tidak valid (harus JSON)', 400);
        }

        $latitude  = trim((string) ($input['latitude'] ?? ''));
        $longitude = trim((string) ($input['longitude'] ?? ''));

        if ($latitude === '' || $longitude === '') {
            jsonError('Latitude dan longitude wajib diisi', 400);
        }

        $pdo = require __DIR__ . '/../config/database.php';

        $stmt = $pdo->prepare(
            'UPDATE karyawan SET latitude_rumah = ?, longitude_rumah = ? WHERE nik = ?'
        );
        $stmt->execute([$latitude, $longitude, $authUser['nik']]);

        jsonSuccess('Lokasi rumah berhasil disimpan');
    }

    public function getLokasiRumah(): void
{
    $authUser = requireAuth();

    $pdo = require __DIR__ . '/../config/database.php';

    $stmt = $pdo->prepare(
        'SELECT latitude_rumah, longitude_rumah, radius_rumah_meter
         FROM karyawan WHERE nik = ?'
    );
    $stmt->execute([$authUser['nik']]);
    $data = $stmt->fetch();

    if ($data === false || $data['latitude_rumah'] === null) {
        jsonSuccess('Belum ada lokasi rumah tersimpan', null);
    }

    jsonSuccess('Berhasil mengambil lokasi rumah', [
        'latitude'     => $data['latitude_rumah'],
        'longitude'    => $data['longitude_rumah'],
        'radius_meter' => $data['radius_rumah_meter'] ?? 100,
    ]);
}
}
