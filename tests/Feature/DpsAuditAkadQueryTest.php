<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Pembiayaan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Modul DPS — Audit Pembiayaan & Akad (/dps/audit-akad).
 *
 * Cakupan pengujian:
 *   A. Angka badge ("Semua Akad" dan per akad) selalu sama dengan jumlah
 *      baris yang benar-benar ditampilkan di tabel utama.
 *   B. Status 'disetujui', 'aktif', dan 'cair'/'dicairkan' semuanya terambil
 *      oleh query modul DPS — tidak ada status yang terlewat.
 *   C. Kelima produk pembiayaan tampil di tabel tanpa hambatan filter status.
 *   D. Badge tab akad yang sedang tidak aktif tetap berisi angka (bukan 0).
 */
class DpsAuditAkadQueryTest extends TestCase
{
    use DatabaseTransactions;

    /** Kelima produk pembiayaan yang wajib terlihat di modul DPS. */
    private const AKAD = ['Murabahah', 'Mudharabah', 'Musyarakah', 'Ijarah', 'Qardh'];

    /** Prefix unik agar data uji terisolasi dari data dev lewat parameter search. */
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'DPSUJI'.strtoupper(substr(uniqid(), -6));
    }

    private function penggunaDps(): User
    {
        return User::firstOrCreate(
            ['email' => 'dps.auditakad@test.local'],
            [
                'name' => 'DPS Audit Akad',
                'password' => Hash::make('password'),
                'role' => 'dps',
            ]
        );
    }

    /**
     * Buat pembiayaan uji dengan nomor ber-prefix agar mudah diisolasi.
     */
    private function buatPembiayaan(string $suffix, string $akad, string $status): Pembiayaan
    {
        $anggotaId = DB::table('anggota')->value('id');

        if (! $anggotaId) {
            $anggotaId = DB::table('anggota')->insertGetId([
                'nama_lengkap' => 'Anggota Uji DPS',
                'no_anggota' => 'AGT-DPS-'.strtoupper(substr(uniqid(), -6)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $pokok = 1_000_000;

        return Pembiayaan::create([
            'no_pembiayaan' => $this->prefix.'-'.$suffix,
            'anggota_id' => $anggotaId,
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => $akad,
            'tujuan_pembiayaan' => 'Uji modul DPS audit akad',
            'jumlah_pembiayaan' => $pokok,
            'margin_nisbah' => 10,
            'total_ujroh' => $pokok * 0.1,
            'total_pembiayaan' => $pokok * 1.1,
            'jumlah_angsuran' => round($pokok * 1.1 / 12, 2),
            'tenor' => 12,
            'sisa_pembiayaan' => $pokok * 1.1,
            'status_pembiayaan' => $status,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(12)->toDateString(),
            'tgl_pencairan' => now()->subDay()->toDateString(),
            'is_disbursed' => false,
        ]);
    }

    /** GET halaman audit akad dengan filter tambahan. */
    private function bukaHalaman(array $query = [])
    {
        return $this->actingAs($this->penggunaDps())
            ->get(route('dps.audit-akad', array_merge(['search' => $this->prefix], $query)));
    }

    /**
     * A. Badge "Semua Akad" harus sama persis dengan jumlah baris tabel utama.
     * Regresi yang dicegah: badge 5 data tetapi tabel "Menampilkan 0 data".
     */
    public function test_badge_total_sama_dengan_jumlah_baris_tabel(): void
    {
        foreach (self::AKAD as $i => $akad) {
            $this->buatPembiayaan('A'.$i, $akad, Pembiayaan::STATUS_AKTIF);
        }

        $res = $this->bukaHalaman();
        $res->assertOk();

        $totalSemua = $res->viewData('totalSemua');
        $tabel = $res->viewData('pembiayaan');

        $this->assertSame(5, (int) $totalSemua, 'Badge "Semua Akad" harus menghitung 5 data uji.');
        $this->assertSame(5, $tabel->total(), 'Tabel utama harus menampilkan 5 data uji.');
        $this->assertSame(
            (int) $totalSemua,
            $tabel->total(),
            'Jumlah pada badge harus sama dengan jumlah baris pada tabel utama.'
        );

        // Pesan pada layar tidak boleh lagi berbunyi "Menampilkan 0 data".
        $this->assertStringContainsString('>5</strong> data', $res->getContent());
        $this->assertStringNotContainsString('>0</strong> data', $res->getContent());
    }

    /**
     * B. Status 'disetujui', 'aktif', dan 'cair' semuanya terambil.
     * 'cair' ditulis apa adanya (istilah modul DPS) dan harus dinormalisasi
     * ke nilai kanonik 'dicairkan'.
     */
    public function test_status_disetujui_aktif_dan_cair_semuanya_terambil(): void
    {
        $this->buatPembiayaan('B1', 'Murabahah', Pembiayaan::STATUS_DISETUJUI);
        $this->buatPembiayaan('B2', 'Musyarakah', Pembiayaan::STATUS_AKTIF);
        $this->buatPembiayaan('B3', 'Ijarah', 'cair');

        $res = $this->bukaHalaman();
        $res->assertOk();

        $nomor = $res->viewData('pembiayaan')->pluck('no_pembiayaan')->all();

        foreach (['-B1' => 'disetujui', '-B2' => 'aktif', '-B3' => 'cair'] as $suffix => $status) {
            $this->assertContains(
                $this->prefix.$suffix,
                $nomor,
                "Pembiayaan berstatus '{$status}' harus terambil oleh query modul DPS."
            );
        }

        $this->assertSame(
            Pembiayaan::STATUS_DICAIRKAN,
            Pembiayaan::where('no_pembiayaan', $this->prefix.'-B3')->value('status_pembiayaan'),
            "Status 'cair' harus disimpan sebagai nilai kanonik 'dicairkan'."
        );

        $statusList = $res->viewData('statusList');
        foreach ([Pembiayaan::STATUS_DISETUJUI, Pembiayaan::STATUS_AKTIF, Pembiayaan::STATUS_DICAIRKAN] as $st) {
            $this->assertContains($st, $statusList, "Status '{$st}' harus tersedia di dropdown filter.");
        }
    }

    /**
     * B (lanjutan). Memilih filter status 'cair' harus menemukan
     * pembiayaan yang tersimpan sebagai 'dicairkan', dan badge ikut sinkron.
     */
    public function test_filter_status_cair_menemukan_pembiayaan_dicairkan(): void
    {
        $this->buatPembiayaan('C1', 'Murabahah', Pembiayaan::STATUS_DISETUJUI);
        $this->buatPembiayaan('C2', 'Ijarah', 'cair');

        $res = $this->bukaHalaman(['status' => 'cair']);
        $res->assertOk();

        $nomor = $res->viewData('pembiayaan')->pluck('no_pembiayaan')->all();

        $this->assertSame([$this->prefix.'-C2'], $nomor);
        $this->assertSame(1, (int) $res->viewData('totalSemua'));
        $this->assertSame(1, $res->viewData('pembiayaan')->total());
    }

    /**
     * C. Kelima produk pembiayaan langsung tampil tanpa hambatan filter status.
     */
    public function test_lima_produk_pembiayaan_tampil_tanpa_filter_status(): void
    {
        foreach (self::AKAD as $i => $akad) {
            $this->buatPembiayaan('D'.$i, $akad, Pembiayaan::STATUS_AKTIF);
        }

        $res = $this->bukaHalaman();
        $res->assertOk();

        $tabel = $res->viewData('pembiayaan');

        $this->assertSame(5, $tabel->total(), 'Kelima pembiayaan harus tampil tanpa filter status.');
        $this->assertEqualsCanonicalizing(
            self::AKAD,
            $tabel->pluck('akad_pembiayaan')->unique()->values()->all(),
            'Kelima jenis akad harus terwakili di tabel utama.'
        );
    }

    /**
     * D. Saat satu tab akad aktif, badge tab akad lain harus tetap
     * menghitung grupnya sendiri (bukan 0), dan badge tab aktif sama
     * dengan jumlah baris tabel.
     */
    public function test_badge_tiap_tab_akad_terisi_saat_satu_tab_aktif(): void
    {
        foreach (self::AKAD as $i => $akad) {
            $this->buatPembiayaan('E'.$i, $akad, Pembiayaan::STATUS_AKTIF);
        }

        $res = $this->bukaHalaman(['akad' => 'Murabahah']);
        $res->assertOk();

        $counter = $res->viewData('counterAkad');

        foreach (self::AKAD as $akad) {
            $this->assertSame(
                1,
                (int) ($counter[$akad] ?? 0),
                "Badge tab {$akad} tidak boleh 0 saat tab akad lain sedang aktif."
            );
        }

        // Tab yang aktif: badge == jumlah baris tabel.
        $this->assertSame(1, $res->viewData('pembiayaan')->total());
        $this->assertSame(1, (int) $counter['Murabahah']);

        // Badge "Semua Akad" tetap menghitung seluruh populasi (tanpa filter akad).
        $this->assertSame(5, (int) $res->viewData('totalSemua'));
    }
}
