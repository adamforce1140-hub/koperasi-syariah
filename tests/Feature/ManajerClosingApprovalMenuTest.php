<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Jurnal;
use App\Models\KasHarian;
use App\Models\TellerOpname;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\NavigationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Regresi: fungsi APPROVE pada menu Closing Kas harus tersedia bagi Manajer.
 *
 * Akar masalah yang dijaga oleh test ini: entri menu "Approval Closing Kas"
 * tidak ada di menuManajer(), sehingga walau route + controller approve sudah
 * lengkap, Manajer tidak punya jalan masuk dari antarmuka.
 *
 * Cakupan:
 *  - Menu Manajer memuat entri Approval Closing Kas (+ badge antrean pending)
 *  - Menu role lain TIDAK memuat entri tersebut
 *  - Halaman antrean menampilkan tombol approve bagi Manajer
 *  - Riwayat persetujuan/penolakan tercatat di audit_logs
 *  - URL approve/reject yang dikirim ke view dapat di-resolve
 */
class ManajerClosingApprovalMenuTest extends TestCase
{
    use DatabaseTransactions;

    private function teller(): User
    {
        return User::firstOrCreate(
            ['email' => 'teller.menuclosing@example.test'],
            ['name' => 'Teller Menu Closing', 'password' => Hash::make('secret'), 'role' => 'teller', 'is_active' => true]
        );
    }

    private function manajer(): User
    {
        return User::firstOrCreate(
            ['email' => 'manajer.menuclosing@example.test'],
            ['name' => 'Manajer Menu Closing', 'password' => Hash::make('secret'), 'role' => 'manajer', 'is_active' => true]
        );
    }

    /** Siapkan sesi Buka Kas hari ini untuk teller. */
    private function sesiKas(User $teller, float $modalAwal): KasHarian
    {
        $today = now()->toDateString();

        KasHarian::where('user_id', $teller->id)->where('tanggal', $today)->delete();
        TellerOpname::where('user_id', $teller->id)->whereDate('tanggal', $today)->delete();
        Transaksi::where('user_id', $teller->id)->whereDate('waktu_transaksi', $today)->delete();

        return KasHarian::create([
            'user_id' => $teller->id,
            'tanggal' => $today,
            'modal_awal' => $modalAwal,
            'tambah_modal' => [],
            'waktu_opening' => now()->format('H:i:s'),
            'status_opname' => KasHarian::STATUS_OPEN,
        ]);
    }

    /** Susun map pecahan agar total fisik tepat sejumlah target. */
    private function pecahanUntuk(float $target): array
    {
        $sisa = (int) round($target);
        $map = [];

        foreach (TellerOpname::PECAHAN as $p) {
            $lembar = intdiv($sisa, $p);
            $map[$p] = $lembar;
            $sisa -= $lembar * $p;
        }

        return $map;
    }

    /** Ajukan opname pending lewat alur teller sungguhan. */
    private function ajukanOpname(User $teller, float $fisik, ?string $catatan = null): TellerOpname
    {
        $payload = ['rincian_pecahan' => $this->pecahanUntuk($fisik)];
        if ($catatan !== null) {
            $payload['catatan'] = $catatan;
        }

        $this->actingAs($teller)
            ->post(route('teller.kas.closing.store'), $payload)
            ->assertRedirect();

        return TellerOpname::where('user_id', $teller->id)
            ->where('status', TellerOpname::STATUS_PENDING)
            ->latest('id')
            ->firstOrFail();
    }

