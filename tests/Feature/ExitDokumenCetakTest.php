<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\MemberTermination;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\ExitDocumentService;
use App\Services\ExitKeanggotaanService;
use App\Services\MandatorySavingsService;
use App\Support\Coa as KodeCoa;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * DOKUMEN CETAK — "Bukti Penutupan Keanggotaan & Tanda Terima Uang".
 *
 * Mengunci janji:
 *  1. GERBANG: dokumen hanya dapat dicetak bila jurnal SUDAH diposting DAN
 *     status DISETUJUI. Dua syarat, bukan satu.
 *  2. PDF A4 (dompdf) → response `application/pdf` + attachment.
 *  3. Thermal 80 mm → HTML; NIK WAJIB tersamar (kebijakan PDP), sedangkan
 *     PDF resmi memuat NIK lengkap untuk verifikasi identitas.
 *  4. Nomor referensi unik berformat `EXIT/YYYYMM/NNNNNN` + barcode Code 39
 *     + kode verifikasi HMAC-SHA256 pada kedua dokumen.
 *  5. RBAC: peran di luar `terminasi.pengajuan` tidak dapat mengunduh.
 */
class ExitDokumenCetakTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    /** Penomoran urut agar `no_anggota` tetap unik saat satu tes membuat >1 anggota. */
    private int $urutAnggota = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'DOK'.strtoupper(substr(uniqid(), -6));
        Setting::flush();
    }

    protected function tearDown(): void
    {
        Setting::flush();
        parent::tearDown();
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function user(string $role): User
    {
        return User::firstOrCreate(
            ['email' => "{$role}.{$this->prefix}@test.local"],
            [
                'name' => ucfirst($role).' '.$this->prefix,
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );
    }

    private function pastikanCoa(): void
    {
        foreach ([KodeCoa::SIMPANAN_POKOK, KodeCoa::SIMPANAN_WAJIB_MODAL, KodeCoa::KAS_TELLER] as $kode) {
            if (! Coa::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum tersedia/aktif — jalankan CoaSeeder.");
            }
        }
    }

    private function aturKebijakan(array $kolom): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? KebijakanKoperasi::create([]);
        $kebijakan->forceFill($kolom)->save();
        Setting::flush();
    }

    /** Anggota ber-NIK tetap + saldo Pokok & Wajib. */
    private function anggotaSiapExit(string $nik = '3174091203900001'): Anggota
    {
        $ops = $this->user('teller');
        $this->urutAnggota++;

        $anggota = Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix.'-'.$this->urutAnggota,
            'nik' => $nik,
            'nama_lengkap' => 'Anggota Dokumen '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Dokumen No. 7',
            'rt_rw' => '001/002',
            'kelurahan' => 'Kelurahan Uji',
            'kecamatan' => 'Kecamatan Uji',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'kode_pos' => '10110',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'is_auto_debit' => false,
            'tanggal_gabung' => '2026-01-01',
        ]);

        $jurnal = app(AutoJournalingService::class)->jurnalSetorSimpananPokok(
            100000,
            'Setoran Simpanan Pokok uji dokumen '.$this->prefix,
            $ops->id,
            null,
            null,
            $anggota->id
        );
        $this->assertNotNull($jurnal, 'Jurnal setoran Simpanan Pokok gagal diposting.');

        $tagihan = MandatorySavingsBill::create([
            'member_id' => $anggota->id,
            'bill_period' => '2026-01',
            'amount' => 50000,
            'status' => MandatorySavingsBill::STATUS_UNPAID,
        ]);
        app(MandatorySavingsService::class)->bayarViaTeller(
            $tagihan,
            MandatorySavingsBill::METODE_CASH,
            null,
            $ops->id
        );

        return $anggota->refresh();
    }

    /** Jalankan exit penuh sampai jurnal diposting (status DISETUJUI). */
    private function terminasiFinal(Anggota $anggota, ?User $operator = null): MemberTermination
    {
        $operator ??= $this->user('teller');
        $service = app(ExitKeanggotaanService::class);

        $terminasi = $service->ajukan($anggota, ['exit_reason' => 'Pindah domisili'], $operator->id);

        return $service->eksekusi($terminasi, MemberTermination::METODE_CASH, $operator->id);
    }

    /* =====================================================================
     | 1. GERBANG DOKUMEN
     |===================================================================== */

    public function test_dokumen_tidak_dapat_dicetak_sebelum_jurnal_diposting(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 0, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaSiapExit();
        $teller = $this->user('teller');
        $terminasi = app(ExitKeanggotaanService::class)->ajukan($anggota, [], $teller->id);

        $this->assertNull($terminasi->jurnal_id);

        $this->actingAs($teller)
            ->get(route('member-terminations.bukti-pdf', $terminasi->id))
            ->assertRedirect(route('member-terminations.show', $terminasi->id))
            ->assertSessionHas('error');

        $this->actingAs($teller)
            ->get(route('member-terminations.bukti-thermal', $terminasi->id))
            ->assertRedirect(route('member-terminations.show', $terminasi->id))
            ->assertSessionHas('error');
    }

    public function test_dokumen_tetap_terkunci_bila_jurnal_ada_tetapi_status_bukan_disetujui(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 0, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaSiapExit();
        $teller = $this->user('teller');
        $terminasi = $this->terminasiFinal($anggota, $teller);

        // Paksa status kembali DIAJUKAN lewat query builder (melewati guard
        // append-only model) untuk membuktikan syarat KEDUA benar-benar dicek.
        DB::table('member_terminations')
            ->where('id', $terminasi->id)
            ->update(['status' => MemberTermination::STATUS_DIAJUKAN]);

        $this->actingAs($teller)
            ->get(route('member-terminations.bukti-pdf', $terminasi->id))
            ->assertRedirect(route('member-terminations.show', $terminasi->id))
            ->assertSessionHas('error');
    }

    /* =====================================================================
     | 2. PDF A4
     |===================================================================== */

    public function test_pdf_dapat_diunduh_setelah_jurnal_diposting(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 0, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaSiapExit();
        $teller = $this->user('teller');
        $terminasi = $this->terminasiFinal($anggota, $teller);

        $response = $this->actingAs($teller)
            ->get(route('member-terminations.bukti-pdf', $terminasi->id));

        $response->assertOk();
        $this->assertStringContainsString(
            'application/pdf',
            (string) $response->headers->get('content-type'),
            'PDF wajib dikirim dengan Content-Type application/pdf.'
        );
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition'),
            'PDF wajib diunduh sebagai attachment.'
        );
        $this->assertStringContainsString(
            '.pdf',
            (string) $response->headers->get('content-disposition')
        );
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent(), 'Isi respons wajib berupa berkas PDF valid.');
    }

    public function test_pdf_memuat_nik_lengkap_data_anggota_dan_rincian_saldo(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 0, 'closure_admin_fee' => 0]);

        $nik = '3174091203900001';
        $anggota = $this->anggotaSiapExit($nik);
        $terminasi = $this->terminasiFinal($anggota);

        $data = app(ExitDocumentService::class)->dataDokumen($terminasi->refresh());
        $html = view('member-terminations.bukti-pdf', $data)->render();

        $this->assertStringContainsString($nik, $html, 'PDF resmi wajib memuat NIK lengkap.');
        $this->assertStringContainsString($anggota->nama_lengkap, $html);
        $this->assertStringContainsString((string) $anggota->no_cif, $html, 'No. CIF wajib tercetak.');
        $this->assertStringContainsString('Jl. Uji Dokumen No. 7', $html, 'Alamat anggota wajib tercetak.');
        $this->assertStringContainsString('TOTAL NET DITERIMA ANGGOTA', $html);
        $this->assertStringContainsString('Simpanan Pokok', $html);
        $this->assertStringContainsString('Simpanan Wajib', $html);
        $this->assertStringContainsString('Biaya Administrasi Penutupan CIF', $html);
        $this->assertStringContainsString('Potongan Zakat', $html);
        $this->assertStringContainsString('Anggota yang menerima dana', $html, 'Kolom tanda tangan anggota wajib ada.');
    }

    /* =====================================================================
     | 3. THERMAL 80 MM & KEBIJAKAN PII
     |===================================================================== */

    public function test_thermal_menampilkan_nik_tersamar_dan_tidak_membocorkan_nik_penuh(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 0, 'closure_admin_fee' => 0]);

        $nik = '3174091203900001';
        $anggota = $this->anggotaSiapExit($nik);
        $teller = $this->user('teller');
        $terminasi = $this->terminasiFinal($anggota, $teller);

        $response = $this->actingAs($teller)
            ->get(route('member-terminations.bukti-thermal', $terminasi->id));

        $response->assertOk();

        $html = (string) $response->getContent();

        // 16 digit → 12 bintang + 4 digit terakhir.
        $this->assertStringContainsString('************0001', $html, 'NIK pada struk wajib tersamar.');
        $this->assertStringNotContainsString($nik, $html, 'NIK lengkap TIDAK BOLEH bocor pada struk thermal (UU PDP).');
        $this->assertStringContainsString($terminasi->no_referensi, $html);
        $this->assertStringContainsString('TOTAL NET', strtoupper($html));
        $this->assertStringContainsString('80mm', $html, 'Struk wajib dideklarasikan untuk kertas 80 mm.');
    }

    /* =====================================================================
     | 4. NOMOR REFERENSI, BARCODE, KODE VERIFIKASI
     |===================================================================== */

    public function test_nomor_referensi_unik_dan_berformat_exit_tahunbulan_enam_digit(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 0, 'closure_admin_fee' => 0]);

        $teller = $this->user('teller');
        $service = app(ExitKeanggotaanService::class);

        $a = $service->ajukan($this->anggotaSiapExit('3174091203900011'), [], $teller->id);
        $b = $service->ajukan($this->anggotaSiapExit('3174091203900022'), [], $teller->id);

        foreach ([$a, $b] as $terminasi) {
            $this->assertMatchesRegularExpression(
                '/^EXIT\/\d{6}\/\d{6}$/',
                (string) $terminasi->no_referensi,
                'Format wajib EXIT/YYYYMM/NNNNNN.'
            );
            $this->assertSame(
                now()->format('Ym'),
                substr((string) $terminasi->no_referensi, 5, 6),
                'Segmen TahunBulan wajib mengikuti tanggal pengajuan.'
            );
        }

        $this->assertNotSame(
            $a->no_referensi,
            $b->no_referensi,
            'Nomor referensi wajib unik antar dokumen.'
        );
    }

    public function test_kedua_dokumen_memuat_barcode_dan_kode_verifikasi_hmac(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 0, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaSiapExit();
        $terminasi = $this->terminasiFinal($anggota);

        $data = app(ExitDocumentService::class)->dataDokumen($terminasi->refresh());

        $this->assertStringContainsString('<svg', (string) $data['barcode_svg'], 'Thermal memakai barcode SVG inline.');
        $this->assertStringStartsWith('data:image/svg+xml;base64,', (string) $data['barcode_uri'], 'PDF memakai barcode data-URI.');
        $this->assertMatchesRegularExpression(
            '/^[0-9A-F]{20}$/',
            (string) $data['kode_verifikasi'],
            'Kode verifikasi wajib berupa 20 karakter heksadesimal HMAC-SHA256.'
        );

        $htmlPdf = view('member-terminations.bukti-pdf', $data)->render();
        $this->assertStringContainsString('data:image/svg+xml;base64,', $htmlPdf);
        $this->assertStringContainsString($data['kode_verifikasi'], $htmlPdf);

        $htmlThermal = view('member-terminations.bukti-thermal', $data)->render();
        $this->assertStringContainsString('<svg', $htmlThermal);
        $this->assertStringContainsString($data['kode_verifikasi'], $htmlThermal);
    }

    /* =====================================================================
     | 5. RBAC DOKUMEN
     |===================================================================== */

    public function test_peran_tanpa_izin_tidak_dapat_mengunduh_dokumen(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 0, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaSiapExit();
        $terminasi = $this->terminasiFinal($anggota);

        // `super_admin` KELUAR dari daftar ini: sebagai puncak administratif
        // ia memegang `terminasi.pengajuan` (sama dengan teller/manajer/
        // pengurus), sehingga bukti penutupan keanggotaan terbuka baginya.
        // Yang TETAP tertutup adalah aksi persetujuan (`terminasi.approval`).
        foreach (['ao', 'admin', 'dps', 'anggota'] as $peran) {
            $this->actingAs($this->user($peran))
                ->get(route('member-terminations.bukti-pdf', $terminasi->id))
                ->assertForbidden("Peran {$peran} seharusnya 403 pada unduhan PDF.");

            $this->actingAs($this->user($peran))
                ->get(route('member-terminations.bukti-thermal', $terminasi->id))
                ->assertForbidden("Peran {$peran} seharusnya 403 pada struk thermal.");
        }
    }

    public function test_teller_dapat_mengunduh_kedua_dokumen(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 0, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaSiapExit();
        $teller = $this->user('teller');
        $terminasi = $this->terminasiFinal($anggota, $teller);

        $this->actingAs($teller)
            ->get(route('member-terminations.bukti-pdf', $terminasi->id))
            ->assertOk();

        $this->actingAs($teller)
            ->get(route('member-terminations.bukti-thermal', $terminasi->id))
            ->assertOk();
    }
}
