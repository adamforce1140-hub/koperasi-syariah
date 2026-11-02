<?php

namespace Tests\Feature;

use App\Models\Pembiayaan;
use App\Support\AlurStatusPembiayaan;
use Tests\TestCase;

/**
 * Uji state machine status pembiayaan config-driven (Rekomendasi 4 — langkah 1).
 *
 * Fokus utama:
 *  1. Peta transisi adalah DATA di `config/alur_pembiayaan.php`, bukan if/guard
 *     prosedural yang tersebar.
 *  2. Peta memuat SELURUH status kanonik dan menandai status terminal dengan
 *     benar (lunas / ditolak / dibatalkan / hapus_buku).
 *  3. SIFAT DESKRIPTIF: menambahkan kelas ini tidak mengubah perilaku — tanpa
 *     pemanggil yang menegakkannya, sistem tetap seperti sebelumnya.
 */
class AlurStatusPembiayaanTest extends TestCase
{
    /* ══════════════════════════════════════════════════════════════════════
     | 1. Cakupan peta
     ══════════════════════════════════════════════════════════════════════ */

    public function test_peta_transisi_memuat_seluruh_status_kanonik(): void
    {
        $peta = AlurStatusPembiayaan::transisi();

        foreach (Pembiayaan::canonicalStatuses() as $status) {
            $this->assertArrayHasKey(
                $status,
                $peta,
                "Status kanonik '{$status}' harus terdaftar di peta transisi."
            );
        }
    }

