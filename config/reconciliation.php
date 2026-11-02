<?php

/*
|--------------------------------------------------------------------------
| Gerbang Rekonsiliasi Subledger
|--------------------------------------------------------------------------
| Membandingkan saldo rekening anggota (subledger `rekening`) dengan saldo
| akun kontrol di buku besar (lihat JurnalReconciliationService).
|
| `gate_enforce` = false  → MODE LAPOR (bawaan): selisih dicatat ke log dan
|                           dilaporkan oleh `jurnal:rekonsiliasi`, tetapi
|                           transaksi teller tetap berjalan.
| `gate_enforce` = true   → MODE TEGAKKAN: transaksi yang menghasilkan selisih
|                           ditolak dan di-rollback.
|
| Jangan aktifkan mode tegakkan sebelum selisih warisan (P1-2) diremediasi —
| bila tidak, seluruh transaksi simpanan akan gagal.
*/

return [

    'gate_enforce' => (bool) env('RECONCILIATION_GATE_ENFORCE', false),

];
