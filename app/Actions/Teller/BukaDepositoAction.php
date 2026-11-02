<?php

namespace App\Actions\Teller;

use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\DepositoProduct;
use App\Models\JenisSimpanan;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Services\AutoJournalingService;
use App\Services\JurnalReconciliationService;
use App\Support\Coa;
use App\Support\NomorDokumen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Use-case Buka Rekening Deposito (simpanan berjangka mudharabah).
 *
 * Rekening, mutasi, jurnal PSAK 105 (D Kas Teller / K Simpanan Berjangka),
 * audit log, dan gerbang subledger dijalankan dalam SATU transaksi basis data.
 * Validasi HTTP dilakukan di controller.
 *
 * Nisbah dan tanggal jatuh tempo TIDAK diambil dari input pengguna: keduanya
 * diturunkan dari master produk deposito (akad) agar tidak dapat dimanipulasi
 * dan konsisten dengan DepositoService::createDeposito().
 */
class BukaDepositoAction
{
    public function __construct(
        private readonly AutoJournalingService $journalService,
        private readonly JurnalReconciliationService $reconciliation,
    ) {}

    public function execute(Request $request): RedirectResponse
    {
        $anggota = Anggota::findOrFail($request->anggota_id);
        $nominal = (float) $request->nominal;

        // Nisbah & tenor diambil dari MASTER PRODUK (akad), bukan input bebas.
        // Ini mencegah manipulasi nisbah oleh teller dan memastikan jatuh tempo
        // dihitung sistem — selaras dengan DepositoService::createDeposito().
        $product = DepositoProduct::active()->deposito()->find($request->deposito_product_id);

        if (! $product) {
            return back()->withInput()->with(
                'error',
                'Produk deposito tidak ditemukan atau sudah tidak aktif.'
            );
        }

        if ($nominal < (float) $product->min_amount) {
            return back()->withInput()->with(
                'error',
                'Nominal minimal untuk produk '.$product->name.' adalah Rp '
                .number_format((float) $product->min_amount, 0, ',', '.').'.'
            );
        }

        if (! $product->nisbahValid()) {
            return back()->withInput()->with(
                'error',
                'Nisbah produk '.$product->name.' belum valid (nisbah anggota + koperasi harus 100%).'
            );
        }

        $tenor = (int) $product->tenor_months;

        if ($tenor <= 0) {
            return back()->withInput()->with(
                'error',
                'Tenor produk '.$product->name.' belum dikonfigurasi.'
            );
        }

        $nisbahNasabah = (float) $product->nisbah_anggota;
        $nisbahBank = (float) $product->nisbah_koperasi;
        $tanggalJatuhTempo = today()->startOfDay()->addMonthsNoOverflow($tenor);

        // Akun legacy untuk kolom `rekening.akun_id` (ber-FK NOT NULL) diresolusi
        // DINAMIS dari padanan akun simpanan berjangka — bukan `Akun::first()`
        // yang dapat menunjuk akun sembarang. Akuntansi sesungguhnya memakai CoA.
        $akunId = JenisSimpanan::akunLegacyId(JenisSimpanan::namaUtama(JenisSimpanan::TIPE_BERJANGKA))
                  ?? Coa::akunLegacyId(Coa::SIMPANAN_BERJANGKA);

        if ($akunId === null) {
            return back()->withInput()->with(
                'error',
                'Akun simpanan berjangka belum dipetakan. Atur pemetaan akun pada menu '
                .'Pengurus → Jenis Simpanan (Parameter) sebelum membuka deposito.'
            );
        }

        // R-04: berbasis MAX sufiks numerik agar tidak ada tabrakan nomor deposito.
        $noRek = Rekening::nomorRekeningBaru('DEP-'.date('Ymd').'-', 4);

        DB::transaction(function () use ($request, $anggota, $nominal, $akunId, $noRek, $product, $tenor, $nisbahNasabah, $nisbahBank, $tanggalJatuhTempo, &$rekening, &$transaksi) {
            // 1. Buat rekening deposito
            $rekening = Rekening::create([
                'no_rekening' => $noRek,
                'anggota_id' => $anggota->id,
                'akun_id' => $akunId,
                'jenis_rekening' => JenisSimpanan::namaUtama(JenisSimpanan::TIPE_BERJANGKA),
                'akad' => 'Mudharabah',
                'saldo' => $nominal,
                // Jejak nominal pembukaan rekening (tidak berubah saat saldo berjalan berubah).
                'nominal_pembukaan' => $nominal,
                'batas_minimum' => 0,
                'nisbah_nasabah' => $nisbahNasabah,
                'nisbah_bank' => $nisbahBank,
                'tanggal_buka' => today(),
                'tanggal_jatuh_tempo' => $tanggalJatuhTempo,
                'status_rekening' => 'Aktif',
                'keterangan' => $request->catatan ?? 'Simpanan berjangka / deposito mudharabah',
            ]);

            // 2. Catat transaksi mutasi
            $noTrx = NomorDokumen::kode('transaksi').'-'.date('YmdHis').'-'.strtoupper(substr(uniqid(), -4));
            $transaksi = Transaksi::create([
                'no_transaksi' => $noTrx,
                'rekening_id' => $rekening->id,
                'anggota_id' => $anggota->id,
                'jenis_transaksi' => 'Setoran Simpanan Berjangka',
                'posisi' => 'Debet',
                'jumlah' => $nominal,
                'saldo_setelah' => $nominal,
                'sumber_transaksi' => 'Teller',
                'status_transaksi' => 'Sukses',
                'status' => 'success',
                'waktu_transaksi' => now(),
                'user_id' => Auth::id(),
                'catatan' => 'Pembukaan deposito '.$product->name.' (tenor '.$tenor.' bulan) — jatuh tempo: '
                    .$tanggalJatuhTempo->format('d/m/Y'),
            ]);

            // 3. Auto-posting jurnal PSAK 105: D: Kas Teller (1-1001) | K: Simpanan Berjangka (2-1003)
            $jurnal = $this->journalService->jurnalSetorDeposito(
                $nominal,
                'Pembukaan deposito mudharabah '.$noRek.' — '.$anggota->nama_lengkap,
                Auth::id(),
                $transaksi->id,
                $rekening->id,
                $anggota->id
            );

            if (! $jurnal) {
                throw new \RuntimeException('Auto-journaling gagal: pastikan akun 1-1001 dan 2-1003 aktif di CoA.');
            }

            // 4. Audit log
            AuditLog::record(
                'Buka Rekening Deposito',
                $nominal,
                $rekening,
                'Pembukaan simpanan berjangka/deposito mudharabah atas nama '.$anggota->nama_lengkap
            );

            // 5. Gerbang P1-1: subledger simpanan wajib sama dengan akun kontrol.
            $this->reconciliation->pastikanSubledgerSehat();
        });

        return redirect()->route('teller.nota', $transaksi->id)
            ->with('success', 'Rekening deposito '.$noRek.' berhasil dibuka. Jurnal PSAK 105 telah diposting.');
    }
}
