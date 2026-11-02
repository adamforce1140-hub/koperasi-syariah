<?php

namespace App\Models;

use App\Support\HierarkiRole;
use App\Support\Izin;
use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot konfigurasi peran di DATABASE — langkah #6 permintaan pemilik
 * sistem: pengaturan hak akses harus KONSISTEN dan BERTAHAN di semua sesi.
 *
 * Peran tabel ini:
 *   1. MEREKAM apa yang seharusnya dipegang tiap peran (izin, kelompok,
 *      izin_dokumen, level, kategori) pada saat sinkronisasi terakhir;
 *   2. MENDETEKSI penyimpangan: bila berkas config berubah tanpa
 *      sinkronisasi (izin hilang karena salah sunting/deploy), `selisih()`
 *      menampilkannya, sehingga tidak ada wewenang yang lenyap diam-diam;
 *   3. MEMULIHKAN: `pulihkan()` mengembalikan kondisi yang tercatat.
 *
 * BATAS KEWENANGAN: tabel ini TIDAK pernah dipakai middleware/hierarki untuk
 * memberi akses. Penegak tetap membaca config (HierarkiRole/Izin). Yang
 * menyentuh tabel ini hanyalah proses sinkronisasi/pemulihan yang berjalan
 * sebagai ADMINISTRATOR SISTEM, bukan aktor HTTP — karena itu penjaga di
 * bawah menolak perubahan dari sesi pengguna biasa, memakai aturan pusat
 * `HierarkiRole::bolehMengubahPeran()` (langkah #3).
 */
class RoleKonfigurasi extends Model
{
    protected $table = 'role_konfigurasi';

    protected $fillable = [
        'role', 'label', 'level', 'kategori', 'penyetuju', 'operasional',
        'dashboard_route', 'izin', 'kelompok', 'izin_dokumen', 'sidik',
        'disinkronkan_pada',
    ];

    protected $casts = [
        'level' => 'integer',
        'penyetuju' => 'boolean',
        'operasional' => 'boolean',
        'izin' => 'array',
        'kelompok' => 'array',
        'izin_dokumen' => 'array',
        'disinkronkan_pada' => 'datetime',
    ];

    protected static function booted(): void
    {
        // PELINDUNG PUNCAK ADMINISTRATIF — langkah #3. Sinkronisasi dan
        // pemulihan hanya boleh dijalankan konsol administrator (tanpa sesi)
        // atau oleh Super Admin itu sendiri. Teller yang menemukan baris ini
        // dan mencoba menyuntingnya lewat HTTP ditolak di level model.
        static::saving(function (self $konfig): void {
            $aktor = auth()->user()?->role;

            if ($aktor !== null && ! HierarkiRole::bolehMengubahPeran($aktor, (string) $konfig->role)) {
                throw new \RuntimeException(
                    "Konfigurasi peran '{$konfig->role}' terkunci: hanya "
                    ."{$konfig->role} yang boleh mengubahnya."
                );
            }
        });
    }

    /**
     * Payload konfigurasi satu peran, diturunkan dari SELURUH lapisan aturan:
     * `config/role_hierarki.php` (level/kategori/kelompok/izin_dokumen) dan
     * `config/izin.php` (daftar izin).
     *
     * @return array<string, mixed>
     */
    public static function payloadPeran(string $peran): array
    {
        $konfig = HierarkiRole::konfigurasi();
        $meta = (array) ($konfig['peran'][$peran] ?? []);

        $izin = [];

        foreach (Izin::peta() as $nama => $daftarPeran) {
            if (in_array($peran, array_map('strval', (array) $daftarPeran), true)) {
                $izin[] = (string) $nama;
            }
        }

        $kelompok = [];

        foreach ((array) ($konfig['kelompok'] ?? []) as $namaKelompok => $daftar) {
            if (in_array($peran, array_map('strval', (array) $daftar), true)) {
                $kelompok[] = (string) $namaKelompok;
            }
        }

        $izinDokumen = [];

        foreach ((array) ($konfig['izin_dokumen'] ?? []) as $prefiks => $daftar) {
            if (in_array($peran, array_map('strval', (array) $daftar), true)) {
                $izinDokumen[] = (string) $prefiks;
            }
        }

        sort($izin);
        sort($kelompok);
        sort($izinDokumen);

        return [
            'role' => $peran,
            'label' => (string) ($meta['label'] ?? $peran),
            'level' => (int) ($meta['level'] ?? 0),
            'kategori' => isset($meta['kategori']) ? (string) $meta['kategori'] : null,
            'penyetuju' => (bool) ($meta['penyetuju'] ?? false),
            'operasional' => (bool) ($meta['operasional'] ?? false),
            'dashboard_route' => isset($meta['dashboard_route']) ? (string) $meta['dashboard_route'] : null,
            'izin' => $izin,
            'kelompok' => $kelompok,
            'izin_dokumen' => $izinDokumen,
        ];
    }

    /**
     * Sidik jari isi baris. `disinkronkan_pada` sengaja TIDAK ikut, supaya
     * sidik hanya berubah bila ISI hak akses berubah.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function hitungSidik(array $payload): string
    {
        unset($payload['disinkronkan_pada'], $payload['sidik']);

        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Selisih antara snapshot di database dan konfigurasi yang berlaku.
     *
     * @return array<string, array<string, mixed>> peran => [jenis, hilang, muncul, sidik_lama, sidik_baru]
     */
    public static function selisih(): array
    {
        $hasil = [];
        $tersimpan = static::query()->get()->keyBy('role');

        foreach (HierarkiRole::peran() as $peran) {
            $payload = static::payloadPeran($peran);
            $sidikBaru = static::hitungSidik($payload);
            /** @var self|null $baris */
            $baris = $tersimpan->get($peran);

            if ($baris === null) {
                $hasil[$peran] = [
                    'jenis' => 'belum_tersimpan',
                    'hilang' => [],
                    'muncul' => [],
                    'sidik_lama' => null,
                    'sidik_baru' => $sidikBaru,
                ];

                continue;
            }

            if ($baris->sidik === $sidikBaru) {
                continue;
            }

            // Rincian per daftar supaya laporan dapat menunjukkan IZIN MANA
            // yang hilang — bukan sekadar "ada perbedaan".
            $rincian = [];
            foreach (['izin', 'kelompok', 'izin_dokumen'] as $kunci) {
                $lama = (array) ($baris->{$kunci} ?? []);
                $baru = (array) $payload[$kunci];
                $rincian[$kunci] = [
                    'hilang' => array_values(array_diff($lama, $baru)),
                    'muncul' => array_values(array_diff($baru, $lama)),
                ];
            }

            $hasil[$peran] = [
                'jenis' => 'berbeda',
                'rincian' => $rincian,
                'sidik_lama' => $baris->sidik,
                'sidik_baru' => $sidikBaru,
            ];
        }

        // Baris untuk peran yang sudah tidak ada di config.
        foreach ($tersimpan as $peran => $baris) {
            if (! HierarkiRole::dikenal((string) $peran)) {
                $hasil[(string) $peran] = [
                    'jenis' => 'peran_dihapus',
                    'hilang' => (array) ($baris->izin ?? []),
                    'muncul' => [],
                    'sidik_lama' => $baris->sidik,
                    'sidik_baru' => null,
                ];
            }
        }

        return $hasil;
    }

    /**
     * Tulis snapshot dari konfigurasi yang berlaku. Idempoten.
     *
     * `$paksa = true` menulis ULANG seluruh baris (dipakai `role:pulihkan`
     * untuk memulihkan hak akses yang tercatat). Tanpa `$paksa`, hanya peran
     * yang belum tersimpan atau sidiknya berbeda yang disentuh, sehingga
     * `disinkronkan_pada` tidak berubah tanpa alasan.
     *
     * @return array<string, string> peran => 'dibuat' | 'diperbarui' | 'tetap'
     */
    public static function sinkronkan(bool $paksa = false): array
    {
        $hasil = [];

        foreach (HierarkiRole::peran() as $peran) {
            $payload = static::payloadPeran($peran);
            $payload['sidik'] = static::hitungSidik($payload);
            $payload['disinkronkan_pada'] = now();

            $baris = static::query()->firstOrNew(['role' => $peran]);
            $keadaan = 'tetap';

            if (! $baris->exists) {
                $keadaan = 'dibuat';
            } elseif ($baris->sidik !== $payload['sidik']) {
                $keadaan = 'diperbarui';
            }

            // `isDirty` tidak dapat diandalkan untuk kolom json; bandingkan sidik.
            if ($keadaan !== 'tetap' || $paksa) {
                $baris->fill($payload)->save();
            }

            $hasil[$peran] = $keadaan;
        }

        return $hasil;
    }

    /**
     * Pulihkan konfigurasi yang TERCATAT ke berkas config.
     *
     * Kewenangan menegakkan izin dibaca config pada setiap request
     * (`CekIzin`/`HierarkiRole`), karena itu memulihkan snapshot berarti
     * memastikan config memuat kembali nama-nama yang tercatat. Yang
     * dipulihkan hanya DAFTAR IZIN (tempat penyimpangan paling berbahaya:
     * satu nama izin yang terhapus = satu rute tertutup bagi peran itu),
     * dan hanya MENAMBAH — tidak pernah mencabut, supaya aturan yang baru
     * ditambahkan ke config tidak ikut hilang.
     *
     * @return array<string, list<string>> peran => nama izin yang dipulihkan
     */
    public static function pulihkan(): array
    {
        $peta = Izin::peta();
        $dipulihkan = [];

        foreach (static::query()->get() as $baris) {
            $peran = (string) $baris->role;

            if (! HierarkiRole::dikenal($peran)) {
                continue;
            }

            foreach ((array) ($baris->izin ?? []) as $nama) {
                $nama = (string) $nama;

                if (! array_key_exists($nama, $peta)) {
                    continue;
                }

                $daftar = array_values(array_map('strval', (array) $peta[$nama]));

                if (! in_array($peran, $daftar, true)) {
                    $daftar[] = $peran;
                    $dipulihkan[$peran][] = $nama;
                }

                $peta[$nama] = $daftar;
            }
        }

        if ($dipulihkan !== []) {
            config(['izin.izin' => $peta]);
        }

        return $dipulihkan;
    }
}
