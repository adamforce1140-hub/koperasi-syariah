<?php

namespace Database\Seeders;

use App\Models\Coa;
use App\Models\JenisSimpanan;
use Illuminate\Database\Seeder;

/**
 * Master awal Jenis Simpanan (parameter dinamis).
 *
 * Nilai awal sengaja disamakan dengan pemetaan yang selama ini di-hardcode di
 * AutoJournalingService / CifController sehingga pengaktifan parameter ini
 * TIDAK mengubah perilaku jurnal yang sudah berjalan:
 *
 *   Simpanan Pokok     -> 3-1001 Simpanan Pokok Anggota
 *   Simpanan Wajib     -> 3-1002 Simpanan Wajib Anggota (Ekuitas / Modal Anggota)
 *   Simpanan Sukarela  -> 2-1001 Simpanan Sukarela / Wadiah Anggota
 *   Simpanan Berjangka -> 2-1003 Simpanan Berjangka / Deposito Mudharabah
 *
 * Catatan: akun operasional legacy 2-1002 sudah tidak dipakai — PSAK 101
 * mensyaratkan Simpanan Wajib disajikan sebagai MODAL ANGGOTA (3-1002),
 * lihat MandatorySavingsService::akunSimpananWajib().
 *
 * Pengurus dapat menambah / mengubah / menonaktifkan jenis simpanan berikut
 * akun COA-nya melalui menu Pengaturan tanpa mengubah kode program.
 */
class JenisSimpananSeeder extends Seeder
{
    /** @var list<array<string, mixed>> */
    private const DATA = [
        [
            'code' => JenisSimpanan::CODE_POKOK,
            'name' => 'Simpanan Pokok',
            'nama' => 'Simpanan Pokok',
            'kode_akun' => '3-1001',
            'tipe' => JenisSimpanan::TIPE_POKOK,
            'min_deposit_amount' => 100000,
            'allow_auto_debit' => false,
            'is_active' => true,
            'keterangan' => 'Setoran wajib sekali saat registrasi anggota (modal anggota).',
        ],
        [
            'code' => JenisSimpanan::CODE_WAJIB,
            'name' => 'Simpanan Wajib',
            'nama' => 'Simpanan Wajib',
            'kode_akun' => '3-1002',
            'tipe' => JenisSimpanan::TIPE_WAJIB,
            'min_deposit_amount' => 50000,
            'allow_auto_debit' => false,
            'is_active' => true,
            'keterangan' => 'Simpanan wajib bulanan anggota (modal anggota — PSAK 101, akun 3-1002).',
        ],
        [
            'code' => JenisSimpanan::CODE_SUKARELA,
            'name' => 'Simpanan Sukarela',
            'nama' => 'Simpanan Sukarela',
            'kode_akun' => '2-1001',
            'tipe' => JenisSimpanan::TIPE_SUKARELA,
            'min_deposit_amount' => 0,
            'allow_auto_debit' => false,
            'is_active' => true,
            'keterangan' => 'Simpanan sukarela / wadiah yang dapat disetor & ditarik bebas.',
        ],
        [
            'code' => 'BERJANGKA',
            'name' => 'Simpanan Berjangka',
            'nama' => 'Simpanan Berjangka',
            'kode_akun' => '2-1003',
            'tipe' => JenisSimpanan::TIPE_BERJANGKA,
            'min_deposit_amount' => 0,
            'allow_auto_debit' => false,
            'is_active' => true,
            'keterangan' => 'Simpanan berjangka / deposito Mudharabah dengan tenor.',
        ],
    ];

    public function run(): void
    {
        foreach (self::DATA as $data) {
            // Ikat ke COA resmi via FK `account_coa_id` (integritas referensial).
            $data['account_coa_id'] = Coa::query()
                ->where('kode_akun', $data['kode_akun'])
                ->value('id');

            JenisSimpanan::updateOrCreate(
                ['nama' => $data['nama']],
                $data
            );
        }

        JenisSimpanan::flush();

        $this->command?->info('JenisSimpananSeeder: '.count(self::DATA).' jenis simpanan master siap.');
    }
}
