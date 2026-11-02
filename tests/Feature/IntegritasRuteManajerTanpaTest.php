<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\AutodebitUsulan;
use App\Models\Coa;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\RestrukturisasiRequest;
use App\Models\TazirPenangguhan;
use App\Models\TazirReliefRequest;
use App\Models\User;
use App\Support\HierarkiRole;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BATCH 25 — RUTE `manajer` YANG BELUM PERNAH DIRUJUK TEST (14 rute).
 *
 *   CIF (izin `cif.manajer`)
 *     M1  manajer.cif.index              GET  /manajer/cif
 *     M2  manajer.cif.show               GET  /manajer/cif/{id}
 *     M3  manajer.cif.edit               GET  /manajer/cif/{id}/edit
 *     M4  manajer.cif.update             PUT  /manajer/cif/{id}
 *     M5  manajer.cif.export             GET  /manajer/cif/export?export=excel
 *
 *   Restrukturisasi 3R (izin `manajer.operasional`)
 *     M6  manajer.approval-restrukturisasi.index     GET  /manajer/approval-restrukturisasi
 *     M7  manajer.approval-restrukturisasi.approve   POST /manajer/approval-restrukturisasi/{id}/approve
 *     M8  manajer.approval-restrukturisasi.reject    POST /manajer/approval-restrukturisasi/{id}/reject
 *     M9  manajer.approval-restrukturisasi.addendum  GET  /manajer/approval-restrukturisasi/{id}/addendum
 *
 *   Portofolio & approval pembiayaan
 *     M10 manajer.approval-pembiayaan.show           GET  /manajer/approval-pembiayaan/{id}
 *     M11 manajer.pembiayaan.show                    GET  /manajer/pembiayaan/{id}
 *
 *   Keringanan ta'zir
 *     M12 manajer.keringanan-tazir.index             GET  /manajer/keringanan-tazir
 *     M13 manajer.keringanan-tazir.approve           POST /manajer/keringanan-tazir/{relief}/approve
 *     M14 manajer.keringanan-tazir.reject            POST /manajer/keringanan-tazir/{relief}/reject
 *
 * Fokus integritas:
 *  - Daftar & portofolio CIF hanya memuat data yang diminta; ekspor menghormati
 *    batas volume peran (UU PDP 27/2022 — tolak, bukan potong diam-diam).
 *  - `CifController::update` untuk Manajer TIDAK boleh mengubah status anggota
 *    (`pengubah_status_anggota` hanya admin & pengurus) — field harus dibuang
 *    tanpa galat, bukan diam-diam tersimpan.
 *  - Approval restrukturisasi wajib menjaga sisa piutang (Fatwa DSN-MUI 49):
 *    total pokok+margin jadwal baru tepat sama dengan sisa piutang snapshot.
 *  - Pemisahan tugas: pengaju tidak boleh memutus pengajuannya sendiri.
 *  - Penolakan keringanan ta'zir TIDAK memotong saldo — hanya menerbitkan
 *    usulan auto-debit (cash basis, PSAK 109).
 *  - Pagar RBAC menolak peran tanpa izin `cif.manajer` / `manajer.operasional`.
 */
