<?php

namespace Database\Seeders;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\Rekening;
use App\Models\User;
use App\Support\Coa as KodeCoa;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RekeningSeeder extends Seeder
{
    /** Nomor jurnal saldo awal simpanan anggota lama (idempoten). */
    private const NO_JURNAL_SALDO_AWAL = 'JRN-SALDO-AWAL-REK';

    public function run(): void
    {
        $rekenings = [
            [
                'no_rekening' => 'REK-0001-01',
                'anggota_id' => 1,
                'akun_id' => 6,
                'jenis_rekening' => 'Simpanan Pokok',
                'akad' => 'Wadiah',
                'saldo' => 100000,
                'batas_minimum' => 0,
                'tanggal_buka' => '2022-01-10',
                'status_rekening' => 'Aktif',
                'keterangan' => 'Simpanan Pokok Anggota 001',
            ],
            [
                'no_rekening' => 'REK-0001-02',
                'anggota_id' => 1,
                'akun_id' => 7,
                'jenis_rekening' => 'Simpanan Wajib',
                'akad' => 'Mudharabah',
                'saldo' => 1500000,
                'batas_minimum' => 50000,
                'nisbah_bank' => 30,
                'nisbah_nasabah' => 70,
                'tanggal_buka' => '2022-01-10',
                'status_rekening' => 'Aktif',
                'keterangan' => 'Simpanan Wajib Anggota 001',
            ],
            [
                'no_rekening' => 'REK-0001-03',
                'anggota_id' => 1,
                'akun_id' => 8,
                'jenis_rekening' => 'Simpanan Sukarela',
                'akad' => 'Mudharabah',
                'saldo' => 5500000,
                'batas_minimum' => 100000,
                'nisbah_bank' => 25,
                'nisbah_nasabah' => 75,
                'tanggal_buka' => '2022-01-15',
                'status_rekening' => 'Aktif',
                'keterangan' => 'Simpanan Sukarela Anggota 001',
            ],
            [
                'no_rekening' => 'REK-0002-01',
                'anggota_id' => 2,
                'akun_id' => 6,
                'jenis_rekening' => 'Simpanan Pokok',
                'akad' => 'Wadiah',
                'saldo' => 100000,
                'batas_minimum' => 0,
                'tanggal_buka' => '2022-03-15',
                'status_rekening' => 'Aktif',
                'keterangan' => 'Simpanan Pokok Anggota 002',
            ],
            [
                'no_rekening' => 'REK-0002-02',
                'anggota_id' => 2,
                'akun_id' => 8,
                'jenis_rekening' => 'Simpanan Sukarela',
                'akad' => 'Mudharabah',
                'saldo' => 2750000,
                'batas_minimum' => 100000,
                'nisbah_bank' => 25,
                'nisbah_nasabah' => 75,
                'tanggal_buka' => '2022-03-15',
                'status_rekening' => 'Aktif',
                'keterangan' => 'Simpanan Sukarela Anggota 002',
            ],
            [
                'no_rekening' => 'REK-0003-01',
                'anggota_id' => 3,
                'akun_id' => 6,
                'jenis_rekening' => 'Simpanan Pokok',
                'akad' => 'Wadiah',
                'saldo' => 100000,
                'batas_minimum' => 0,
                'tanggal_buka' => '2022-06-01',
                'status_rekening' => 'Aktif',
                'keterangan' => 'Simpanan Pokok Anggota 003',
            ],
            [
                'no_rekening' => 'REK-0003-02',
                'anggota_id' => 3,
                'akun_id' => 7,
                'jenis_rekening' => 'Simpanan Wajib',
                'akad' => 'Mudharabah',
                'saldo' => 800000,
                'batas_minimum' => 50000,
                'nisbah_bank' => 30,
                'nisbah_nasabah' => 70,
                'tanggal_buka' => '2022-06-01',
                'status_rekening' => 'Aktif',
                'keterangan' => 'Simpanan Wajib Anggota 003',
            ],
            [
                'no_rekening' => 'REK-0004-01',
                'anggota_id' => 4,
                'akun_id' => 6,
                'jenis_rekening' => 'Simpanan Pokok',
                'akad' => 'Wadiah',
                'saldo' => 100000,
                'batas_minimum' => 0,
                'tanggal_buka' => '2023-01-20',
                'status_rekening' => 'Aktif',
                'keterangan' => null,
            ],
            [
                'no_rekening' => 'REK-0004-02',
                'anggota_id' => 4,
                'akun_id' => 8,
                'jenis_rekening' => 'Simpanan Sukarela',
                'akad' => 'Mudharabah',
                'saldo' => 10250000,
                'batas_minimum' => 100000,
                'nisbah_bank' => 25,
                'nisbah_nasabah' => 75,
                'tanggal_buka' => '2023-01-20',
                'status_rekening' => 'Aktif',
                'keterangan' => 'Simpanan fleksibel dengan nisbah kompetitif',
            ],
        ];

        foreach ($rekenings as $rekening) {
            // firstOrCreate agar seeder idempoten: rekening yang sudah ada
            // (beserta saldo berjalannya) tidak ditimpa saat db:seed diulang.
            Rekening::firstOrCreate(
                ['no_rekening' => $rekening['no_rekening']],
                $rekening
            );
        }

        // Saldo awal WAJIB punya pendukung di buku besar. Tanpa jurnal ini,
        // subledger (rekening.saldo) lebih besar dari saldo GL akun kontrol
        // (2-1001 / 3-1001 / 3-1002) sehingga laporan tidak rekonsiliasi.
        $this->jurnalSaldoAwal();
    }

    /**
     * Posting jurnal saldo awal simpanan anggota 1–4:
     *   DEBET  1-1001 Kas Teller            (total saldo simpanan)
     *   KREDIT 3-1001 Simpanan Pokok        (per rekening Pokok)
     *   KREDIT 3-1002 Simpanan Wajib        (per rekening Wajib)
     *   KREDIT 2-1001 Simpanan Sukarela     (per rekening Sukarela)
     *
     * Setiap baris kredit diatribusikan ke `rekening_id` + `anggota_id` rekening
     * yang bersangkutan agar audit `simpanan:audit-subledger` dapat menelusuri
     * saldo ke mutasi GL per anggota. Baris kas dibiarkan tanpa atribusi —
     * konsisten dengan `PostingJurnal::rekeningIdUntukCoa()`.
     *
     * Idempoten: dilewati bila jurnal dengan nomor tetap sudah pernah dibuat.
     */
    private function jurnalSaldoAwal(): void
    {
        if (Jurnal::where('no_jurnal', self::NO_JURNAL_SALDO_AWAL)->exists()) {
            return;
        }

        $kas = Coa::findByKode(KodeCoa::KAS_TELLER);
        if (! $kas) {
            return;
        }

        /* Simpanan Wajib harus ke 3-1002 (Modal/Ekuitas Anggota, PSAK 101).
         | `Coa::SIMPANAN_WAJIB` = 2-1002 adalah akun kewajiban legacy dan
         | TIDAK dipakai modul simpanan wajib — memakai konstanta itu membuat
         | jurnal saldo awal mendarat di akun yang salah sehingga GL 3-1002
         | tidak pernah bertambah. */
        $petaAkun = [
            'Simpanan Pokok' => KodeCoa::SIMPANAN_POKOK,
            'Simpanan Wajib' => KodeCoa::SIMPANAN_WAJIB_MODAL,
            'Simpanan Sukarela' => KodeCoa::SIMPANAN_SUKARELA,
        ];

        $rekening = Rekening::whereIn('no_rekening', [
            'REK-0001-01', 'REK-0001-02', 'REK-0001-03',
            'REK-0002-01', 'REK-0002-02',
            'REK-0003-01', 'REK-0003-02',
            'REK-0004-01', 'REK-0004-02',
        ])->where('saldo', '>', 0)->orderBy('id')->get();

        $kredit = [];
        $total = 0.0;

        foreach ($rekening as $rek) {
            $kode = $petaAkun[$rek->jenis_rekening] ?? null;
            if ($kode === null) {
                continue;
            }

            $coa = Coa::findByKode($kode);
            if (! $coa) {
                continue;
            }

            $saldo = (float) $rek->saldo;
            if ($saldo <= 0) {
                continue;
            }

            $kredit[] = [
                'coa_id' => $coa->id,
                'debet' => 0,
                'kredit' => $saldo,
                'keterangan' => $rek->jenis_rekening.' — '.$rek->no_rekening,
                'rekening_id' => $rek->id,
                'anggota_id' => $rek->anggota_id,
            ];
            $total += $saldo;
        }

        if ($kredit === [] || $total <= 0) {
            return;
        }

        DB::transaction(function () use ($kas, $kredit, $total) {
            $pengurus = User::where('role', 'pengurus')->first();

            $jurnal = Jurnal::create([
                'no_jurnal' => self::NO_JURNAL_SALDO_AWAL,
                'tanggal_jurnal' => '2022-01-01',
                'jenis_jurnal' => 'Jurnal Penyesuaian',
                'sumber' => 'Saldo Awal',
                'no_ref' => 'OPENING-REKENING',
                'keterangan' => 'Saldo awal simpanan anggota (migrasi data keanggotaan lama)',
                'total_debet' => $total,
                'total_kredit' => $total,
                'is_balance' => true,
                'status' => 'Posted',
                'posted_at' => now(),
                'user_posted' => $pengurus?->id,
                'user_id' => $pengurus?->id,
            ]);

            JurnalDetail::create([
                'jurnal_id' => $jurnal->id,
                'coa_id' => $kas->id,
                'baris' => 1,
                'debet' => $total,
                'kredit' => 0,
                'keterangan' => 'Kas Teller — saldo awal simpanan',
            ]);

            $baris = 2;
            foreach ($kredit as $row) {
                JurnalDetail::create([
                    'jurnal_id' => $jurnal->id,
                    'coa_id' => $row['coa_id'],
                    'baris' => $baris++,
                    'debet' => 0,
                    'kredit' => $row['kredit'],
                    'keterangan' => $row['keterangan'],
                    'rekening_id' => $row['rekening_id'],
                    'anggota_id' => $row['anggota_id'],
                ]);
            }
        });
    }
}
