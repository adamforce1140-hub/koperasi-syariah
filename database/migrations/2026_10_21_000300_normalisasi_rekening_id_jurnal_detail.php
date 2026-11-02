<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S-3 kategori C — Normalisasi `jurnal_detail.rekening_id`.
 *
 * Sebelum `AutoJournalingService::post()` diperbaiki, satu `$rekeningId`
 * global ditempel ke SEMUA baris jurnal. Akibatnya baris kas, piutang,
 * pendapatan, dan baris simpanan jenis lain ikut bertanda rekening yang
 * salah. Subledger per rekening menjadi menyesatkan.
 *
 * Aturan normalisasi mengikuti `rekeningIdUntukCoa()`:
 *   - Baris akun simpanan (2-1001/2-1002/2-1003/3-1001/3-1002) hanya
 *     diatribusikan ke rekening milik anggota yang sama dengan
 *     `jenis_rekening` yang sesuai.
 *   - Bila rekening yang sah tidak ada (mis. anggota belum punya rekening
 *     Pokok/Wajib), `rekening_id` dikosongkan — lebih baik tidak
 *     diatribusikan daripada salah tandai.
 *   - Baris selain akun simpanan (kas, piutang, pendapatan, dana
 *     kebajikan) dikosongkan.
 *
 * Nominal, pasangan debet/kredit, dan status jurnal TIDAK diubah.
 */
return new class extends Migration
{
    /**
     * Kode CoA simpanan -> `rekening.jenis_rekening` yang sah.
     *
     * @var array<string, string>
     */
    private const SIMPANAN = [
        '2-1001' => 'Simpanan Sukarela',
        '2-1002' => 'Simpanan Wajib',
        '2-1003' => 'Simpanan Berjangka',
        '3-1001' => 'Simpanan Pokok',
        '3-1002' => 'Simpanan Wajib',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $baris = DB::table('jurnal_detail as d')
                ->join('jurnal as j', 'j.id', '=', 'd.jurnal_id')
                ->join('coas as c', 'c.id', '=', 'd.coa_id')
                ->leftJoin('rekening as r', 'r.id', '=', 'd.rekening_id')
                ->whereNull('j.deleted_at')
                ->get([
                    'd.id',
                    'd.anggota_id',
                    'd.rekening_id',
                    'c.kode_akun',
                    'r.jenis_rekening',
                    'r.anggota_id as rekening_anggota_id',
                ]);

            foreach ($baris as $row) {
                $target = $this->rekeningYangSah($row);

                if ((int) $row->rekening_id === (int) $target) {
                    continue;
                }

                DB::table('jurnal_detail')
                    ->where('id', $row->id)
                    ->update(['rekening_id' => $target]);
            }
        });
    }

    public function down(): void
    {
        // Koreksi ini tidak dapat dibalik: nilai lama adalah atribusi yang
        // salah dan tidak menyimpan informasi yang dapat direkonstruksi.
        // Tidak ada nominal jurnal yang berubah, sehingga rollback cukup
        // membiarkan data tetap ternormalisasi.
    }

    /** Rekening yang sah untuk satu baris jurnal, atau null bila tidak ada. */
    private function rekeningYangSah(object $row): ?int
    {
        $jenis = self::SIMPANAN[$row->kode_akun] ?? null;

        if ($jenis === null) {
            return null;
        }

        if ($row->rekening_id !== null
            && $row->jenis_rekening === $jenis
            && (int) $row->rekening_anggota_id === (int) $row->anggota_id) {
            return (int) $row->rekening_id;
        }

        if ($row->anggota_id === null) {
            return null;
        }

        $id = DB::table('rekening')
            ->where('anggota_id', $row->anggota_id)
            ->where('jenis_rekening', $jenis)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }
};
