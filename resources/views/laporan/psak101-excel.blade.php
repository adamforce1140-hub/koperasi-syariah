@php
    /**
     * Berkas ekspor Excel — HTML standalone (tanpa @extends) agar dapat dibuka
     * langsung oleh Excel/WPS. Format angka memakai mso-number-format supaya
     * Excel memperlakukannya sebagai bilangan, bukan teks.
     */
    $fmtAngka = fn ($nilai) => number_format((float) $nilai, 2, ',', '');

    $p    = $komponen['posisi'];
    $lr   = $komponen['labaRugi'];
    $pe   = $komponen['perubahanEkuitas'];
    $ak   = $komponen['arusKas'];
    $dit  = $komponen['dit'];
    $calk = $komponen['kepatuhan'];
    $keb  = $komponen['kebijakan'];
    $cat  = $komponen['catatan'];
    $zkt  = $komponen['zakat'];
@endphp
<table border="0" cellspacing="0" cellpadding="4">
    <tr>
        <td colspan="6" style="font-size:14pt;"><b>LAPORAN KEUANGAN SYARIAH</b></td>
    </tr>
    <tr>
        <td colspan="6">
            Periode {{ $periode['dari']->format('d/m/Y') }} &ndash; {{ $periode['sampai']->format('d/m/Y') }}
            &mdash; Standar PSAK 101
        </td>
    </tr>
    <tr><td colspan="6"></td></tr>
</table>

{{-- ── 1. POSISI KEUANGAN ─────────────────────────────────────────── --}}
<table border="1" cellspacing="0" cellpadding="4">
    <tr>
        <td colspan="3" style="background:#e2e8f0;"><b>1. LAPORAN POSISI KEUANGAN</b></td>
    </tr>
    <tr style="background:#f1f5f9;">
        <td><b>Kode</b></td>
        <td><b>Nama Akun</b></td>
        <td><b>Saldo</b></td>
    </tr>

    @foreach($p['asetGroups'] as $grup)
    <tr><td colspan="3" style="background:#f8fafc;"><b>{{ strtoupper($grup['label']) }}</b></td></tr>
        @foreach($grup['akun'] as $a)
        <tr>
            <td>{{ $a->kode_akun }}</td>
            <td>{{ $a->nama_akun }}</td>
            <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($a->saldoBerjalan($periode['sampaiStr'])) }}</td>
        </tr>
        @endforeach
    @endforeach
    <tr style="background:#dbeafe;">
        <td colspan="2"><b>TOTAL ASET</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b>{{ $fmtAngka($p['totalAset']) }}</b></td>
    </tr>

    <tr><td colspan="3"></td></tr>
    <tr><td colspan="3" style="background:#f8fafc;"><b>KEWAJIBAN</b></td></tr>
    @foreach($p['kewajibanGroups'] as $grup)
        @foreach($grup['akun'] as $k)
        <tr>
            <td>{{ $k->kode_akun }}</td>
            <td>{{ $k->nama_akun }}</td>
            <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($k->saldoBerjalan($periode['sampaiStr'])) }}</td>
        </tr>
        @endforeach
    @endforeach
    <tr style="background:#f8fafc;">
        <td colspan="2"><b>Total Kewajiban</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($p['totalKewajiban']) }}</td>
    </tr>

    <tr><td colspan="3"></td></tr>
    <tr><td colspan="3" style="background:#f8fafc;"><b>DANA SYIRKAH TEMPORER</b></td></tr>
    @foreach($p['dstGroups'] as $grup)
        @foreach($grup['akun'] as $d)
        <tr>
            <td>{{ $d->kode_akun }}</td>
            <td>{{ $d->nama_akun }}</td>
            <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($d->saldoBerjalan($periode['sampaiStr'])) }}</td>
        </tr>
        @endforeach
    @endforeach
    <tr style="background:#f8fafc;">
        <td colspan="2"><b>Total Dana Syirkah Temporer</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($p['totalDanaSyirkahTemporer']) }}</td>
    </tr>

    <tr><td colspan="3"></td></tr>
    <tr><td colspan="3" style="background:#f8fafc;"><b>EKUITAS</b></td></tr>
    @foreach($p['ekuitasGroups'] as $grup)
        @foreach($grup['akun'] as $e)
        <tr>
            <td>{{ $e->kode_akun }}</td>
            <td>{{ $e->nama_akun }}</td>
            <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($e->saldoBerjalanLaporan($periode['sampaiStr'])) }}</td>
        </tr>
        @endforeach
    @endforeach
    <tr>
        <td>&mdash;</td>
        <td>Laba (Rugi) Berjalan</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($p['labaBersih']) }}</td>
    </tr>
    <tr style="background:#f8fafc;">
        <td colspan="2"><b>Total Ekuitas</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($p['totalEkuitas']) }}</td>
    </tr>
    <tr style="background:#dbeafe;">
        <td colspan="2"><b>TOTAL PASIVA</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b>{{ $fmtAngka($p['totalPasiva']) }}</b></td>
    </tr>
    <tr>
        <td colspan="2">
            Selisih (Aset &minus; Pasiva) &mdash;
            {{ $p['seimbang'] ? 'SEIMBANG' : 'TIDAK SEIMBANG' }}
        </td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($p['selisihNeraca']) }}</td>
    </tr>
