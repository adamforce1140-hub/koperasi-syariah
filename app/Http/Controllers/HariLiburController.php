<?php

namespace App\Http\Controllers;

use App\Models\HariLibur;
use App\Services\HariLiburService;
use App\Support\HierarkiRole;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Modul Kalender Hari Libur — Role Pengurus.
 *
 * Tabel `hari_liburs` sebelumnya hanya dapat diisi lewat seeder, padahal
 * `HariLiburService` membacanya untuk menggeser tanggal jatuh tempo angsuran
 * ke hari kerja. Modul ini membuka pemeliharaannya dari antarmuka sehingga
 * libur koperasi / cuti bersama tahun berikutnya tidak lagi memerlukan deploy.
 *
 * Hak akses mengikuti grup rute `izin:kebijakan.koperasi` (Pengurus) karena
 * kalender ini adalah parameter kebijakan penjadwalan, bukan konfigurasi teknis.
 */
class HariLiburController extends Controller
{
    /** Rentang tahun yang dipakai statistik kelengkapan kalender. */
    private const RENTANG_TAHUN = 2;

    public function index(Request $request)
    {
        $tahun = (int) $request->query('tahun', now()->year);

        if ($tahun < 2000 || $tahun > 2100) {
            $tahun = now()->year;
        }

        $libur = HariLibur::query()
            ->whereYear('tanggal', $tahun)
            ->orderByDesc('is_active')
            ->orderBy('tanggal')
            ->get();

        // Statistik hanya untuk hari libur AKTIF — satu-satunya yang dipakai
        // penjadwalan angsuran.
        $statistik = [
            'aktif' => $libur->where('is_active', true)->count(),
            'nonaktif' => $libur->where('is_active', false)->count(),
            'per_jenis' => collect(HariLibur::daftarJenis())
                ->mapWithKeys(fn ($jenis) => [
                    $jenis => $libur->where('is_active', true)->where('jenis', $jenis)->count(),
                ])
                ->all(),
            'sudah_lewat' => $libur->where('is_active', true)->filter(fn ($item) => $item->tanggal->isPast())->count(),
            'akan_datang' => $libur->where('is_active', true)->filter(fn ($item) => ! $item->tanggal->isPast())->count(),
        ];

        // Tahun yang tersedia di database — agar navigasi tahun tidak kosong.
        $tahunTersedia = HariLibur::query()
            ->selectRaw('YEAR(tanggal) as tahun')
            ->distinct()
            ->orderByDesc('tahun')
            ->pluck('tahun')
            ->map(fn ($t) => (int) $t)
            ->all();

        $daftarTahun = collect(range($tahun - self::RENTANG_TAHUN, $tahun + self::RENTANG_TAHUN))
            ->merge($tahunTersedia)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        return view('pengurus.hari-libur', [
            'libur' => $libur,
            'tahun' => $tahun,
            'daftarTahun' => $daftarTahun,
            'statistik' => $statistik,
            'labelJenis' => HariLibur::LABEL_JENIS,
            'daftarJenis' => HariLibur::daftarJenis(),
            'bolehUbah' => $this->bolehUbah(),
            'besok' => $this->contohGeser(now()->addDay()),
        ]);
    }

    public function store(Request $request)
    {
        $this->pastikanBerwenang();

        $validated = $request->validate($this->aturanValidasi(null), $this->pesanValidasi());
        $validated['jenis'] = ($validated['jenis'] ?? null) ?: HariLibur::JENIS_NASIONAL;
        $validated['is_active'] = $request->boolean('is_active', true);

        HariLibur::create($validated);

        return redirect()->route('pengurus.hari-libur.index', ['tahun' => $this->tahunDari($validated['tanggal'])])
            ->with('success', 'Hari libur '.$this->tanggalPanjang($validated['tanggal'])
                .' berhasil ditambahkan dan langsung dipakai penjadwalan angsuran.');
    }

    public function update(Request $request, HariLibur $hari_libur)
    {
        $this->pastikanBerwenang();

        $validated = $request->validate(
            $this->aturanValidasi($hari_libur->id),
            $this->pesanValidasi()
        );

        $validated['jenis'] = ($validated['jenis'] ?? null) ?: HariLibur::JENIS_NASIONAL;

        // Status aktif boleh diubah dari form edit; default pertahankan nilai lama.
        $validated['is_active'] = $request->has('is_active')
            ? $request->boolean('is_active')
            : $hari_libur->is_active;

        $hari_libur->update($validated);

        return redirect()->route('pengurus.hari-libur.index', ['tahun' => $this->tahunDari($validated['tanggal'])])
            ->with('success', 'Hari libur '.$this->tanggalPanjang($validated['tanggal']).' berhasil diperbarui.');
    }