class IntegritasRuteManajerTanpaTest extends TestCase
{
    use DatabaseTransactions;

    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['anggota', 'pembiayaan', 'angsuran', 'rekening', 'akun',
            'tazir_relief_requests', 'tazir_penangguhans', 'restrukturisasi_requests',
            'autodebit_usulans'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                $this->markTestSkipped("Tabel {$tabel} belum tersedia pada basis data uji.");
            }
        }

        if (Coa::query()->count() === 0) {
            $this->markTestSkipped('CoA belum tersedia pada basis data uji.');
        }

        $this->suffix = strtoupper(substr(uniqid(), -6));
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

    private function user(string $role, string $penanda): User
    {
        return User::firstOrCreate(
            ['email' => strtolower($role).'.'.$penanda.'.b25.'.strtolower($this->suffix).'@test.local'],
            [
                'name' => 'B25 '.$role.' '.$penanda,
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function manajer(string $penanda = 'pemutus'): User
    {
        return $this->user('manajer', $penanda);
    }

    private function anggota(string $kode = 'A', string $status = Anggota::STATUS_ACTIVE): Anggota
    {
        return Anggota::create([
            'no_anggota' => $kode.'-'.$this->suffix,
            'no_cif' => 'CIF-B25-'.$this->suffix.'-'.$kode,
            'nik' => '3275'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Manajer '.$kode.' '.$this->suffix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Batch 25 No. '.$kode,
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_hp' => '0812'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status_anggota' => $status,
            'tanggal_gabung' => now()->subYear()->toDateString(),
        ]);
    }

    private function akunLegacyId(): int
    {
        $id = Akun::query()->value('id');

        if (! $id) {
            $this->markTestSkipped('Tabel `akun` kosong — jalankan AkunSeeder.');
        }

        return (int) $id;
    }

    private function rekening(Anggota $anggota, float $saldo = 0): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-B25-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -5)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunLegacyId(),
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    /** Pembiayaan murabahah 10 tenor × Rp 120.000 (pokok 100.000 + margin 20.000). */
    private function pembiayaan(Anggota $anggota, string $status = Pembiayaan::STATUS_AKTIF): Pembiayaan
    {
        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-B25-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -4)),
            'anggota_id' => $anggota->id,
            'akun_id' => (int) (Coa::query()->value('id') ?? 1),
            'akad_pembiayaan' => 'Murabahah',
            'jumlah_pembiayaan' => 1000000.0,
            'total_pembiayaan' => 1200000.0,
            'total_ujroh' => 200000.0,
            'sisa_pembiayaan' => 1200000.0,
            'tenor' => 10,
            'jumlah_angsuran' => 120000.0,
            'margin_deferred_amount' => 200000.0,
            'tanggal_akad' => now()->subMonths(6)->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(10)->toDateString(),
            'status_pembiayaan' => $status,
        ]);

        for ($ke = 1; $ke <= 10; $ke++) {
            Angsuran::create([
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_ke' => $ke,
                'tanggal_jatuh_tempo' => now()->addMonths($ke)->toDateString(),
                'pokok_angsuran' => 100000.0,
                'margin_ujroh' => 20000.0,
                'jumlah_angsuran' => 120000.0,
                'sisa_pokok' => 0.0,
                'status_angsuran' => 'Belum Jatuh Tempo',
            ]);
        }

        return $pembiayaan;
    }

    /** Tandai angsuran pertama menunggak `$hari` hari (opsional dengan denda ta'zir). */
    private function tandaiTerlambat(Pembiayaan $pembiayaan, int $hari, float $denda = 0.0): Angsuran
    {
        $angsuran = Angsuran::query()
            ->where('pembiayaan_id', $pembiayaan->id)
            ->where('angsuran_ke', 1)
            ->firstOrFail();

        $angsuran->update([
            'tanggal_jatuh_tempo' => now()->subDays($hari)->toDateString(),
            'status_angsuran' => 'Terlambat',
            'denda' => $denda,
            'status_tazir' => $denda > 0 ? 'BELUM_LUNAS' : null,
        ]);

        return $angsuran->fresh();
    }

    /** Pengajuan restrukturisasi PENDING_APPROVAL dengan snapshot dari service. */
    private function pengajuanRestrukturisasi(Pembiayaan $pembiayaan, int $aoId, int $tenorBaru = 5, string $status = RestrukturisasiRequest::STATUS_PENDING_APPROVAL): RestrukturisasiRequest
    {
        return RestrukturisasiRequest::create([
            'pembiayaan_id' => $pembiayaan->id,
            'ao_id' => $aoId,
            'skema_restrukturisasi' => RestrukturisasiRequest::SKEMA_RESCHEDULING,
            'tenor_baru' => $tenorBaru,
            'grace_period' => 0,
            'tanggal_jatuh_tempo_baru' => now()->addMonth()->toDateString(),
            'alasan_restrukturisasi' => 'Uji batch 25 — kemampuan bayar menurun.',
            'kemampuan_bayar_baru' => 240000.0,
            'sisa_pokok_snapshot' => 1000000.0,
            'sisa_margin_snapshot' => 200000.0,
            'sisa_piutang_snapshot' => 1200000.0,
            'angsuran_lama_snapshot' => 120000.0,
            'status' => $status,
        ]);
    }

    /** Pengajuan keringanan ta'zir pada tahap keputusan Manajer. */
    private function reliefPendingManajer(User $teller, Pembiayaan $pembiayaan, float $nominal = 30000.0): TazirReliefRequest
    {
        $angsuran = Angsuran::query()
            ->where('pembiayaan_id', $pembiayaan->id)
            ->where('angsuran_ke', 1)
            ->firstOrFail();

        return TazirReliefRequest::create([
            'jadwal_angsuran_id' => $angsuran->id,
            'pembiayaan_id' => $pembiayaan->id,
            'anggota_id' => $pembiayaan->anggota_id,
            'nominal_tazir_awal' => $nominal,
            'nominal_tazir_disetujui' => 0,
            'alasan_teller' => 'Uji batch 25 — anggota mengajukan keringanan.',
            'teller_id' => $teller->id,
            'status' => TazirReliefRequest::STATUS_PENDING_MANAJER,
        ]);
    }

    /* =====================================================================
     | M1 — DAFTAR CIF MANAJER
     |===================================================================== */

    public function test_cif_index_menampilkan_daftar_dan_mencari_berdasarkan_no_cif(): void
    {
        $manajer = $this->manajer();
        $dicari = $this->anggota('DICARI');
        $lain = $this->anggota('LAIN');

        $this->actingAs($manajer)
            ->get(route('manajer.cif.index'))
            ->assertOk()
            ->assertViewIs('manajer.cif.index')
            ->assertViewHas('anggota', fn ($p) => $p->getCollection()->pluck('id')->contains($dicari->id));

        $this->actingAs($manajer)
            ->get(route('manajer.cif.index', ['search' => $dicari->no_cif]))
            ->assertOk()
            ->assertViewHas('anggota', function ($p) use ($dicari, $lain) {
                $ids = $p->getCollection()->pluck('id');

                return $ids->contains($dicari->id) && ! $ids->contains($lain->id);
            })
            ->assertSee($dicari->nama_lengkap)
            ->assertDontSee($lain->nama_lengkap);
    }

    public function test_cif_index_filter_status_menerima_alias_dan_nilai_kanonik(): void
    {
        $manajer = $this->manajer();
        $aktif = $this->anggota('AKTIF', Anggota::STATUS_ACTIVE);
        $beku = $this->anggota('BEKU', Anggota::STATUS_SUSPENDED);

        foreach (['Aktif', Anggota::STATUS_ACTIVE] as $nilai) {
            $this->actingAs($manajer)
                ->get(route('manajer.cif.index', ['status' => $nilai]))
                ->assertOk()
                ->assertViewHas('anggota', function ($p) use ($aktif, $beku) {
                    $ids = $p->getCollection()->pluck('id');

                    return $ids->contains($aktif->id) && ! $ids->contains($beku->id);
                })
                ->assertViewHas('statusFilter', Anggota::STATUS_ACTIVE);
        }
    }

    public function test_cif_index_menolak_tamu_dengan_pengalihan_ke_login(): void
    {
        $this->get(route('manajer.cif.index'))->assertRedirect(route('login'));
    }

    /* =====================================================================
     | M2 — PORTOFOLIO CIF
     |===================================================================== */

    public function test_cif_show_menampilkan_portofolio_anggota(): void
    {
        $manajer = $this->manajer();
        $anggota = $this->anggota('PORTO');
        $pembiayaan = $this->pembiayaan($anggota);

        $this->actingAs($manajer)
            ->get(route('manajer.cif.show', $anggota->id))
            ->assertOk()
            ->assertViewIs('manajer.cif.show')
            ->assertViewHas('anggota', fn ($a) => (int) $a->id === (int) $anggota->id)
            ->assertViewHas('pembiayaanAktif', fn ($p) => $p->pluck('id')->contains($pembiayaan->id))
            ->assertSee($anggota->nama_lengkap)
            ->assertSee($anggota->no_cif);
    }

    public function test_cif_show_partial_deposito_mengembalikan_fragmen_tanpa_layout(): void
    {
        $manajer = $this->manajer();
        $anggota = $this->anggota('PARTIAL');

        $respons = $this->actingAs($manajer)
            ->get(route('manajer.cif.show', ['id' => $anggota->id, 'partial' => 'deposito']))
            ->assertOk()
            ->assertSee('deposito-bilyet-card', false);

        $this->assertStringNotContainsString(
            '<!DOCTYPE html>',
            $respons->getContent(),
            'Mode parsial hanya boleh mengembalikan fragmen tabel deposito, bukan halaman penuh.'
        );
    }

    public function test_cif_show_mengembalikan_404_untuk_id_tidak_ada(): void
    {
        $this->actingAs($this->manajer())
            ->get(route('manajer.cif.show', 999999999))
            ->assertNotFound();
    }

    /* =====================================================================
     | M3/M4 — UBAH DATA CIF
     |===================================================================== */

    public function test_cif_edit_menampilkan_form_ubah(): void
    {
        $manajer = $this->manajer();
        $anggota = $this->anggota('EDIT');

        $this->actingAs($manajer)
            ->get(route('manajer.cif.edit', $anggota->id))
            ->assertOk()
            ->assertViewIs('manajer.cif.edit')
            ->assertViewHas('anggota', fn ($a) => (int) $a->id === (int) $anggota->id)
            ->assertSee($anggota->nama_lengkap);
    }

    public function test_cif_update_menyimpan_perubahan_tetapi_mengabaikan_status_anggota(): void
    {
        $manajer = $this->manajer();
        $anggota = $this->anggota('UPDATE', Anggota::STATUS_ACTIVE);

        $payload = [
            'nama_lengkap' => 'Nama Baru B25 '.$this->suffix,
            'nik' => $anggota->nik,
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => $anggota->no_hp,
            'alamat' => 'Jl. Baru Batch 25 No. 9',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            // Manajer TIDAK termasuk `pengubah_status_anggota` (role_hierarki).
            'status_anggota' => Anggota::STATUS_SUSPENDED,
        ];

        $this->actingAs($manajer)
            ->put(route('manajer.cif.update', $anggota->id), $payload)
            ->assertRedirect(route('manajer.cif.show', $anggota->id))
            ->assertSessionHas('success', 'Data CIF berhasil diperbarui.');

        $anggota->refresh();
        $this->assertSame($payload['nama_lengkap'], $anggota->nama_lengkap);
        $this->assertSame($payload['alamat'], $anggota->alamat);
        $this->assertSame(
            Anggota::STATUS_ACTIVE,
            $anggota->status_anggota,
            'Perubahan status anggota wajib DIBUANG untuk Manajer — kewenangan itu milik admin/pengurus.'
        );
    }

    public function test_cif_update_menolak_nik_dan_no_hp_milik_anggota_lain(): void
    {
        $manajer = $this->manajer();
        $anggota = $this->anggota('UNIK1');
        $lain = $this->anggota('UNIK2');

        $dasar = [
            'nama_lengkap' => $anggota->nama_lengkap,
            'nik' => $anggota->nik,
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => $anggota->no_hp,
            'alamat' => $anggota->alamat,
            'kota' => $anggota->kota,
            'provinsi' => $anggota->provinsi,
        ];

        $this->actingAs($manajer)
            ->put(route('manajer.cif.update', $anggota->id), array_merge($dasar, ['nik' => $lain->nik]))
            ->assertSessionHasErrors('nik');

        $this->actingAs($manajer)
            ->put(route('manajer.cif.update', $anggota->id), array_merge($dasar, ['no_hp' => $lain->no_hp]))
            ->assertSessionHasErrors('no_hp');
    }

    /* =====================================================================
     | M5 — EKSPOR CIF
     |===================================================================== */

    public function test_cif_export_tanpa_parameter_excel_mengalihkan_kembali(): void
    {
        $this->actingAs($this->manajer())
            ->from(route('manajer.cif.index'))
            ->get(route('manajer.cif.export'))
            ->assertRedirect(route('manajer.cif.index'));
    }

    public function test_cif_export_mengirim_csv_utf8_bom_dengan_header_lengkap(): void
    {
        $manajer = $this->manajer();
        $anggota = $this->anggota('EXPORT');

        $respons = $this->actingAs($manajer)
            ->get(route('manajer.cif.export', ['export' => 'excel']));

        $respons->assertOk();
        $this->assertStringContainsString('text/csv', (string) $respons->headers->get('content-type'));
        $this->assertStringContainsString('attachment;', (string) $respons->headers->get('content-disposition'));

        $isi = $respons->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $isi, 'CSV wajib diawali BOM agar Excel membaca UTF-8.');
        $this->assertStringContainsString('No CIF', $isi);
        $this->assertStringContainsString($anggota->no_cif, $isi);
        $this->assertStringContainsString($anggota->nama_lengkap, $isi);
    }

    public function test_batas_ekspor_manajer_dua_puluh_ribu_baris(): void
    {
        $this->assertSame(
            20000,
            HierarkiRole::aksesData('manajer', 'maks_baris_ekspor'),
            'Batas ekspor Manajer adalah kontrak konfigurasi; perubahan angka wajib disengaja.'
        );
    }

    /* =====================================================================
     | M6 — DAFTAR APPROVAL RESTRUKTURISASI
     |===================================================================== */

    public function test_approval_restrukturisasi_index_default_hanya_pending(): void
    {
        $manajer = $this->manajer();
        $ao = $this->user('ao', 'pengaju');

        $pending = $this->pengajuanRestrukturisasi($this->pembiayaan($this->anggota('RSP')), $ao->id);
        $disetujui = $this->pengajuanRestrukturisasi(
            $this->pembiayaan($this->anggota('RSA')),
            $ao->id,
            5,
            RestrukturisasiRequest::STATUS_APPROVED
        );

        $this->actingAs($manajer)
            ->get(route('manajer.approval-restrukturisasi.index'))
            ->assertOk()
            ->assertViewIs('manajer.approval-restrukturisasi')
            ->assertViewHas('statusFilter', RestrukturisasiRequest::STATUS_PENDING_APPROVAL)
            ->assertViewHas('pengajuan', function ($p) use ($pending, $disetujui) {
                $ids = $p->getCollection()->pluck('id');

                return $ids->contains($pending->id) && ! $ids->contains($disetujui->id);
            })
            ->assertViewHas('totalPending', RestrukturisasiRequest::where('status', RestrukturisasiRequest::STATUS_PENDING_APPROVAL)->count())
            ->assertViewHas('totalApproved', RestrukturisasiRequest::where('status', RestrukturisasiRequest::STATUS_APPROVED)->count())
            ->assertViewHas('totalRejected', RestrukturisasiRequest::where('status', RestrukturisasiRequest::STATUS_REJECTED)->count());
    }

    public function test_approval_restrukturisasi_index_filter_status_dan_pencarian(): void
    {
        $manajer = $this->manajer();
        $ao = $this->user('ao', 'pengaju');

        $pending = $this->pengajuanRestrukturisasi($this->pembiayaan($this->anggota('RSP2')), $ao->id);
        $disetujui = $this->pengajuanRestrukturisasi(
            $this->pembiayaan($this->anggota('RSA2')),
            $ao->id,
            5,
            RestrukturisasiRequest::STATUS_APPROVED
        );

        $this->actingAs($manajer)
            ->get(route('manajer.approval-restrukturisasi.index', ['status' => RestrukturisasiRequest::STATUS_APPROVED]))
            ->assertOk()
            ->assertViewHas('statusFilter', RestrukturisasiRequest::STATUS_APPROVED)
            ->assertViewHas('pengajuan', function ($p) use ($pending, $disetujui) {
                $ids = $p->getCollection()->pluck('id');

                return $ids->contains($disetujui->id) && ! $ids->contains($pending->id);
            });

        $this->actingAs($manajer)
            ->get(route('manajer.approval-restrukturisasi.index', ['search' => $pending->pembiayaan->no_pembiayaan]))
            ->assertOk()
            ->assertViewHas('pengajuan', function ($p) use ($pending, $disetujui) {
                $ids = $p->getCollection()->pluck('id');

                return $ids->contains($pending->id) && ! $ids->contains($disetujui->id);
            });
    }

    public function test_approval_restrukturisasi_index_status_tidak_valid_kembali_ke_pending(): void
    {
        $this->actingAs($this->manajer())
            ->get(route('manajer.approval-restrukturisasi.index', ['status' => 'NGARANG']))
            ->assertOk()
            ->assertViewHas('statusFilter', RestrukturisasiRequest::STATUS_PENDING_APPROVAL);
    }

    /* =====================================================================
     | M7 — APPROVE RESTRUKTURISASI
     |===================================================================== */

    public function test_approve_restrukturisasi_membuat_addendum_dan_jadwal_baru_tanpa_tambahan_margin(): void
    {
        $manajer = $this->manajer('pemutus');
        $ao = $this->user('ao', 'pengaju');
        $pembiayaan = $this->pembiayaan($this->anggota('APR'));
        $req = $this->pengajuanRestrukturisasi($pembiayaan, $ao->id, 5);

        $this->actingAs($manajer)
            ->post(route('manajer.approval-restrukturisasi.approve', $req->id), [
                'catatan_manajer' => 'Disetujui untuk meringankan anggota.',
            ])
            ->assertRedirect(route('manajer.approval-restrukturisasi.index'))
            ->assertSessionHas('success');

        $req->refresh();
        $this->assertSame(RestrukturisasiRequest::STATUS_APPROVED, $req->status);
        $this->assertSame($manajer->id, (int) $req->manajer_id);
        $this->assertNotEmpty($req->addendum_no, 'Addendum wajib diterbitkan saat approval.');
        $this->assertSame(5, (int) $req->tenor_baru);

        $pembiayaan->refresh();
        $this->assertSame(Pembiayaan::STATUS_RESTRUKTURISASI, $pembiayaan->status_pembiayaan);
        $this->assertNull(
            $pembiayaan->status_kolektibilitas,
            'Kolektibilitas pasca-restrukturisasi harus dihitung dinamis, bukan dibekukan.'
        );

        $jadwal = Angsuran::query()->where('pembiayaan_id', $pembiayaan->id)->get();
        $this->assertCount(5, $jadwal, 'Jadwal lama yang belum dibayar diganti tepat 5 angsuran baru.');
        $this->assertTrue(
            $jadwal->every(fn ($a) => $a->status_angsuran === 'Belum Jatuh Tempo'),
            'Seluruh jadwal baru harus berstatus Belum Jatuh Tempo.'
        );

        $totalBaru = round($jadwal->sum(fn ($a) => (float) $a->pokok_angsuran + (float) $a->margin_ujroh), 2);
        $this->assertSame(
            round((float) $req->sisa_piutang_snapshot, 2),
            $totalBaru,
            'Fatwa DSN-MUI 49: total jadwal baru wajib tepat sama dengan sisa piutang (tanpa margin tambahan).'
        );
    }

    public function test_approve_restrukturisasi_menolak_pemutus_yang_sama_dengan_pengaju(): void
    {
        $manajer = $this->manajer('rangkap');
        $pembiayaan = $this->pembiayaan($this->anggota('SELF'));
        $req = $this->pengajuanRestrukturisasi($pembiayaan, $manajer->id, 5);

        $this->actingAs($manajer)
            ->from(route('manajer.approval-restrukturisasi.index'))
            ->post(route('manajer.approval-restrukturisasi.approve', $req->id), [
                'catatan_manajer' => 'Setuju sendiri',
            ])
            ->assertRedirect(route('manajer.approval-restrukturisasi.index'))
            ->assertSessionHas('error', fn ($pesan) => str_contains($pesan, 'pemisahan tugas'));

        $req->refresh();
        $this->assertSame(RestrukturisasiRequest::STATUS_PENDING_APPROVAL, $req->status);
    }

    public function test_approve_restrukturisasi_menolak_pengajuan_yang_sudah_diproses(): void
    {
        $manajer = $this->manajer('pemutus');
        $ao = $this->user('ao', 'pengaju');
        $req = $this->pengajuanRestrukturisasi(
            $this->pembiayaan($this->anggota('SUDAH')),
            $ao->id,
            5,
            RestrukturisasiRequest::STATUS_REJECTED
        );

        $this->actingAs($manajer)
            ->from(route('manajer.approval-restrukturisasi.index'))
            ->post(route('manajer.approval-restrukturisasi.approve', $req->id), [])
            ->assertRedirect(route('manajer.approval-restrukturisasi.index'))
            ->assertSessionHas('error', fn ($pesan) => str_contains($pesan, 'sudah diproses'));

        $this->assertSame(RestrukturisasiRequest::STATUS_REJECTED, $req->fresh()->status);
    }

    public function test_approve_restrukturisasi_membatasi_catatan_seribu_karakter(): void
    {
        $manajer = $this->manajer('pemutus');
        $ao = $this->user('ao', 'pengaju');
        $req = $this->pengajuanRestrukturisasi($this->pembiayaan($this->anggota('PANJANG')), $ao->id);

        $this->actingAs($manajer)
            ->from(route('manajer.approval-restrukturisasi.index'))
            ->post(route('manajer.approval-restrukturisasi.approve', $req->id), [
                'catatan_manajer' => str_repeat('a', 1001),
            ])
            ->assertSessionHasErrors('catatan_manajer');

        $this->assertSame(RestrukturisasiRequest::STATUS_PENDING_APPROVAL, $req->fresh()->status);
    }

    /* =====================================================================
     | M8 — REJECT RESTRUKTURISASI
     |===================================================================== */

    public function test_reject_restrukturisasi_wajib_catatan(): void
    {
        $manajer = $this->manajer('pemutus');
        $ao = $this->user('ao', 'pengaju');
        $req = $this->pengajuanRestrukturisasi($this->pembiayaan($this->anggota('TOLAK0')), $ao->id);

        $this->actingAs($manajer)
            ->from(route('manajer.approval-restrukturisasi.index'))
            ->post(route('manajer.approval-restrukturisasi.reject', $req->id), [])
            ->assertSessionHasErrors('catatan_manajer');

        $this->assertSame(RestrukturisasiRequest::STATUS_PENDING_APPROVAL, $req->fresh()->status);
    }

    public function test_reject_restrukturisasi_tidak_mengubah_jadwal_lama(): void
    {
        $manajer = $this->manajer('pemutus');
        $ao = $this->user('ao', 'pengaju');
        $pembiayaan = $this->pembiayaan($this->anggota('TOLAK'));
        $req = $this->pengajuanRestrukturisasi($pembiayaan, $ao->id);

        $jumlahAwal = Angsuran::query()->where('pembiayaan_id', $pembiayaan->id)->count();

        $this->actingAs($manajer)
            ->post(route('manajer.approval-restrukturisasi.reject', $req->id), [
                'catatan_manajer' => 'Kemampuan bayar belum terbukti menurun.',
            ])
            ->assertRedirect(route('manajer.approval-restrukturisasi.index'))
            ->assertSessionHas('success');

        $req->refresh();
        $this->assertSame(RestrukturisasiRequest::STATUS_REJECTED, $req->status);
        $this->assertSame($manajer->id, (int) $req->manajer_id);
        $this->assertSame('Kemampuan bayar belum terbukti menurun.', $req->catatan_manajer);

        $this->assertSame(
            $jumlahAwal,
            Angsuran::query()->where('pembiayaan_id', $pembiayaan->id)->count(),
            'Penolakan tidak boleh menghapus/mengganti jadwal angsuran lama.'
        );
    }

    /* =====================================================================
     | M9 — ADDENDUM
     |===================================================================== */

    public function test_addendum_ditolak_untuk_pengajuan_belum_disetujui(): void
    {
        $manajer = $this->manajer();
        $ao = $this->user('ao', 'pengaju');
        $req = $this->pengajuanRestrukturisasi($this->pembiayaan($this->anggota('ADD0')), $ao->id);

        $this->actingAs($manajer)
            ->get(route('manajer.approval-restrukturisasi.addendum', $req->id))
            ->assertRedirect(route('manajer.approval-restrukturisasi.index'))
            ->assertSessionHas('error', fn ($pesan) => str_contains($pesan, 'telah disetujui'));
    }

    public function test_addendum_tampil_setelah_pengajuan_disetujui(): void
    {
        $manajer = $this->manajer('pemutus');
        $ao = $this->user('ao', 'pengaju');
        $req = $this->pengajuanRestrukturisasi($this->pembiayaan($this->anggota('ADD1')), $ao->id, 5);

        $this->actingAs($manajer)
            ->post(route('manajer.approval-restrukturisasi.approve', $req->id), [])
            ->assertRedirect(route('manajer.approval-restrukturisasi.index'));

        $req->refresh();

        $this->actingAs($manajer)
            ->get(route('manajer.approval-restrukturisasi.addendum', $req->id))
            ->assertOk()
            ->assertViewIs('manajer.addendum-restrukturisasi')
            ->assertViewHas('req', fn ($r) => (int) $r->id === (int) $req->id)
            ->assertViewHas('jadwalBaru', fn ($j) => $j->count() === 5)
            ->assertSee($req->addendum_no);
    }

    /* =====================================================================
     | M10 — DETAIL APPROVAL PEMBIAYAAN
     |===================================================================== */

    public function test_approval_pembiayaan_show_menampilkan_detail_pengajuan(): void
    {
        $manajer = $this->manajer();
        $anggota = $this->anggota('APV');
        $pembiayaan = $this->pembiayaan($anggota);

        $this->actingAs($manajer)
            ->get(route('manajer.approval-pembiayaan.show', $pembiayaan->id))
            ->assertOk()
            ->assertViewIs('manajer.approval-pembiayaan-show')
            ->assertViewHas('item', fn ($i) => (int) $i->id === (int) $pembiayaan->id)
            ->assertSee($pembiayaan->no_pembiayaan)
            ->assertSee($anggota->nama_lengkap);
    }

    public function test_approval_pembiayaan_show_404_untuk_id_tidak_ada(): void
    {
        $this->actingAs($this->manajer())
            ->get(route('manajer.approval-pembiayaan.show', 999999999))
            ->assertNotFound();
    }

    /* =====================================================================
     | M11 — PORTOFOLIO PEMBIAYAAN (KOLEKTIBILITAS)
     |===================================================================== */

    public function test_pembiayaan_show_menghitung_tunggakan_dan_kolektibilitas_dinamis(): void
    {
        $manajer = $this->manajer();
        $pembiayaan = $this->pembiayaan($this->anggota('KOLEK'));
        $this->tandaiTerlambat($pembiayaan, 45);

        $respons = $this->actingAs($manajer)
            ->get(route('manajer.pembiayaan.show', $pembiayaan->id))
            ->assertOk()
            ->assertViewIs('manajer.pembiayaan-show')
            ->assertViewHas('hariTunggakan', 45)
            ->assertViewHas('totalTunggakan', fn ($t) => round((float) $t, 2) === 120000.0)
            ->assertViewHas('kolektibilitas', function ($k) {
                return is_string($k) && in_array($k, array_values((array) config('kolektibilitas.label')), true);
            });

        $label = $respons->viewData('kolektibilitas');
        $this->assertStringContainsString($label, $respons->getContent());
        $this->assertStringContainsString('Menunggak', $respons->getContent());
    }

    public function test_pembiayaan_show_memakai_override_kolektibilitas_dan_fallback_kode_tak_dikenal(): void
    {
        $manajer = $this->manajer();

        $override = $this->pembiayaan($this->anggota('OVR'));
        $override->update(['status_kolektibilitas' => 'kol5']);

        $this->actingAs($manajer)
            ->get(route('manajer.pembiayaan.show', $override->id))
            ->assertOk()
            ->assertViewHas('kolektibilitas', 'Macet (Kol-5)')
            ->assertSee('Macet (Kol-5)');

        $ngawur = $this->pembiayaan($this->anggota('NGAWUR'));
        $ngawur->update(['status_kolektibilitas' => 'kode-tidak-dikenal']);

        $this->actingAs($manajer)
            ->get(route('manajer.pembiayaan.show', $ngawur->id))
            ->assertOk()
            ->assertViewHas(
                'kolektibilitas',
                (string) config('kolektibilitas.label.'.config('kolektibilitas.default', 'kol5'))
            );
    }

    public function test_pembiayaan_show_404_untuk_id_tidak_ada(): void
    {
        $this->actingAs($this->manajer())
            ->get(route('manajer.pembiayaan.show', 999999999))
            ->assertNotFound();
    }

    /* =====================================================================
     | M12 — DAFTAR KERINGANAN TA'ZIR
     |===================================================================== */

    public function test_keringanan_tazir_index_default_hanya_tahap_manajer(): void
    {
        $manajer = $this->manajer();
        $teller = $this->user('teller', 'pengaju');

        $pembiayaan = $this->pembiayaan($this->anggota('TZR1'));
        $this->tandaiTerlambat($pembiayaan, 45, 30000);
        $padaManajer = $this->reliefPendingManajer($teller, $pembiayaan);

        $pembiayaan2 = $this->pembiayaan($this->anggota('TZR2'));
        $this->tandaiTerlambat($pembiayaan2, 45, 30000);
        $padaAo = TazirReliefRequest::create([
            'jadwal_angsuran_id' => Angsuran::query()->where('pembiayaan_id', $pembiayaan2->id)->where('angsuran_ke', 1)->value('id'),
            'pembiayaan_id' => $pembiayaan2->id,
            'anggota_id' => $pembiayaan2->anggota_id,
            'nominal_tazir_awal' => 30000,
            'alasan_teller' => 'Uji batch 25 tahap AO.',
            'teller_id' => $teller->id,
            'status' => TazirReliefRequest::STATUS_PENDING_AO,
        ]);

        $this->actingAs($manajer)
            ->get(route('manajer.keringanan-tazir.index'))
            ->assertOk()
            ->assertViewIs('manajer.keringanan_tazir.index')
            ->assertViewHas('status', TazirReliefRequest::STATUS_PENDING_MANAJER)
            ->assertViewHas('pengajuan', function ($p) use ($padaManajer, $padaAo) {
                $ids = $p->getCollection()->pluck('id');

                return $ids->contains($padaManajer->id) && ! $ids->contains($padaAo->id);
            })
            ->assertViewHas('totalPendingManajer', TazirReliefRequest::pendingManajer()->count());
    }

    public function test_keringanan_tazir_index_filter_status_dan_pencarian(): void
    {
        $manajer = $this->manajer();
        $teller = $this->user('teller', 'pengaju');

        $pembiayaan = $this->pembiayaan($this->anggota('TZR3'));
        $this->tandaiTerlambat($pembiayaan, 45, 30000);
        $padaManajer = $this->reliefPendingManajer($teller, $pembiayaan);

        $this->actingAs($manajer)
            ->get(route('manajer.keringanan-tazir.index', ['status' => TazirReliefRequest::STATUS_PENDING_MANAJER]))
            ->assertOk()
            ->assertViewHas('pengajuan', fn ($p) => $p->getCollection()->pluck('id')->contains($padaManajer->id));

        $this->actingAs($manajer)
            ->get(route('manajer.keringanan-tazir.index', [
                'search' => $pembiayaan->no_pembiayaan,
                'status' => TazirReliefRequest::STATUS_PENDING_MANAJER,
            ]))
            ->assertOk()
            ->assertViewHas('pengajuan', fn ($p) => $p->getCollection()->pluck('id')->contains($padaManajer->id))
            ->assertViewHas('search', $pembiayaan->no_pembiayaan);
    }

    /* =====================================================================
     | M13 — APPROVE KERINGANAN TA'ZIR
     |===================================================================== */

    public function test_keringanan_tazir_approve_membebaskan_denda_angsuran(): void
    {
        $manajer = $this->manajer('pemutus');
        $teller = $this->user('teller', 'pengaju');
        $pembiayaan = $this->pembiayaan($this->anggota('TAZIRAPP'));
        $angsuran = $this->tandaiTerlambat($pembiayaan, 45, 30000);
        $relief = $this->reliefPendingManajer($teller, $pembiayaan);

        $this->actingAs($manajer)
            ->from(route('manajer.keringanan-tazir.index'))
            ->post(route('manajer.keringanan-tazir.approve', $relief->id), [
                'catatan_manajer' => 'Anggota kooperatif, keringanan disetujui.',
            ])
            ->assertRedirect(route('manajer.keringanan-tazir.index'))
            ->assertSessionHas('success');

        $relief->refresh();
        $this->assertSame(TazirReliefRequest::STATUS_APPROVED, $relief->status);
        $this->assertSame($manajer->id, (int) $relief->manajer_id);
        $this->assertSame(0.0, round((float) $relief->nominal_tazir_disetujui, 2));

        $angsuran->refresh();
        $this->assertSame(0.0, round((float) $angsuran->denda, 2));
        $this->assertSame('DIBEBASKAN', $angsuran->status_tazir);

        $this->assertSame(
            0,
            TazirPenangguhan::query()->where('tazir_relief_request_id', $relief->id)->count(),
            'Approval membebaskan ta\'zir — tidak boleh ada tunggakan yang dicatat.'
        );
    }

    public function test_keringanan_tazir_approve_menolak_bila_belum_tahap_manajer(): void
    {
        $manajer = $this->manajer('pemutus');
        $teller = $this->user('teller', 'pengaju');
        $pembiayaan = $this->pembiayaan($this->anggota('TAZIRAO'));
        $angsuran = $this->tandaiTerlambat($pembiayaan, 45, 30000);

        $relief = TazirReliefRequest::create([
            'jadwal_angsuran_id' => $angsuran->id,
            'pembiayaan_id' => $pembiayaan->id,
            'anggota_id' => $pembiayaan->anggota_id,
            'nominal_tazir_awal' => 30000,
            'alasan_teller' => 'Uji batch 25 tahap AO.',
            'teller_id' => $teller->id,
            'status' => TazirReliefRequest::STATUS_PENDING_AO,
        ]);

        $this->actingAs($manajer)
            ->from(route('manajer.keringanan-tazir.index'))
            ->post(route('manajer.keringanan-tazir.approve', $relief->id), [])
            ->assertRedirect(route('manajer.keringanan-tazir.index'))
            ->assertSessionHas('error', fn ($pesan) => str_contains($pesan, 'tahap approval Manajer'));

        $this->assertSame(TazirReliefRequest::STATUS_PENDING_AO, $relief->fresh()->status);
    }

    public function test_keringanan_tazir_approve_menolak_pemisahan_tugas(): void
    {
        $manajer = $this->manajer('rangkap');
        $pembiayaan = $this->pembiayaan($this->anggota('TAZIRSELF'));
        $this->tandaiTerlambat($pembiayaan, 45, 30000);
        $relief = $this->reliefPendingManajer($manajer, $pembiayaan);

        $this->actingAs($manajer)
            ->from(route('manajer.keringanan-tazir.index'))
            ->post(route('manajer.keringanan-tazir.approve', $relief->id), [])
            ->assertRedirect(route('manajer.keringanan-tazir.index'))
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $this->assertSame(TazirReliefRequest::STATUS_PENDING_MANAJER, $relief->fresh()->status);
    }

    /* =====================================================================
     | M14 — REJECT KERINGANAN TA'ZIR
     |===================================================================== */

    public function test_keringanan_tazir_reject_wajib_catatan(): void
    {
        $manajer = $this->manajer('pemutus');
        $teller = $this->user('teller', 'pengaju');
        $pembiayaan = $this->pembiayaan($this->anggota('TAZIRTOLAK0'));
        $this->tandaiTerlambat($pembiayaan, 45, 30000);
        $relief = $this->reliefPendingManajer($teller, $pembiayaan);

        $this->actingAs($manajer)
            ->from(route('manajer.keringanan-tazir.index'))
            ->post(route('manajer.keringanan-tazir.reject', $relief->id), [])
            ->assertSessionHasErrors('catatan_manajer');

        $this->assertSame(TazirReliefRequest::STATUS_PENDING_MANAJER, $relief->fresh()->status);
    }

    public function test_keringanan_tazir_reject_mengusulkan_autodebit_tanpa_memotong_saldo(): void
    {
        $manajer = $this->manajer('pemutus');
        $teller = $this->user('teller', 'pengaju');
        $anggota = $this->anggota('TAZIRTOLAK');
        $pembiayaan = $this->pembiayaan($anggota);
        $angsuran = $this->tandaiTerlambat($pembiayaan, 45, 30000);
        $rekening = $this->rekening($anggota, 500000.0);
        $relief = $this->reliefPendingManajer($teller, $pembiayaan);

        $this->actingAs($manajer)
            ->from(route('manajer.keringanan-tazir.index'))
            ->post(route('manajer.keringanan-tazir.reject', $relief->id), [
                'catatan_manajer' => 'Anggota belum memenuhi syarat keringanan.',
            ])
            ->assertRedirect(route('manajer.keringanan-tazir.index'))
            ->assertSessionHas('success');

        $relief->refresh();
        $this->assertSame(TazirReliefRequest::STATUS_REJECTED, $relief->status);
        $this->assertSame(30000.0, round((float) $relief->nominal_tazir_disetujui, 2));

        $tunggakan = TazirPenangguhan::query()
            ->where('tazir_relief_request_id', $relief->id)
            ->first();

        $this->assertNotNull($tunggakan, 'Penolakan wajib mencatat tunggakan ta\'zir.');
        $this->assertSame(TazirPenangguhan::FLAG_PENDING_AUTODEBET_SUSULAN, $tunggakan->flag);
        $this->assertFalse((bool) $tunggakan->is_tazir_paid);
        $this->assertNull($tunggakan->paid_at);

        $this->assertSame('TUNGGAKAN', $angsuran->fresh()->status_tazir);

        $usulan = AutodebitUsulan::query()
            ->where('kanal', AutodebitUsulan::KANAL_TAZIR)
            ->where('referensi_id', $tunggakan->id)
            ->first();

        $this->assertNotNull($usulan, 'Usulan auto-debit kanal TAZIR wajib terbit.');
        $this->assertSame(AutodebitUsulan::STATUS_MENUNGGU_KONFIRMASI, $usulan->status);

        $this->assertSame(
            500000.0,
            round((float) $rekening->fresh()->saldo, 2),
            'Saldo TIDAK boleh dipotong pada saat penolakan — pemotongan menunggu konfirmasi.'
        );
    }

    /* =====================================================================
     | PAGAR RBAC — 14 RUTE
     |===================================================================== */

    public function test_empat_belas_rute_manajer_menolak_peran_tanpa_izin(): void
    {
        $anggota = $this->anggota('RBAC');
        $pembiayaan = $this->pembiayaan($anggota);
        $teller = $this->user('teller', 'pengaju');

        $reqRestrukturisasi = $this->pengajuanRestrukturisasi($pembiayaan, $teller->id);
        $relief = $this->reliefPendingManajer($teller, $pembiayaan);

        $rute = [
            ['GET', route('manajer.cif.index')],
            ['GET', route('manajer.cif.show', $anggota->id)],
            ['GET', route('manajer.cif.edit', $anggota->id)],
            ['PUT', route('manajer.cif.update', $anggota->id)],
            ['GET', route('manajer.cif.export', ['export' => 'excel'])],
            ['GET', route('manajer.approval-restrukturisasi.index')],
            ['POST', route('manajer.approval-restrukturisasi.approve', $reqRestrukturisasi->id)],
            ['POST', route('manajer.approval-restrukturisasi.reject', $reqRestrukturisasi->id)],
            ['GET', route('manajer.approval-restrukturisasi.addendum', $reqRestrukturisasi->id)],
            ['GET', route('manajer.approval-pembiayaan.show', $pembiayaan->id)],
            ['GET', route('manajer.pembiayaan.show', $pembiayaan->id)],
            ['GET', route('manajer.keringanan-tazir.index')],
            ['POST', route('manajer.keringanan-tazir.approve', $relief->id)],
            ['POST', route('manajer.keringanan-tazir.reject', $relief->id)],
        ];

        $this->assertCount(14, $rute, 'Batch 25 harus mencakup tepat 14 rute.');

        foreach (['teller', 'ao', 'pengurus', 'dps'] as $peran) {
            $pengguna = $this->user($peran, 'rbac');

            foreach ($rute as [$metode, $uri]) {
                $this->actingAs($pengguna)
                    ->call($metode, $uri)
                    ->assertForbidden();
            }
        }
    }
}