</table>

<br>

{{-- ── 2. LABA RUGI ──────────────────────────────────────────────── --}}
<table border="1" cellspacing="0" cellpadding="4">
    <tr><td colspan="3" style="background:#e2e8f0;"><b>2. LAPORAN LABA RUGI</b></td></tr>
    <tr style="background:#f1f5f9;">
        <td><b>Kode</b></td>
        <td><b>Uraian</b></td>
        <td><b>Jumlah</b></td>
    </tr>

    <tr><td colspan="3" style="background:#f8fafc;"><b>PENDAPATAN USAHA</b></td></tr>
    @foreach($lr['pendapatanUsaha'] as $r)
    <tr>
        <td>{{ $r['kode'] }}</td>
        <td>{{ $r['nama'] }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['saldo']) }}</td>
    </tr>
    @endforeach
    <tr style="background:#f8fafc;">
        <td colspan="2"><b>Jumlah Pendapatan Usaha</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($lr['totalPendapatanUsaha']) }}</td>
    </tr>

    @if($lr['pendapatanLain']->isNotEmpty())
    <tr><td colspan="3" style="background:#f8fafc;"><b>PENDAPATAN LAIN-LAIN</b></td></tr>
        @foreach($lr['pendapatanLain'] as $r)
        <tr>
            <td>{{ $r['kode'] }}</td>
            <td>{{ $r['nama'] }}</td>
            <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['saldo']) }}</td>
        </tr>
        @endforeach
    @endif

    @if($lr['pendapatanTakKlasifikasi']->isNotEmpty())
    <tr><td colspan="3" style="background:#fef3c7;"><b>PENDAPATAN &mdash; BELUM DIKLASIFIKASI</b></td></tr>
        @foreach($lr['pendapatanTakKlasifikasi'] as $r)
        <tr>
            <td>{{ $r['kode'] }}</td>
            <td>{{ $r['nama'] }}</td>
            <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['saldo']) }}</td>
        </tr>
        @endforeach
    @endif

    <tr style="background:#dbeafe;">
        <td colspan="2"><b>TOTAL PENDAPATAN</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b>{{ $fmtAngka($lr['totalPendapatan']) }}</b></td>
    </tr>

    <tr><td colspan="3" style="background:#f8fafc;"><b>BEBAN USAHA</b></td></tr>
    @foreach($lr['bebanUsaha'] as $r)
    <tr>
        <td>{{ $r['kode'] }}</td>
        <td>{{ $r['nama'] }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['saldo']) }}</td>
    </tr>
    @endforeach
    <tr style="background:#f8fafc;">
        <td colspan="2"><b>Jumlah Beban Usaha</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($lr['totalBebanUsaha']) }}</td>
    </tr>

    @if($lr['bebanLain']->isNotEmpty())
    <tr><td colspan="3" style="background:#f8fafc;"><b>BEBAN LAIN-LAIN</b></td></tr>
        @foreach($lr['bebanLain'] as $r)
        <tr>
            <td>{{ $r['kode'] }}</td>
            <td>{{ $r['nama'] }}</td>
            <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['saldo']) }}</td>
        </tr>
        @endforeach
    @endif

    @if($lr['bebanTakKlasifikasi']->isNotEmpty())
    <tr><td colspan="3" style="background:#fef3c7;"><b>BEBAN &mdash; BELUM DIKLASIFIKASI</b></td></tr>
        @foreach($lr['bebanTakKlasifikasi'] as $r)
        <tr>
            <td>{{ $r['kode'] }}</td>
            <td>{{ $r['nama'] }}</td>
            <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['saldo']) }}</td>
        </tr>
        @endforeach
    @endif

    <tr style="background:#dbeafe;">
        <td colspan="2"><b>TOTAL BEBAN</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b>{{ $fmtAngka($lr['totalBeban']) }}</b></td>
    </tr>
    <tr style="background:#f8fafc;">
        <td colspan="2"><b>Laba Kotor</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($lr['labaKotor']) }}</td>
    </tr>
    <tr style="background:#dbeafe;">
        <td colspan="2"><b>LABA (RUGI) BERSIH &mdash; {{ strtoupper($lr['statusLaba']) }}</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b>{{ $fmtAngka($lr['labaBersih']) }}</b></td>
    </tr>
