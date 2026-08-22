<?php

/**:
 * - checkin()   -> POST /api/absensi/checkin
 * - checkout()  -> PUT  /api/absensi/checkout
 * - today()     -> GET  /api/absensi/today
 * - riwayat()   -> GET  /api/absensi/riwayat
 * - detail()    -> GET  /api/absensi/detail
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
        // Verifikasi token, ambil identitas user dari payload JWT
        $authUser = requireAuth();

        //Ambil dan decode body JSON
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            jsonError('Body request tidak valid (harus JSON)', 400);
        }

        $latitude   = trim((string) ($input['latitude'] ?? ''));
        $longitude  = trim((string) ($input['longitude'] ?? ''));
        $status     = trim((string) ($input['status'] ?? ''));
        $keterangan = $input['keterangan'] ?? null;
        $fotoBase64 = $input['foto_base64'] ?? null;

        // Validasi input
        if ($latitude === '' || $longitude === '') {
            jsonError('Latitude dan longitude wajib diisi', 400);
        }

        $statusValid = ['H', 'I', 'S'];
        if (!in_array($status, $statusValid, true)) {
            jsonError('Status tidak valid (harus H, I, atau S)', 400);
        }

        // Ambil koneksi database
        $pdo = require __DIR__ . '/../config/database.php';

        // Cek apakah user sudah check-in hari ini
        $cekStmt = $pdo->prepare(
            'SELECT id_absensi FROM absensi WHERE nik = ? AND tanggal = CURDATE()'
        );
        $cekStmt->execute([$authUser['nik']]);

        if ($cekStmt->fetch() !== false) {
            jsonError('Anda sudah melakukan check-in hari ini', 409);
        }

        $fotoPath = null;
        if (is_string($fotoBase64) && $fotoBase64 !== '') {
            $fotoPath = $this->simpanFotoBukti($authUser['nik'], $fotoBase64);

            if ($fotoPath === null) {
                jsonError('Gagal menyimpan foto bukti (format tidak didukung)', 400);
            }
        }

        $masuk = ($status === 'H') ? date('H:i:s') : null;

        // Insert record absensi baru
        $insertStmt = $pdo->prepare(
            'INSERT INTO absensi
                (tanggal, nik, id_unit, id_jabatan, masuk, absensi, ket, foto_bukti, longitude, latitude)
             VALUES
                (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
        ]);

        $idAbsensi = (int) $pdo->lastInsertId();

        // Ambil kembali data yang baru diinsert untuk response
        $dataStmt = $pdo->prepare(
            'SELECT id_absensi, tanggal, masuk, absensi, foto_bukti
             FROM absensi
             WHERE id_absensi = ?'
        );
        $dataStmt->execute([$idAbsensi]);
        $data = $dataStmt->fetch();

        // Kirim response sukses
        jsonSuccess('Absensi berhasil dikirim', [
            'id_absensi' => (int) $data['id_absensi'],
            'tanggal'    => $data['tanggal'],
            'masuk'      => $data['masuk'],
            'absensi'    => $data['absensi'],
            'foto_bukti' => $data['foto_bukti'],
        ], 201);
    }

    private function simpanFotoBukti(string $nik, string $base64): ?string
    {
        // Format umum dari Flutter: "data:image/jpeg;base64,xxxxxx"
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

        $uploadDir = __DIR__ . '/../uploads/absensi/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = $nik . '_' . date('YmdHis') . '.' . $extension;
        $fullPath = $uploadDir . $filename;

        if (file_put_contents($fullPath, $decoded) === false) {
            return null;
        }

        // Simpan PATH RELATIF di database (bukan path absolute server),
        // supaya gampang dipakai bikin URL lengkap nanti di dashboard admin
        // (tinggal digabung: baseUrl + '/' + foto_bukti).
        return 'uploads/absensi/' . $filename;
    }

    // Absen Keluar
    public function checkout(): void
    {
        // Verifikasi token
        $authUser = requireAuth();

        // Ambil dan decode body JSON
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            jsonError('Body request tidak valid (harus JSON)', 400);
        }

        $latitude  = trim((string) ($input['latitude'] ?? ''));
        $longitude = trim((string) ($input['longitude'] ?? ''));

        // Validasi input — lat-long WAJIB dikirim baru saat checkout
        if ($latitude === '' || $longitude === '') {
            jsonError('Latitude dan longitude wajib diisi', 400);
        }

        // Ambil koneksi database
        $pdo = require __DIR__ . '/../config/database.php';

        // Cari record absensi hari ini milik user ini
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

        // Update jam keluar + lokasi baru
        $updateStmt = $pdo->prepare(
            'UPDATE absensi
             SET keluar = CURTIME(), longitude = ?, latitude = ?
             WHERE nik = ? AND tanggal = CURDATE()'
        );
        $updateStmt->execute([$longitude, $latitude, $authUser['nik']]);

        // Ambil kembali data terbaru untuk response
        $dataStmt = $pdo->prepare(
            'SELECT id_absensi, tanggal, masuk, keluar
             FROM absensi
             WHERE id_absensi = ?'
        );
        $dataStmt->execute([$record['id_absensi']]);
        $data = $dataStmt->fetch();

        // Kirim response sukses
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
        // Verifikasi token
        $authUser = requireAuth();

        // Ambil koneksi database
        $pdo = require __DIR__ . '/../config/database.php';

        // Ambil record absensi hari ini milik user ini
        $stmt = $pdo->prepare(
            'SELECT id_absensi, tanggal, masuk, keluar, absensi, keterangan, longitude, latitude
             FROM absensi
             WHERE nik = ? AND tanggal = CURDATE()'
        );
        $stmt->execute([$authUser['nik']]);
        $data = $stmt->fetch();

        if ($data === false) {
            jsonSuccess('Belum ada absensi hari ini', null);
        }

        // Kirim data yang ditemukan
        jsonSuccess('Data absensi hari ini ditemukan', [
            'id_absensi' => (int) $data['id_absensi'],
            'tanggal'    => $data['tanggal'],
            'masuk'      => $data['masuk'],
            'keluar'     => $data['keluar'],
            'absensi'    => $data['absensi'],
            'keterangan' => $data['ket'],
            'longitude'  => $data['longitude'],
            'latitude'   => $data['latitude'],
        ]);
    }

    // Riwayat absensi
    public function riwayat(): void
    {
        // Verifikasi token
        $authUser = requireAuth();

        // Ambil query parameter
        $dari   = $_GET['dari'] ?? null;
        $sampai = $_GET['sampai'] ?? null;

        // Validasi (Keduanya harus diisi)
        if (($dari !== null && $sampai === null) || ($dari === null && $sampai !== null)) {
            jsonError('Parameter dari dan sampai harus diisi bersamaan', 400);
        }

        // Validasi format tanggal jika diisi
        if ($dari !== null && !$this->isValidDate($dari)) {
            jsonError('Format tanggal dari tidak valid (harus YYYY-MM-DD)', 400);
        }
        if ($sampai !== null && !$this->isValidDate($sampai)) {
            jsonError('Format tanggal sampai tidak valid (harus YYYY-MM-DD)', 400);
        }

        // Ambil koneksi database
        $pdo = require __DIR__ . '/../config/database.php';

        // Query dengan atau tanpa filter tanggal
        if ($dari !== null && $sampai !== null) {
            $stmt = $pdo->prepare(
                'SELECT id_absensi, tanggal, masuk, keluar, absensi, ket
                 FROM absensi
                 WHERE nik = ? AND tanggal BETWEEN ? AND ?
                 ORDER BY tanggal DESC'
            );
            $stmt->execute([$authUser['nik'], $dari, $sampai]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id_absensi, tanggal, masuk, keluar, absensi, ket
                 FROM absensi
                 WHERE nik = ?
                 ORDER BY tanggal DESC'
            );
            $stmt->execute([$authUser['nik']]);
        }

        $rows = $stmt->fetchAll();

        // Bentuk ulang array agar key konsisten (lowercase) dengan endpoint lain
        $data = array_map(static function (array $row): array {
            return [
                'id_absensi' => (int) $row['ID_absensi'],
                'tanggal'    => $row['tanggal'],
                'masuk'      => $row['masuk'],
                'keluar'     => $row['keluar'],
                'absensi'    => $row['absensi'],
                'keterangan' => $row['ket'],
            ];
        }, $rows);

        // Kirim response 
        jsonSuccess('Berhasil mengambil riwayat absensi', $data);
    }

    // Detail absensi
    public function detail(): void
    {
        // Verifikasi token
        $authUser = requireAuth();

        // Ambil query parameter id
        $id = $_GET['id'] ?? null;

        // Validasi: id wajib ada dan harus angka
        if ($id === null || !ctype_digit((string) $id)) {
            jsonError('ID absensi tidak valid', 400);
        }

        // Ambil koneksi database
        $pdo = require __DIR__ . '/../config/database.php';

        // Ambil data 
        $stmt = $pdo->prepare(
            'SELECT id_absensi, tanggal, masuk, keluar, absensi, ket, longitude, latitude
             FROM absensi
             WHERE id_absensi = ? AND nik = ?'
        );
        $stmt->execute([(int) $id, $authUser['nik']]);
        $data = $stmt->fetch();

        if ($data === false) {
            jsonError('Data absensi tidak ditemukan', 404);
        }

        // Kirim response sukses
        jsonSuccess('Berhasil mengambil detail absensi', [
            'id_absensi' => (int) $data['id_absensi'],
            'tanggal'    => $data['tanggal'],
            'masuk'      => $data['masuk'],
            'keluar'     => $data['keluar'],
            'absensi'    => $data['absensi'],
            'keterangan' => $data['ket'],
            'longitude'  => $data['longitude'],
            'latitude'   => $data['latitude'],
        ]);
    }

    private function isValidDate(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
