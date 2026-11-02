<?php

namespace App\Http\Controllers;

use App\Models\Coa;
use App\Models\KebijakanKoperasi;
use App\Support\Setting;
use Illuminate\Http\Request;

/**
 * Manajemen 'Pengaturan Kebijakan Koperasi' (ZERO-HARDCODING POLICY).
 *
 * Halaman ini adalah pintu masuk RESMI bagi pengurus untuk mengubah SELURUH
 * parameter bisnis koperasi (nominal Simpanan Pokok/Wajib, persentase alokasi
 * SHU, ambang kolektibilitas, batas kas teller, parameter PSAK/AML, dst.)
 * TANPA perlu memodifikasi kode program sumber.
 *
 * Arsitektur:
 *   - Sumber data tunggal tetap tabel `kebijakan_koperasis` (satu baris,
 *     banyak kolom) — sesuai keputusan arsitektur "petakan ke tabel yang ada".
 *   - Metadata `key` / `type` / `description` dipublikasikan oleh
 *     App\Support\Setting (TIPE, DESKRIPSI) sehingga halaman ini dapat
 *     menampilkan daftar parameter secara generik & auditable.
 *   - Penyimpanan tetap melalui KebijakanKoperasi → event `saved` otomatis
 *     memanggil Setting::flush() (Cache Clear Observer) sehingga nilai baru
 *     langsung berlaku di seluruh aplikasi.
 *   - Halaman ini adalah ALIAS tampilan dari form kebijakan yang sudah ada;
 *     ia menggunakan view & endpoint penyimpanan yang sama supaya tidak ada
 *     dua jalur logika yang bisa berbeda (single source of truth).
 */
class AdminSettingsController extends Controller
{
    /**
     * Daftar parameter yang termasuk kategori operasional Teller & Simpanan.
     *
     * Dipakai untuk menyorot parameter yang paling sering disesuaikan
     * pengurus pada halaman ini (informasi baca-saja; sumber nilai tetap
     * satu baris kebijakan yang sama).
     *
     * @var list<string>
     */
    private const PARAMETER_UTAMA = [
        'nama_koperasi',
        'mandatory_principal_savings',
        'mandatory_monthly_savings',
        'minimum_pembukaan_rekening',
        'maksimum_pembukaan_rekening',
        'shu_hold_on_overdue',
        'simpanan_wajib_kanal_default',
        'simpanan_wajib_boleh_ditarik',
        'simpanan_wajib_hari_penarikan',
        'account_simpanan_wajib_id',
        'simpanan_wajib_auto_debit_aktif',
        'simpanan_wajib_penanganan_dinamis',
        'simpanan_wajib_maks_cicilan',
        'simpanan_wajib_reschedule_bulan',
        'simpanan_wajib_maks_reschedule',
        'simpanan_wajib_notif_anonim',
        'simpanan_wajib_ambang_kurang_ringan',
        'teller_max_cash_limit',
        'minimum_transaksi_teller',
        'limit_penarikan_teller',
        'limit_setoran_teller',
    ];

    public function index(Request $request)
    {
        $kebijakan = KebijakanKoperasi::getSetting();
        $riwayat = KebijakanKoperasi::riwayatPerubahan(30);

        $akunCoa = Coa::query()
            ->where('is_active', true)
            ->orderBy('kode_akun')
            ->get(['id', 'kode_akun', 'nama_akun']);

        // Metadata generik (key/value/type/description) — dipublikasikan agar
        // UI Pengaturan Kebijakan menampilkan parameter beserta tipe & fungsi
        // tanpa harus menyalin daftarnya ke dalam Blade (zero-hardcoding).
        $metadata = collect(Setting::DEFAULTS)->keys()->mapWithKeys(function (string $key): array {
            return [$key => [
                'key' => $key,
                'value' => Setting::get($key),
                'type' => Setting::tipe($key),
                'description' => Setting::deskripsi($key),
            ]];
        })->all();

        $parameterUtama = collect(self::PARAMETER_UTAMA)
            ->filter(fn (string $key): bool => array_key_exists($key, $metadata))
            ->mapWithKeys(fn (string $key): array => [$key => $metadata[$key]])
            ->all();

        return view('pengurus.kebijakan', compact(
            'kebijakan',
            'riwayat',
            'akunCoa',
            'metadata',
            'parameterUtama',
        ));
    }

    /**
     * Bersihkan cache pengaturan secara manual.
     *
     * Normalnya cache dibersihkan OTOMATIS oleh KebijakanKoperasi::booted()
     * setiap kali baris kebijakan disimpan. Tombol ini disediakan sebagai
     * jalur pemulihan (recovery) bila cache eksternal (mis. Redis) diduga
     * masih menyimpan nilai lama setelah perubahan manual di level database.
     */
    public function clearCache(Request $request)
    {
        Setting::refresh();

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Cache pengaturan kebijakan berhasil dibersihkan dan dimuat ulang dari database.');
    }
}