</table>

<br>

{{-- ── 3. PERUBAHAN EKUITAS ──────────────────────────────────────── --}}
<table border="1" cellspacing="0" cellpadding="4">
    <tr><td colspan="6" style="background:#e2e8f0;"><b>3. LAPORAN PERUBAHAN EKUITAS</b></td></tr>
    <tr style="background:#f1f5f9;">
        <td><b>Kode</b></td>
        <td><b>Komponen Ekuitas</b></td>
        <td><b>Saldo Awal</b></td>
        <td><b>Penambah</b></td>
        <td><b>Pengurang</b></td>
        <td><b>Saldo Akhir</b></td>
    </tr>
    @foreach($pe['baris'] as $r)
    <tr>
        <td>{{ $r['kode'] }}</td>
        <td>{{ $r['nama'] }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['saldoAwal']) }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['penambah']) }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['pengurang']) }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['saldoAkhir']) }}</td>
    </tr>
    @endforeach
    <tr style="background:#f8fafc;">
        <td colspan="2"><b>Subtotal</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($pe['totalAwal']) }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($pe['totalPenambah']) }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($pe['totalPengurang']) }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($pe['totalAkhir']) }}</td>
    </tr>
    <tr>
        <td colspan="2">Laba (Rugi) Bersih Periode Berjalan</td>
        <td></td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($pe['labaBersih']) }}</td>
        <td></td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($pe['labaBersih']) }}</td>
    </tr>
    <tr style="background:#dbeafe;">
        <td colspan="2"><b>TOTAL EKUITAS (termasuk laba berjalan)</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($pe['totalAwalTermasukLaba']) }}</td>
        <td></td>
        <td></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b>{{ $fmtAngka($pe['totalAkhirTermasukLaba']) }}</b></td>
    </tr>
</table>

<br>

{{-- ── 4. ARUS KAS ───────────────────────────────────────────────── --}}
<table border="1" cellspacing="0" cellpadding="4">
    <tr><td colspan="4" style="background:#e2e8f0;"><b>4. LAPORAN ARUS KAS (METODE LANGSUNG)</b></td></tr>
    <tr style="background:#f1f5f9;">
        <td><b>Uraian</b></td>
        <td><b>Masuk</b></td>
        <td><b>Keluar</b></td>
        <td><b>Neto</b></td>
    </tr>
    @foreach(['operasi' => $ak['operasi'], 'investasi' => $ak['investasi'], 'pendanaan' => $ak['pendanaan'], 'lainnya' => $ak['lainnya']] as $kunci => $bag)
        @if($bag['baris']->isEmpty() && $kunci === 'lainnya')
            @continue
        @endif
        <tr><td colspan="4" style="background:#f8fafc;"><b>{{ strtoupper($bag['label']) }}</b></td></tr>
        @foreach($bag['baris'] as $b)
        <tr>
            <td>{{ $b['label'] }} ({{ $b['akun'] }})</td>
            <td align="right" style="mso-number-format:'#,##0.00';">{{ $b['masuk'] > 0 ? $fmtAngka($b['masuk']) : '' }}</td>
            <td align="right" style="mso-number-format:'#,##0.00';">{{ $b['keluar'] > 0 ? $fmtAngka($b['keluar']) : '' }}</td>
            <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($b['neto']) }}</td>
        </tr>
        @endforeach
        <tr style="background:#f8fafc;">
            <td colspan="3"><b>Jumlah {{ $bag['label'] }}</b></td>
            <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($bag['total']) }}</td>
        </tr>
    @endforeach
    <tr style="background:#f8fafc;">
        <td colspan="3"><b>Kenaikan (Penurunan) Kas Bersih</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($ak['kenaikanKas']) }}</td>
    </tr>
    <tr>
        <td colspan="3">Saldo Kas &amp; Bank Awal Periode</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($ak['saldoAwalKas']) }}</td>
    </tr>
    <tr style="background:#dbeafe;">
        <td colspan="3"><b>SALDO KAS &amp; BANK AKHIR PERIODE</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b>{{ $fmtAngka($ak['saldoAkhirKas']) }}</b></td>
    </tr>
    <tr>
        <td colspan="3">
            Verifikasi rekonsiliasi &mdash; {{ $ak['rekonsiliasiCocok'] ? 'COCOK' : 'SELISIH' }}
        </td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($ak['selisihRekonsiliasi']) }}</td>
    </tr>
