<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\KebijakanKoperasi;
use App\Models\MemberTermination;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BATCH 23 — RUTE MODUL PENGUNDURAN DIRI (`member-terminations`) YANG
 * SEBELUMNYA TANPA RUJUKAN TEST.
 *
 * Rute yang dikunci:
 *   - `member-terminations.index`          (daftar + filter + pencarian + ringkasan)
 *   - `member-terminations.create`         (formulir + pratinjau anggota terpilih)
 *   - `member-terminations.cari-anggota`   (JSON pratinjau kelayakan, NIK tersamar)
 *   - `member-terminations.tolak`          (keputusan penolakan + audit)
 *   - `member-terminations.batalkan`       (pembatalan pengajuan + audit)
 *
 * Janji yang diuji:
 *  1. Ringkasan hanya menghitung status yang relevan (DIAJUKAN +
 *     MENUNGGU_APPROVAL; total hanya DISETUJUI) — bukan seluruh baris.
 *  2. Filter status & pencarian teks benar-benar menyaring; status tak dikenal
 *     diabaikan tanpa galat 500.
 *  3. `cari-anggota` menemukan lewat No. CIF/No. Anggota/NIK (blind index) dan
 *     SELALU menyamarkan NIK (UU PDP No. 27/2022).
 *  4. Anggota TERMINATED atau yang masih punya pengajuan berjalan ditandai
 *     `boleh_ajukan = false` beserta alasannya.
 *  5. `tolak`/`batalkan` mengubah status + mencatat alasan/pelaku + audit,
 *     menolak keputusan ganda, dan menolak peran tanpa izin (403).
 */
class IntegritasModulTerminasiTest extends TestCase
{
    use DatabaseTransactions;

    private string $suffix;

