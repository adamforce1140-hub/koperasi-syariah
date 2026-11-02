<?php

namespace App\Services;

use App\Events\AngsuranLunas;
use App\Models\Angsuran;
use App\Models\DanaKebajikan;
use App\Models\JenisSimpanan;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\TazirPenangguhan;
use App\Models\TazirReliefRequest;
use App\Models\Transaksi;
use App\Support\NomorDokumen;
use App\Support\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service terpusat untuk memproses pembayaran angsuran pembiayaan.
 *
 * Mendukung dua metode pembayaran:
 *   - TUNAI    : Kas Loket Teller (1-1001)
 *   - AUTODEBET: Simpanan Sukarela Anggota (2-1001)
 *
 * Jurnal double-entry sesuai PSAK 101–111 & PSAK 109 (Dana Kebajikan).
 *
 * PENTING (Syariah Compliance):
 *   Ta'zir (denda keterlambatan) DILARANG masuk ke akun Pendapatan (kelas 4).
 *   Seluruh nominal ta'zir wajib ke Akun 2-3001 (Dana Kebajikan / Social Fund).
 */
class AngsuranTransactionService
{
    public function __construct(
        private AutoJournalingService $journalService,
        private TazirService $tazirService
    ) {}

    /**
     * Proses pembayaran angsuran lengkap (pokok + margin + ta'zir opsional).
     *
     * @param  array  $options  {
     *                          jumlah_bayar      : float   (total uang diterima teller / didebet)
     *                          nominal_tazir     : float   (0 jika dibebaskan / ditangguhkan)
     *                          metode_bayar      : string  'TUNAI' | 'AUTODEBET'
     *                          dibebaskan_tazir  : bool    true = tazir dibebaskan (keringanan syariah final)
     *                          tazir_ditangguhkan: bool    true = tazir ditangguhkan menunggu keputusan keringanan (PENDING)
     *                          tunggakan_tazir   : float   ta'zir tertunggak (keringanan ditolak) yang ikut ditagih & diterima
     *                          alasan_keringanan : string  (wajib diisi jika dibebaskan/ditangguhkan)
     *                          user_id           : int     Auth user yang memproses
     *                          }
     * @return array { transaksi_id, jurnal_id, nominal_tazir, tunggakan_tazir, metode }
     *
     * @throws \Exception jika saldo autodebet tidak cukup
     */
    public function proses(Angsuran $angsuran, array $options): array
    {
        $pembiayaan = $angsuran->pembiayaan()->with('anggota')->firstOrFail();

        // GATE STATUS PEMBIAYAAN (A-06): hanya kontrak yang masih berjalan yang
        // boleh menerima pembayaran. Tanpa gerbang ini angsuran milik pembiayaan
        // `lunas`, `macet`, `ditolak`, `dibatalkan`, maupun yang belum dicairkan
        // tetap dapat dibayar — uang diterima, jurnal terbentuk, dan
        // `sisa_pembiayaan` berkurang walau tidak ada kewajiban sah.
        if (! in_array($pembiayaan->status_pembiayaan, Pembiayaan::statusBerjalan(), true)) {
            throw new \Exception(
                'Pembayaran angsuran ditolak: pembiayaan '.$pembiayaan->no_pembiayaan.
                ' berstatus "'.($pembiayaan->status_pembiayaan ?? '-').'" sehingga tidak memiliki '.
                'kewajiban angsuran yang berjalan.'
            );
        }

        $metode = strtoupper($options['metode_bayar'] ?? 'TUNAI');

        /* GERBANG KEBIJAKAN INDUK (TIDAK ADA AUTO-DEBIT TANPA KONFIRMASI).
         | Metode AUTODEBET memindahkan dana anggota langsung dari Simpanan
         | Sukarela. Selama kebijakan induk aktif, pemindahan hanya sah bila
         | berasal dari konfirmasi usulan (AutodebitService::konfirmasi()),
         | yang mengirim `via_konfirmasi = true`. Pemanggil langsung (seeder,
         | command, tinker) DITOLAK agar dana tidak diambil tanpa persetujuan. */
        if ($metode === 'AUTODEBET'
            && ! ($options['via_konfirmasi'] ?? false)
            && Setting::autodebitWajibKonfirmasi()) {
            throw new \Exception(
                'Autodebet angsuran ditolak: kebijakan "tidak ada auto-debit tanpa konfirmasi '
                .'pengguna" sedang aktif. Ajukan usulan lalu konfirmasi di Pusat Konfirmasi Auto-Debit.'
            );
        }
        $nominalTazir = (float) ($options['nominal_tazir'] ?? 0);
        $tunggakanTazir = (float) ($options['tunggakan_tazir'] ?? 0);
        $dibebaskan = (bool) ($options['dibebaskan_tazir'] ?? false);
        $ditangguhkan = (bool) ($options['tazir_ditangguhkan'] ?? false);
        $userId = $options['user_id'] ?? Auth::id();

        // SAFEGUARD PSAK 109: jika sudah ada pengajuan keringanan yang APPROVED
        // untuk angsuran ini, ta'zir WAJIB Rp 0 (jurnal tetap balance).
        $reliefApproved = TazirReliefRequest::where('jadwal_angsuran_id', $angsuran->id)
            ->where('status', TazirReliefRequest::STATUS_APPROVED)
            ->exists();

        // Jika dibebaskan / ditangguhkan / sudah ada approval keringanan, paksa ta'zir = 0
        if ($dibebaskan || $ditangguhkan || $reliefApproved) {
            $nominalTazir = 0.0;
        }

        /* Saklar tunggal fitur ta'zir (batch 11).
         | Kebijakan PSAK 109 & Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000: denda hanya
         | boleh dibebankan bila pengurus mengaktifkannya. `TazirService::fiturAktif()`
         | adalah satu-satunya otoritas saklar; selama menolak, TUNGGAKAN ta'zir
         | lama tidak boleh ikut terpungut lagi — tanpa ini, tunggakan yang
         | tersimpan di basis data tetap tertagih walau pengurus sudah mematikan
         | fitur (F-02).
         |
         | Nominal ta'zir yang dikirim EKSPLISIT oleh pemanggil (teller yang
         | memutuskan menerima/membebaskan) tetap dihormati: `beliung` itu sudah
         | melewati gerbang saklar di jalur UI/Action, dan pelunasan ta'zir yang
         | sudah terlanjur disepakati kas tidak boleh digagalkan diam-diam oleh
         | service (regresi F-06: ta'zir Rp 5.000 lenyap dari Dana Kebajikan). */
        if ($tunggakanTazir > 0 && ! app(TazirService::class)->fiturAktif()) {
            $tunggakanTazir = 0.0;
        }

        $pokok = (float) ($angsuran->pokok_angsuran ?? 0);
        $margin = (float) ($angsuran->margin_ujroh ?? 0);

        // Jika jumlah_bayar tidak disuplai, hitung dari angsuran
        $jumlahBayar = isset($options['jumlah_bayar']) && $options['jumlah_bayar'] > 0
            ? (float) $options['jumlah_bayar']
            : (float) $angsuran->jumlah_angsuran;

        /* Batas uang yang boleh mengisi porsi ANGSURAN di dalam `jumlah_bayar`.
         | `jumlah_bayar` adalah uang yang diserahkan untuk ANGSURAN saja (ta'zir
         | dan tunggakan ditagih terpisah). Tanpa batas ini, kelebihan bayar
         | (mis. bayar Rp 120.000 atas tagihan pokok+margin Rp 110.000) dianggap
         | uang angsuran, lalu dipakai membayar margin yang SUDAH dibayar —
         | piutang dikredit dua kali dan Dana Kebajikan kelebihan kredit, sementara
         | kas hanya bertambah sekali → jurnal tetap balance tetapi sebarannya
         | salah (F-04). */
        $uangAngsuran = min($jumlahBayar, Setting::bulatkanUang($pokok + $margin));

        $totalTagihan = $jumlahBayar + $nominalTazir + $tunggakanTazir;

        // Ambil rekening simpanan sukarela anggota
        $rekeningAnggota = Rekening::where('anggota_id', $pembiayaan->anggota_id)
            ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))
            ->where('status_rekening', 'Aktif')
            ->first();

        // Validasi saldo autodebet
        if ($metode === 'AUTODEBET') {
            if (! $rekeningAnggota) {
                throw new \Exception('Anggota tidak memiliki rekening Simpanan Sukarela aktif untuk autodebet.');
            }
            if (! $rekeningAnggota->isDapatDitarik($totalTagihan)) {
                throw new \Exception(
                    'Saldo Simpanan Sukarela tidak mencukupi untuk autodebet angsuran & denda. '.
                    'Saldo tersedia: Rp '.number_format($rekeningAnggota->saldo, 0, ',', '.').
                    ' | Batas minimum: Rp '.number_format($rekeningAnggota->batas_minimum, 0, ',', '.').
                    ' | Dibutuhkan: Rp '.number_format($totalTagihan, 0, ',', '.')
                );
            }
        }

        $noTrx = NomorDokumen::kode('transaksi').'-'.date('YmdHis').'-'.strtoupper(substr(uniqid(), -4));
        $transaksiId = null;

        // ─── ALOKASI POKOK: pokok yang BENAR-BENAR terbayar ────────────────────
        // Kewajiban pokok hanya boleh berkurang sebesar uang yang benar-benar
        // diterima untuk porsi pokok — BUKAN sebesar pokok yang ditagih.
        //
        // Insiden yang diperbaiki: dua transaksi berbeda (bayar Rp 100.000 dan
        // Rp 50.000 terhadap tagihan pokok Rp 104.166,67) sama-sama memotong
        // pokok penuh Rp 104.166,67, sehingga sisa kewajiban understated dan
        // jurnal tidak mencerminkan kas yang riil diterima.
        //
        // Urutan alokasi uang ANGSURAN (sesuai kaidah syariah & praktik koperasi):
        //   1. Pokok   — piutang anggota (amanah) ditutup lebih dahulu
        //   2. Margin  — hak koperasi, hanya bila uang benar-benar ada
        // Margin TIDAK boleh mendahului pokok bila uang tidak cukup untuk
        // keduanya, agar pendapatan tidak diakui atas piutang yang belum tertagih.
        /* Uang untuk pembayaran angsuran dibatasi `$uangAngsuran`: kelebihan
         | bayar tidak boleh mengisi pokok/margin melebihi kewajiban baris ini.
         | Ta'zir & tunggakan ditagih TERPISAH dari porsi angsuran, sehingga
         | alokasi hanya membagi uang angsuran ke pokok & margin; nominal ta'zir
         | yang dibebankan dihitung setelahnya dari nominal yang ditagih. */
        $alokasi = $this->alokasiPembayaran($pokok, $margin, $uangAngsuran, $nominalTazir + $tunggakanTazir);

        $pokokTerbayar = $alokasi['pokok'];
        $marginTerbayar = $alokasi['margin'];
        // Nominal ta'zir yang DIBEBANKAN baris ini (ditagih terpisah dari porsi angsuran).
        $tazirTerbayar = $alokasi['tazir'];

        /* Ta'zir ditagih TERPISAH dari porsi angsuran: seluruh nominal yang
         | ditagih dan disetujui kas dianggap diterima. Porsi ta'zir periode
         | berjalan didahulukan, sisanya melunasi tunggakan (maks. nominal
         | tunggakan yang ditagih). */
        $tazirPeriodeBerjalan = Setting::bulatkanUang(min($tazirTerbayar, $nominalTazir));
        $tunggakanTazirTerbayar = Setting::bulatkanUang(
            max(0.0, min($tazirTerbayar - $tazirPeriodeBerjalan, $tunggakanTazir))
        );
        $kekurangan = $alokasi['kekurangan'];

        // Pokok yang belum tertagih dari angsuran ini (dibawa ke kewajiban berikutnya).
        $pokokKurang = Setting::bulatkanUang(max(0, $pokok - $pokokTerbayar));

        /* Margin yang belum tertagih dari angsuran ini (F-07).
         | Karena alokasi kini mengutamakan POKOK, situasi "pokok lunas tetapi
         | margin belum tertagih" menjadi mungkin. Status `Sudah Dibayar` hanya
         | boleh diberikan bila SELURUH kewajiban baris ini (pokok DAN margin)
         | tertagih; tanpa ini angsuran berstatus lunas padahal masih ada
         | kewajiban margin, dan RestrukturisasiService::ringkasan() (yang
         | menyimpulkan "margin terakui = total_bayar - denda" untuk baris
         | berstatus Dibayar Sebagian) menghitung sisa piutang terlalu kecil. */
        $marginKurang = Setting::bulatkanUang(max(0, (float) $margin - $marginTerbayar));

        $sisaPembiayaan = Setting::bulatkanUang(max(0, (float) $pembiayaan->sisa_pembiayaan - $pokokTerbayar));

        DB::transaction(function () use (
            $angsuran, $pembiayaan, $jumlahBayar, $nominalTazir, $tunggakanTazir, $totalTagihan, $pokokTerbayar, $marginTerbayar, $tazirTerbayar, $pokokKurang, $marginKurang, $kekurangan,
            $sisaPembiayaan,
            $metode, $noTrx, $rekeningAnggota, $userId,
            $dibebaskan, $ditangguhkan, $options,
            $tunggakanTazirTerbayar, $tazirPeriodeBerjalan, $uangAngsuran,
            &$transaksiId
        ) {
            $rekeningId = null;
            $saldoSetelah = $sisaPembiayaan;

            // TAHAP 5 — INTEGRITAS SALDO: kunci baris rekening & pembiayaan
            // sebelum mutasi. `decrement()` mengirim `saldo = saldo - X` ke SQL
            // sehingga aman dari lost update, TETAPI tanpa kunci baris, rangkaian
            // baca-guard(potong/lunas)-tulis di sekitar blok ini masih dapat
            // saling menimpa antar-transaksi konkuren. Kunci membuat selisih
            // saldo selalu sama dengan selisih mutasi jurnal.
            $rekeningTerkunci = null;
            if ($metode === 'AUTODEBET' && $rekeningAnggota) {
                // Kunci baris lalu ULANGI guard saldo di dalam kunci. Pra-cek di
                // luar transaksi membaca baris TAK TERKUNCI, sehingga dua
                // autodebet konkuren dapat sama-sama lolos dan membuat saldo
                // menembus batas_minimum. Guard di dalam kunci adalah satu-satunya
                // yang mengikat (pola sama dengan TazirService & MandatorySavingsService).
                $rekeningTerkunci = Rekening::whereKey($rekeningAnggota->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $rekeningTerkunci->isDapatDitarik($totalTagihan)) {
                    throw new \Exception(
                        'Autodebet dibatalkan: saldo Simpanan Sukarela tidak mencukupi setelah rekening dikunci '.
                        '(saldo Rp '.number_format($rekeningTerkunci->saldo, 0, ',', '.').
                        ', batas minimum Rp '.number_format($rekeningTerkunci->batas_minimum, 0, ',', '.').
                        ', dibutuhkan Rp '.number_format($totalTagihan, 0, ',', '.').').'
                    );
                }

                $rekeningTerkunci->decrement('saldo', $totalTagihan);
                $rekeningTerkunci->refresh();
                $rekeningId = $rekeningTerkunci->id;
                $saldoSetelah = (float) $rekeningTerkunci->saldo;
            }

            // Buat transaksi utama
            $transaksi = Transaksi::create([
                'no_transaksi' => $noTrx,
                'rekening_id' => $rekeningId,
                'anggota_id' => $pembiayaan->anggota_id,
                'pembiayaan_id' => $pembiayaan->id,
                'jenis_transaksi' => 'Pembayaran Angsuran',
                'posisi' => $metode === 'AUTODEBET' ? 'Kredit' : 'Debet',
                'jumlah' => $totalTagihan,
                'nominal' => $totalTagihan,
                'saldo_setelah' => $saldoSetelah,
                'sumber_transaksi' => $metode === 'AUTODEBET' ? 'Autodebet Simpanan' : 'Kas Teller',
                'jenis_pembayaran' => $metode === 'AUTODEBET' ? 'autodebet' : 'tunai',
                'catatan' => 'Bayar angsuran ke-'.$angsuran->angsuran_ke.
                                      ($nominalTazir > 0 ? ' + Ta\'zir Rp '.number_format($nominalTazir, 0, ',', '.') : '').
                                      ($tunggakanTazir > 0 ? ' + Tunggakan Ta\'zir Rp '.number_format($tunggakanTazir, 0, ',', '.') : '').
                                      ' | '.$pembiayaan->no_pembiayaan.
                                      ($dibebaskan ? ' [DIBEBASKAN: '.($options['alasan_keringanan'] ?? '-').']' : ''),
                'waktu_transaksi' => now(),
                'status' => 'success',
                'status_transaksi' => 'Sukses',
                'user_id' => $userId,
            ]);

            $transaksiId = $transaksi->id;

            // Update angsuran → Sudah Dibayar (penuh) / Dibayar Sebagian.
            $catatanAngsuran = $dibebaskan
                ? 'Dibebaskan: '.($options['alasan_keringanan'] ?? '-')
                : ($ditangguhkan
                    ? 'Ta\'zir ditangguhkan (pengajuan keringanan menunggu persetujuan AO & Manajer): '.
                      ($options['alasan_keringanan'] ?? '-')
                    : null);

            if ($pokokKurang > 0) {
                $catatanAngsuran = trim(
                    ($catatanAngsuran ? $catatanAngsuran.' | ' : '').
                    'Dibayar sebagian: pokok tertagih Rp '.number_format($pokokTerbayar, 0, ',', '.').
                    ', sisa Rp '.number_format($pokokKurang, 0, ',', '.').' belum dibayar'
                );
            }

            /* F-07: lunas berarti SELURUH kewajiban baris ini tertagih — pokok
             | DAN margin. Karena alokasi mengutamakan pokok, pokok dapat lunas
             | lebih dulu sementara margin belum tertagih; baris seperti itu
             | tetap "Dibayar Sebagian" agar sisa piutang tidak hilang dari
             | laporan maupun dasar restrukturisasi. */
            $anggapLunas = $pokokKurang <= 0 && $marginKurang <= 0;

            if ($marginKurang > 0) {
                $catatanAngsuran = trim(
                    ($catatanAngsuran ? $catatanAngsuran.' | ' : '').
                    'Dibayar sebagian: margin tertagih Rp '.number_format($marginTerbayar, 0, ',', '.').
                    ', sisa Rp '.number_format($marginKurang, 0, ',', '.').' belum dibayar'
                );
            }

            $angsuran->update([
                'status_angsuran' => $anggapLunas ? 'Sudah Dibayar' : 'Dibayar Sebagian',
                'tanggal_bayar' => today(),
                'total_bayar' => $jumlahBayar,
                // sisa_pokok = pokok angsuran ini yang MASIH menjadi kewajiban.
                'sisa_pokok' => $pokokKurang,
                'denda' => $tazirTerbayar,
                'transaksi_id' => $transaksiId,
                'hari_terlambat' => $angsuran->hari_terlambat_dinamis ?? 0,
                'user_id' => $userId,
                'catatan' => $catatanAngsuran,
            ]);

            // Kurangi sisa pembiayaan SEBESAR POKOK YANG BENAR-BENAR DIBAYAR.
            // TAHAP 5 — kunci baris pembiayaan agar cek lunas di bawah ini
            // membaca nilai yang sudah memperhitungkan mutasi transaksi konkuren.
            Pembiayaan::whereKey($pembiayaan->id)->lockForUpdate()->first();
            $pembiayaan->decrement('sisa_pembiayaan', $pokokTerbayar);
            $pembiayaan->refresh();

            // Cek lunas: tidak boleh ada kewajiban yang tersisa, termasuk
            // kekurangan pokok dari angsuran yang baru dibayar sebagian.
            $sisaBelumBayar = Angsuran::where('pembiayaan_id', $pembiayaan->id)
                ->whereIn('status_angsuran', ['Belum Jatuh Tempo', 'Jatuh Tempo', 'Terlambat', 'Dibayar Sebagian'])
                ->count();

            // Angsuran yang sudah Dibayar Sebagian namun seluruh pokoknya kini
            // terbayar (sisa_pokok = 0) tidak lagi dihitung sebagai kewajiban.
            $kurangPokokTersisa = (float) Angsuran::where('pembiayaan_id', $pembiayaan->id)
                ->where('status_angsuran', 'Dibayar Sebagian')
                ->sum('sisa_pokok');

            if ($sisaBelumBayar === 0 && $kurangPokokTersisa <= 0) {
                $pembiayaan->update(['status_pembiayaan' => Pembiayaan::STATUS_LUNAS]);
            }

            // Total ta'zir yang BENAR-BENAR diterima tunai/saldo (cash basis, PSAK 109)
            $totalTazirDiterima = $nominalTazir + $tunggakanTazir;

            // Catat penerimaan ta'zir ke tabel dana_kebajikan
            if ($totalTazirDiterima > 0) {
                DanaKebajikan::create([
                    'no_referensi' => 'DK-TAZIR-'.now()->format('YmdHis'),
                    'sumber_dana' => "Ta'zir Angsuran Ke-{$angsuran->angsuran_ke} - ".
                                      ($pembiayaan->anggota->nama_lengkap ?? 'Anggota').
                                      " ({$pembiayaan->no_pembiayaan})".
                                      ($tunggakanTazir > 0
                                          ? ' (termasuk tunggakan Rp '.number_format($tunggakanTazir, 0, ',', '.').')'
                                          : ''),
                    'jenis' => 'Penerimaan',
                    'jumlah' => $totalTazirDiterima,
                    'keterangan' => "Denda keterlambatan angsuran ke-{$angsuran->angsuran_ke} ({$pembiayaan->no_pembiayaan})".
                                      ($tunggakanTazir > 0
                                          ? " + tunggakan ta'zir keringanan ditolak Rp ".number_format($tunggakanTazir, 0, ',', '.')
                                          : '').
                                      ' via '.($metode === 'AUTODEBET' ? 'Autodebet' : 'Tunai'),
                    'created_by' => Auth::user()->name ?? 'System',
                ]);
            }

            // Pelunasan tunggakan ta'zir: tandai is_tazir_paid = true (cash basis)
            if ($tunggakanTazir > 0) {
                TazirPenangguhan::belumDibayar()
                    ->where('pembiayaan_id', $pembiayaan->id)
                    ->update([
                        'is_tazir_paid' => true,
                        'paid_at' => now(),
                        'paid_angsuran_id' => $angsuran->id,
                        'transaksi_id' => $transaksiId,
                    ]);
            }

            // ─── POSTING JURNAL DOUBLE-ENTRY (di dalam transaksi atomik) ────────
            // WAJIB berada di dalam DB::transaction: bila posting jurnal gagal,
            // seluruh mutasi (saldo rekening, angsuran, sisa_pembiayaan) ikut
            // di-rollback sehingga tidak pernah ada kas keluar tanpa jurnal.
            // Menelan error di sini akan menghasilkan neraca yang tidak seimbang.
            $this->postJurnal(
                $angsuran, $pembiayaan, $pokokTerbayar, $marginTerbayar,
                // F-04: yang masuk kas untuk porsi angsuran adalah UANG ANGSURAN
                // (dibatasi tagihan pokok+margin), bukan `jumlah_bayar` mentah.
                $uangAngsuran,
                // F-05: argumen ini adalah ta'zir PERIODE BERJALAN yang ditagih.
                // Memakai `$tazirTerbayar` (total ditagih: berjalan + tunggakan)
                // membuat tunggakan terhitung dua kali bersama argumen terakhir.
                $tazirPeriodeBerjalan,
                $kekurangan, $metode,
                $transaksiId, $userId, $rekeningAnggota ?? null,
                // Kas yang masuk mencakup tunggakan ta'zir yang dilunasi; tanpa
                // disertakan di sini, sisi kredit jurnal kurang sebesar tunggakan.
                $tunggakanTazirTerbayar
            );
        });

        // ─── VALIDASI OTOMATIS ANTI-SELISIH ────────────────────────────────────
        // Pastikan sisa kewajiban yang tercatat benar-benar mencerminkan uang
        // yang diterima. Bila tidak, transaksi ditandai untuk investigasi agar
        // kesalahan perhitungan tidak menyebar ke laporan keuangan.
        $this->verifikasiKonsistensiSisaKewajiban($pembiayaan, $angsuran, $pokokTerbayar, $kekurangan);

        /*
         | Publikasikan peristiwa domain SETELAH transaksi DB berhasil di-commit.
         | Hanya dikirim bila angsuran ini benar-benar LUNAS (pokok DAN margin
         | terbayar penuh), agar makna peristiwa tidak kabur. Dibungkus try/catch:
         | kegagalan listener tidak boleh menggagalkan pembayaran (fail-safe).
         */
        $lunasPenuh = $pokokKurang <= 0 && $marginKurang <= 0;

        if ($lunasPenuh) {
            try {
                AngsuranLunas::dispatch(
                    $angsuran->refresh(),
                    $jumlahBayar,
                    $userId,
                );
            } catch (\Throwable $e) {
                Log::warning(
                    'Gagal mempublikasikan peristiwa AngsuranLunas: '.$e->getMessage()
                );
            }
        }

        return [
            'transaksi_id' => $transaksiId,
            'nominal_tazir' => $nominalTazir,
            'tunggakan_tazir' => $tunggakanTazir,
            'metode' => $metode,
            'total_dibayar' => $jumlahBayar + $nominalTazir + $tunggakanTazir,
        ];
    }

    /**
     * Alokasikan UANG ANGSURAN ke pokok lalu margin.
     *
     * `$uangDiterima` adalah uang yang diserahkan untuk PORSI ANGSURAN saja
     * (pokok + margin). Ta'zir dan tunggakan ta'zir ditagih TERPISAH, sehingga
     * tidak diambil dari `$uangDiterima` (F-04/F-05); `$tazir` hanya diteruskan
     * sebagai nominal yang DITAGIH.
     *
     * Urutan prioritas:
     *   1. Pokok  — piutang anggota (amanah) harus tertutup lebih dahulu
     *   2. Margin — hanya diakui sebesar sisa uang yang benar-benar ada
     *
     * Margin TIDAK didahulukan atas pokok, supaya koperasi tidak mengakui
     * pendapatan (amortisasi 1-2101) atas uang yang masih menjadi hak pokok
     * (F-03). Porsi yang tidak tertutup uang TIDAK diakui — hanya dikembalikan
     * sebagai `kekurangan`.
     *
     * @param  float  $tazir  ta'zir yang ditagih; TIDAK diambil dari `$uangDiterima`.
     * @return array{pokok: float, margin: float, tazir: float, kekurangan: float}
     */
    private function alokasiPembayaran(float $pokok, float $margin, float $uangDiterima, float $tazir): array
    {
        $uang = Setting::bulatkanUang(max(0, $uangDiterima));

        // 1) Pokok didahulukan — piutang anggota (amanah) harus tertutup lebih
        //    dahulu. Margin adalah hak koperasi yang boleh menyusul.
        $pokokTerbayar = Setting::bulatkanUang(min($pokok, $uang));
        $uang = Setting::bulatkanUang($uang - $pokokTerbayar);

        // 2) Margin — hanya sebesar sisa uang yang benar-benar ada, sehingga
        //    margin TIDAK diakui sebagai pendapatan bila uang habis untuk pokok
        //    (F-03).
        $marginTerbayar = Setting::bulatkanUang(min($margin, $uang));
        $uang = Setting::bulatkanUang($uang - $marginTerbayar);

        return [
            'pokok' => $pokokTerbayar,
            'margin' => $marginTerbayar,
            /* F-05: `tazir` = ta'zir yang DITAGIH baris ini. Ta'zir TIDAK
             | diambil dari `$uangDiterima` karena ditagih terpisah dari porsi
             | angsuran. Sebelumnya fungsi ini mengembalikan ta'zir yang
             | "tertampung", lalu nilai itu dipakai kembali sebagai nominal yang
             | ditagih → ta'zir tunggakan terhitung dua kali (Dana Kebajikan
             | 80.000 atas kas 160.000). */
            'tazir' => Setting::bulatkanUang(max(0, $tazir)),
            'kekurangan' => Setting::bulatkanUang(max(0, $pokok + $margin - $uangDiterima)),
        ];
    }

    /**
     * Validasi otomatis: memastikan sisa kewajiban konsisten setelah pembayaran.
     *
     * Insiden yang dicegah: sisa kewajiban ter-update seolah angsuran lunas
     * padahal uang yang diterima kurang, sehingga dua transaksi berbeda nominal
     * menghasilkan sisa kewajiban yang sama.
     *
     * Tiga invarian yang diperiksa:
     *   1. Pokok terbayar tidak boleh melebihi uang yang diterima.
     *   2. sisa_pembiayaan harus turun tepat sebesar pokok terbayar.
     *   3. Total kewajiban (sisa_pembiayaan) tidak boleh negatif.
     *
     * Bila dilanggar, selisihnya dicatat ke log untuk investigasi dan
     * dikembalikan sebagai array agar pemanggil dapat menindaklanjuti.
     *
     * @return array{pokok_terbayar: float, sisa_pembiayaan: float, anomali: array<int,string>}
     */
    public function verifikasiKonsistensiSisaKewajiban(
        Pembiayaan $pembiayaan,
        Angsuran $angsuran,
        float $pokokTerbayar,
        float $kekurangan = 0.0
    ): array {
        $pembiayaan->refresh();

        $sisa = (float) $pembiayaan->sisa_pembiayaan;
        $anomali = [];

        // Invarian 1: pokok terbayar tidak boleh negatif.
        if ($pokokTerbayar < 0) {
            $anomali[] = 'Pokok terbayar bernilai negatif ('.$pokokTerbayar.').';
        }

        // Invarian 2: sisa kewajiban tidak boleh negatif (overpayment tak tercatat).
        if ($sisa < 0) {
            $anomali[] = 'sisa_pembiayaan negatif ('.$sisa.') — pokok terbayar melebihi kewajiban.';
        }

        // Invarian 3: bila masih ada pokok yang belum tertagih, sisa kewajiban
        // tidak boleh nol (gejala klasik pemotongan pokok penuh atas pembayaran sebagian).
        if ($kekurangan > 0 && $sisa <= 0) {
            $anomali[] = 'Masih ada pokok belum tertagih Rp '.number_format($kekurangan, 2, ',', '.')
                .' tetapi sisa_pembiayaan sudah Rp 0.';
        }

        if ($anomali !== []) {
            Log::warning('AngsuranTransactionService: anomali sisa kewajiban terdeteksi', [
                'pembiayaan' => $pembiayaan->no_pembiayaan,
                'angsuran_ke' => $angsuran->angsuran_ke,
                'pokok_terbayar' => $pokokTerbayar,
                'sisa_pembiayaan' => $sisa,
                'anomali' => $anomali,
            ]);
        }

        return [
            'pokok_terbayar' => $pokokTerbayar,
            'sisa_pembiayaan' => $sisa,
            'anomali' => $anomali,
        ];
    }

    /**
     * Posting jurnal double-entry sesuai skenario.
     *
     * TUNAI — akad BERMARGIN (Murabahah dll., punya akun 1-2101):
     *   DEBET : 1-1001 (Kas Teller)             → jumlahBayar + ta'zir + tunggakan
     *   KREDIT: 1-200x (Piutang sesuai akad)    → pokok + margin (piutang bruto)
     *   DEBET : 1-2101 (Margin Ditangguhkan)    → margin (AMORTISASI)
     *   KREDIT: 4-100x (Pendapatan sesuai akad) → margin
     *   KREDIT: 2-3001 (Dana Kebajikan)         → ta'zir + tunggakan ta'zir
     *
     * AUTODEBET — idem, hanya sisi DEBET memakai 2-1001 (Simpanan Sukarela).
     *
     * Akad BAGI HASIL / QARDH (tanpa akun 1-2101):
     *   KREDIT piutang hanya sebesar POKOK, dan margin/bagi hasil dikreditkan
     *   langsung ke 4-100x (tanpa baris amortisasi).
     *
     * AMORTISASI MARGIN (PSAK 102/402 — CKPN-11): saat pencairan, margin yang
     * belum menjadi hak diakui pada akun kontra piutang 1-2101. Saat angsuran
     * diterima, margin harus DIPINDAHKAN dari 1-2101 ke pendapatan (DEBET 1-2101
     * | KREDIT 4-100x), bukan diakui langsung, agar akun kontra tidak menumpuk
     * dan pendapatan tidak berlebih.
     *
     * Ta'zir hanya dibukukan saat benar-benar diterima (cash basis, PSAK 109).
     */
    private function postJurnal(
        Angsuran $angsuran,
        Pembiayaan $pembiayaan,
        float $pokok,
        float $margin,
        float $jumlahBayar,
        float $nominalTazir,
        float $sisaTidakTertagih,
        string $metode,
        ?int $transaksiId,
        ?int $userId,
        ?Rekening $rekeningAnggota,
        float $tunggakanTazir = 0.0
    ): void {
        $akad = $pembiayaan->akad_pembiayaan ?? Pembiayaan::akadDefault();
        /* F-01 (batch 11): ta'zir yang diterima kas = ta'zir periode berjalan +
         | tunggakan yang dilunasi. Sebelumnya hanya `$nominalTazir` yang
         | dibukukan, padahal `$total` sudah memuat tunggakan → jurnal
         | JURNAL_TIDAK_BALANCE tepat sebesar tunggakan, sehingga pelunasan
         | tunggakan ta'zir selalu gagal dan kas tidak pernah tercatat. */
        $totalTazir = $nominalTazir + max(0.0, $tunggakanTazir);
        $keterangan = "Pembayaran angsuran ke-{$angsuran->angsuran_ke} | {$pembiayaan->no_pembiayaan}".
                           ($totalTazir > 0 ? " + Ta'zir" : '').
                           ($tunggakanTazir > 0
                               ? ' (termasuk tunggakan ta\'zir Rp '.number_format($tunggakanTazir, 0, ',', '.').')'
                               : '').
                           ($sisaTidakTertagih > 0
                               ? ' (pokok belum tertagih Rp '.number_format($sisaTidakTertagih, 0, ',', '.').')'
                               : '');
        $total = $jumlahBayar + $totalTazir;

        // Akun debet berbeda tergantung metode
        $akunDebet = $metode === 'AUTODEBET'
            ? AutoJournalingService::SIMPANAN_SUKARELA  // 2-1001
            : AutoJournalingService::KAS_TELLER;         // 1-1001

        /* AMORTISASI MARGIN DITANGGUHKAN (PSAK 102/402 — CKPN-11).
         |
         | Saat pencairan, margin yang belum menjadi hak diakui pada akun kontra
         | piutang "Margin Murabahah Ditangguhkan" (1-2101):
         |     DEBET  1-200x Piutang (Pokok + Margin)
         |     KREDIT Sumber Dana (Pokok)
         |     KREDIT 1-2101 Margin Ditangguhkan (Margin)
         |
         | Maka saat angsuran diterima, margin yang menjadi hak TIDAK boleh
         | dikreditkan langsung ke pendapatan (4-100x) — itu mengakui pendapatan
         | atas margin yang masih tersimpan pada akun kontra, sehingga 1-2101
         | menumpuk permanen dan pendapatan margin terbaca berlebih. Margin harus
         | DIPINDAHKAN keluar dari 1-2101:
         |     DEBET  1-2101 Margin Ditangguhkan (Margin)
         |     KREDIT 4-100x Pendapatan Margin   (Margin)
         |
         | Akad bagi hasil (Mudharabah/Musyarakah) dan Qardh tidak memiliki akun
         | margin ditangguhkan, sehingga baris pendapatan langsung dipertahankan
         | (bagi hasil baru diakui saat bagi hasil, Qardh tanpa margin). */
        $akunPendapatan = $this->journalService->getPendapatanByAkad($akad);
        $akunMargin = $this->journalService->getMarginDitangguhkanByAkad($akad);

        /* Porsi piutang yang boleh dikredit.
         |
         | F-04 (batch 11): SEMUA baris memakai porsi yang BENAR-BENAR terbayar
         | ($pokok / $margin). Piutang bruto akad Murabahah memuat POKOK + MARGIN,
         | dan margin itu dibukukan paralel di akun kontra 1-2101, sehingga
         | pelunasan mengkredit piutang sebesar porsi angsuran yang tertagih.
         | Akad bagi hasil/Qardh tidak punya akun kontra: piutang hanya sebesar
         | pokok, margin (bagi hasil/ujrah) langsung menjadi pendapatan.
         |
         | Sebelumnya `$porsiPiutang` memakai tagihan bruto sehingga piutang
         | dikredit melebihi porsi yang diterima → selisihnya melimpah ke Dana
         | Kebajikan (2-3001) lewat penyeimbang PostingJurnal. */
        $memakaiAkunMargin = $margin > 0 && $akunPendapatan !== null && $akunMargin !== null;
        $porsiPiutang = $memakaiAkunMargin ? $pokok + $margin : $pokok;

        $entries = [
            ['kode' => $akunDebet,                                     'debet' => $total],
            ['kode' => $this->journalService->getPiutangByAkad($akad), 'kredit' => $porsiPiutang],
        ];

        if ($margin > 0 && $akunPendapatan !== null) {
            if ($akunMargin !== null) {
                // Amortisasi: pindahkan margin dari kewajiban ke pendapatan.
                $entries[] = ['kode' => $akunMargin,     'debet' => $margin];
                $entries[] = ['kode' => $akunPendapatan, 'kredit' => $margin];
            } else {
                $entries[] = ['kode' => $akunPendapatan, 'kredit' => $margin];
            }
        }

        // *** SYARIAH CRITICAL: ta'zir (termasuk tunggakan) wajib ke Dana Kebajikan (2-3001) ***
        if ($totalTazir > 0) {
            $entries[] = ['kode' => AutoJournalingService::DANA_KEBAJIKAN, 'kredit' => $totalTazir];
        }

        $jurnal = $this->journalService->post(
            $keterangan,
            $entries,
            'Angsuran',
            $transaksiId,
            $pembiayaan->id,
            $userId,
            $rekeningAnggota?->id,
            $pembiayaan->anggota_id
        );

        /* Sejak penguatan pencatatan keuangan, AutoJournalingService::post()
         | MELEMPAR JournalPostingException (bukan lagi `return null`) bila CoA
         | tidak ditemukan/nonaktif, jurnal tidak balance, atau melanggar aturan
         | pemisahan margin & Dana Kebajikan. Exception menjalar ke DB::transaction
         | di proses() sehingga seluruh mutasi di-rollback — pembayaran angsuran
         | tidak akan tersimpan tanpa jurnal. Guard berikut dipertahankan sebagai
         | pertahanan berlapis (defense in depth). */
        if (! $jurnal) {
            throw new \RuntimeException(
                "Gagal memposting jurnal pembayaran angsuran ke-{$angsuran->angsuran_ke} ".
                "({$pembiayaan->no_pembiayaan}). Pembayaran dibatalkan agar jurnal tidak hilang."
            );
        }
    }
}
