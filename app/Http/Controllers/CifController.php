<?php

namespace App\Http\Controllers;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Deposito;
use App\Models\DepositoProduct;
use App\Models\JenisSimpanan;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Rules\NikUnik;
use App\Services\AutoJournalingService;
use App\Services\CddService;
use App\Services\DepositoService;
use App\Services\ShuCalculatorService;
use App\Support\HierarkiRole;
use App\Support\NomorDokumen;
use App\Support\PiiHasher;
use App\Support\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CifController extends Controller
{
    /** Pesan galat status anggota — daftar pilihan dibaca dari model. */
    private static function pesanStatusTidakValid(): string
    {
        $pilihan = array_map(
            fn (string $status) => Anggota::STATUS_LABEL[$status].' ('.$status.')',
            Anggota::STATUS_LIST
        );

        return 'Status anggota tidak valid. Pilihan yang tersedia: '.implode(', ', $pilihan).'.';
    }

    // ─────────────────────────────────────────────────────────────
    // INDEX — AO, Manajer & Pengurus
    // ─────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $search = $request->query('search', '');
        $statusFilter = Anggota::normalizeStatus($request->query('status', ''));

        $query = Anggota::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nama_lengkap', 'like', "%{$search}%")
                    ->orWhere('no_anggota', 'like', "%{$search}%")
                    ->orWhere('no_cif', 'like', "%{$search}%");

                /* NIK kini tersimpan sebagai ciphertext non-deterministik,
                 | sehingga pencarian pola (LIKE) tidak mungkin dilakukan.
                 | Pencarian NIK dilayani blind index dengan kecocokan TEPAT. */
                if (($hash = PiiHasher::hash($search)) !== null) {
                    $q->orWhere('nik_hash', $hash);
                }
            });
        }

        if ($statusFilter) {
            $query->status($statusFilter);
        }

        $role = Auth::user()->role;

        // Manajer & Pengurus butuh withCount untuk kolom rekening & pembiayaan
        // aktif — keduanya peran pengelola berbagi kebutuhan data yang sama.
        if (HierarkiRole::dalamKelompok($role, 'lihat_detil_cif')) {
            $query->withCount([
                'rekening',
                'pembiayaan as pembiayaan_aktif_count' => fn ($q) => $q->statusIn(Pembiayaan::statusTercair()),
            ]);
        }

        $anggota = $query->orderByDesc('id')->paginate(15)->withQueryString();
        $totalAnggota = Anggota::count();
        $totalAktif = Anggota::aktif()->count();

        $viewMap = [
            'ao' => 'ao.cif.index',
            'manajer' => 'manajer.cif.index',
            'pengurus' => 'pengurus.cif.index',
            // Super Admin (puncak administratif) memakai tampilan terlengkap.
            'super_admin' => 'manajer.cif.index',
        ];

        // Fail-CLOSED: peran yang tidak dipetakan TIDAK diarahkan ke view default
        // 'ao' (yang dapat membocorkan tampilan tanpa hak akses). Tolak akses.
        if (! isset($viewMap[$role])) {
            abort(403, 'Akses ditolak. Peran Anda tidak berhak mengakses daftar anggota.');
        }

        $view = $viewMap[$role];

        return view($view, compact('anggota', 'search', 'statusFilter', 'totalAnggota', 'totalAktif'));
    }

    // ─────────────────────────────────────────────────────────────
    // CREATE — AO only
    // ─────────────────────────────────────────────────────────────
    public function create()
    {
        // Nilai minimum setoran awal diambil dari parameter koperasi (dinamis),
        // harus konsisten dengan validasi bisnis di store().
        return view('ao.cif.create', [
            'minimumSetoranAwal' => KebijakanKoperasi::totalSetoranAwal(),
            'simpananPokok' => KebijakanKoperasi::simpananPokokWajib(),
            'simpananWajib' => KebijakanKoperasi::simpananWajibBulanan(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // STORE — AO only
    //
    // BUNDLING SETORAN AWAL DINAMIS (PSAK 101/108):
    //  Nominal Simpanan Pokok & Simpanan Wajib Bulan ke-1 diambil dari
    //  parameter koperasi (`kebijakan_koperasis.mandatory_principal_savings`
    //  & `mandatory_monthly_savings`) — TIDAK ada angka yang di-hardcode,
    //  sehingga perubahan kebijakan tidak memerlukan perubahan kode.
    //
    //  Anggota baru selalu dicatat berstatus PENDING. Status berubah menjadi
    //  ACTIVE HANYA setelah setoran awal tervalidasi & jurnal terposting.
    // ─────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_lengkap' => 'required|string|max:255',
            'nik' => ['required', 'digits:16', new NikUnik(Anggota::class)],
            'jenis_kelamin' => 'required|in:Laki-laki,Perempuan',
            'no_hp' => 'required|digits_between:10,15|unique:anggota,no_hp',
            'alamat' => 'required|string',
            'kota' => 'required|string|max:100',
            'provinsi' => 'required|string|max:100',
            'tempat_lahir' => 'nullable|string|max:100',
            'tanggal_lahir' => 'nullable|date',
            'status_perkawinan' => 'nullable|string|max:50',
            'no_telepon' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'pekerjaan' => 'nullable|string|max:100',
            'nama_ibu_kandung' => 'nullable|string|max:255',
            'rt_rw' => 'nullable|string|max:20',
            'kelurahan' => 'nullable|string|max:100',
            'kecamatan' => 'nullable|string|max:100',
            'kode_pos' => 'nullable|string|max:10',
            'keterangan' => 'nullable|string',
            // Preferensi pembayaran Simpanan Wajib: true = auto-debit dari saldo
            // Simpanan Sukarela, false = bayar manual via Teller.
            'is_auto_debit' => 'nullable|boolean',
            // Setoran awal bersifat opsional: bila kosong, nominal bundling
            // (Pokok + Wajib bulan 1) dipakai; bila diisi, itu menjadi total
            // setoran dan divalidasi terhadap minimum bundling.
            'setoran_pertama' => 'nullable|numeric|min:0',
            // OJK-08 (POJK 27/2022, UU 8/2010 Pasal 18): data CDD/PEP/pemilik
            // manfaat. `pep_status` wajib dipilih agar profil risiko dapat
            // ditentukan sejak onboarding (pendekatan berbasis risiko).
            'pep_status' => 'required|in:'.implode(',', Anggota::PEP_PILIHAN),
            'pep_keterangan' => 'nullable|string|max:255',
            'pemilik_manfaat_nama' => 'nullable|string|max:255',
            'pemilik_manfaat_hubungan' => 'nullable|string|max:100',
            'pemilik_manfaat_keterangan' => 'nullable|string',
        ], [
            'setoran_pertama.numeric' => 'Jumlah setoran awal harus berupa angka.',
        ]);

        // Nominal bundling dinamis dari parameter koperasi.
        $simpananPokok = KebijakanKoperasi::simpananPokokWajib();
        $simpananWajib1 = KebijakanKoperasi::simpananWajibBulanan();
        $totalSetoranAwal = Setting::bulatkanUang($simpananPokok + $simpananWajib1);

        $setoranAwal = $validated['setoran_pertama'] ?? null;

        if ($setoranAwal === null || (float) $setoranAwal <= 0) {
            $setoranAwal = $totalSetoranAwal;
        }

        $setoranAwal = Setting::bulatkanUang((float) $setoranAwal);

        if ($simpananPokok <= 0 && $simpananWajib1 <= 0) {
            return back()->withInput()->with('error',
                'Parameter Simpanan Pokok & Simpanan Wajib belum dikonfigurasi pada Kebijakan Koperasi.');
        }

        if ($setoranAwal < $totalSetoranAwal) {
            return back()->withInput()->with('error',
                'Setoran awal minimal Rp '.number_format($totalSetoranAwal, 0, ',', '.')
                .' (Simpanan Pokok Rp '.number_format($simpananPokok, 0, ',', '.')
                .' + Simpanan Wajib bulan ke-1 Rp '.number_format($simpananWajib1, 0, ',', '.').').');
        }

        $anggota = null;

        try {
            DB::transaction(function () use ($validated, $simpananPokok, $simpananWajib1, &$anggota) {
                // Auto-generate no_anggota: ANG-YYYY-XXXX.
                // Urutan diambil dari nomor urut TERBESAR tahun berjalan (bukan
                // jumlah baris) lalu dicari nomor bebas, sehingga tidak lagi
                // bertabrakan dengan data lama/soft-deleted (unique violation).
                $tahun = date('Y');
                $prefix = 'ANG-'.$tahun.'-';
                $panjangP = strlen($prefix);

                /* Ambil HANYA digit di depan sufiks. Tanpa ini sufiks
                 | non-numerik (mis. '3E15' dari data lama) diurai PHP sebagai
                 | notasi ilmiah (3.0E+15) sehingga urutan meledak menjadi nomor
                 | yang melebihi panjang kolom `no_anggota`. */
                $urutSufiks = function (string $no) use ($panjangP): int {
                    $sufiks = substr($no, $panjangP);
                    $digit = strspn($sufiks, '0123456789');

                    return $digit > 0 ? (int) substr($sufiks, 0, $digit) : 0;
                };

                $urut = (int) (Anggota::withTrashed()
                    ->where('no_anggota', 'like', $prefix.'%')
                    ->pluck('no_anggota')
                    ->map($urutSufiks)
                    ->max() ?? 0);

                do {
                    $noAnggota = $prefix.str_pad((string) ++$urut, 4, '0', STR_PAD_LEFT);
                } while (Anggota::withTrashed()->where('no_anggota', $noAnggota)->exists());

                /* Status awal SELALU PENDING: keanggotaan baru sah setelah setoran
                 | awal tervalidasi. Nilai dari form diabaikan agar tidak ada AO
                 | yang bisa "melompati" proses aktivasi. */
                $anggota = Anggota::create(array_merge(
                    collect($validated)->except([
                        'setoran_pertama', 'jenis_setoran', 'status_anggota',
                        // OJK-08: kolom CDD/PEP/PM dihitung terpusat oleh CddService
                        // (bukan diambil mentah dari form) agar profil risiko &
                        // jenis CDD konsisten di seluruh jalur onboarding.
                        'pep_status', 'pep_keterangan', 'pemilik_manfaat_nama',
                        'pemilik_manfaat_hubungan', 'pemilik_manfaat_keterangan',
                    ])->toArray(),
                    [
                        'no_anggota' => $noAnggota,
                        'status_anggota' => Anggota::STATUS_PENDING,
                        'tanggal_gabung' => today()->toDateString(),
                        'simpanan_pokok' => $simpananPokok,
                        'simpanan_wajib' => $simpananWajib1,
                    ],
                    // OJK-08: profil risiko, jenis CDD, & jejak verifikasi.
                    app(CddService::class)->terapkan($validated, Auth::id())
                ));

                /* Tiga rekening simpanan dibuka sekaligus saat pendaftaran:
                 | Simpanan Pokok, Simpanan Wajib (bundling berisi saldo), dan
                 | Simpanan Sukarela (saldo Rp 0 — wadah auto-debit & setoran bebas).
                 |
                 | Kode CoA di-resolve DINAMIS dari konfigurasi produk
                 | (`jenis_simpanans.account_coa_id` — Bagian 1), bukan hardcode. */
                $coaPokok = self::coaProduk(JenisSimpanan::namaUtama(JenisSimpanan::TIPE_POKOK, 'Simpanan Pokok'), AutoJournalingService::SIMPANAN_POKOK);
                $coaWajib = self::coaProduk(JenisSimpanan::namaUtama(JenisSimpanan::TIPE_WAJIB, 'Simpanan Wajib'), AutoJournalingService::SIMPANAN_WAJIB_MODAL);
                $coaSukarela = self::coaProduk(JenisSimpanan::namaUtama(JenisSimpanan::TIPE_SUKARELA, 'Simpanan Sukarela'), \App\Support\Coa::SIMPANAN_SUKARELA);

                if (! $coaPokok || ! $coaWajib || ! $coaSukarela) {
                    throw new \RuntimeException(
                        'Auto-journaling gagal: akun CoA '.AutoJournalingService::SIMPANAN_POKOK
                        .' (Simpanan Pokok), '.AutoJournalingService::SIMPANAN_WAJIB_MODAL
                        .' (Simpanan Wajib — Modal Anggota), dan '.\App\Support\Coa::SIMPANAN_SUKARELA
                        .' (Simpanan Sukarela) harus tersedia.'
                    );
                }

                /* Rekening Simpanan Pokok & Simpanan Wajib dibuka sekaligus
                 | (bundling), masing-masing dengan saldo sesuai porsi komponennya. */
                $noRekeningPokok = Rekening::nomorRekeningBaru();

                $rekeningPokok = Rekening::create([
                    'no_rekening' => $noRekeningPokok,
                    'anggota_id' => $anggota->id,
                    'akun_id' => $coaPokok->id,
                    'jenis_rekening' => JenisSimpanan::namaUtama(JenisSimpanan::TIPE_POKOK, 'Simpanan Pokok'),
                    'akad' => 'Wadiah',
                    'saldo' => $simpananPokok,
                    'batas_minimum' => 0,
                    'tanggal_buka' => today()->toDateString(),
                    'status_rekening' => 'Aktif',
                    'keterangan' => 'Rekening Simpanan Pokok — dibuka saat pendaftaran anggota',
                ]);

                // Nomor Wajib digenerate SETELAH Pokok tersimpan agar tidak
                // menghasilkan nomor yang sama.
                $noRekeningWajib = Rekening::nomorRekeningBaru();

                $rekeningWajib = Rekening::create([
                    'no_rekening' => $noRekeningWajib,
                    'anggota_id' => $anggota->id,
                    'akun_id' => $coaWajib->id,
                    'jenis_rekening' => JenisSimpanan::namaUtama(JenisSimpanan::TIPE_WAJIB, 'Simpanan Wajib'),
                    'akad' => 'Wadiah',
                    'saldo' => $simpananWajib1,
                    'batas_minimum' => 0,
                    'tanggal_buka' => today()->toDateString(),
                    'status_rekening' => 'Aktif',
                    'keterangan' => 'Rekening Simpanan Wajib — dibuka saat pendaftaran anggota',
                ]);

                /* Rekening Simpanan Sukarela dibuka otomatis dengan saldo Rp 0.
                 | Rekening ini menjadi wadah setoran bebas sekaligus sumber dana
                 | auto-debit Simpanan Wajib (Jalur B). Tidak ada mutasi/jurnal di
                 | sini karena saldo awal nol — jurnal baru dibuat saat ada setoran. */
                $noRekeningSukarela = Rekening::nomorRekeningBaru();

                Rekening::create([
                    'no_rekening' => $noRekeningSukarela,
                    'anggota_id' => $anggota->id,
                    'akun_id' => $coaSukarela->id,
                    'jenis_rekening' => JenisSimpanan::namaUtama(JenisSimpanan::TIPE_SUKARELA, 'Simpanan Sukarela'),
                    'akad' => 'Wadiah',
                    'saldo' => 0,
                    'batas_minimum' => 0,
                    'tanggal_buka' => today()->toDateString(),
                    'status_rekening' => 'Aktif',
                    'keterangan' => 'Rekening Simpanan Sukarela — dibuka saat pendaftaran anggota',
                ]);

                // Catat transaksi setoran awal (satu mutasi per komponen).
                $transaksiPokok = Transaksi::create([
                    'no_transaksi' => NomorDokumen::kode('transaksi').'-'.date('YmdHis').'-P'.str_pad($anggota->id, 4, '0', STR_PAD_LEFT),
                    'waktu_transaksi' => now(),
                    'rekening_id' => $rekeningPokok->id,
                    'anggota_id' => $anggota->id,
                    'jenis_transaksi' => 'Setoran Simpanan Pokok',
                    'posisi' => 'Debet',
                    'jumlah' => $simpananPokok,
                    'saldo_setelah' => $simpananPokok,
                    'user_id' => Auth::id(),
                    'sumber_transaksi' => 'Tunai',
                    'status_transaksi' => 'Sukses',
                    'status' => 'success',
                    'catatan' => 'Setoran awal (bundling) Simpanan Pokok pendaftaran anggota baru',
                ]);

                $transaksiWajib = Transaksi::create([
                    'no_transaksi' => NomorDokumen::kode('transaksi').'-'.date('YmdHis').'-W'.str_pad($anggota->id, 4, '0', STR_PAD_LEFT),
                    'waktu_transaksi' => now(),
                    'rekening_id' => $rekeningWajib->id,
                    'anggota_id' => $anggota->id,
                    'jenis_transaksi' => 'Setoran Simpanan Wajib',
                    'posisi' => 'Debet',
                    'jumlah' => $simpananWajib1,
                    'saldo_setelah' => $simpananWajib1,
                    'user_id' => Auth::id(),
                    'sumber_transaksi' => 'Tunai',
                    'status_transaksi' => 'Sukses',
                    'status' => 'success',
                    'catatan' => 'Setoran awal (bundling) Simpanan Wajib bulan ke-1 pendaftaran anggota baru',
                ]);

                // ── Jurnal Otomatis Bundling Setoran Awal (PSAK 101/108) ────────
                // DEBET  1-1001 Kas/Bank (total)
                // KREDIT 3-1001 Simpanan Pokok
                // KREDIT 3-1002 Simpanan Wajib Bulan ke-1
                $journalService = app(AutoJournalingService::class);

                $jurnal = $journalService->jurnalBundlingSetoranAwal(
                    $simpananPokok,
                    $simpananWajib1,
                    'Setoran awal (bundling) pendaftaran anggota '.$anggota->nama_lengkap,
                    Auth::id(),
                    $transaksiPokok->id,
                    $rekeningPokok->id,
                    $anggota->id,
                    $rekeningWajib->id
                );

                if (! $jurnal) {
                    throw new \RuntimeException(
                        'Auto-journaling gagal: pastikan akun CoA '.AutoJournalingService::KAS_TELLER
                        .', '.AutoJournalingService::SIMPANAN_POKOK
                        .', dan '.AutoJournalingService::SIMPANAN_WAJIB_MODAL.' tersedia.'
                    );
                }

                /* Setoran awal tervalidasi & jurnal terposting → aktivasi anggota.
                 | Inilah satu-satunya titik transisi PENDING → ACTIVE. */
                $anggota->update(['status_anggota' => Anggota::STATUS_ACTIVE]);

                /* Catat juga sebagai tagihan Simpanan Wajib periode berjalan yang
                 | sudah lunas, agar scheduler tidak menagih ulang bulan yang sama. */
                MandatorySavingsBill::create([
                    'member_id' => $anggota->id,
                    'bill_period' => MandatorySavingsBill::periodDari(today()),
                    'amount' => $simpananWajib1,
                    'status' => MandatorySavingsBill::STATUS_PAID,
                    'paid_at' => now(),
                    'payment_method' => MandatorySavingsBill::METODE_CASH,
                    'transaksi_id' => $transaksiWajib->id,
                    'jurnal_id' => $jurnal->id,
                ]);
            });
        } catch (ValidationException $e) {
            // Validasi yang dilempar dari dalam transaksi (mis. aturan bisnis
            // CoA) dipropagasikan apa adanya agar pesannya tampil di form.
            throw $e;
        } catch (QueryException $e) {
            // Jangan catat NIK plaintext ke log (kepatuhan UU PDP No. 27/2022).
            Log::error('Pendaftaran CIF gagal (QueryException)', [
                'no_hp' => $validated['no_hp'] ?? null,
                'user' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return back()->withInput()->with('error',
                self::pesanGalatDuplikat($e)
                ?? 'Data CIF gagal disimpan karena terjadi galat basis data. Silakan coba beberapa saat lagi.');
        } catch (\Throwable $e) {
            // Jangan catat NIK plaintext ke log (kepatuhan UU PDP No. 27/2022).
            Log::error('Pendaftaran CIF gagal (Exception)', [
                'user' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return back()->withInput()->with('error',
                'Pendaftaran CIF gagal diproses: '.$e->getMessage()
                .' Tidak ada data yang tersimpan (perubahan dibatalkan otomatis).');
        }

        // Jaring pengaman: transaksi sukses namun objek anggota tak terbentuk.
        if (! $anggota instanceof Anggota) {
            Log::error('Pendaftaran CIF: transaksi selesai tanpa objek anggota.', [
                'user' => Auth::id(),
            ]);

            return back()->withInput()->with('error',
                'Pendaftaran CIF gagal: data anggota tidak terbentuk. Silakan coba lagi.');
        }

        $anggota->refresh();

        return redirect()->route('ao.cif.show', $anggota->id)
            ->with('success', 'Data CIF anggota berhasil didaftarkan. No CIF: '.$anggota->no_cif
                .'. Status: '.$anggota->status_label
                .'. Setoran awal bundling Rp '.number_format($setoranAwal, 0, ',', '.')
                .' (Simpanan Pokok Rp '.number_format($simpananPokok, 0, ',', '.')
                .' + Simpanan Wajib Rp '.number_format($simpananWajib1, 0, ',', '.').') telah dicatat.');
    }

    /**
     * Resolve CoA produk simpanan secara DINAMIS (zero-hardcoding).
     *
     * Prioritas: konfigurasi `jenis_simpanans.account_coa_id` (Bagian 1) →
     * fallback ke `kode_akun` kanonik bila konfigurasi belum diisi.
     *
     * @param  string  $namaProduk  nama produk simpanan (mis. 'Simpanan Wajib')
     * @param  string  $kodeKanonik  kode CoA kanonik sebagai fallback
     */
    private static function coaProduk(string $namaProduk, string $kodeKanonik): ?Coa
    {
        $coaId = JenisSimpanan::coaId($namaProduk);

        return ($coaId !== null ? Coa::find($coaId) : null)
            ?? Coa::where('kode_akun', $kodeKanonik)->first();
    }

    /**
     * Terjemahkan galat unique-constraint basis data menjadi pesan yang ramah.
     * Mengembalikan null bila galat bukan galat duplikasi yang dikenal.
     */
    private static function pesanGalatDuplikat(QueryException $e): ?string
    {
        $pesan = $e->getMessage();

        if (str_contains($pesan, 'anggota_nik_hash_unique') || str_contains($pesan, 'nik_hash')) {
            return 'NIK sudah terdaftar pada anggota lain. Periksa kembali atau gunakan NIK yang berbeda.';
        }

        if (str_contains($pesan, 'anggota_no_hp_unique') || (str_contains($pesan, 'no_hp') && str_contains($pesan, 'Duplicate'))) {
            return 'Nomor HP sudah terdaftar pada anggota lain. Gunakan nomor HP yang berbeda.';
        }

        if (str_contains($pesan, 'no_anggota') || str_contains($pesan, 'no_cif')) {
            return 'Penomoran anggota/CIF sedang dipakai proses lain. Silakan kirim ulang pendaftaran.';
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────
    // SHOW — semua role kecuali Teller (Teller juga via route teller.cif.show)
    // ─────────────────────────────────────────────────────────────
    public function show(Request $request, int $id)
    {
        $role = Auth::user()->role;

        // ── Otorisasi eksplisit (fail-closed) ──────────────────────────────
        // Daftar putih peran yang boleh membuka Portofolio CIF. Menggantikan
        // pola lama `$viewMap[$role] ?? 'ao.cif.show'` yang bersifat
        // fail-open: peran tak terdaftar (mis. `dps`) akan diam-diam
        // mendapat view AO. Kini peran di luar daftar ditolak 403.
        $viewMap = [
            'ao' => 'ao.cif.show',
            'manajer' => 'manajer.cif.show',
            'pengurus' => 'pengurus.cif.show',
            'teller' => 'teller.cif.show',
        ];

        abort_unless(isset($viewMap[$role]), 403, 'Akses ditolak. Peran Anda tidak berwenang membuka Portofolio CIF.');
        $view = $viewMap[$role];

        $anggota = Anggota::with([
            'rekening',
            'pembiayaan' => fn ($q) => $q->with('angsuran')->orderByDesc('created_at'),
            'transaksi' => fn ($q) => $q->orderByDesc('waktu_transaksi')->limit(10),
            'depositos' => fn ($q) => $q->with('product')->orderByDesc('start_date'),
        ])->findOrFail($id);

        // Hitung saldo per jenis simpanan
        $saldoPokok = $anggota->rekening->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_POKOK))->sum('saldo');
        $saldoWajib = $anggota->rekening->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_WAJIB))->sum('saldo');
        $saldoSukarela = $anggota->rekening->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))->sum('saldo');

        // Satu sumber kebenaran status pasca-pencairan: 'dicairkan' dan
        // 'aktif' sama-sama berarti akad berjalan, bergantung jalur pencairan.
        $pembiayaanAktif = $anggota->pembiayaan->whereIn('status_pembiayaan', Pembiayaan::statusTercair());
        $mutasi = $anggota->transaksi; // sudah limit 10

        // Data pendukung form "Buka Rekening Simpanan" untuk Simpanan Berjangka:
        // produk deposito aktif (menentukan tenor & nisbah) dan rekening
        // Simpanan Sukarela anggota yang menjadi sumber dana pemotongan.
        // WAJIB difilter tipe_produk = 'deposito' (PSAK 108): master produk
        // juga menyimpan Simpanan Pokok/Wajib/Sukarela yang tidak punya tenor
        // maupun minimum setoran, sehingga tidak boleh muncul di dropdown
        // Produk Deposito.
        $produkDeposito = DepositoProduct::active()->deposito()->orderBy('tenor_months')->get();

        // Nisbah default Simpanan Sukarela (PSAK 101) dipakai modal untuk
        // menampilkan bagi hasil anggota secara read-only saat user memilih
        // jenis rekening 'Simpanan Sukarela'. Diambil dari master produk agar
        // konfigurasi nisbah tetap dinamis (single source of truth di DB).
        $produkSukarela = DepositoProduct::active()
            ->tipe(DepositoProduct::TIPE_SUKARELA)
            ->first();
        $nisbahSukarela = $produkSukarela
            ? (float) $produkSukarela->nisbah_anggota
            : 0.0;

        $sumberSukarela = $anggota->rekening
            ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))
            ->where('status_rekening', 'Aktif')
            ->first();

        // Daftar bilyet Simpanan Berjangka anggota — dipakai tabel deposito pada
        // Portofolio CIF beserta tombol 'Cetak Bilyet PDF'. Data ini tidak bisa
        // diambil dari tabel `rekening` karena `deposito_number` & status
        // verifikasi hanya hidup di tabel `depositos`.
        $deposito = $anggota->depositos;

        // Batas dinamis nominal pembukaan rekening (dipakai modal "Buka Rekening"
        // untuk min/max input & pesan bantuan). Sumber: parameter kebijakan koperasi.
        $batasPembukaan = KebijakanKoperasi::batasPembukaanRekening();

        /* ── Penahanan SHU (HOLD) — notifikasi di halaman detail CIF ──────
         | Sumber kebenaran tunggal: ShuCalculatorService. Penahanan dihitung
         | REAL-TIME dari tagihan Simpanan Wajib berstatus OVERDUE anggota,
         | dibatasi kebijakan `shu_hold_on_overdue`. Begitu Teller melunasi
         | tunggakan, banner hilang dengan sendirinya tanpa intervensi.
         |
         | Penerima notifikasi sisi anggota dipetakan ke halaman ini karena
         | sistem tidak memiliki login anggota (lihat keputusan desain). */
        $kalkulatorShu = app(ShuCalculatorService::class);
        $statusHold = $kalkulatorShu->statusPenahanan($anggota, now());
        $isShuHold = (bool) ($statusHold['is_hold'] ?? false);
        $alasanHold = $statusHold['hold_reason'] ?? null;
        $tunggakanWajib = $isShuHold
            ? $kalkulatorShu->tunggakanAnggota($anggota)
            : collect();
        $totalTunggakanWajib = $isShuHold
            ? Setting::bulatkanUang((float) $tunggakanWajib->sum('amount'))
            : 0.0;

        // Permintaan parsial dari modal "Buka Rekening Simpanan": kembalikan
        // HANYA tabel bilyet deposito sebagai HTML, sehingga daftar deposito
        // dapat disegarkan tanpa memuat ulang seluruh halaman Portofolio CIF.
        if ($request->query('partial') === 'deposito') {
            return response()->view('partials.deposito-bilyet-table', [
                'deposito' => $deposito,
                'sertakanScript' => false, // script cetak bilyet sudah terpasang di halaman induk
            ]);
        }

        return view($view, compact(
            'anggota', 'saldoPokok', 'saldoWajib', 'saldoSukarela',
            'pembiayaanAktif', 'mutasi', 'produkDeposito', 'sumberSukarela',
            'deposito', 'nisbahSukarela', 'batasPembukaan',
            'isShuHold', 'alasanHold', 'tunggakanWajib', 'totalTunggakanWajib'
        ));
    }

    // ─────────────────────────────────────────────────────────────
    // EDIT — AO, Manajer & Pengurus
    // ─────────────────────────────────────────────────────────────
    public function edit(int $id)
    {
        $anggota = Anggota::findOrFail($id);
        $role = Auth::user()->role;

        $viewMap = [
            'ao' => 'ao.cif.edit',
            'manajer' => 'manajer.cif.edit',
            'pengurus' => 'pengurus.cif.edit',
        ];

        // Fail-CLOSED: peran di luar daftar putih ditolak, bukan diam-diam
        // diarahkan ke view AO (pola lama `$viewMap[$role] ?? 'ao.cif.edit'`).
        abort_unless(isset($viewMap[$role]), 403, 'Akses ditolak. Peran Anda tidak berwenang mengubah data CIF.');
        $view = $viewMap[$role];

        return view($view, compact('anggota'));
    }

    // ─────────────────────────────────────────────────────────────
    // UPDATE — AO, Manajer & Pengurus
    // ─────────────────────────────────────────────────────────────
    public function update(Request $request, int $id)
    {
        $anggota = Anggota::findOrFail($id);
        $role = Auth::user()->role;

        // Fail-CLOSED: gerbang diletakkan di AWAL method, sebelum validasi dan
        // `$anggota->update()`. Peran di luar daftar putih ditolak tanpa
        // menyentuh data — pola lama `$showRouteMap[$role] ?? 'ao.cif.show'`
        // baru dievaluasi setelah data terlanjur tersimpan.
        $showRouteMap = [
            'ao' => 'ao.cif.show',
            'manajer' => 'manajer.cif.show',
            'pengurus' => 'pengurus.cif.show',
        ];

        abort_unless(isset($showRouteMap[$role]), 403, 'Akses ditolak. Peran Anda tidak berwenang mengubah data CIF.');

        /* Status keanggotaan HANYA dapat diubah oleh Super Admin/Pengurus.
         | Bagi peran lain field ini tidak dirender dan tidak dilindungi aturan
         | `in:` sama sekali — nilai apa pun yang tetap dikirim akan dibuang
         | tanpa memunculkan galat validasi. */
        $bolehUbahSt = Anggota::peranBolehUbahStatus($role);

        $validated = $request->validate([
            'nama_lengkap' => 'required|string|max:255',
            'nik' => ['required', 'digits:16', new NikUnik(Anggota::class, (int) $id)],
            'jenis_kelamin' => 'required|in:Laki-laki,Perempuan',
            'no_hp' => 'required|digits_between:10,15|unique:anggota,no_hp,'.$id,
            'alamat' => 'required|string',
            'kota' => 'required|string|max:100',
            'provinsi' => 'required|string|max:100',
            'tempat_lahir' => 'nullable|string|max:100',
            'tanggal_lahir' => 'nullable|date',
            'status_perkawinan' => 'nullable|string|max:50',
            'no_telepon' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'pekerjaan' => 'nullable|string|max:100',
            'nama_ibu_kandung' => 'nullable|string|max:255',
            'rt_rw' => 'nullable|string|max:20',
            'kelurahan' => 'nullable|string|max:100',
            'kecamatan' => 'nullable|string|max:100',
            'kode_pos' => 'nullable|string|max:10',
            'keterangan' => 'nullable|string',
            'status_anggota' => $bolehUbahSt
                ? 'nullable|in:'.implode(',', Anggota::statusValidValues())
                : 'nullable|string|max:50',
            'is_auto_debit' => 'nullable|boolean',
        ], [
            'status_anggota.in' => self::pesanStatusTidakValid(),
        ]);

        if ($bolehUbahSt && filled($validated['status_anggota'] ?? null)) {
            // Nilai kanonik disimpan agar satu makna = satu nilai di kolom enum
            // (form hanya menawarkan nilai kanonik; normalisasi ini menutup
            // kemungkinan kiriman manual berisi alias legacy).
            $validated['status_anggota'] = Anggota::normalizeStatus($validated['status_anggota'])
                ?? $anggota->status_anggota;
        } else {
            // Peran tanpa kewenangan: nilai lama selalu dipertahankan.
            unset($validated['status_anggota']);
        }

        $anggota->update($validated);

        $showRoute = $showRouteMap[$role];

        return redirect()->route($showRoute, $anggota->id)
            ->with('success', 'Data CIF berhasil diperbarui.');
    }

    // ─────────────────────────────────────────────────────────────
    // BUKA REKENING SIMPANAN LAIN — AO only
    // ─────────────────────────────────────────────────────────────
    public function bukaRekening(Request $request, int $id)
    {
        $anggota = Anggota::findOrFail($id);

        // Jenis simpanan bertipe `berjangka` (parameter dinamis) diarahkan ke
        // pembukaan deposito; sisanya ke rekening simpanan biasa.
        $jenisRekening = (string) $request->input('jenis_rekening');
        $isDeposito = JenisSimpanan::tipe($jenisRekening) === JenisSimpanan::TIPE_BERJANGKA;

        // Batas minimum/maksimum nominal pembukaan rekening dibaca dinamis dari
        // parameter kebijakan koperasi (dapat diubah pengurus) — TANPA hardcode.
        $validated = $request->validate([
            // Daftar sah dibaca dari master parameter jenis simpanan (Pengurus).
            'jenis_rekening' => 'required|in:'.implode(',', JenisSimpanan::daftarAktif()),
            // Deposito: akad dipaksa Mudharabah di server (select akad dikunci di form).
            'akad' => $isDeposito ? 'nullable|in:Wadiah,Mudharabah' : 'required|in:Wadiah,Mudharabah',
            // Batas minimum/maksimum nominal bersumber dari parameter dinamis.
            'setoran_awal' => KebijakanKoperasi::aturanNominalPembukaan(),
            'nisbah_nasabah' => 'nullable|numeric|min:0|max:100',
            // Deposito: tenor & nisbah ditentukan produk. Tanggal jatuh tempo
            // TIDAK diinput manual — dihitung sistem (Tanggal Akad + tenor).
            'deposito_product_id' => $isDeposito ? 'required|integer|exists:deposito_products,id' : 'nullable',
            'tanggal_akad' => $isDeposito ? 'required|date' : 'nullable|date',
            // Mode ARO (Automatic Roll Over) bilyet deposito.
            'aro_mode' => $isDeposito
                ? 'nullable|in:'.implode(',', Deposito::ARO_MODE)
                : 'nullable',
        ], array_merge(
            KebijakanKoperasi::pesanValidasiNominalPembukaan('Setoran awal'),
            [
                'deposito_product_id.required' => 'Produk deposito wajib dipilih untuk Simpanan Berjangka.',
                'deposito_product_id.exists' => 'Produk deposito yang dipilih tidak tersedia.',
                'tanggal_akad.required' => 'Tanggal akad wajib diisi untuk Simpanan Berjangka.',
                'tanggal_akad.date' => 'Tanggal akad tidak berformat tanggal yang sah.',
                'aro_mode.in' => 'Mode ARO tidak dikenal. Pilihan sah: NON_ARO, ARO_POKOK, ARO_PLUS.',
            ]
        ));

        /* ── Gerbang kepemilikan tunggal (anti-bypass API) ──────────────────
         | Simpanan Pokok & Simpanan Wajib hanya boleh satu rekening per anggota.
         | Pemeriksaan dilakukan DI SINI (sebelum cabang deposito & sebelum
         | transaksi) memakai sumber acuan yang sama dengan UI, sehingga
         | permintaan yang menembus UI — POST langsung, API call, atau DOM yang
         | dimodifikasi — tetap ditolak dengan pesan yang dapat dibaca. */
        $jenisTunggalAda = JenisSimpanan::jenisTunggalSudahDimiliki($anggota->id);

        if (in_array($validated['jenis_rekening'], $jenisTunggalAda, true)) {
            throw ValidationException::withMessages([
                'jenis_rekening' => JenisSimpanan::pesanSudahAda($validated['jenis_rekening']),
            ]);
        }

        // ── Simpanan Berjangka → seluruh logika ditangani DepositoService ──
        if ($isDeposito) {
            return $this->bukaDeposito($anggota, $validated);
        }

        // Akun terkait diresolusi DINAMIS dari parameter jenis simpanan:
        //  - `$akunId`      → akun legacy (`akun.id`) untuk kolom rekening.akun_id
        //  - `$kodeCoaSimpanan` → akun COA (tabel coas) untuk snapshot nisbah.
        $akunId = JenisSimpanan::akunLegacyId($validated['jenis_rekening']);
        $kodeCoaSimpanan = JenisSimpanan::coaKode($validated['jenis_rekening']);

        if (! $akunId || $kodeCoaSimpanan === null) {
            throw ValidationException::withMessages([
                'jenis_rekening' => 'Jenis simpanan "'.$validated['jenis_rekening']
                    .'" belum dipetakan ke akun simpanan yang sah. Atur pemetaan akun pada menu '
                    .'Pengurus → Jenis Simpanan (Parameter).',
            ]);
        }

        DB::transaction(function () use ($validated, $anggota, $akunId, $kodeCoaSimpanan) {

            // Cek sudah punya rekening jenis sama & aktif
            $sudahAda = Rekening::where('anggota_id', $anggota->id)
                ->where('jenis_rekening', $validated['jenis_rekening'])
                ->where('status_rekening', 'Aktif')
                ->exists();

            if ($sudahAda) {
                throw ValidationException::withMessages([
                    'jenis_rekening' => 'Anggota sudah memiliki rekening '.$validated['jenis_rekening'].' yang aktif.',
                ]);
            }

            // Generate no rekening (selalu bebas bentrok, termasuk vs. rekening soft-deleted)
            $noRekening = Rekening::nomorRekeningBaru();

            $rekening = Rekening::create([
                'no_rekening' => $noRekening,
                'anggota_id' => $anggota->id,
                'akun_id' => $akunId,
                'jenis_rekening' => $validated['jenis_rekening'],
                'akad' => $validated['akad'],
                'saldo' => $validated['setoran_awal'],
                // Jejak nominal pembukaan rekening (tidak berubah saat saldo berjalan berubah).
                'nominal_pembukaan' => $validated['setoran_awal'],
                'batas_minimum' => 0,
                'nisbah_nasabah' => $validated['nisbah_nasabah'] ?? null,
                'nisbah_bank' => isset($validated['nisbah_nasabah'])
                                            ? (100 - $validated['nisbah_nasabah'])
                                            : null,
                'tanggal_buka' => today()->toDateString(),
                'tanggal_jatuh_tempo' => null,
                'status_rekening' => 'Aktif',
                'keterangan' => 'Rekening dibuka oleh AO via Portofolio CIF',
            ] + DepositoService::snapshotNisbahSimpanan($kodeCoaSimpanan));

            // Catat transaksi setoran awal
            $noTrx = NomorDokumen::kode('transaksi').'-'.date('YmdHis').'-'.str_pad($anggota->id, 4, '0', STR_PAD_LEFT);
            $transaksi = Transaksi::create([
                'no_transaksi' => $noTrx,
                'waktu_transaksi' => now(),
                'rekening_id' => $rekening->id,
                'anggota_id' => $anggota->id,
                'jenis_transaksi' => $validated['jenis_rekening'],
                'posisi' => 'Debet',
                'jumlah' => $validated['setoran_awal'],
                'saldo_setelah' => $validated['setoran_awal'],
                'user_id' => Auth::id(),
                'sumber_transaksi' => 'Tunai',
                'status_transaksi' => 'Sukses',
                'status' => 'success',
                'catatan' => 'Setoran awal pembukaan rekening '.$validated['jenis_rekening'],
            ]);

            // ── Jurnal Otomatis ─────────────────────────────────────────
            // DEBET Kas Teller (1-1001) | KREDIT akun COA jenis simpanan
            // (diambil dari parameter dinamis, mis. 3-1001 / 2-1002 / 2-1001).
            $keteranganJurnal = 'Pembukaan rekening '.$validated['jenis_rekening']
                              .' atas nama '.$anggota->nama_lengkap;

            $jurnal = app(AutoJournalingService::class)->jurnalSetorSimpanan(
                (float) $validated['setoran_awal'],
                $keteranganJurnal,
                $validated['jenis_rekening'],
                Auth::id(),
                $transaksi->id,
                $rekening->id,
                $anggota->id
            );

            if (! $jurnal) {
                throw new \RuntimeException('Auto-journaling gagal: pastikan akun CoA kas & simpanan tersedia.');
            }
        });

        return redirect()->route('ao.cif.show', $anggota->id)
            ->with('success', 'Rekening '.$validated['jenis_rekening'].' berhasil dibuka. Setoran awal Rp '
                .number_format($validated['setoran_awal'], 0, ',', '.')
                .' telah dicatat ke jurnal.');
    }

    /**
     * Pembukaan deposito (Simpanan Berjangka) via DepositoService.
     *
     * Aturan yang ditegakkan:
     *  - Akad Mudharabah Muthlaqah dengan nisbah dari produk (bukan bunga tetap).
     *  - Sumber dana adalah Rekening Simpanan Sukarela anggota (CoA 2-1001),
     *    bukan Kas Teller. Saldo sukarela dipotong sebesar pokok deposito.
     *  - Jatuh tempo dihitung sistem: start_date + tenor produk (Carbon).
     */
    private function bukaDeposito(Anggota $anggota, array $validated)
    {
        $sumber = Rekening::where('anggota_id', $anggota->id)
            ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))
            ->where('status_rekening', 'Aktif')
            ->orderBy('id')
            ->first();

        if (! $sumber) {
            throw ValidationException::withMessages([
                'jenis_rekening' => 'Anggota belum memiliki Rekening Simpanan Sukarela aktif. '
                    .'Deposito hanya dapat dibuka dengan sumber dana dari Simpanan Sukarela.',
            ]);
        }

        $deposito = app(DepositoService::class)->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => (int) $validated['deposito_product_id'],
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => (float) $validated['setoran_awal'],
            // Jatuh tempo dihitung DepositoService dari Tanggal Akad + tenor produk.
            'start_date' => $validated['tanggal_akad'],
            // Mode ARO dari form; fallback aman ke NON_ARO bila tidak dikirim.
            'aro_mode' => $validated['aro_mode'] ?? Deposito::ARO_NON,
            'user_id' => Auth::id(),
        ]);

        return redirect()->route('ao.cif.show', $anggota->id)
            ->with('success', 'Deposito Mudharabah '.$deposito->deposito_number.' berhasil dibuka. '
                .'Pokok Rp '.number_format((float) $deposito->principal_amount, 0, ',', '.')
                .' dipotong dari Simpanan Sukarela '.$sumber->no_rekening
                .', jatuh tempo '.$deposito->maturity_date->format('d/m/Y')
                .' (tenor '.$deposito->tenorMonths().' bulan, mode ARO: '
                .($deposito->aro_label ?? '-').').');
    }

    // ─────────────────────────────────────────────────────────────
    // SEARCH — Teller only
    // ─────────────────────────────────────────────────────────────
    public function search(Request $request)
    {
        $q = $request->query('q', '');

        $anggota = null;
        if ($q) {
            $anggota = Anggota::where('nama_lengkap', 'like', "%{$q}%")
                ->orWhere('no_anggota', 'like', "%{$q}%")
                ->orWhere('no_cif', 'like', "%{$q}%");

            // NIK dicari lewat blind index (kecocokan tepat), bukan LIKE.
            if (($hash = PiiHasher::hash($q)) !== null) {
                $anggota->orWhere('nik_hash', $hash);
            }

            $anggota = $anggota->orderBy('nama_lengkap')
                ->paginate(20)
                ->withQueryString();
        }

        return view('teller.cif.index', compact('anggota', 'q'));
    }

    // ─────────────────────────────────────────────────────────────
    // EXPORT CSV — Pengurus & Manajer
    // ─────────────────────────────────────────────────────────────
    public function export(Request $request)
    {
        if ($request->query('export') !== 'excel') {
            return redirect()->back();
        }

        $hasil = HierarkiRole::ambilDenganBatas(
            Anggota::orderBy('no_anggota'),
            $request->user()?->role
        );

        if ($hasil['melampaui']) {
            return redirect()->back()->with(
                'error',
                'Volume data melebihi batas akses peran Anda (maksimal '
                .number_format((int) $hasil['batas'], 0, ',', '.')
                .' baris). Persempit filter atau ajukan permohonan ke peran yang berwenang.'
            );
        }

        $anggota = $hasil['data'];

        $filename = 'cif-anggota-'.date('Y-m-d').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $callback = function () use ($anggota) {
            $handle = fopen('php://output', 'w');

            // BOM untuk Excel agar UTF-8 terbaca benar
            fwrite($handle, "\xEF\xBB\xBF");

            // Header kolom
            fputcsv($handle, [
                'No CIF', 'No Anggota', 'NIK', 'Nama Lengkap',
                'No HP', 'Alamat', 'Kota', 'Status', 'Tgl Gabung',
            ]);

            foreach ($anggota as $a) {
                fputcsv($handle, [
                    $a->no_cif ?? '',
                    $a->no_anggota ?? '',
                    $a->nik ?? '',
                    $a->nama_lengkap ?? '',
                    $a->no_hp ?? '',
                    $a->alamat ?? '',
                    $a->kota ?? '',
                    $a->status_anggota ?? '',
                    $a->tanggal_gabung ? $a->tanggal_gabung->format('Y-m-d') : '',
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
