<?php

namespace App\Services;

use App\Models\Anggota;
use App\Models\JenisSimpanan;
use App\Models\Jurnal;
use App\Models\KebijakanKoperasi;
use App\Models\Rekening;
use App\Models\Transaksi;
use Illuminate\Support\Facades\Log;

/**
 * VERIFIKASI SETORAN AWAL & AKTIVASI ANGGOTA.
 *
 * Spesifikasi Bagian 2 butir 3:
 *   "Setelah setoran awal (Simpanan Pokok & Simpanan Wajib) berhasil diproses
 *    dan terverifikasi di modul Teller, ubah `anggota.status_anggota` menjadi
 *    'ACTIVE' secara otomatis. Tambahkan validasi untuk memastikan status hanya
 *    berubah jika KEDUA setoran awal tercatat dengan benar di sistem akuntansi."
 *
 * Ambang setoran bersifat 100% DINAMIS dan mengacu pada SATU sumber kebenaran
 * yang sama dengan yang dipakai saat registrasi anggota (`CifController::store`)
 * dan pengelolaan tagihan bulanan (`MandatorySavingsService`):
 *   - Simpanan Pokok → `kebijakan_koperasis.mandatory_principal_savings`
 *                      (fallback: `jenis_simpanans.min_deposit_amount` produk POKOK).
 *   - Simpanan Wajib → `kebijakan_koperasis.mandatory_monthly_savings`
 *                      (fallback: `jenis_simpanans.min_deposit_amount` produk WAJIB).
 *
 * Catatan penting: `KebijakanKoperasi::simpananPokokWajib()` sengaja diresolusi
 * melalui `Setting` yang membaca kolom `kebijakan_koperasis.mandatory_principal_savings`
 * (bukan `Setting::simpananPokok()` yang membaca `pengaturan_pengaturan`), agar
 * ambang aktivasi identik dengan nominal yang benar-benar diminta ke anggota.
 *
 * Sebelumnya ambang Simpanan Pokok diambil dari `min_deposit_amount` produk
 * POKOK (100.000). Karena registrasi hanya menagih sesuai kebijakan (50.000),
 * anggota yang sudah melunasi setoran awal sesuai tagihan TIDAK PERNAH menjadi
 * ACTIVE. Penyelarasan ini menutup celah tersebut.
 *
 * "Tercatat dengan benar di sistem akuntansi" diartikan sebagai berikut:
 *   - Rekening Simpanan Pokok & Simpanan Wajib anggota ada dan berstatus Aktif;
 *   - Saldo BUKU rekening ≥ ambang sesuai konfigurasi produk;
 *   - Terdapat mutasi setoran (posisi Debet) pada `transaksi` untuk masing-masing
 *     rekening, dan mutasi tersebut sudah tercatat di jurnal — baik lewat
 *     `jurnal.transaksi_id` maupun sebagai baris jurnal bundling setoran awal
 *     yang mengkredit rekening tersebut.
 */
class SetoranAwalService
{
    /**
     * Ambang setoran awal yang disyaratkan untuk aktivasi anggota.
     *
     * @return array{pokok: float, wajib: float}
     */
    public function ambangSetoranAwal(): array
    {
        /* Prioritas: nominal yang DITAGIH ke anggota saat registrasi.
         | `KebijakanKoperasi::simpananPokokWajib()` membaca kolom
         | `kebijakan_koperasis.mandatory_principal_savings` (sumber yang sama
         | dengan `CifController::store()`), sehingga ambang aktivasi selalu
         | konsisten dengan setoran awal yang benar-benar diminta ke anggota.
         | `jenis_simpanans.min_deposit_amount` hanya dipakai sebagai fallback
         | bila kebijakan belum/ tidak diisi (≤ 0). */
        $pokok = (float) KebijakanKoperasi::simpananPokokWajib();

        if ($pokok <= 0) {
            $pokok = (float) JenisSimpanan::minimalSetoranPokok();
        }

        $wajib = (float) KebijakanKoperasi::simpananWajibBulanan();

        if ($wajib <= 0) {
            $wajib = (float) JenisSimpanan::minimalSetoranWajib();
        }

        return ['pokok' => $pokok, 'wajib' => $wajib];
    }