    public function test_seluruh_tujuan_transisi_dikenal_sistem(): void
    {
        $kanonik = Pembiayaan::canonicalStatuses();

        foreach (AlurStatusPembiayaan::transisi() as $dari => $tujuan) {
            foreach ((array) $tujuan as $status) {
                $this->assertContains(
                    $status,
                    $kanonik,
                    "Transisi '{$dari}' menuju status tak dikenal '{$status}'."
                );
            }
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
     | 2. Status terminal
     ══════════════════════════════════════════════════════════════════════ */

    public function test_status_terminal_tidak_punya_transisi_keluar(): void
    {
        $terminal = [
            Pembiayaan::STATUS_LUNAS,
            Pembiayaan::STATUS_DITOLAK,
            Pembiayaan::STATUS_DIBATALKAN,
            Pembiayaan::STATUS_HAPUS_BUKU,
        ];

        $this->assertEqualsCanonicalizing($terminal, AlurStatusPembiayaan::statusAkhir());

        foreach ($terminal as $status) {
            $this->assertSame([], AlurStatusPembiayaan::transisiSah($status));
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
     | 3. Transisi kunci daur hidup
     ══════════════════════════════════════════════════════════════════════ */

    public function test_rantai_persetujuan_berjenjang_sesuai_konfigurasi(): void
    {
        $this->assertTrue(
            AlurStatusPembiayaan::bolehTransisi(
                Pembiayaan::STATUS_PENDING_AO,
                Pembiayaan::STATUS_PENDING_MANAJER
            ),
            'AO harus dapat meneruskan ke Manajer.'
        );

        $this->assertTrue(
            AlurStatusPembiayaan::bolehTransisi(
                Pembiayaan::STATUS_PENDING_MANAJER,
                Pembiayaan::STATUS_PENDING_PENGURUS
            ),
            'Manajer luar limit harus dapat meneruskan ke Pengurus.'
        );

        $this->assertTrue(
            AlurStatusPembiayaan::bolehTransisi(
                Pembiayaan::STATUS_PENDING_PENGURUS,
                Pembiayaan::STATUS_PENDING_DPS
            ),
            'Pengurus harus dapat meneruskan ke DPS.'
        );

        $this->assertTrue(
            AlurStatusPembiayaan::bolehTransisi(
                Pembiayaan::STATUS_PENDING_DPS,
                Pembiayaan::STATUS_DISETUJUI
            ),
            'DPS harus dapat mengesahkan (pengesahan akad → disetujui).'
        );
    }

    public function test_pencairan_dan_pelunasan_berjalan_sesuai_daur_hidup(): void
    {
        // Disetujui → dicairkan (pencairan langsung).
        $this->assertTrue(AlurStatusPembiayaan::bolehTransisi(
            Pembiayaan::STATUS_DISETUJUI,
            Pembiayaan::STATUS_DICAIRKAN
        ));

        // Dicairkan → aktif (normalisasi autodebet/angsuran pertama).
        $this->assertTrue(AlurStatusPembiayaan::bolehTransisi(
            Pembiayaan::STATUS_DICAIRKAN,
            Pembiayaan::STATUS_AKTIF
        ));

        // Angsuran terakhir → lunas.
        $this->assertTrue(AlurStatusPembiayaan::bolehTransisi(
            Pembiayaan::STATUS_AKTIF,
            Pembiayaan::STATUS_LUNAS
        ));

        // Macet → aktif (pemulihan kolektibilitas oleh CkpnService).
        $this->assertTrue(AlurStatusPembiayaan::bolehTransisi(
            Pembiayaan::STATUS_MACET,
            Pembiayaan::STATUS_AKTIF
        ));

        // Aktif → hapus buku (write-off PSAK 71).
        $this->assertTrue(AlurStatusPembiayaan::bolehTransisi(
            Pembiayaan::STATUS_AKTIF,
            Pembiayaan::STATUS_HAPUS_BUKU
        ));
    }

    public function test_transisi_terlarang_ditolak_oleh_penjaga(): void
    {
        // Status terminal tidak boleh menghidupkan kembali kontrak.
        $this->assertFalse(AlurStatusPembiayaan::bolehTransisi(
            Pembiayaan::STATUS_LUNAS,
            Pembiayaan::STATUS_AKTIF
        ));

        $this->assertFalse(AlurStatusPembiayaan::bolehTransisi(
            Pembiayaan::STATUS_HAPUS_BUKU,
            Pembiayaan::STATUS_MACET
        ));

        // Tidak boleh melompati jenjang persetujuan.
        $this->assertFalse(AlurStatusPembiayaan::bolehTransisi(
            Pembiayaan::STATUS_PENDING_AO,
            Pembiayaan::STATUS_PENDING_DPS
        ));

        // Pembiayaan yang belum cair tidak boleh langsung lunas.
        $this->assertFalse(AlurStatusPembiayaan::bolehTransisi(
            Pembiayaan::STATUS_PENDING_AO,
            Pembiayaan::STATUS_LUNAS
        ));
    }

    /* ══════════════════════════════════════════════════════════════════════
     | 4. Perilaku tahan banting & netral
     ══════════════════════════════════════════════════════════════════════ */

    public function test_transisi_ke_status_sama_selalu_sah(): void
    {
        // Menyegarkan data pada status yang sama (mis. tanggal review) bukan
        // perpindahan tahap — sistem saat ini memang melakukannya.
        foreach ([Pembiayaan::STATUS_PENDING_MANAJER, Pembiayaan::STATUS_AKTIF] as $status) {
            $this->assertTrue(AlurStatusPembiayaan::bolehTransisi($status, $status));
        }
    }

    public function test_ejaan_status_case_insensitive_dan_beralias(): void
    {
        // Konstanta & alias ('cair') diperlakukan sama.
        $this->assertTrue(AlurStatusPembiayaan::bolehTransisi(
            'PENDING_MANAJER',
            'disetujui'
        ));

        $this->assertSame(
            AlurStatusPembiayaan::transisiSah(Pembiayaan::STATUS_DICAIRKAN),
            AlurStatusPembiayaan::transisiSah('cair')
        );
    }

    public function test_status_tak_dikenal_tidak_punya_transisi(): void
    {
        $this->assertSame([], AlurStatusPembiayaan::transisiSah('status_karangan'));

        $this->assertFalse(AlurStatusPembiayaan::bolehTransisi('status_karangan', Pembiayaan::STATUS_AKTIF));
    }
}