    /** Cari entri menu "Approval Closing Kas" di dalam struktur menu. */
    private function cariEntriMenu(array $menu): ?array
    {
        foreach ($menu as $section) {
            foreach ($section['children'] ?? [] as $child) {
                if (($child['label'] ?? null) === 'Approval Closing Kas') {
                    return $child;
                }
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────
    // 1. Root cause: entri menu wajib ada untuk Manajer
    // ─────────────────────────────────────────────────────────

    public function test_menu_manajer_memuat_approval_closing_kas(): void
    {
        $entri = $this->cariEntriMenu(NavigationService::getMenu('manajer'));

        $this->assertNotNull($entri, 'Menu Manajer harus memuat "Approval Closing Kas".');
        $this->assertSame('manajer.closing.index', $entri['route'],
            'Entri menu harus menunjuk route antrean approval closing manajer.');
        $this->assertNotEmpty(route($entri['route']), 'Route entri menu harus dapat di-resolve.');
    }

    /** Entri menu wajib berada di dalam section Verifikasi & Approval. */
    public function test_entri_menu_berada_di_section_verifikasi_approval(): void
    {
        $sectionDitemukan = null;

        foreach (NavigationService::getMenu('manajer') as $section) {
            foreach ($section['children'] ?? [] as $child) {
                if (($child['label'] ?? null) === 'Approval Closing Kas') {
                    $sectionDitemukan = $section['label'];
                }
            }
        }

        $this->assertSame('VERIFIKASI & APPROVAL', $sectionDitemukan);
    }

    /** Badge antrean dipasang agar Manajer melihat ada yang menunggu. */
    public function test_entri_menu_memiliki_badge_antrean_pending(): void
    {
        $entri = $this->cariEntriMenu(NavigationService::getMenu('manajer'));

        $this->assertNotNull($entri);
        $this->assertSame('closing_pending_manajer', $entri['badge'] ?? null,
            'Entri menu harus memakai badge antrean closing pending.');
    }

    /** Sidebar Manajer menampilkan entri menu + badge angka antrean pending. */
    public function test_sidebar_menampilkan_badge_antrean_pending(): void
    {
        $teller = $this->teller();
        $this->sesiKas($teller, 1000000);
        $opname = $this->ajukanOpname($teller, 1000000);

        $sidebar = $this->actingAs($this->manajer())
            ->get(route('manajer.closing.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Approval Closing Kas', $sidebar);
        $this->assertStringContainsString(route('manajer.closing.index'), $sidebar);

        // Badge merender angka antrean pending pada baris menu.
        $pending = TellerOpname::where('status', TellerOpname::STATUS_PENDING)->count();
        $this->assertGreaterThan(0, $pending);
        $this->assertMatchesRegularExpression(
            '/Approval Closing Kas.*?>\s*'.$pending.'\s*</s',
            $sidebar,
            'Badge antrean pending harus tampil di baris menu Approval Closing Kas.'
        );

        $this->assertSame(TellerOpname::STATUS_PENDING, $opname->fresh()->status);
    }

    /** Menu role lain tidak boleh memuat entri approval closing. */
    public function test_menu_role_lain_tidak_memuat_approval_closing(): void
    {
        foreach (['teller', 'ao', 'pengurus', 'dps'] as $role) {
            $this->assertNull(
                $this->cariEntriMenu(NavigationService::getMenu($role)),
                "Menu role {$role} tidak boleh memuat entri Approval Closing Kas."
            );
        }
    }

    /** Menu Manajer tidak boleh punya route yang tidak dapat di-resolve. */
    public function test_semua_route_menu_manajer_dapat_diresolve(): void
    {
        foreach (NavigationService::getMenu('manajer') as $section) {
            foreach ($section['children'] ?? [] as $child) {
                $this->assertNotEmpty(
                    route($child['route']),
                    "Route menu '{$child['label']}' ({$child['route']}) tidak dapat di-resolve."
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────
    // 2. Akses: hanya Manajer
    // ─────────────────────────────────────────────────────────

    public function test_manajer_dapat_membuka_antrean_closing(): void
    {
        $this->actingAs($this->manajer())
            ->get(route('manajer.closing.index'))
            ->assertOk()
            ->assertSee('Approval Closing Kas', false);
    }

    /** Alias rute (dipakai sebagian tautan) harus mengarah ke halaman yang sama. */
    public function test_alias_route_closing_kas_dapat_diakses_manajer(): void
    {
        $this->actingAs($this->manajer())
            ->get(route('manajer.closing-kas.index'))
            ->assertOk()
            ->assertSee('Approval Closing Kas', false);
    }

    public function test_teller_ao_pengurus_dps_tidak_bisa_akses_antrean_closing(): void
    {
        foreach (['teller', 'ao', 'pengurus', 'dps'] as $role) {
            $user = User::firstOrCreate(
                ['email' => "{$role}.closing.acl@example.test"],
                ['name' => strtoupper($role).' ACL', 'password' => Hash::make('secret'), 'role' => $role, 'is_active' => true]
            );

            $this->actingAs($user)
                ->get(route('manajer.closing.index'))
                ->assertForbidden();
        }
    }

    public function test_teller_tidak_bisa_memanggil_approve_dan_reject(): void
    {
        $teller = $this->teller();
        $this->sesiKas($teller, 1000000);
        $opname = $this->ajukanOpname($teller, 1000000);

        $this->actingAs($teller)
            ->post(route('manajer.closing.approve', $opname->id))
            ->assertForbidden();

        $this->actingAs($teller)
            ->post(route('manajer.closing.reject', $opname->id), ['catatan_approval' => 'coba tolak'])
            ->assertForbidden();

        $this->assertSame(TellerOpname::STATUS_PENDING, $opname->fresh()->status,
            'Status harus tetap pending setelah percobaan akses tidak sah.');
    }

    // ─────────────────────────────────────────────────────────
    // 3. Tampilan: tombol approve muncul untuk Manajer
    // ─────────────────────────────────────────────────────────

    public function test_halaman_menampilkan_tombol_approve_dan_reject_untuk_manajer(): void
    {
        $teller = $this->teller();
        $this->sesiKas($teller, 1500000);
        $opname = $this->ajukanOpname($teller, 1500000);

        $html = $this->actingAs($this->manajer())
            ->get(route('manajer.closing.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('btnKonfirmasiApprove', $html, 'Tombol approve harus dirender.');
        $this->assertStringContainsString('btnKonfirmasiReject', $html, 'Tombol tolak harus dirender.');
        $this->assertStringContainsString('Terima Kas', $html);

        // URL aksi dirender di dalam blok <script type="application/json">.
        // Perhatikan: json_encode() meng-escape "/" menjadi "\/", jadi bandingkan
        // terhadap bentuk ter-escape tersebut.
        $this->assertStringContainsString(
            addcslashes('/manajer/closing/'.$opname->id.'/approve', '/'),
            $html,
            'URL approve opname harus tersedia untuk tombol modal.'
        );
        $this->assertStringContainsString(
            addcslashes('/manajer/closing/'.$opname->id.'/reject', '/'),
            $html,
            'URL reject opname harus tersedia untuk tombol modal.'
        );
        $this->assertStringContainsString('opname-data-'.$opname->id, $html);
    }

    /**
     * Gating di dalam Blade: tombol approve hanya muncul untuk pengguna berperan
     * Manajer. View dirender langsung (tanpa middleware) supaya klausa @if di
     * dalam Blade benar-benar teruji.
     */
    public function test_blade_merender_tombol_hanya_untuk_manajer(): void
    {
        $teller = $this->teller();
        $manajer = $this->manajer();
        $this->sesiKas($teller, 1500000);
        $this->ajukanOpname($teller, 1500000);

        $opnames = TellerOpname::with(['user', 'approvedBy', 'jurnal'])->paginate(15);
        $statistik = ['pending' => 1, 'closed' => 0, 'rejected' => 0, 'nilai_pending' => 1500000.0];

        // Sebagai Manajer → tombol dirender.
        // Catatan: render langsung (tanpa middleware HTTP) tidak menyediakan
        // $errors dari ShareErrorsFromSession, jadi harus di-inject manual.
        $this->actingAs($manajer);
        $denganHak = view('manajer.closing.index', [
            'opnames' => $opnames,
            'status' => TellerOpname::STATUS_PENDING,
            'statistik' => $statistik,
            'error' => null,
            'errors' => new ViewErrorBag,
        ])->render();

        $this->assertStringContainsString('btnKonfirmasiApprove', $denganHak,
            'Manajer harus melihat tombol approve.');
        $this->assertStringContainsString('btnKonfirmasiReject', $denganHak);
        $this->assertStringNotContainsString('tidak memiliki hak akses', $denganHak);

        // Sebagai Teller → elemen tombol tidak dirender, ada pemberitahuan.
        // Catatan: nama id tetap muncul di blok JS modal (skrip statis), jadi yang
        // diuji adalah absennya ELEMEN tombol, bukan absennya string.
        $this->actingAs($teller);
        $tanpaHak = view('manajer.closing.index', [
            'opnames' => $opnames,
            'status' => TellerOpname::STATUS_PENDING,
            'statistik' => $statistik,
            'error' => null,
            'errors' => new ViewErrorBag,
        ])->render();

        $this->assertStringNotContainsString('id="btnKonfirmasiApprove"', $tanpaHak,
            'Non-Manajer tidak boleh melihat tombol approve.');
        $this->assertStringNotContainsString('id="btnKonfirmasiReject"', $tanpaHak,
            'Non-Manajer tidak boleh melihat tombol tolak.');
        $this->assertStringNotContainsString('id="catatanApproval"', $tanpaHak,
            'Non-Manajer tidak boleh melihat kolom catatan verifikasi.');
        $this->assertStringContainsString('tidak memiliki hak akses', $tanpaHak);
    }

    // ─────────────────────────────────────────────────────────
    // 4. Riwayat persetujuan (jejak audit)
    // ─────────────────────────────────────────────────────────

    public function test_approve_mencatat_riwayat_persetujuan(): void
    {
        $teller = $this->teller();
        $manajer = $this->manajer();
        $this->sesiKas($teller, 2000000);
        $opname = $this->ajukanOpname($teller, 2000000);

        $this->actingAs($manajer)
            ->post(route('manajer.closing.approve', $opname->id), ['catatan_approval' => 'Fisik sesuai.'])
            ->assertRedirect(route('manajer.closing.index'));

        $log = AuditLog::where('jenis_transaksi', 'Approve Closing Kas')
            ->where('user_id', $manajer->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Approve harus mencatat riwayat di audit_logs.');
        $this->assertSame(2000000.0, (float) $log->nominal);
        $this->assertStringContainsString('menyetujui closing kas', $log->keterangan);
        $this->assertStringContainsString($teller->name, $log->keterangan);
        $this->assertStringContainsString('opname #'.$opname->id, $log->keterangan);
        $this->assertNotNull($log->ip_address);
    }

    public function test_reject_mencatat_riwayat_penolakan(): void
    {
        $teller = $this->teller();
        $manajer = $this->manajer();
        $this->sesiKas($teller, 2000000);
        $opname = $this->ajukanOpname($teller, 1900000);

        $this->actingAs($manajer)
            ->post(route('manajer.closing.reject', $opname->id), [
                'catatan_approval' => 'Selisih Rp 100.000 mohon dihitung ulang.',
            ])
            ->assertRedirect(route('manajer.closing.index'));

        $log = AuditLog::where('jenis_transaksi', 'Reject Closing Kas')
            ->where('user_id', $manajer->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Penolakan harus mencatat riwayat di audit_logs.');
        $this->assertStringContainsString('menolak closing kas', $log->keterangan);
        $this->assertStringContainsString('Selisih Rp 100.000', $log->keterangan);
    }

    /**
     * Approve kedua ditolak sebelum masuk transaksi (guard `isPending()`), sehingga
     * tidak ada riwayat tambahan — jejak audit tetap satu baris per opname.
     * Guard ini juga yang mencegah jurnal pemindahan kas diposting dua kali.
     */
    public function test_approve_ganda_ditolak_tanpa_riwayat_dan_jurnal_tambahan(): void
    {
        $teller = $this->teller();
        $manajer = $this->manajer();
        $this->sesiKas($teller, 1000000);
        // Catatan wajib disertakan agar keterangan audit memuat penanda unik
        // "opname #<id> |" yang dipakai untuk menghitung jejak approve.
        $opname = $this->ajukanOpname($teller, 1000000, 'Setoran sesuai fisik.');

        $this->actingAs($manajer)
            ->post(route('manajer.closing.approve', $opname->id))
            ->assertRedirect(route('manajer.closing.index'));

        $jurnalPertama = $opname->fresh()->jurnal_id;
        $this->assertNotNull($jurnalPertama, 'Jurnal harus terbentuk pada approve pertama.');

        // Approve kedua: ditolak dengan pesan error.
        $this->actingAs($manajer)
            ->post(route('manajer.closing.approve', $opname->id))
            ->assertSessionHas('error');

        // Keterangan audit berbentuk:
        // "Manajer X menyetujui closing kas teller Y (opname #N) | Sistem: ... |"
        $this->assertSame(1, AuditLog::where('jenis_transaksi', 'Approve Closing Kas')
            ->where('keterangan', 'like', '%(opname #'.$opname->id.') |%')->count(),
            'Riwayat approve tidak boleh tercatat dua kali.');

        $this->assertSame($jurnalPertama, $opname->fresh()->jurnal_id,
            'Approve ganda tidak boleh mengganti jurnal.');
        $this->assertSame(TellerOpname::STATUS_CLOSED, $opname->fresh()->status);
    }

    /** Penolakan yang gagal validasi tidak boleh meninggalkan jejak audit. */
    public function test_reject_tanpa_alasan_tidak_mencatat_riwayat(): void
    {
        $teller = $this->teller();
        $manajer = $this->manajer();
        $this->sesiKas($teller, 1000000);
        $opname = $this->ajukanOpname($teller, 1000000);

        $sebelum = AuditLog::where('jenis_transaksi', 'Reject Closing Kas')->count();

        $this->actingAs($manajer)
            ->post(route('manajer.closing.reject', $opname->id), ['catatan_approval' => ''])
            ->assertSessionHasErrors('catatan_approval');

        $this->assertSame($sebelum, AuditLog::where('jenis_transaksi', 'Reject Closing Kas')->count(),
            'Penolakan tanpa alasan tidak boleh mencatat riwayat.');
        $this->assertSame(TellerOpname::STATUS_PENDING, $opname->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────
    // 5. Integrasi kas setelah approve
    // ─────────────────────────────────────────────────────────

    /** Setelah approve: opname closed, sesi kas closed, jurnal terbentuk & balance. */
    public function test_approve_menutup_sesi_kas_dan_membentuk_jurnal_balance(): void
    {
        $teller = $this->teller();
        $manajer = $this->manajer();
        $kas = $this->sesiKas($teller, 3000000);
        $opname = $this->ajukanOpname($teller, 3000000);

        $this->actingAs($manajer)
            ->post(route('manajer.closing.approve', $opname->id), ['catatan_approval' => 'Diterima.'])
            ->assertRedirect(route('manajer.closing.index'));

        $opname->refresh();
        $this->assertSame(TellerOpname::STATUS_CLOSED, $opname->status);
        $this->assertSame($manajer->id, (int) $opname->approved_by);
        $this->assertNotNull($opname->approved_at);
        $this->assertNotNull($opname->jurnal_id, 'Jurnal pemindahan kas harus terbentuk.');

        $kas->refresh();
        $this->assertSame(KasHarian::STATUS_CLOSED, $kas->status_opname);
        $this->assertSame(3000000.0, (float) $kas->fisik_kas);
        $this->assertSame(3000000.0, (float) $kas->saldo_sistem);
        $this->assertSame(0.0, (float) $kas->selisih);
        $this->assertNotNull($kas->waktu_closing);

        $jurnal = Jurnal::with('detail')->find($opname->jurnal_id);
        $this->assertTrue($jurnal->hitungBalance(), 'Jurnal pemindahan kas harus balance.');
        $this->assertSame((float) $jurnal->total_debet, (float) $jurnal->total_kredit);
    }
}