    /**
     * Periksa kelengkapan setoran awal seorang anggota.
     *
     * @return array{
     *     lengkap: bool,
     *     pokok: array{saldo: float, ambang: float, ada: bool, terjurnal: bool},
     *     wajib: array{saldo: float, ambang: float, ada: bool, terjurnal: bool},
     *     alasan: array<int, string>
     * }
     */
    public function periksa(Anggota $anggota): array
    {
        $ambang = $this->ambangSetoranAwal();

        $pokok = $this->periksaKomponen($anggota, JenisSimpanan::namaUtama(JenisSimpanan::TIPE_POKOK, 'Simpanan Pokok'), $ambang['pokok']);
        $wajib = $this->periksaKomponen($anggota, JenisSimpanan::namaUtama(JenisSimpanan::TIPE_WAJIB, 'Simpanan Wajib'), $ambang['wajib']);

        $alasan = [];

        if (! $pokok['ada']) {
            $alasan[] = 'Rekening Simpanan Pokok belum tersedia.';
        } elseif (! $pokok['terjurnal']) {
            $alasan[] = 'Setoran Simpanan Pokok belum tercatat di jurnal akuntansi.';
        } elseif ($pokok['saldo'] < $ambang['pokok']) {
            $alasan[] = 'Saldo Simpanan Pokok Rp '.number_format($pokok['saldo'], 0, ',', '.')
                .' belum mencapai Rp '.number_format($ambang['pokok'], 0, ',', '.').'.';
        }

        if (! $wajib['ada']) {
            $alasan[] = 'Rekening Simpanan Wajib belum tersedia.';
        } elseif (! $wajib['terjurnal']) {
            $alasan[] = 'Setoran Simpanan Wajib bulan ke-1 belum tercatat di jurnal akuntansi.';
        } elseif ($wajib['saldo'] < $ambang['wajib']) {
            $alasan[] = 'Saldo Simpanan Wajib Rp '.number_format($wajib['saldo'], 0, ',', '.')
                .' belum mencapai Rp '.number_format($ambang['wajib'], 0, ',', '.').'.';
        }

        return [
            'lengkap' => $pokok['ada'] && $pokok['terjurnal'] && $pokok['saldo'] >= $ambang['pokok']
                && $wajib['ada'] && $wajib['terjurnal'] && $wajib['saldo'] >= $ambang['wajib'],
            'pokok' => $pokok,
            'wajib' => $wajib,
            'alasan' => $alasan,
        ];
    }

    /**
     * Ubah status anggota PENDING → ACTIVE HANYA bila setoran awal terverifikasi.
     *
     * @return array{diaktivasi: bool, alasan: array<int, string>}
     */
    public function aktivasiJikaLengkap(Anggota $anggota): array
    {
        if ($anggota->status_anggota !== Anggota::STATUS_PENDING) {
            return ['diaktivasi' => false, 'alasan' => []];
        }

        $hasil = $this->periksa($anggota);

        if (! $hasil['lengkap']) {
            Log::info('Aktivasi anggota ditangguhkan: setoran awal belum lengkap.', [
                'anggota_id' => $anggota->id,
                'no_anggota' => $anggota->no_anggota,
                'alasan' => $hasil['alasan'],
            ]);

            return ['diaktivasi' => false, 'alasan' => $hasil['alasan']];
        }

        $anggota->update(['status_anggota' => Anggota::STATUS_ACTIVE]);

        Log::info('Anggota diaktivasi otomatis setelah setoran awal terverifikasi.', [
            'anggota_id' => $anggota->id,
            'no_anggota' => $anggota->no_anggota,
        ]);

        return ['diaktivasi' => true, 'alasan' => []];
    }

    /**
     * Periksa satu komponen setoran awal (Pokok atau Wajib).
     *
     * @return array{saldo: float, ambang: float, ada: bool, terjurnal: bool}
     */
    private function periksaKomponen(Anggota $anggota, string $jenisRekening, float $ambang): array
    {
        $rekening = Rekening::where('anggota_id', $anggota->id)
            ->where('jenis_rekening', $jenisRekening)
            ->where('status_rekening', 'Aktif')
            ->first();

        if (! $rekening) {
            return ['saldo' => 0.0, 'ambang' => $ambang, 'ada' => false, 'terjurnal' => false];
        }

        /* "Tercatat di sistem akuntansi" = ada mutasi setoran (Debet) pada
         | rekening ini DAN mutasi tersebut sudah ditautkan ke jurnal.
         |
         | Tautan tersimpan pada `jurnal.transaksi_id` (satu jurnal per transaksi),
         | bukan pada kolom di tabel `transaksi` — lihat
         | `AutoJournalingService::post()` yang mengisi `Jurnal::create([...,
         | 'transaksi_id' => $transaksiId])`. */
        $transaksiIds = Transaksi::where('rekening_id', $rekening->id)
            ->where('posisi', 'Debet')
            ->pluck('id');

        $terjurnal = $transaksiIds->isNotEmpty()
            && (
                Jurnal::whereIn('transaksi_id', $transaksiIds)->exists()
                || $this->tercatatDalamJurnalBundling($rekening)
            );

        return [
            'saldo' => (float) $rekening->saldo,
            'ambang' => $ambang,
            'ada' => true,
            'terjurnal' => $terjurnal,
        ];
    }

    /**
     * Jurnal bundling setoran awal menautkan `transaksi_id` hanya ke transaksi
     * Simpanan Pokok, padahal satu jurnal yang sama juga mengkredit rekening
     * Simpanan Wajib anggota. Tanpa pengakuan baris detail ini, komponen Wajib
     * selalu dianggap belum terjurnal meskipun saldonya terbentuk dari jurnal
     * yang sama. Baris detail simpanan kini membawa `rekening_id` yang sah
     * (lihat `AutoJournalingService::rekeningIdUntukCoa()`), sehingga
     * atribusinya dapat dipercaya.
     */
    private function tercatatDalamJurnalBundling(Rekening $rekening): bool
    {
        return Jurnal::where('sumber', AutoJournalingService::SUMBER_BUNDLING_SETORAN_AWAL)
            ->whereHas('detail', function ($query) use ($rekening) {
                $query->where('rekening_id', $rekening->id)
                    ->where('kredit', '>', 0);
            })
            ->exists();
    }
}
