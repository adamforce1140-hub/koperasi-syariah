<?php

namespace Tests\Feature;

use App\Models\LaporanKeuanganLog;
use App\Models\User;
use App\Support\LaporanKeuanganPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tampilan & penyegaran dinamis Matriks Hak Akses pada halaman Audit Laporan
 * Keuangan (path: laporan/audit).
 *
 * Menguji tiga hal:
 *   1. Badge hijau "Lihat" benar-benar muncul untuk peran DPS pada kolom
 *      AUDIT LAPORAN, tanpa mengubah konfigurasi peran lain.
 *   2. Sel yang tidak punya hak menampilkan SATU badge abu "Tidak ada akses"
 *      (bukan lagi teks biasa), dan setiap jenis hak memakai kelas badge
 *      berwarna yang seragam.
 *   3. Endpoint JSON matriks menyajikan data yang identik dengan sumber
 *      kebenaran middleware, sehingga tabel bisa disegarkan tanpa reload
 *      halaman penuh.
 */
class MatriksHakAksesAuditTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Peran yang boleh membuka halaman audit.
     *
     * PEMISAHAN TUGAS: modul Audit Laporan Keuangan EKSKLUSIF milik pengawas
     * independen (DPS/SPI). Pihak yang diawasi (pengurus), pelaksana
     * (manajer), dan administrator teknis (super_admin/admin) tidak boleh
     * mengaudit pekerjaannya sendiri.
     */
    private const PERAN_BERWENANG = ['dps'];

    private array $pengguna = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (LaporanKeuanganPermission::PERAN as $peran) {
            $this->pengguna[$peran] = User::firstOrCreate(
                ['email' => "matriks.{$peran}@test.local"],
                [
                    'name' => 'Uji '.ucfirst($peran),
                    'password' => Hash::make('password'),
                    'role' => $peran,
                ]
            );
        }
    }

    private function sebagai(string $peran): self
    {
        $this->actingAs($this->pengguna[$peran]);

        return $this;
    }

    /** Isi kolom Audit Laporan pada baris sebuah peran, apa adanya. */
    private function selAuditLaporan(string $html, string $namaPeran): string
    {
        $pola = '/<td class="fw-semibold text-nowrap">'.preg_quote($namaPeran, '/')
              .'<\/td>(.*?)<\/tr>/s';

        $this->assertSame(
            1,
            preg_match($pola, $html, $cocok),
            "Baris peran {$namaPeran} tidak ditemukan pada tabel matriks."
        );

        $sel = [];
        preg_match_all('/<td>(.*?)<\/td>/s', $cocok[1], $sel);

        $this->assertNotEmpty($sel[1], "Kolom pada baris {$namaPeran} tidak terbaca.");

        return end($sel[1]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 1 — Badge hijau "Lihat" untuk DPS pada kolom AUDIT LAPORAN
    // ═════════════════════════════════════════════════════════════════════

    public function test_dps_menampilkan_badge_hijau_lihat_pada_kolom_audit_laporan(): void
    {
        $html = $this->sebagai('dps')
            ->get(route('audit.laporan.index'))
            ->assertOk()
            ->assertSee('Matriks Hak Akses Modul Laporan Keuangan')
            ->assertSee('Audit Laporan')
            ->getContent();

        $sel = $this->selAuditLaporan($html, 'DPS');

        // Badge "Lihat" = hak baca; "Ekspor" = hak mengunduh jejak audit
        // (CSV) sebagai bukti kerja pengawasan DPS.
        $this->assertStringContainsString(
            '<span class="matriks-hak ">Lihat</span>',
            $sel,
            'Badge hijau "Lihat" tidak muncul pada kolom Audit Laporan untuk DPS.'
        );

        // Selain Lihat & Ekspor, jenis hak lain tidak boleh ikut tampil.
        $this->assertStringNotContainsString('Unduh', $sel);
        $this->assertStringNotContainsString('Edit', $sel);
        $this->assertStringNotContainsString('Hapus', $sel);
        $this->assertStringContainsString('Ekspor', $sel);
        $this->assertStringNotContainsString('Verifikasi', $sel);
        $this->assertStringNotContainsString('Tidak ada akses', $sel);
    }

    public function test_konfigurasi_dps_tidak_melebar_ke_peran_lain(): void
    {
        // DPS: baca tiap modul laporan, plus LIHAT + EKSPOR pada modul audit.
        $this->assertTrue(LaporanKeuanganPermission::boleh('dps', LaporanKeuanganPermission::MODUL_AUDIT, LaporanKeuanganPermission::LIHAT));
        $this->assertTrue(LaporanKeuanganPermission::boleh('dps', LaporanKeuanganPermission::MODUL_AUDIT, LaporanKeuanganPermission::EKSPOR));
        // EKSPOR tidak melebar ke modul laporan (dokumen keuangan tetap view-only).
        $this->assertFalse(LaporanKeuanganPermission::boleh('dps', LaporanKeuanganPermission::MODUL_NERACA, LaporanKeuanganPermission::EKSPOR));
        $this->assertFalse(LaporanKeuanganPermission::boleh('dps', LaporanKeuanganPermission::MODUL_BUKU_BESAR, LaporanKeuanganPermission::EKSPOR));
        $this->assertFalse(LaporanKeuanganPermission::boleh('dps', LaporanKeuanganPermission::MODUL_LAPORAN_PSAK101, LaporanKeuanganPermission::EKSPOR));

        // PEMISAHAN TUGAS: modul Audit Laporan Keuangan eksklusif milik DPS.
        // Pihak yang diawasi (pengurus), pelaksana (manajer), dan administrator
        // teknis (super_admin/admin) tidak boleh mengaudit dirinya sendiri.
        $this->assertSame(
            ['dps'],
            LaporanKeuanganPermission::peranYangBoleh(LaporanKeuanganPermission::MODUL_AUDIT, LaporanKeuanganPermission::LIHAT)
        );
        $this->assertSame(
            ['dps'],
            LaporanKeuanganPermission::peranYangBoleh(LaporanKeuanganPermission::MODUL_AUDIT, LaporanKeuanganPermission::EKSPOR)
        );
        foreach (['super_admin', 'admin', 'pengurus', 'manajer'] as $peran) {
            $this->assertSame([], LaporanKeuanganPermission::hak($peran, LaporanKeuanganPermission::MODUL_AUDIT));
        }
        $this->assertSame([], LaporanKeuanganPermission::hak('teller', LaporanKeuanganPermission::MODUL_AUDIT));
        $this->assertSame([], LaporanKeuanganPermission::hak('ao', LaporanKeuanganPermission::MODUL_AUDIT));
    }

    // ═════════════════════════════════════════════════════════════════════
    // 2 — Keseragaman badge & "Tidak ada akses" sebagai badge
    // ═════════════════════════════════════════════════════════════════════

    public function test_sel_tanpa_hak_menampilkan_tepat_satu_badge_tidak_ada_akses(): void
    {
        $html = $this->sebagai('dps')
            ->get(route('audit.laporan.index'))
            ->assertOk()
            ->getContent();

        // Manajer tidak punya hak pada modul Laporan Operasional? — punya.
        // Teller tidak punya hak apa pun, jadi seluruh kolomnya "Tidak ada akses".
        $polaBarisTeller = '/<td class="fw-semibold text-nowrap">Teller<\/td>(.*?)<\/tr>/s';
        $this->assertSame(1, preg_match($polaBarisTeller, $html, $cocok));

        $jumlah = substr_count($cocok[1], '<span class="matriks-hak mh-tidak">Tidak ada akses</span>');

        $this->assertSame(
            count(LaporanKeuanganPermission::MODUL),
            $jumlah,
            'Setiap sel Teller (satu per modul) harus berisi tepat satu badge "Tidak ada akses".'
        );

        // Tidak boleh lagi berupa teks polos tanpa badge.
        $this->assertStringNotContainsString(
            '<span class="text-muted small">Tidak ada akses</span>',
            $html,
            'Teks "Tidak ada akses" masih dirender sebagai teks biasa, bukan badge.'
        );
    }

    public function test_jenis_hak_memakai_kelas_badge_berwarna_yang_seragam(): void
    {
        $html = $this->sebagai('dps')
            ->get(route('audit.laporan.index'))
            ->assertOk()
            ->getContent();

        // Pengurus melihat seluruh hak baca laporan (lihat/unduh/ekspor),
        // sedangkan hak menulis kini berada di modul Penyesuaian Jurnal
        // (edit/hapus) — jadi seluruh jenis badge tetap harus muncul di halaman.
        $this->assertStringContainsString('<span class="matriks-hak ">Lihat</span>', $html);
        $this->assertStringContainsString('<span class="matriks-hak mh-unduh">Unduh</span>', $html);
        $this->assertStringContainsString('<span class="matriks-hak mh-ekspor">Ekspor</span>', $html);
        $this->assertStringContainsString('<span class="matriks-hak mh-tidak">Tidak ada akses</span>', $html);

        // Badge edit/hapus tidak lagi berada di kolom laporan (pemisahan tugas),
        // tetapi tetap dirender untuk modul Penyesuaian Jurnal.
        $this->assertStringContainsString('<span class="matriks-hak mh-edit">Edit</span>', $html);
        $this->assertStringContainsString('<span class="matriks-hak mh-hapus">Hapus</span>', $html);

        // Kelas CSS warna tiap jenis hak terdefinisi di halaman.
        foreach (['mh-unduh', 'mh-edit', 'mh-hapus', 'mh-ekspor', 'mh-verifikasi', 'mh-tidak'] as $kelas) {
            $this->assertStringContainsString('.matriks-hak.'.$kelas, $html, "Kelas {$kelas} tidak terdefinisi.");
        }
    }

    public function test_hak_tulis_tidak_menempel_pada_modul_laporan(): void
    {
        // Inti pemisahan tugas (R19/B11): kolom laporan hanya berisi hak baca.
        $this->assertSame(
            ['lihat', 'unduh', 'ekspor'],
            LaporanKeuanganPermission::hak('pengurus', LaporanKeuanganPermission::MODUL_NERACA)
        );
        $this->assertSame(
            ['lihat', 'unduh', 'ekspor'],
            LaporanKeuanganPermission::hak('pengurus', LaporanKeuanganPermission::MODUL_BUKU_BESAR)
        );

        // Seluruh modul laporan bersifat hanya-baca bagi setiap peran.
        foreach (LaporanKeuanganPermission::modulLaporan() as $modul) {
            $this->assertTrue(
                LaporanKeuanganPermission::modulHanyaBaca($modul),
                "Modul laporan {$modul} tidak boleh punya hak edit/hapus."
            );
        }

        // Wewenang menulis terkumpul pada modul Penyesuaian Jurnal, hanya Pengurus.
        $this->assertSame(
            ['lihat', 'edit', 'hapus'],
            LaporanKeuanganPermission::hak('pengurus', LaporanKeuanganPermission::MODUL_PENYESUAIAN)
        );
        $this->assertSame([], LaporanKeuanganPermission::hak('manajer', LaporanKeuanganPermission::MODUL_PENYESUAIAN));
        $this->assertSame([], LaporanKeuanganPermission::hak('dps', LaporanKeuanganPermission::MODUL_PENYESUAIAN));
    }

    public function test_tabel_matriks_membawa_atribut_untuk_refresh_dinamis(): void
    {
        $html = $this->sebagai('dps')
            ->get(route('audit.laporan.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="kartuMatriks"', $html);
        $this->assertStringContainsString('data-refresh-url="'.route('audit.laporan.matriks').'"', $html);
        $this->assertStringContainsString('id="tabelMatriks"', $html);
        $this->assertStringContainsString('data-signature="', $html);

        // Pemicu refresh tanpa reload halaman penuh.
        $this->assertStringContainsString("document.addEventListener('visibilitychange'", $html);
        $this->assertStringContainsString("document.addEventListener('permission:changed'", $html);
        $this->assertStringContainsString('setInterval(segarkanMatriks', $html);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 3 — Endpoint JSON matriks (sumber data refresh)
    // ═════════════════════════════════════════════════════════════════════

    public function test_endpoint_matriks_mengembalikan_data_yang_sesuai_sumber_kebenaran(): void
    {
        $respon = $this->sebagai('dps')
            ->getJson(route('audit.laporan.matriks'))
            ->assertOk()
            ->assertJsonPath('matriks.dps.audit_laporan', ['lihat', 'ekspor'])
            ->assertJsonPath('matriks.teller.audit_laporan', [])
            ->assertJsonPath('labelAktivitas.lihat', 'Lihat');

        $data = $respon->json();

        // Setiap sel harus sama persis dengan hasil LaporanKeuanganPermission::hak().
        foreach (LaporanKeuanganPermission::PERAN as $peran) {
            foreach ($data['modul'] as $modul) {
                $this->assertSame(
                    LaporanKeuanganPermission::hak($peran, $modul),
                    $data['matriks'][$peran][$modul],
                    "Endpoint matriks tidak sinkron dengan sumber kebenaran pada {$peran}/{$modul}."
                );
            }
        }

        // Sidik jari (tanda) ikut dikirim agar klien bisa mendeteksi perubahan.
        $this->assertIsString($data['tanda']);
        $this->assertSame(64, strlen($data['tanda']));

        // Respons tidak boleh di-cache: matriks harus selalu segar.
        $this->assertStringContainsString('no-store', (string) $respon->headers->get('Cache-Control'));
    }

    public function test_tanda_matriks_stabil_dan_sama_dengan_yang_dirender_halaman(): void
    {
        $json = $this->sebagai('dps')->getJson(route('audit.laporan.matriks'))->assertOk()->json('tanda');

        $html = $this->sebagai('dps')->get(route('audit.laporan.index'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'data-signature="'.$json.'"',
            $html,
            'Sidik jari pada halaman tidak sama dengan yang dikirim endpoint matriks.'
        );
    }

    public function test_endpoint_matriks_tertolak_untuk_peran_tanpa_akses(): void
    {
        foreach (['teller', 'ao', 'anggota'] as $peran) {
            $this->sebagai($peran)->getJson(route('audit.laporan.matriks'))->assertForbidden();
        }
    }

    public function test_akses_matriks_tercatat_pada_audit_log(): void
    {
        $this->sebagai('dps')->getJson(route('audit.laporan.matriks'))->assertOk();

        $log = LaporanKeuanganLog::where('role', 'dps')
            ->where('route', 'audit.laporan.matriks')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log, 'Akses endpoint matriks tidak tercatat di audit log.');
        $this->assertSame(LaporanKeuanganPermission::MODUL_AUDIT, $log->modul);
        $this->assertSame(LaporanKeuanganPermission::LIHAT, $log->aktivitas);
        $this->assertTrue((bool) $log->berhasil);
    }

    public function test_halaman_audit_tetap_dapat_dibuka_semua_peran_berwenang(): void
    {
        foreach (self::PERAN_BERWENANG as $peran) {
            $this->sebagai($peran)->get(route('audit.laporan.index'))->assertOk();
        }
    }
}
