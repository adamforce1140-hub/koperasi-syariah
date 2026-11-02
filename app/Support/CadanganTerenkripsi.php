<?php

namespace App\Support;

use RuntimeException;

/**
 * CadanganTerenkripsi — enkripsi aliran berkas cadangan basis data (R6).
 *
 * MENGAPA ADA
 * -----------
 * Berkas `.sql.gz` memuat seluruh jurnal, saldo, dan PII anggota. Salinan
 * mentah di disk, USB, atau object storage berarti kebocoran data penuh bila
 * media itu hilang. Kelas ini mengenkripsi berkas cadangan dengan
 * XChaCha20-Poly1305 (libsodium secretstream) secara bertahap, sehingga basis
 * data besar tidak perlu dimuat ke memori dan plaintext tidak pernah menyentuh
 * disk.
 *
 * FORMAT BERKAS `.sql.gz.enc`
 * ---------------------------
 *   [24 byte header]
 *   lalu berulang: [4 byte panjang plaintext (big-endian)][ciphertext + 17 byte tag]
 *   Potongan terakhir berpanjang 0 dan bertag FINAL, sehingga berkas yang
 *   terpotong dapat dideteksi saat pemulihan. Panjang ditulis eksplisit karena
 *   `fread()` dapat mengembalikan potongan lebih pendek dari yang diminta.
 *
 * KUNCI
 * -----
 * `config('cadangan.kunci')` (BACKUP_ENCRYPTION_KEY, base64 dari 32 byte) bila
 * tersedia; jika tidak, diturunkan dari APP_KEY memakai HKDF-SHA256. Tanpa
 * kunci yang benar, cadangan TIDAK dapat dipulihkan — simpan salinan kunci di
 * tempat terpisah dari berkas cadangan.
 */
class CadanganTerenkripsi
{
    public const EKSTENSI = '.sql.gz.enc';

    /** Ukuran potongan plaintext (1 MiB). */
    private const POTONGAN = 1048576;

    private const TAG_MESSAGE = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

    private const TAG_FINAL = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;

    private const HEADER_BYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;

    private const ABYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

    private const KEY_BYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES;

    /**
     * Apakah cadangan baru harus dienkripsi.
     */
    public static function aktif(): bool
    {
        return (bool) config('cadangan.enkripsi', true);
    }