</table>

<br>

{{-- ── 5. PERUBAHAN DIT ──────────────────────────────────────────── --}}
<table border="1" cellspacing="0" cellpadding="4">
    <tr><td colspan="6" style="background:#e2e8f0;"><b>5. LAPORAN PERUBAHAN DANA INVESTASI TERIKAT (PSAK 109)</b></td></tr>
    <tr style="background:#f1f5f9;">
        <td><b>Jenis Dana</b></td>
        <td><b>Kode</b></td>
        <td><b>Saldo Awal</b></td>
        <td><b>Penerimaan</b></td>
        <td><b>Penyaluran</b></td>
        <td><b>Saldo Akhir</b></td>
    </tr>
    @foreach($dit['baris'] as $r)
    <tr>
        <td>{{ $r['label'] }}{{ $r['akunTersedia'] ? '' : ' (akun tidak ditemukan)' }}</td>
        <td>{{ $r['kode'] }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['saldoAwal']) }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['penerimaan']) }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['penyaluran']) }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['saldoAkhir']) }}</td>
    </tr>
    @endforeach
    <tr style="background:#dbeafe;">
        <td colspan="2"><b>TOTAL</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b>{{ $fmtAngka($dit['totalAwal']) }}</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b>{{ $fmtAngka($dit['totalPenerimaan']) }}</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b>{{ $fmtAngka($dit['totalPenyaluran']) }}</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b>{{ $fmtAngka($dit['totalAkhir']) }}</b></td>
    </tr>
    <tr>
        <td colspan="6">
            Mutasi {{ $dit['mutasiSeimbang'] ? 'SEIMBANG' : 'TIDAK SEIMBANG' }} &mdash;
            dana titipan (amanah), bukan pendapatan koperasi dan bukan bagian ekuitas.
        </td>
    </tr>
</table>

<br>

