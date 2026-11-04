<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * SIMULASI HARIAN — BATCH 2: AO, MANAJER, PENGURUS, DPS.
 *
 * Menjalankan alur pembiayaan end-to-end lewat HTTP nyata:
 *   AO ajukan -> AO approve -> Manajer approve (dalam limit: langsung disetujui)
 *   AO ajukan -> AO approve -> Manajer approve (luar limit) -> Pengurus -> DPS.
 *
 * Basis data uji: koperasi_syariah_test (phpunit.xml).
 */
class SimulasiHarianBatch2Test extends TestCase
{
    use DatabaseTransactions;

    private ?int $akunLegacyId = null;

    private function pengguna(string $peran, string $suffix): User
    {
        return User::firstOrCreate(
            ['email' => "simulasi.{$suffix}@test.local"],
            [
                'name' => 'Simulasi '.ucfirst($suffix),
                'password' => Hash::make('password'),
                'role' => $peran,
                'is_active' => true,
            ],
        );
    }

    private function akunLegacyId(): int
    {
        if ($this->akunLegacyId === null) {
            $id = Akun::query()->value('id');

            if (! $id) {
                $this->markTestSkipped('Tabel akun (legacy) belum berisi data.');
            }

            $this->akunLegacyId = (int) $id;
        }

        return $this->akunLegacyId;
    }

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'SIM-'.strtoupper(substr(uniqid(), -6)),
            'nik' => 'NIK'.substr(md5(uniqid('', true)), 0, 12),
            'nama_lengkap' => 'Anggota Simulasi Batch2',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Simulasi No. 2',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_telepon' => '081200000002',
            'status_anggota' => 'Aktif',
            'tanggal_gabung' => now()->toDateString(),
        ]);
    }

    private function produk(): MasterProdukPembiayaan
    {
        return MasterProdukPembiayaan::create([
            'kode_produk' => 'SIM-'.strtoupper(substr(uniqid(), -6)),
            'nama_produk' => 'Produk Simulasi',
            'akad_syariah' => Pembiayaan::AKAD_MURABAHAH,
            'min_plafon' => 1000000,
            'max_plafon' => 500000000,
            'margin_persen' => 10,
            'min_tenor_bulan' => 1,
            'max_tenor_bulan' => 60,
            'status_aktif' => true,
            'status_sertifikasi_dps' => MasterProdukPembiayaan::SERTIFIKASI_VALID,
        ]);
    }

    private function pembiayaan(Anggota $anggota, float $pokok, string $status): Pembiayaan
    {
        $margin = $pokok * 0.1;

        return Pembiayaan::create([
            'no_pembiayaan' => 'SIM-'.strtoupper(substr(uniqid(), -8)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunLegacyId(),
            'akad_pembiayaan' => Pembiayaan::AKAD_MURABAHAH,
            'tujuan_pembiayaan' => 'Modal Usaha',
            'jumlah_pembiayaan' => $pokok,
            'margin_nisbah' => $margin,
            'total_ujroh' => $margin,
            'total_pembiayaan' => $pokok + $margin,
            'tenor' => 12,
            'frekuensi_angsuran' => 'Bulanan',
            'jumlah_angsuran' => round(($pokok + $margin) / 12, 2),
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addYear()->toDateString(),
            'status_pembiayaan' => $status,
            'sisa_pembiayaan' => $pokok + $margin,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 1. AO membuat pengajuan baru (POST ao.pengajuan.store)
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_ao_dapat_membuat_pengajuan_pembiayaan_baru(): void
    {
        $ao = $this->pengguna('ao', 'ao1');
        $anggota = $this->anggota();
        $produk = $this->produk();

        $this->actingAs($ao)->post(route('ao.pengajuan.store'), [
            'anggota_id' => $anggota->id,
            'produk_id' => $produk->id,
            'jenis_akad' => Pembiayaan::AKAD_MURABAHAH,
            'jumlah_pengajuan' => 20000000,
            'tenor' => 12,
            'tujuan' => 'Modal usaha warung',
        ])->assertRedirect(route('ao.pengajuan.index'));

        $this->assertDatabaseHas('pengajuan_pembiayaan', [
            'anggota_id' => $anggota->id,
            'produk_id' => $produk->id,
            'status' => PengajuanPembiayaan::STATUS_MENUNGGU,
            'ao_id' => $ao->id,
            'jumlah_pengajuan' => 20000000,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 2. Manajer menyetujui pembiayaan dalam limit -> langsung disetujui
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_manajer_menyetujui_pembiayaan_dalam_limit_menjadi_disetujui(): void
    {
        $manajer = $this->pengguna('manajer', 'manajer1');
        $item = $this->pembiayaan($this->anggota(), 10000000, Pembiayaan::STATUS_PENDING_MANAJER);

        $this->actingAs($manajer)
            ->post(route('manajer.approval-pembiayaan.approve', $item->id), ['catatan' => 'Layak'])
            ->assertRedirect(route('manajer.approval-pembiayaan.index'))
            ->assertSessionHas('success');

        $this->assertSame(Pembiayaan::STATUS_DISETUJUI, $item->fresh()->status_pembiayaan);
        $this->assertSame($manajer->id, (int) $item->fresh()->direview_oleh);
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 3. Manajer meneruskan pembiayaan luar limit ke Pengurus
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_manajer_meneruskan_pembiayaan_luar_limit_ke_pengurus(): void
    {
        $manajer = $this->pengguna('manajer', 'manajer2');
        $item = $this->pembiayaan($this->anggota(), 80000000, Pembiayaan::STATUS_PENDING_MANAJER);

        $this->actingAs($manajer)
            ->post(route('manajer.approval-pembiayaan.approve', $item->id))
            ->assertSessionHas('success');

        $this->assertSame(Pembiayaan::STATUS_PENDING_PENGURUS, $item->fresh()->status_pembiayaan);
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 4. Pengurus menyetujui -> pending_dps; DPS mengesahkan -> disetujui
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_rantai_pengurus_lalu_dps_menghasilkan_status_disetujui(): void
    {
        $pengurus = $this->pengguna('pengurus', 'pengurus1');
        $dps = $this->pengguna('dps', 'dps1');
        $item = $this->pembiayaan($this->anggota(), 80000000, Pembiayaan::STATUS_PENDING_PENGURUS);

        $this->actingAs($pengurus)
            ->post(route('pengurus.pembiayaan.approve', $item->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(Pembiayaan::STATUS_PENDING_DPS, $item->fresh()->status_pembiayaan);

        $this->actingAs($dps)
            ->post(route('dps.pengesahan-akad.approve', $item->id), [
                'tgl_pencairan' => now()->addDay()->toDateString(),
                'catatan_dps' => 'Akad sesuai prinsip syariah',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $item->fresh();
        $this->assertSame(Pembiayaan::STATUS_DISETUJUI, $fresh->status_pembiayaan);
        $this->assertSame($dps->id, (int) $fresh->disahkan_oleh);
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 5. Pengurus tidak boleh menyetujui pembiayaan di luar tahapnya
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_pengurus_ditolak_menyetujui_pembiayaan_yang_belum_di_tahapnya(): void
    {
        $pengurus = $this->pengguna('pengurus', 'pengurus2');
        $item = $this->pembiayaan($this->anggota(), 10000000, Pembiayaan::STATUS_PENDING_MANAJER);

        $this->actingAs($pengurus)
            ->post(route('pengurus.pembiayaan.approve', $item->id))
            ->assertSessionHas('error');

        $this->assertSame(Pembiayaan::STATUS_PENDING_MANAJER, $item->fresh()->status_pembiayaan);
    }
}