    /**
     * Toggle status aktif / nonaktif.
     *
     * Nonaktif bersifat "soft disable": tanggal tetap tersimpan sebagai
     * catatan historis, namun tidak lagi menggeser jatuh tempo angsuran.
     */
    public function toggle(HariLibur $hari_libur)
    {
        $this->pastikanBerwenang();

        $hari_libur->update(['is_active' => ! $hari_libur->is_active]);

        $status = $hari_libur->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return redirect()->route('pengurus.hari-libur.index', ['tahun' => $hari_libur->tanggal->year])
            ->with('success', 'Hari libur '.$this->tanggalPanjang($hari_libur->tanggal).' berhasil '.$status.'.');
    }

    public function destroy(HariLibur $hari_libur)
    {
        $this->pastikanBerwenang();

        $tanggal = $this->tanggalPanjang($hari_libur->tanggal);

        $hari_libur->delete();

        return redirect()->route('pengurus.hari-libur.index', ['tahun' => $hari_libur->tanggal->year])
            ->with('success', 'Hari libur '.$tanggal.' berhasil dihapus.');
    }

    /* ── Bantuan ──────────────────────────────────────────────────────── */

    /**
     * Aturan validasi. `tanggal` unik — dua baris pada tanggal sama akan
     * membuat penjadwalan ambigu saat salah satunya dinonaktifkan.
     *
     * @return array<string, string>
     */
    private function aturanValidasi(?int $abaikanId): array
    {
        return [
            'tanggal' => 'required|date_format:Y-m-d|unique:hari_liburs,tanggal'
                .($abaikanId ? ','.$abaikanId : ''),
            'keterangan' => 'required|string|max:150',
            'jenis' => 'nullable|in:'.implode(',', HariLibur::daftarJenis()),
            'is_active' => 'nullable|boolean',
        ];
    }

    /** Pesan validasi berbahasa Indonesia. */
    private function pesanValidasi(): array
    {
        return [
            'tanggal.required' => 'Tanggal hari libur wajib diisi.',
            'tanggal.date_format' => 'Format tanggal harus YYYY-MM-DD (contoh 2026-12-25).',
            'tanggal.unique' => 'Tanggal tersebut sudah terdaftar sebagai hari libur. Ubah baris yang ada alih-alih menambah baris baru.',
            'keterangan.required' => 'Keterangan hari libur wajib diisi (contoh: Hari Raya Idul Fitri).',
            'keterangan.max' => 'Keterangan maksimal 150 karakter.',
            'jenis.in' => 'Jenis hari libur harus salah satu dari: '.implode(', ', HariLibur::daftarJenis()).'.',
        ];
    }

    /**
     * Perubahan kalender menggeser jadwal angsuran — dibatasi setara Manajer
     * ke atas (pola yang sama dengan modul master lain).
     *
     * @throws ValidationException
     */
    private function pastikanBerwenang(): void
    {
        if ($this->bolehUbah()) {
            return;
        }

        throw ValidationException::withMessages([
            'tanggal' => 'Perubahan kalender hari libur memerlukan kewenangan minimal Manajer.',
        ]);
    }

    private function bolehUbah(): bool
    {
        return HierarkiRole::level(auth()->user()?->role) >= HierarkiRole::level('manajer');
    }

    /** Petunjuk dampak: bila tanggal ini jatuh pada akhir pekan / libur, ke mana digeser. */
    private function contohGeser(Carbon $tanggal): array
    {
        $service = app(HariLiburService::class);

        return [
            'tanggal' => $tanggal->copy(),
            'hari_kerja' => $service->isHariKerja($tanggal),
            'hasil_geser' => $service->keHariKerja($tanggal),
            'aktif' => Setting::hariLiburAktif(),
            'strategi' => Setting::hariLiburStrategi(),
        ];
    }

    private function tahunDari(string $tanggal): int
    {
        return (int) Carbon::parse($tanggal)->year;
    }

    private function tanggalPanjang(\DateTimeInterface|string $tanggal): string
    {
        return Carbon::parse($tanggal)->locale('id')->translatedFormat('d F Y');
    }
}