{{-- ── 6. CaLK ───────────────────────────────────────────────────── --}}
<table border="1" cellspacing="0" cellpadding="4">
    <tr><td colspan="6" style="background:#e2e8f0;"><b>6. CATATAN ATAS LAPORAN KEUANGAN (CaLK)</b></td></tr>
    <tr><td colspan="6"><b>6.a Kebijakan Akuntansi &amp; Asumsi</b></td></tr>
    <tr><td colspan="2">Dasar penyusunan</td><td colspan="4">{{ $keb['dasarPenyusunan'] }}</td></tr>
    <tr><td colspan="2">Standar acuan</td><td colspan="4">{{ $keb['standar'] }}</td></tr>
    <tr><td colspan="2">Mata uang</td><td colspan="4">{{ $keb['mataUang'] }}</td></tr>
    <tr><td colspan="2">Pembulatan</td><td colspan="4">{{ $keb['pembulatan'] }}</td></tr>
    <tr><td colspan="2">Sumber kebenaran nilai</td><td colspan="4">{{ $keb['sumberKebenaran'] }}</td></tr>
    <tr><td colspan="2">Kolom kanonik</td><td colspan="4">{{ $keb['kolomKanonik'] }}</td></tr>
    <tr><td colspan="2">Dana titipan (PSAK 109)</td><td colspan="4">{{ $keb['danaTitipan'] }}</td></tr>
    <tr><td colspan="2">Dana Syirkah Temporer</td><td colspan="4">{{ $keb['danaSyirkahTemporer'] }}</td></tr>
    <tr><td colspan="2">Batas penyaluran zakat</td><td colspan="4">{{ $keb['batasZakat'] }}</td></tr>
    <tr>
        <td colspan="2">Toleransi selisih jurnal</td>
        <td colspan="4" align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($keb['toleransiSelisih']) }}</td>
    </tr>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.b Rincian Piutang Pembiayaan per Akad</b></td></tr>
    <tr style="background:#f1f5f9;">
        <td><b>Akad</b></td><td><b>Kode</b></td><td><b>Nama Akun</b></td>
        <td><b>Saldo</b></td><td><b>Mutasi Debet</b></td><td><b>Mutasi Kredit</b></td>
    </tr>
    @foreach($cat['piutang'] as $r)
    <tr>
        <td>{{ $r['akad'] }}</td>
        <td>{{ $r['kode'] }}</td>
        <td>{{ $r['nama'] }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['saldo']) }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['mutasi']['debet']) }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['mutasi']['kredit']) }}</td>
    </tr>
    @endforeach
    <tr style="background:#dbeafe;">
        <td colspan="3"><b>TOTAL PIUTANG PEMBIAYAAN</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b>{{ $fmtAngka($cat['totalPiutang']) }}</b></td>
        <td colspan="2"></td>
    </tr>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.c CKPN &amp; Persentase per Kolektibilitas</b></td></tr>
    <tr>
        <td colspan="2">{{ $cat['ckpn']['kode'] }} {{ $cat['ckpn']['nama'] }}</td>
        <td colspan="2">Saldo</td>
        <td colspan="2" align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($cat['ckpn']['saldo']) }}</td>
    </tr>
    @foreach($cat['ckpn']['persen'] as $kode => $persen)
    <tr>
        <td colspan="2">{{ strtoupper($kode) }}</td>
        <td colspan="4" align="right" style="mso-number-format:'0.00';">{{ number_format((float) $persen, 2, ',', '') }}%</td>
    </tr>
    @endforeach

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.d Margin Murabahah Ditangguhkan</b></td></tr>
    <tr>
        <td colspan="2">{{ $cat['marginDitangguhkan']['kode'] }} {{ $cat['marginDitangguhkan']['nama'] }}</td>
        <td colspan="2">Saldo</td>
        <td colspan="2" align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($cat['marginDitangguhkan']['saldo']) }}</td>
    </tr>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.e Rincian Kas &amp; Bank</b></td></tr>
    @foreach($cat['kas'] as $r)
    <tr>
        <td colspan="2">{{ $r['kode'] }} {{ $r['nama'] }}</td>
        <td colspan="2">Saldo</td>
        <td colspan="2" align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['saldo']) }}</td>
    </tr>
    @endforeach

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.f Uji Kepatuhan Penyajian</b></td></tr>
    <tr>
        <td colspan="3">Kelompok penyajian terisi / total kelompok</td>
        <td colspan="3">{{ collect($calk['kelompokDisajikan'])->filter()->count() }} / {{ count($calk['kelompokDisajikan']) }}</td>
    </tr>
    <tr>
        <td colspan="3">Jumlah jurnal periode</td>
        <td colspan="3">{{ (int) $calk['mutasi']['jumlah_jurnal'] }}</td>
    </tr>
    <tr>
        <td colspan="3">Keseimbangan debet &minus; kredit</td>
        <td colspan="3" align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($calk['mutasi']['selisih']) }}</td>
    </tr>
    <tr>
        <td colspan="3">Status kepatuhan</td>
        <td colspan="3">
            {{ $calk['patuh'] ? 'PATUH' : count($calk['temuan']) . ' TEMUAN' }}
        </td>
    </tr>
    @foreach($calk['temuan'] as $temuan)
    <tr><td colspan="6">Temuan: {{ $temuan }}</td></tr>
    @endforeach

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6" style="background:#e2e8f0;"><b>6.g Laporan Sumber &amp; Penggunaan Dana Zakat (PSAK 109)</b></td></tr>
    <tr><td colspan="6">Dana zakat adalah dana titipan (amanah), bukan pendapatan koperasi; disajikan terpisah dari Laba Rugi dan tidak memengaruhi SHU.</td></tr>
    <tr style="background:#f1f5f9;">
        <td><b>Uraian</b></td><td><b>Saldo Awal</b></td><td><b>Penerimaan</b></td>
        <td><b>Penyaluran</b></td><td><b>Saldo Akhir</b></td><td></td>
    </tr>
    <tr>
        <td>Dana Zakat ({{ $zkt['akun'] }})</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($zkt['saldoAwal']) }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($zkt['penerimaan']) }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($zkt['penyaluran']) }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($zkt['saldoAkhir']) }}</td>
        <td></td>
    </tr>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.g.1 Sumber Dana &mdash; per Jenis Zakat</b></td></tr>
    <tr style="background:#f1f5f9;"><td colspan="4"><b>Jenis Zakat</b></td><td colspan="2"><b>Jumlah</b></td></tr>
    @foreach($zkt['rincianPenerimaan'] as $r)
    <tr>
        <td colspan="4">{{ $r['label'] }}{{ $r['kode'] === '__lainnya' ? ' (di luar daftar baku)' : '' }}</td>
        <td colspan="2" align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['jumlah']) }}</td>
    </tr>
    @endforeach
    <tr style="background:#dbeafe;">
        <td colspan="4"><b>TOTAL PENERIMAAN</b></td>
        <td colspan="2" align="right" style="mso-number-format:'#,##0.00';"><b>{{ $fmtAngka($zkt['totalRincianPenerimaan']) }}</b></td>
    </tr>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.g.2 Penggunaan Dana &mdash; per Asnaf</b></td></tr>
    <tr style="background:#f1f5f9;"><td colspan="3"><b>Asnaf</b></td><td><b>Jumlah</b></td><td colspan="2"><b>Persentase</b></td></tr>
    @foreach($zkt['rincianPenyaluran'] as $r)
    <tr>
        <td colspan="3">{{ $r['label'] }}{{ $r['kode'] === '__lainnya' ? ' (di luar 8 asnaf)' : '' }}</td>
        <td align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($r['jumlah']) }}</td>
        <td colspan="2" align="right" style="mso-number-format:'0.00';">{{ number_format((float) $r['persen'], 2, ',', '') }}%</td>
    </tr>
    @endforeach
    <tr style="background:#dbeafe;">
        <td colspan="3"><b>TOTAL PENYALURAN</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b>{{ $fmtAngka($zkt['totalRincianPenyaluran']) }}</b></td>
        <td colspan="2" align="right"><b>{{ $zkt['totalRincianPenyaluran'] > 0 ? '100.00%' : '-' }}</b></td>
    </tr>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.g.3 Rekonsiliasi Hak Amil ({{ number_format((float) $zkt['amil']['persen'], 2, ',', '') }}%)</b></td></tr>
    <tr><td colspan="3">Dasar hak (penerimaan)</td><td colspan="3" align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($zkt['amil']['dasar']) }}</td></tr>
    <tr><td colspan="3">Hak amil</td><td colspan="3" align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($zkt['amil']['hak']) }}</td></tr>
    <tr><td colspan="3">Realisasi (asnaf amil)</td><td colspan="3" align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($zkt['amil']['realisasi']) }}</td></tr>
    <tr><td colspan="3">Sisa hak belum direalisasikan</td><td colspan="3" align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($zkt['amil']['sisa']) }}</td></tr>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.g.4 Rekonsiliasi Jurnal vs Buku Besar Modul</b></td></tr>
    <tr><td colspan="3">Selisih penerimaan</td><td colspan="3" align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($zkt['kontrol']['selisihPenerimaan']) }}</td></tr>
    <tr><td colspan="3">Selisih dana tersalurkan</td><td colspan="3" align="right" style="mso-number-format:'#,##0.00';">{{ $fmtAngka($zkt['kontrol']['selisihPenyaluran']) }}</td></tr>
    <tr><td colspan="3">Baris tanpa jurnal</td><td colspan="3">{{ (int) $zkt['kontrol']['tanpaJurnal'] }}</td></tr>
    <tr><td colspan="3">Penyaluran di luar 8 asnaf</td><td colspan="3">{{ (int) $zkt['kontrol']['asnafDiLuar'] }}</td></tr>
    <tr>
        <td colspan="3">Status rekonsiliasi</td>
        <td colspan="3">
            {{ $zkt['kontrol']['selaras'] ? 'SELARAS' : 'SELISIH' }}
            &mdash;
            {{ $zkt['mutasiSeimbang'] ? 'MUTASI SEIMBANG' : 'MUTASI TIDAK SEIMBANG' }}
        </td>
    </tr>
    @if(! $zkt['akunTersedia'])
    <tr><td colspan="6">TEMUAN: Akun DIT Dana Zakat {{ $zkt['akun'] }} belum ada pada CoA.</td></tr>
    @endif
</table>
