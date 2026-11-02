<?php

namespace App\Services;

use App\Models\Anggota;

/**
 * CddService — logika domain Customer Due Diligence (CDD), Politically Exposed
 * Person (PEP), dan Pemilik Manfaat (beneficial owner) pada onboarding anggota.
 *
 * Dasar hukum:
 * - POJK No. 27/POJK.03/2022 (APU-PPT) — kewajiban CDD/EDD & penentuan profil risiko.
 * - UU No. 8/2010 Pasal 18 — kewajiban mengenali pemilik manfaat.
 * - Perpres No. 13/2018 — prinsip mengenali pemilik manfaat.
 *
 * Dipusatkan di satu tempat (SSOT) agar ketiga jalur onboarding
 * (CIF, quick-store AO, persetujuan pengajuan rekening) menghasilkan
 * perhitungan profil risiko & jenis CDD yang KONSISTEN.
 */
class CddService
{
    /**
     * Tentukan profil risiko nasabah.
     *
     * Aturan (POJK 27/2022, pendekatan berbasis risiko):
     * - Tergolong PEP            => Tinggi (wajib Enhanced Due Diligence).
     * - Ada pemilik manfaat yang diungkap => Sedang.
     * - Selain itu               => Rendah.
     */
    public function hitungProfilRisiko(?string $pepStatus, bool $adaPemilikManfaat = false): string
    {
        if ($pepStatus === Anggota::PEP_YA) {
            return Anggota::RISIKO_TINGGI;
        }

        return $adaPemilikManfaat ? Anggota::RISIKO_SEDANG : Anggota::RISIKO_RENDAH;
    }

    /**
     * Tentukan jenis CDD berdasarkan profil risiko.
     * Risiko Tinggi => Enhanced Due Diligence (Mendalam), selain itu Sederhana.
     */
    public function jenisCdd(string $profilRisiko): string
    {
        return $profilRisiko === Anggota::RISIKO_TINGGI
            ? Anggota::CDD_MENDALAM
            : Anggota::CDD_SEDERHANA;
    }

    /**
     * Bangun atribut CDD siap-simpan untuk model Anggota.
     *
     * Menetapkan profil risiko, jenis CDD, serta jejak verifikasi
     * (`cdd_verified_at` / `cdd_verified_by`) sekaligus. Hanya kunci yang
     * relevan dengan kolom `anggota` yang dikembalikan.
     *
     * @param  array<string, mixed>  $data  Data onboarding (hasil validasi).
     * @return array<string, mixed>
     */
    public function terapkan(array $data, ?int $verifikatorId = null): array
    {
        $pep = $data['pep_status'] ?? null;
        $adaPm = filled($data['pemilik_manfaat_nama'] ?? null);

        $profil = $this->hitungProfilRisiko($pep, $adaPm);

        return [
            'pep_status' => $pep,
            'pep_keterangan' => $data['pep_keterangan'] ?? null,
            'pemilik_manfaat_nama' => $data['pemilik_manfaat_nama'] ?? null,
            'pemilik_manfaat_hubungan' => $data['pemilik_manfaat_hubungan'] ?? null,
            'pemilik_manfaat_keterangan' => $data['pemilik_manfaat_keterangan'] ?? null,
            'profil_risiko' => $profil,
            'cdd_jenis' => $this->jenisCdd($profil),
            'cdd_verified_at' => now(),
            'cdd_verified_by' => $verifikatorId,
        ];
    }
}