    private User $teller;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'anggota',
            'member_terminations',
            'audit_logs',
            'kebijakan_koperasis',
        ] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                $this->markTestSkipped("Tabel {$tabel} belum tersedia.");
            }
        }

        $this->suffix = strtoupper(substr(uniqid(), -6));
        $this->teller = $this->user('teller');
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
            ['email' => "{$role}.b23.{$this->suffix}@test.local"],
            [
                'name' => ucfirst($role).' B23 '.$this->suffix,
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );
    }

    private function anggota(string $kode, string $status = Anggota::STATUS_ACTIVE, ?string $nik = null): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'B23-'.$this->suffix.'-'.$kode,
            'no_cif' => 'CIF-B23-'.$this->suffix.'-'.$kode,
            'nik' => $nik ?? ('3273'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT)),
            'nama_lengkap' => 'Anggota Terminasi '.$kode.' '.$this->suffix,
            'tempat_lahir' => 'Bandung',
            'tanggal_lahir' => '1990-01-15',
            'jenis_kelamin' => 'Laki-laki',
            'status_perkawinan' => 'Menikah',
            'alamat' => 'Jl. Uji Batch 23 No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => $status,
            'tanggal_gabung' => now()->subYear()->toDateString(),
        ]);
    }

    private function terminasi(Anggota $anggota, string $status = MemberTermination::STATUS_DIAJUKAN, float $net = 100000, float $dikembalikan = 0): MemberTermination
    {
        return MemberTermination::create([
            'anggota_id' => $anggota->id,
            'no_terminasi' => 'TRM-B23-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -5)),
            'no_referensi' => 'EXIT/'.now()->format('Ym').'/'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'status' => $status,
            'total_net' => $net,
            'total_dikembalikan' => $dikembalikan,
            'butuh_approval' => $status === MemberTermination::STATUS_MENUNGGU_APPROVAL,
            'diajukan_oleh' => $this->teller->id,
            'diajukan_pada' => now(),
            'tanggal_pengunduran_diri' => now()->toDateString(),
        ]);
    }

    private function aturKebijakan(array $kolom): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? KebijakanKoperasi::create([]);
        $kebijakan->forceFill($kolom)->save();
        Setting::flush();
    }

    /* =====================================================================
     | 1. INDEX — DAFTAR, FILTER, PENCARIAN, RINGKASAN
     |===================================================================== */

    public function test_index_menampilkan_daftar_dan_ringkasan_hanya_dari_status_relevan(): void
    {
        $anggota = $this->anggota('A');
        $diajukan = $this->terminasi($anggota, MemberTermination::STATUS_DIAJUKAN, 100000);
        $menunggu = $this->terminasi($anggota, MemberTermination::STATUS_MENUNGGU_APPROVAL, 200000);
        $disetujui = $this->terminasi($anggota, MemberTermination::STATUS_DISETUJUI, 300000, 300000);
        $ditolak = $this->terminasi($anggota, MemberTermination::STATUS_DITOLAK, 400000, 400000);

        $harapanDiajukan = MemberTermination::query()
            ->whereIn('status', [MemberTermination::STATUS_DIAJUKAN, MemberTermination::STATUS_MENUNGGU_APPROVAL])
            ->count();
        $harapanDisetujui = MemberTermination::query()
            ->where('status', MemberTermination::STATUS_DISETUJUI)
            ->count();
        $harapanTotal = (float) MemberTermination::query()
            ->where('status', MemberTermination::STATUS_DISETUJUI)
            ->sum('total_dikembalikan');

        $respons = $this->actingAs($this->teller)->get(route('member-terminations.index'));

        $respons->assertOk()
            ->assertViewIs('member-terminations.index')
            ->assertViewHas('ringkasan', fn ($r) => (int) $r['diajukan'] === $harapanDiajukan
                && (int) $r['disetujui'] === $harapanDisetujui
                && (float) $r['ditotal'] === $harapanTotal);

        $respons->assertViewHas('daftar', function ($paginator) use ($diajukan, $menunggu, $disetujui, $ditolak) {
            $id = $paginator->getCollection()->pluck('id')->all();

            return in_array($diajukan->id, $id, true)
                && in_array($menunggu->id, $id, true)
                && in_array($disetujui->id, $id, true)
                && in_array($ditolak->id, $id, true);
        });

        // Baris DITOLAK ber-nominal 400.000 TIDAK boleh masuk total ringkasan
        // (hanya status DISETUJUI yang dihitung).
        $this->assertNotEquals(
            100000.0 + 200000.0 + 300000.0 + 400000.0,
            $harapanTotal,
            'Ringkasan tidak boleh menjumlahkan seluruh status.'
        );
    }

    public function test_index_filter_status_menyaring_dan_status_tak_dikenal_diabaikan(): void
    {
        $anggota = $this->anggota('B');
        $ditolak = $this->terminasi($anggota, MemberTermination::STATUS_DITOLAK);
        $diajukan = $this->terminasi($anggota, MemberTermination::STATUS_DIAJUKAN);

        $this->actingAs($this->teller)
            ->get(route('member-terminations.index', ['status' => MemberTermination::STATUS_DITOLAK]))
            ->assertOk()
            ->assertViewHas('daftar', function ($paginator) use ($ditolak, $diajukan) {
                $koleksi = $paginator->getCollection();

                return $koleksi->every(fn ($t) => $t->status === MemberTermination::STATUS_DITOLAK)
                    && $koleksi->contains('id', $ditolak->id)
                    && ! $koleksi->contains('id', $diajukan->id);
            });

        // Nilai status liar tidak boleh menghasilkan galat server.
        $this->actingAs($this->teller)
            ->get(route('member-terminations.index', ['status' => 'STATUS_TIDAK_ADA']))
            ->assertOk()
            ->assertViewHas('daftar', fn ($paginator) => $paginator->getCollection()->contains('id', $ditolak->id)
                && $paginator->getCollection()->contains('id', $diajukan->id));
    }

    public function test_index_pencarian_menyaring_berdasarkan_nama_anggota_dan_nomor_terminasi(): void
    {
        $anggotaA = $this->anggota('CARI-A');
        $anggotaB = $this->anggota('CARI-B');
        $milikA = $this->terminasi($anggotaA);
        $milikB = $this->terminasi($anggotaB);

        $this->actingAs($this->teller)
            ->get(route('member-terminations.index', ['cari' => $anggotaA->nama_lengkap]))
            ->assertOk()
            ->assertViewHas('daftar', fn ($p) => $p->getCollection()->contains('id', $milikA->id)
                && ! $p->getCollection()->contains('id', $milikB->id));

        $this->actingAs($this->teller)
            ->get(route('member-terminations.index', ['cari' => $milikB->no_terminasi]))
            ->assertOk()
            ->assertViewHas('daftar', fn ($p) => $p->getCollection()->contains('id', $milikB->id)
                && ! $p->getCollection()->contains('id', $milikA->id));
    }

    /* =====================================================================
     | 2. CREATE — FORMULIR & PRATINJAU ANGGOTA TERPILIH
     |===================================================================== */

    public function test_create_menampilkan_formulir_tanpa_dan_dengan_anggota_terpilih(): void
    {
        $anggota = $this->anggota('D');

        $this->actingAs($this->teller)
            ->get(route('member-terminations.create'))
            ->assertOk()
            ->assertViewIs('member-terminations.create')
            ->assertViewHas('anggotaTerpilih', fn ($v) => $v === null);

        $this->actingAs($this->teller)
            ->get(route('member-terminations.create', ['anggota_id' => $anggota->id]))
            ->assertOk()
            ->assertViewHas('anggotaTerpilih', fn ($v) => $v !== null && (int) $v->id === (int) $anggota->id);

        // ID yang tidak ada tidak boleh memicu galat 500 — cukup null.
        $this->actingAs($this->teller)
            ->get(route('member-terminations.create', ['anggota_id' => 999999999]))
            ->assertOk()
            ->assertViewHas('anggotaTerpilih', fn ($v) => $v === null);
    }

    /* =====================================================================
     | 3. CARI ANGGOTA — JSON PRATINJAU KELAYAKAN
     |===================================================================== */

    public function test_cari_anggota_menemukan_lewat_no_anggota_dan_menyamarkan_nik(): void
    {
        $this->aturKebijakan(['closure_admin_fee' => 0, 'high_value_withdrawal_limit' => 0]);
        $nik = '3273010101010001';
        $anggota = $this->anggota('E', Anggota::STATUS_ACTIVE, $nik);

        $respons = $this->actingAs($this->teller)
            ->getJson(route('member-terminations.cari-anggota', ['q' => $anggota->no_anggota]));

        $respons->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('anggota.id', (int) $anggota->id)
            ->assertJsonPath('anggota.nik_masked', '************0001');

        $this->assertStringNotContainsString(
            $nik,
            $respons->getContent(),
            'NIK penuh tidak boleh dikirim ke klien (UU PDP No. 27/2022).'
        );

        // NIK penuh harus tetap dapat dicari lewat blind index `nik_hash`.
        $this->actingAs($this->teller)
            ->getJson(route('member-terminations.cari-anggota', ['q' => $nik]))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('anggota.id', (int) $anggota->id);

        // No. CIF juga menjadi kunci pencarian.
        $this->actingAs($this->teller)
            ->getJson(route('member-terminations.cari-anggota', ['q' => $anggota->no_cif]))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('anggota.id', (int) $anggota->id);
    }

    public function test_cari_anggota_menolak_kata_kunci_kurang_dari_tiga_karakter(): void
    {
        $this->actingAs($this->teller)
            ->getJson(route('member-terminations.cari-anggota', ['q' => 'ab']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');

        $this->actingAs($this->teller)
            ->getJson(route('member-terminations.cari-anggota'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_cari_anggota_tidak_ditemukan_mengembalikan_found_false(): void
    {
        $this->actingAs($this->teller)
            ->getJson(route('member-terminations.cari-anggota', ['q' => 'TIDAK-ADA-'.$this->suffix]))
            ->assertOk()
            ->assertJsonPath('found', false)
            ->assertJsonStructure(['found', 'message']);
    }

    public function test_cari_anggota_terminated_ditandai_tidak_boleh_ajukan(): void
    {
        $this->aturKebijakan(['closure_admin_fee' => 0, 'high_value_withdrawal_limit' => 0]);
        $anggota = $this->anggota('F', Anggota::STATUS_TERMINATED);

        $respons = $this->actingAs($this->teller)
            ->getJson(route('member-terminations.cari-anggota', ['q' => $anggota->no_anggota]));

        $respons->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('boleh_ajukan', false);

        $kendala = $respons->json('kendala');
        $this->assertIsArray($kendala);
        $this->assertTrue(
            collect($kendala)->contains(fn ($k) => str_contains((string) $k, 'TERMINATED')),
            'Anggota TERMINATED wajib memunculkan kendala eksplisit.'
        );
    }

    public function test_cari_anggota_dengan_pengajuan_berjalan_menandai_kendala_pending(): void
    {
        $this->aturKebijakan(['closure_admin_fee' => 0, 'high_value_withdrawal_limit' => 0]);
        $anggota = $this->anggota('G');
        $pending = $this->terminasi($anggota, MemberTermination::STATUS_DIAJUKAN);

        $respons = $this->actingAs($this->teller)
            ->getJson(route('member-terminations.cari-anggota', ['q' => $anggota->no_anggota]));

        $respons->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('boleh_ajukan', false)
            ->assertJsonPath('pending.id', (int) $pending->id);

        $this->assertTrue(
            collect($respons->json('kendala'))->contains(fn ($k) => str_contains((string) $k, 'belum selesai')),
            'Pengajuan berjalan harus dilaporkan sebagai kendala.'
        );
    }

    /* =====================================================================
     | 4. TOLAK — KEPUTUSAN PENOLAKAN
     |===================================================================== */

    public function test_tolak_mengubah_status_mencatat_alasan_dan_audit(): void
    {
        $anggota = $this->anggota('H');
        $terminasi = $this->terminasi($anggota);

        $this->actingAs($this->teller)
            ->post(route('member-terminations.tolak', $terminasi->id), [
                'alasan_penolakan' => 'Dokumen identitas tidak lengkap.',
            ])
            ->assertRedirect(route('member-terminations.show', $terminasi->id))
            ->assertSessionHas('success');

        $segar = $terminasi->refresh();
        $this->assertSame(MemberTermination::STATUS_DITOLAK, $segar->status);
        $this->assertSame('Dokumen identitas tidak lengkap.', $segar->alasan_penolakan);
        $this->assertSame((int) $this->teller->id, (int) $segar->diproses_oleh);
        $this->assertNotNull($segar->diproses_pada);

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('jenis_transaksi', 'EXIT_KEANGGOTAAN_DITOLAK')
                ->where('keterangan', 'like', '%'.$terminasi->no_referensi.'%')
                ->count(),
            'Penolakan wajib meninggalkan jejak audit.'
        );
    }

    public function test_tolak_tanpa_alasan_gagal_validasi_dan_status_tidak_berubah(): void
    {
        $anggota = $this->anggota('I');
        $terminasi = $this->terminasi($anggota);

        $this->actingAs($this->teller)
            ->from(route('member-terminations.show', $terminasi->id))
            ->post(route('member-terminations.tolak', $terminasi->id), [])
            ->assertRedirect(route('member-terminations.show', $terminasi->id))
            ->assertSessionHasErrors('alasan_penolakan');

        $this->assertSame(MemberTermination::STATUS_DIAJUKAN, $terminasi->refresh()->status);
    }

    public function test_tolak_dokumen_yang_sudah_ditolak_menolak_dengan_pesan_tanpa_mengubah_data(): void
    {
        $anggota = $this->anggota('J');
        $terminasi = $this->terminasi($anggota, MemberTermination::STATUS_DITOLAK);
        $terminasi->forceFill(['alasan_penolakan' => 'Alasan asli.'])->save();

        $this->actingAs($this->teller)
            ->from(route('member-terminations.show', $terminasi->id))
            ->post(route('member-terminations.tolak', $terminasi->id), [
                'alasan_penolakan' => 'Alasan baru.',
            ])
            ->assertRedirect(route('member-terminations.show', $terminasi->id))
            ->assertSessionHas('error');

        $segar = $terminasi->refresh();
        $this->assertSame(MemberTermination::STATUS_DITOLAK, $segar->status);
        $this->assertSame('Alasan asli.', $segar->alasan_penolakan, 'Keputusan terminal tidak boleh ditimpa.');
    }

    /* =====================================================================
     | 5. BATALKAN — PEMBATALAN PENGAJUAN
     |===================================================================== */

    public function test_batalkan_mengubah_status_dan_alasan_opsional(): void
    {
        $anggota = $this->anggota('K');
        $terminasi = $this->terminasi($anggota);

        $this->actingAs($this->teller)
            ->post(route('member-terminations.batalkan', $terminasi->id))
            ->assertRedirect(route('member-terminations.show', $terminasi->id))
            ->assertSessionHas('success');

        $segar = $terminasi->refresh();
        $this->assertSame(MemberTermination::STATUS_DIBATALKAN, $segar->status);
        $this->assertNull($segar->alasan_penolakan);
        $this->assertSame((int) $this->teller->id, (int) $segar->diproses_oleh);

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('jenis_transaksi', 'EXIT_KEANGGOTAAN_DIBATALKAN')
                ->where('keterangan', 'like', '%'.$terminasi->no_referensi.'%')
                ->count()
        );
    }

    public function test_batalkan_ditolak_untuk_dokumen_yang_sudah_disetujui(): void
    {
        $anggota = $this->anggota('L');
        $terminasi = $this->terminasi($anggota, MemberTermination::STATUS_DISETUJUI, 150000, 150000);

        $this->actingAs($this->teller)
            ->from(route('member-terminations.show', $terminasi->id))
            ->post(route('member-terminations.batalkan', $terminasi->id), [
                'alasan_penolakan' => 'Coba batalkan.',
            ])
            ->assertRedirect(route('member-terminations.show', $terminasi->id))
            ->assertSessionHas('error');

        $segar = $terminasi->refresh();
        $this->assertSame(MemberTermination::STATUS_DISETUJUI, $segar->status);
        $this->assertNull($segar->alasan_penolakan);
    }

    /* =====================================================================
     | 6. RBAC — PERAN DI LUAR DAFTAR IZIN
     |===================================================================== */

    public function test_peran_tanpa_izin_ditolak_403_pada_seluruh_rute_terminasi(): void
    {
        $anggota = $this->anggota('M');
        $terminasi = $this->terminasi($anggota);
        $anggotaBiasa = $this->user('anggota');

        $this->actingAs($anggotaBiasa)->get(route('member-terminations.index'))->assertForbidden();
        $this->actingAs($anggotaBiasa)->get(route('member-terminations.create'))->assertForbidden();
        $this->actingAs($anggotaBiasa)
            ->getJson(route('member-terminations.cari-anggota', ['q' => $anggota->no_anggota]))
            ->assertForbidden();
        $this->actingAs($anggotaBiasa)
            ->post(route('member-terminations.tolak', $terminasi->id), ['alasan_penolakan' => 'Uji RBAC'])
            ->assertForbidden();
        $this->actingAs($anggotaBiasa)
            ->post(route('member-terminations.batalkan', $terminasi->id))
            ->assertForbidden();

        $this->assertSame(MemberTermination::STATUS_DIAJUKAN, $terminasi->refresh()->status);
    }

    public function test_manajer_dan_pengurus_dapat_membuka_daftar_terminasi(): void
    {
        foreach (['manajer', 'pengurus'] as $peran) {
            $this->actingAs($this->user($peran))
                ->get(route('member-terminations.index'))
                ->assertOk();
        }
    }
}