    /**
     * Kunci 32 byte untuk secretstream.
     */
    public static function kunci(): string
    {
        $kunci = config('cadangan.kunci');

        if (is_string($kunci) && $kunci !== '') {
            $biner = base64_decode($kunci, true);

            if ($biner === false || strlen($biner) !== self::KEY_BYTES) {
                throw new RuntimeException('BACKUP_ENCRYPTION_KEY harus berupa base64 dari tepat 32 byte.');
            }

            return $biner;
        }

        $appKey = (string) config('app.key');

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7), true) ?: $appKey;
        }

        if ($appKey === '') {
            throw new RuntimeException('APP_KEY kosong; kunci cadangan tidak dapat diturunkan.');
        }

        // HKDF memisahkan kunci cadangan dari kunci sesi aplikasi, sehingga
        // kebocoran salah satu tidak langsung membuka yang lain.
        return hash_hkdf('sha256', $appKey, self::KEY_BYTES, 'koperasi-cadangan');
    }

    /**
     * Enkripsi berkas apa pun menjadi berkas `.enc`.
     *
     * Dipakai untuk pemulihan/verifikasi dan sebagai jalur cadangan bila
     * pemanggil sudah memiliki berkas gzip jadi.
     */
    public static function enkripsiBerkas(string $sumber, string $tujuan): void
    {
        $masuk = @fopen($sumber, 'rb');

        if ($masuk === false) {
            throw new RuntimeException("Berkas sumber tidak dapat dibuka: {$sumber}");
        }

        $keluar = @fopen($tujuan, 'wb');

        if ($keluar === false) {
            fclose($masuk);
            throw new RuntimeException("Berkas tujuan tidak dapat ditulis: {$tujuan}");
        }

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push(self::kunci());
            fwrite($keluar, $header);

            while (! feof($masuk)) {
                $potongan = fread($masuk, self::POTONGAN);

                if ($potongan === false) {
                    throw new RuntimeException("Berkas sumber tidak dapat dibaca: {$sumber}");
                }

                if ($potongan === '') {
                    break;
                }

                fwrite($keluar, pack('N', strlen($potongan)));
                fwrite($keluar, sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $potongan,
                    '',
                    self::TAG_MESSAGE
                ));
            }

            fwrite($keluar, pack('N', 0));
            fwrite($keluar, sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                '',
                '',
                self::TAG_FINAL
            ));
        } finally {
            if (is_resource($masuk)) {
                fclose($masuk);
            }

            if (is_resource($keluar)) {
                fclose($keluar);
            }
        }
    }

    /**
     * Dekripsi berkas `.enc` kembali menjadi berkas aslinya.
     */
    public static function dekripsiBerkas(string $sumber, string $tujuan): void
    {
        $masuk = @fopen($sumber, 'rb');

        if ($masuk === false) {
            throw new RuntimeException("Berkas cadangan tidak dapat dibuka: {$sumber}");
        }

        $keluar = @fopen($tujuan, 'wb');

        if ($keluar === false) {
            fclose($masuk);
            throw new RuntimeException("Berkas tujuan tidak dapat ditulis: {$tujuan}");
        }

        try {
            $header = fread($masuk, self::HEADER_BYTES);

            if ($header === false || strlen($header) !== self::HEADER_BYTES) {
                throw new RuntimeException('Berkas cadangan tidak dikenali (header terlalu pendek).');
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, self::kunci());
            $selesai = false;

            while (true) {
                $kepala = self::bacaTepat($masuk, 4);

                if ($kepala === null) {
                    break;
                }

                if (strlen($kepala) !== 4) {
                    throw new RuntimeException('Berkas cadangan terpotong (panjang potongan tidak lengkap).');
                }

                $panjang = unpack('N', $kepala)[1];

                if ($panjang > self::POTONGAN) {
                    throw new RuntimeException('Berkas cadangan rusak (panjang potongan tidak wajar).');
                }

                $potongan = self::bacaTepat($masuk, $panjang + self::ABYTES);

                if ($potongan === null || strlen($potongan) !== $panjang + self::ABYTES) {
                    throw new RuntimeException('Berkas cadangan terpotong (potongan tidak lengkap).');
                }

                $hasil = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $potongan);

                if ($hasil === false) {
                    throw new RuntimeException('Cadangan rusak atau kunci enkripsi salah.');
                }

                [$plain, $tag] = $hasil;

                if ($plain !== '') {
                    fwrite($keluar, $plain);
                }

                if ($tag === self::TAG_FINAL) {
                    $selesai = true;

                    break;
                }
            }

            if (! $selesai) {
                throw new RuntimeException('Berkas cadangan terpotong (penanda akhir tidak ditemukan).');
            }
        } catch (RuntimeException $e) {
            @unlink($tujuan);

            throw $e;
        } finally {
            if (is_resource($masuk)) {
                fclose($masuk);
            }

            if (is_resource($keluar)) {
                fclose($keluar);
            }
        }
    }

    /**
     * Baca tepat $panjang byte, atau null bila sudah di akhir berkas.
     *
     * `fread()` boleh mengembalikan lebih sedikit byte dari yang diminta,
     * sehingga pembacaan diulang sampai jumlahnya terpenuhi.
     *
     * @param  resource  $handle
     */
    private static function bacaTepat($handle, int $panjang): ?string
    {
        $buffer = '';

        while (strlen($buffer) < $panjang) {
            $bagian = fread($handle, $panjang - strlen($buffer));

            if ($bagian === false) {
                throw new RuntimeException('Berkas cadangan tidak dapat dibaca.');
            }

            if ($bagian === '') {
                return $buffer === '' ? null : $buffer;
            }

            $buffer .= $bagian;
        }

        return $buffer;
    }
}
