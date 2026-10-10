<?php

/**
 * helpers/face.php
 *
 * Helper bersama untuk urusan face embedding (dipakai oleh
 * WajahController untuk enroll/verifikasi absen, DAN oleh
 * AuthController untuk alur "Lupa Password via Wajah").
 *
 * Ditarik keluar jadi helper terpisah supaya logic validasi &
 * perhitungan cosine similarity TIDAK duplikat di dua tempat.
 */

declare(strict_types=1);

// Jumlah angka yang harus ada di setiap embedding MobileFaceNet.
const FACE_EMBEDDING_LENGTH = 192;

// Ambang batas cosine similarity untuk dianggap "cocok".
// 1.0 = identik sempurna, 0.0 = tidak mirip sama sekali.
const FACE_MATCH_THRESHOLD = 0.8;

// Validasi bentuk embedding: harus array angka sepanjang FACE_EMBEDDING_LENGTH
function validasiEmbedding($embedding): ?string
{
    if (!is_array($embedding)) {
        return 'Field embedding wajib berupa array angka';
    }

    if (count($embedding) !== FACE_EMBEDDING_LENGTH) {
        return 'Panjang embedding tidak valid (harus '
            . FACE_EMBEDDING_LENGTH . ' angka)';
    }

    foreach ($embedding as $nilai) {
        if (!is_int($nilai) && !is_float($nilai)) {
            return 'Semua elemen embedding harus berupa angka';
        }
    }

    return null; // valid
}

// Hitung cosine similarity antara 2 vector embedding.
// Hasil mendekati 1.0 = sangat mirip, mendekati 0.0 = tidak mirip.
function cosineSimilarity(array $a, array $b): float
{
    $dotProduct = 0.0;
    $normA = 0.0;
    $normB = 0.0;

    $panjang = count($a);
    for ($i = 0; $i < $panjang; $i++) {
        $dotProduct += $a[$i] * $b[$i];
        $normA += $a[$i] ** 2;
        $normB += $b[$i] ** 2;
    }

    if ($normA <= 0.0 || $normB <= 0.0) {
        return 0.0; // hindari pembagian dengan nol
    }

    return $dotProduct / (sqrt($normA) * sqrt($normB));
}
