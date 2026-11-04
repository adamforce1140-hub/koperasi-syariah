<?php

namespace App\Http\Controllers;

use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\Rekening;
use App\Support\Izin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Modul Master Parameter Jenis Simpanan — Role Pengurus.
 *
 * Memungkinkan pengurus menambah, mengedit, menonaktifkan, dan menghapus
 * jenis simpanan beserta akun COA terkait TANPA mengubah kode inti sistem.
 * Setiap perubahan otomatis tercermin di seluruh modul yang bergantung pada
 * klasifikasi simpanan (jurnal otomatis, pembukaan rekening, dropdown CIF)
 * karena semuanya membaca resolver JenisSimpanan yang ber-cache.
 *
 * Hak akses dibatasi middleware `role:pengurus` pada grup rute.
 */
class JenisSimpananController extends Controller
{
    /** Nilai ENUM rekening.jenis_rekening — batas atas kompatibilitas alur lama. */
    private const ENUM_REKENING = [
        'Simpanan Pokok',
        'Simpanan Wajib',
        'Simpanan Sukarela',
        'Simpanan Berjangka',
        'Simpanan Hari Raya',
        'Modal Penyertaan',
    ];

    public function index()
    {
        // Urut aktif dulu, lalu tipe, lalu nama — agar parameter yang berlaku
        // langsung terlihat di atas tabel.
        $jenis = JenisSimpanan::query()
            ->orderByDesc('is_active')
            ->orderBy('tipe')
            ->orderBy('nama')
            ->get();

        $riwayat = JenisSimpanan::riwayatPerubahan(50);

        // Pilihan akun COA: semua akun aktif, dikelompokkan per kategori.
        $coas = Coa::where('is_active', true)
            ->orderBy('kode_akun')
            ->get(['id', 'kode_akun', 'nama_akun', 'kategori']);

        // Jumlah rekening yang memakai tiap jenis — mencegah nonaktif/ubah nama
        // yang akan memutus data historis.
        $pemakaiRekening = Rekening::query()
            ->selectRaw('jenis_rekening, COUNT(*) as jumlah')
            ->groupBy('jenis_rekening')
            ->pluck('jumlah', 'jenis_rekening')
            ->all();

        return view('pengurus.jenis-simpanan', [
            'jenis' => $jenis,
            'riwayat' => $riwayat,
            'coas' => $coas,
            'labelTipe' => JenisSimpanan::LABEL_TIPE,
            'enumRekening' => self::ENUM_REKENING,
            'pemakaiRekening' => $pemakaiRekening,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->aturanValidasi(null), $this->pesanValidasi());

        $validated['tipe'] = ($validated['tipe'] ?? null) ?: JenisSimpanan::TIPE_LAIN;
        $validated['kode_akun'] = ($validated['kode_akun'] ?? null) ?: null;
        $validated['is_active'] = $request->boolean('is_active', true);

        $this->pastikanKodeAkunValid($validated['kode_akun']);
        $validated = $this->lengkapiKolomDinamis($request, $validated, null);

        JenisSimpanan::create($validated);

        return redirect()->route('pengurus.jenis-simpanan.index')
            ->with('success', 'Jenis simpanan "'.$validated['nama'].'" berhasil ditambahkan dan langsung berlaku di seluruh modul.');
    }

    public function update(Request $request, JenisSimpanan $jenis_simpanan)
    {
        $validated = $request->validate(
            $this->aturanValidasi($jenis_simpanan->id),
            $this->pesanValidasi()
        );

        $validated['tipe'] = ($validated['tipe'] ?? null) ?: JenisSimpanan::TIPE_LAIN;
        $validated['kode_akun'] = ($validated['kode_akun'] ?? null) ?: null;

        // Status aktif boleh diubah dari form edit; default pertahankan nilai lama.
        $validated['is_active'] = $request->has('is_active')
            ? $request->boolean('is_active')
            : $jenis_simpanan->is_active;

        $this->pastikanKodeAkunValid($validated['kode_akun']);

        // Mengubah NAMA = mengubah kunci klasifikasi yang dipakai rekening &
        // jurnal historis. Tolak bila rekening sudah memakai nama lama, kecuali
        // nama barunya masih salah satu nilai ENUM (agar alur lama tetap sah).
        if ($validated['nama'] !== $jenis_simpanan->nama) {
            $this->pastikanGantiNamaAman($jenis_simpanan->nama, $validated['nama']);
        }

        $validated = $this->lengkapiKolomDinamis($request, $validated, $jenis_simpanan);

        $jenis_simpanan->update($validated);

        return redirect()->route('pengurus.jenis-simpanan.index')
            ->with('success', 'Jenis simpanan "'.$validated['nama'].'" berhasil diperbarui. Perubahan langsung berlaku di seluruh modul terkait.');
    }

    /**
     * Toggle status aktif / nonaktif.
     *
     * Nonaktif bersifat "soft disable": baris tetap ada (riwayat jurnal &
     * rekening aman), namun jenis tidak lagi tersedia pada dropdown pembukaan
     * rekening dan jurnal baru tidak diposting ke akun COA-nya.
     */
    public function toggle(JenisSimpanan $jenis_simpanan)
    {
        if ($jenis_simpanan->is_active) {
            $this->pastikanBolehDinonaktifkan($jenis_simpanan);
        }

        $jenis_simpanan->update(['is_active' => ! $jenis_simpanan->is_active]);

        $status = $jenis_simpanan->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return redirect()->route('pengurus.jenis-simpanan.index')
            ->with('success', 'Jenis simpanan "'.$jenis_simpanan->nama.'" berhasil '.$status.'.');
    }

    /**
     * Hapus permanen — hanya diizinkan bila belum pernah dipakai rekening
     * mana pun. Untuk jenis yang sudah terpakai, gunakan Nonaktifkan.
     */
    public function destroy(JenisSimpanan $jenis_simpanan)
    {
        $dipakai = Rekening::where('jenis_rekening', $jenis_simpanan->nama)->count();

        if ($dipakai > 0) {
            return redirect()->route('pengurus.jenis-simpanan.index')
                ->with('error', 'Jenis simpanan "'.$jenis_simpanan->nama.'" tidak dapat dihapus karena sudah dipakai '
                    .$dipakai.' rekening. Gunakan tombol Nonaktifkan agar riwayat tetap utuh.');
        }

        $nama = $jenis_simpanan->nama;

        $jenis_simpanan->delete();

        return redirect()->route('pengurus.jenis-simpanan.index')
            ->with('success', 'Jenis simpanan "'.$nama.'" berhasil dihapus.');
    }

    /* ── Aturan & guard ────────────────────────────────────────────────── */

    /**
     * Aturan validasi parameter jenis simpanan.
     *
     * `nama` dibatasi ke nilai ENUM `rekening.jenis_rekening` agar seluruh alur
     * lama (kolom ENUM, laporan, filter) tetap kompatibel — inilah batas aman
     * parameterisasi: yang dinamis adalah PEMETAAN jenis → akun COA & status
     * aktifnya, bukan struktur ENUM basis data.
     */
    private function aturanValidasi(?int $abaikanId): array
    {
        return [
            'nama' => 'required|string|max:100|in:'.implode(',', self::ENUM_REKENING)
                .'|unique:jenis_simpanans,nama'.($abaikanId ? ','.$abaikanId : ''),
            'kode_akun' => 'nullable|string|max:30',
            'tipe' => 'nullable|in:'.implode(',', array_keys(JenisSimpanan::LABEL_TIPE)),
            'min_deposit_amount' => 'nullable|integer|min:0',
            'allow_auto_debit' => 'nullable|boolean',
            'account_coa_id' => 'nullable|integer|exists:coas,id',
            'is_active' => 'nullable|boolean',
            'keterangan' => 'nullable|string|max:255',
        ];
    }

    /** Pesan validasi berbahasa Indonesia. */
    private function pesanValidasi(): array
    {
        return [
            'nama.in' => 'Nama jenis simpanan harus salah satu dari: '.implode(', ', self::ENUM_REKENING)
                .' — agar tetap selaras dengan klasifikasi rekening yang sudah ada.',
            'nama.unique' => 'Jenis simpanan dengan nama tersebut sudah terdaftar.',
            'tipe.in' => 'Tipe pengelompokan tidak dikenal.',
            'min_deposit_amount.integer' => 'Nominal minimal setoran harus berupa angka bulat (rupiah).',
            'min_deposit_amount.min' => 'Nominal minimal setoran tidak boleh negatif.',
            'account_coa_id.exists' => 'Akun COA yang dipilih tidak ditemukan pada Bagan Akun.',
        ];
    }

    /**
     * Lengkapi kolom konfigurasi dinamis (kode produk, nama resmi, nominal
     * minimal, izin auto-debit, dan FK akun COA) beserta validasi aksesnya.
     *
     * Aturan ketat (sesuai spesifikasi):
     *   - `allow_auto_debit` = true HANYA boleh diubah oleh Admin/Manager DAN
     *     HANYA untuk produk Simpanan Wajib (kode WAJIB).
     *   - `account_coa_id` bila diisi diselaraskan dengan `kode_akun` agar tidak
     *     ada dua sumber kebenaran yang bertentangan.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function lengkapiKolomDinamis(Request $request, array $validated, ?JenisSimpanan $existing): array
    {
        // Nama resmi produk (`name`) selalu mengikuti nama jenis simpanan.
        $validated['name'] = $validated['nama'];

        // Kode produk (POKOK | WAJIB | SUKARELA) HANYA dimiliki oleh produk baku
        // dengan NAMA resmi yang sama — bukan diturunkan dari `tipe`. Satu tipe
        // (mis. `sukarela`) boleh menampung banyak produk turunan (Simpanan Hari
        // Raya, Simpanan Qurban, dll.); produk turunan tersebut adalah entri
        // mandiri dengan kode kosong, sehingga tidak pernah bertabrakan dengan
        // kode baku. Turunkan dari tipe akan membuat semua produk bertipe sama
        // berebut satu kode — itulah yang harus dicegah.
        $kodeDiturunkan = $this->kodeDariNama($validated['nama'] ?? null);
        if ($kodeDiturunkan !== null) {
            $this->pastikanKodeProdukUnik($kodeDiturunkan, $existing);
            $validated['code'] = $kodeDiturunkan;
        } else {
            // Produk turunan/kustom → tanpa kode baku. Pertahankan kode lama bila
            // ada (agar edit tidak menghapus kode yang sudah tersimpan), tapi
            // bersihkan bila produk ini sebelumnya memegang kode baku lalu
            // berganti nama menjadi produk turunan.
            $validated['code'] = ($existing !== null
                && $this->kodeDariNama($existing->nama) === null)
                ? $existing->code
                : null;
        }

        // Nominal minimal setoran (bigint). Default 0 bila tidak dikirim.
        $validated['min_deposit_amount'] = (int) ($request->input('min_deposit_amount') ?? 0);

        // ── Gembok auto-debit ──────────────────────────────────────────────
        $mintaAutoDebit = $request->boolean('allow_auto_debit');
        $this->pastikanAllowAutoDebitSah($mintaAutoDebit, $validated['code'] ?? null, $existing);
        $validated['allow_auto_debit'] = $mintaAutoDebit;

        // ── Integritas referensial COA ─────────────────────────────────────
        $coaId = $request->input('account_coa_id');
        if (empty($coaId) && ! empty($validated['kode_akun'])) {
            // Turunkan FK dari kode akun bila form hanya mengirim kode.
            $coaId = Coa::where('kode_akun', $validated['kode_akun'])->value('id');
        }
        $validated['account_coa_id'] = $coaId ?: null;

        // Selaraskan kolom `kode_akun` dengan akun COA terpilih.
        if (! empty($validated['account_coa_id'])) {
            $kode = Coa::whereKey($validated['account_coa_id'])->value('kode_akun');
            if ($kode !== null) {
                $validated['kode_akun'] = $kode;
            }
        }

        return $validated;
    }

    /**
     * Gembok akses perubahan izin auto-debit: wewenangnya dibaca dari izin
     * bernama `simpanan.jenis` (sama dengan gerbang rute), bukan perbandingan
     * level peran. HANYA pemegang izin itu yang boleh menyalakan
     * `allow_auto_debit`, dan HANYA untuk produk Simpanan Wajib.
     *
     * @throws ValidationException
     */
    private function pastikanAllowAutoDebitSah(bool $mintaAutoDebit, ?string $code, ?JenisSimpanan $existing): void
    {
        if (! $mintaAutoDebit) {
            return; // Mematikan auto-debit selalu diizinkan.
        }

        if (! Izin::peranMemegang(Auth::user()?->role, 'simpanan.jenis')) {
            throw ValidationException::withMessages([
                'allow_auto_debit' => 'Hanya pengelola master jenis simpanan yang berwenang mengaktifkan auto-debit produk simpanan.',
            ]);
        }

        if (! JenisSimpanan::kodeBolehAutoDebit($code)) {
            $diizinkan = JenisSimpanan::kodeAutoDebitDiizinkan();

            throw ValidationException::withMessages([
                'allow_auto_debit' => 'Auto-debit pindah buku hanya dapat diaktifkan untuk produk berkode '
                    .implode(', ', $diizinkan).'.',
            ]);
        }
    }

    /**
     * Turunkan kode produk baku dari NAMA resmi produk (POKOK | WAJIB | SUKARELA).
     *
     * Hanya tiga nama baku pada `JenisSimpanan::FALLBACK` yang memetakan kode
     * (Simpanan Pokok/Wajib/Sukarela). Nama lain — termasuk produk turunan
     * seperti "Simpanan Hari Raya" maupun "Simpanan Berjangka" — mengembalikan
     * null, sehingga tidak pernah berebut kode baku.
     */
    private function kodeDariNama(?string $nama): ?string
    {
        $kode = JenisSimpanan::FALLBACK[trim((string) $nama)]['code'] ?? null;

        return in_array($kode, [
            JenisSimpanan::CODE_POKOK,
            JenisSimpanan::CODE_WAJIB,
            JenisSimpanan::CODE_SUKARELA,
        ], true) ? $kode : null;
    }

    /**
     * Kode produk baku (POKOK | WAJIB | SUKARELA) wajib unik — hanya satu baris
     * per kode. Produk turunan ber-`code` NULL tidak ikut dibandingkan (di MySQL
     * `NULL = 'WAJIB'` bernilai UNKNOWN), sehingga aman berdampingan.
     *
     * @throws ValidationException
     */
    private function pastikanKodeProdukUnik(string $code, ?JenisSimpanan $existing): void
    {
        $bentrok = JenisSimpanan::query()
            ->where('code', $code)
            ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->id))
            ->exists();

