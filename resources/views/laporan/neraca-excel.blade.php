{{-- Template unduhan Excel (HTML table) untuk Laporan Neraca.
     Excel membuka berkas .xls berisi tabel HTML ini sebagai spreadsheet.
     Pengelompokan mengikuti klasifikasi penyajian standar PSAK 101. --}}
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
<table border="1" cellspacing="0" cellpadding="4">
    <thead>
        <tr>
            <th colspan="3" style="font-size:14pt;text-align:left;">
                LAPORAN NERACA (BALANCE SHEET)
            </th>
        </tr>
        <tr>
            <th colspan="3" style="text-align:left;">
                Per {{ $tanggalFilter->translatedFormat('d F Y') }} &mdash; Standar PSAK 101
            </th>
        </tr>
        <tr>
            <th colspan="3" style="text-align:left;">
                {{ $mode === 'akhir_bulan' ? 'Mode: Per Akhir Bulan' : 'Mode: Per Tanggal Spesifik' }}
            </th>
        </tr>
        <tr><td colspan="3"></td></tr>
    </thead>
    <tbody>
        {{-- ASET --}}
        <tr><td colspan="3" style="font-weight:bold;background:#dbeafe;">ASET</td></tr>
        <tr style="font-weight:bold;background:#f1f5f9;">
            <td>Kode</td><td>Nama Akun</td><td>Saldo</td>
        </tr>
        @foreach($asetGroups as $grup)
        <tr><td colspan="3" style="font-weight:bold;background:#eff6ff;">{{ $grup['label'] }}</td></tr>
        @foreach($grup['akun'] as $a)
        <tr>
            <td>{{ $a->kode_akun }}</td>
            <td>{{ $a->nama_akun }}</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $a->saldoBerjalan($sampai) }}</td>
        </tr>
        @endforeach
        @if(count($asetGroups) > 1)
        <tr style="font-weight:bold;">
            <td colspan="2">Subtotal {{ $grup['label'] }}</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $grup['subtotal'] }}</td>
        </tr>
        @endif
        @endforeach
        <tr style="font-weight:bold;background:#dbeafe;">
            <td colspan="2">Total Aset</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $totalAset }}</td>
        </tr>
        <tr><td colspan="3"></td></tr>

        {{-- KEWAJIBAN --}}
        <tr><td colspan="3" style="font-weight:bold;background:#fef3c7;">KEWAJIBAN</td></tr>
        <tr style="font-weight:bold;background:#f1f5f9;">
            <td>Kode</td><td>Nama Akun</td><td>Saldo</td>
        </tr>
        @foreach($kewajibanGroups as $grup)
        <tr><td colspan="3" style="font-weight:bold;background:#fffbeb;">{{ $grup['label'] }}</td></tr>
        @foreach($grup['akun'] as $k)
        <tr>
            <td>{{ $k->kode_akun }}</td>
            <td>{{ $k->nama_akun }}</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $k->saldoBerjalan($sampai) }}</td>
        </tr>
        @endforeach
        @if(count($kewajibanGroups) > 1)
        <tr style="font-weight:bold;">
            <td colspan="2">Subtotal {{ $grup['label'] }}</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $grup['subtotal'] }}</td>
        </tr>
        @endif
        @endforeach
        <tr style="font-weight:bold;background:#fef3c7;">
            <td colspan="2">Total Kewajiban</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $totalKewajiban }}</td>
        </tr>
        <tr><td colspan="3"></td></tr>

        {{-- DANA SYIRKAH TEMPORER (disajikan terpisah dari Kewajiban — PSAK 101) --}}
        <tr><td colspan="3" style="font-weight:bold;background:#ccfbf1;">DANA SYIRKAH TEMPORER</td></tr>
        @foreach($dstGroups as $grup)
        <tr><td colspan="3" style="font-weight:bold;background:#f0fdfa;">{{ $grup['label'] }}</td></tr>
        @foreach($grup['akun'] as $d)
        <tr>
            <td>{{ $d->kode_akun }}</td>
            <td>{{ $d->nama_akun }}</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $d->saldoBerjalan($sampai) }}</td>
        </tr>
        @endforeach
        @endforeach
        <tr style="font-weight:bold;background:#ccfbf1;">
            <td colspan="2">Total Dana Syirkah Temporer</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $totalDanaSyirkahTemporer }}</td>
        </tr>
        <tr><td colspan="3"></td></tr>

        {{-- EKUITAS --}}
        <tr><td colspan="3" style="font-weight:bold;background:#e0e7ff;">EKUITAS / MODAL SENDIRI</td></tr>
        <tr style="font-weight:bold;background:#f1f5f9;">
            <td>Kode</td><td>Nama Akun</td><td>Saldo</td>
        </tr>
        @foreach($ekuitasGroups as $grup)
        <tr><td colspan="3" style="font-weight:bold;background:#eef2ff;">{{ $grup['label'] }}</td></tr>
        @foreach($grup['akun'] as $e)
        <tr>
            <td>{{ $e->kode_akun }}</td>
            <td>{{ $e->nama_akun }}</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $e->saldoBerjalanLaporan($sampai) }}</td>
        </tr>
        @endforeach
        @if(count($ekuitasGroups) > 1)
        <tr style="font-weight:bold;">
            <td colspan="2">Subtotal {{ $grup['label'] }}</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $grup['subtotal'] }}</td>
        </tr>
        @endif
        @endforeach
        {{-- Laba/Rugi Berjalan disajikan sebagai bagian RINCIAN Pasiva agar sama
          | dengan tampilan web: angka yang mempengaruhi keseimbangan Neraca
          | selalu terlihat sumbernya, tidak berdiri di luar tabel. */
        --}}
        <tr style="background:#f8fafc;">
            <td>&mdash;</td>
            <td>Laba/Rugi Berjalan
                (Pendapatan {{ number_format($totalPendapatan, 0, ',', '.') }}
                &minus; Beban {{ number_format($totalBeban, 0, ',', '.') }})
                &mdash; {{ ucfirst($statusLaba) }}</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $labaBersih }}</td>
        </tr>
        <tr style="font-weight:bold;background:#e0e7ff;">
            <td colspan="2">Total Ekuitas</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $totalEkuitas }}</td>
        </tr>
        <tr style="font-weight:bold;background:#eff6ff;">
            <td colspan="2">TOTAL PASIVA (Kewajiban + DST + Ekuitas + Laba/Rugi Berjalan)</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $totalPasiva }}</td>
        </tr>
        <tr style="font-weight:bold;">
            <td colspan="2">Total Aset (Aktiva)</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $totalAset }}</td>
        </tr>
        <tr style="font-weight:bold;background:{{ abs($selisihNeraca) > 0.01 ? '#fee2e2' : '#dcfce7' }};">
            <td colspan="2">Selisih (Aset &minus; Pasiva)</td>
            <td style="mso-number-format:'#,##0.00';">{{ (float) $selisihNeraca }}</td>
        </tr>
        <tr><td colspan="3"></td></tr>

        {{-- RINCIAN PENDAPATAN --}}
        <tr><td colspan="3" style="font-weight:bold;background:#d1fae5;">RINCIAN PENDAPATAN</td></tr>
        @foreach($pendapatanGroups as $grup)
        <tr><td colspan="3" style="font-weight:bold;background:#ecfdf5;">{{ $grup['label'] }}</td></tr>
        @foreach($grup['akun'] as $p)
        <tr>
            <td>{{ $p->kode_akun }}</td>
            <td>{{ $p->nama_akun }}</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $p->saldoBerjalanLaporan($sampai) }}</td>
        </tr>
        @endforeach
        @endforeach
        <tr style="font-weight:bold;">
            <td colspan="2">Total Pendapatan</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $totalPendapatan }}</td>
        </tr>
        <tr><td colspan="3"></td></tr>

        {{-- RINCIAN BEBAN --}}
        <tr><td colspan="3" style="font-weight:bold;background:#fee2e2;">RINCIAN BEBAN</td></tr>
        @foreach($bebanGroups as $grup)
        <tr><td colspan="3" style="font-weight:bold;background:#fef2f2;">{{ $grup['label'] }}</td></tr>
        @foreach($grup['akun'] as $b)
        <tr>
            <td>{{ $b->kode_akun }}</td>
            <td>{{ $b->nama_akun }}</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $b->saldoBerjalanLaporan($sampai) }}</td>
        </tr>
        @endforeach
        @endforeach
        <tr style="font-weight:bold;">
            <td colspan="2">Total Beban</td>
            <td style="mso-number-format:'#,##0';">{{ (float) $totalBeban }}</td>
        </tr>
    </tbody>
</table>
</body>
</html>