        if ($bentrok) {
            throw ValidationException::withMessages([
                'tipe' => 'Kode produk "'.$code.'" sudah dipakai jenis simpanan lain. '
                    .'Ubah tipe agar tidak terjadi duplikasi produk.',
            ]);
        }
    }

    /**
     * Kode akun COA harus benar-benar ada dan AKTIF pada Master COA.
     * Mencegah parameter menunjuk akun yang tidak bisa dijurnal (post() akan
     * mengembalikan null bila akun nonaktif/tidak ada).
     *
     * @throws ValidationException
     */
    private function pastikanKodeAkunValid(?string $kodeAkun): void
    {
        if (empty($kodeAkun)) {
            return;
        }

        $coa = Coa::where('kode_akun', $kodeAkun)->first();

        if (! $coa) {
            throw ValidationException::withMessages([
                'kode_akun' => 'Kode akun "'.$kodeAkun.'" tidak ditemukan pada Bagan Akun (CoA).',
            ]);
        }

        if (! $coa->is_active) {
            throw ValidationException::withMessages([
                'kode_akun' => 'Akun "'.$kodeAkun.' — '.$coa->nama_akun
                    .'" sedang NONAKTIF sehingga tidak dapat dipakai untuk jurnal baru.',
            ]);
        }
    }

    /**
     * Guard pergantian nama.
     *
     * Nama adalah kunci klasifikasi yang tersimpan pada `rekening.jenis_rekening`
     * (ENUM) dan riwayat jurnal. Bila sudah ada rekening memakai nama lama,
     * perubahan ditolak agar tidak ada rekening "yatim" yang tidak lagi dikenali
     * resolver — kecuali pemakaiannya nol.
     *
     * @throws ValidationException
     */
    private function pastikanGantiNamaAman(string $namaLama, string $namaBaru): void
    {
        $dipakai = Rekening::where('jenis_rekening', $namaLama)->count();

        if ($dipakai > 0) {
            throw ValidationException::withMessages([
                'nama' => 'Nama tidak dapat diubah karena "'.$namaLama.'" sudah dipakai '.$dipakai
                    .' rekening. Buat jenis simpanan baru bila perlu klasifikasi berbeda, '
                    .'lalu nonaktifkan jenis lama.',
            ]);
        }
    }

    /**
     * Mencegah koperasi kehilangan satu-satunya jalur simpanan yang masih
     * dipakai rekening aktif — sistem harus selalu punya minimal satu jenis
     * simpanan aktif sebagai tujuan transaksi.
     *
     * @throws ValidationException
     */
    private function pastikanBolehDinonaktifkan(JenisSimpanan $jenis): void
    {
        $jumlahAktif = JenisSimpanan::query()->where('is_active', true)->count();

        if ($jumlahAktif <= 1) {
            throw ValidationException::withMessages([
                'is_active' => 'Tidak dapat menonaktifkan "'.$jenis->nama.'" — minimal harus ada satu jenis simpanan aktif.',
            ]);
        }

        $dipakai = Rekening::where('jenis_rekening', $jenis->nama)
            ->where('status_rekening', 'Aktif')
            ->count();

        if ($dipakai > 0 && JenisSimpanan::aktifBertipe($jenis->tipe) === [$jenis->nama]) {
            throw ValidationException::withMessages([
                'is_active' => 'Tidak dapat menonaktifkan "'.$jenis->nama.'" — masih ada '.$dipakai
                    .' rekening AKTIF bertipe '.(JenisSimpanan::LABEL_TIPE[$jenis->tipe] ?? $jenis->tipe)
                    .' yang bergantung padanya. Aktifkan jenis pengganti terlebih dahulu.',
            ]);
        }
    }
}
