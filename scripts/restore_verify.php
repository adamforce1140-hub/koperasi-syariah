<?php

/**
 * Uji pemulihan: memulihkan cadangan .sql.gz ke database sementara lalu
 * membandingkan jumlah baris tiap tabel dengan database sumber.
 *
 * Pemakaian: php restore_verify.php <berkas.sql.gz> <db_tujuan> <db_sumber>
 */
$arg = $argv;
$berkas = $arg[1] ?? null;
$tujuan = $arg[2] ?? null;
$sumber = $arg[3] ?? null;
if (! $berkas || ! $tujuan || ! $sumber) {
    fwrite(STDERR, "Pemakaian: php restore_verify.php <berkas.sql.gz> <db_tujuan> <db_sumber>\n");
    exit(2);
}

$mysql = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe';
$pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// 1. Buat database tujuan kosong.
$pdo->exec("DROP DATABASE IF EXISTS `{$tujuan}`");
$pdo->exec("CREATE DATABASE `{$tujuan}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// 2. Dekompres cadangan ke berkas sementara.
$sql = tempnam(sys_get_temp_dir(), 'restore').'.sql';
$in = gzopen($berkas, 'rb');
$out = fopen($sql, 'wb');
while (! gzeof($in)) {
    fwrite($out, gzread($in, 1048576));
}
gzclose($in);
fclose($out);
$ukuran = filesize($sql);

// 3. Pulihkan memakai klien mysql (aman terhadap trigger/view yang tidak
//    dapat dijalankan ulang lewat PDO satu kueri per statement).
//    PowerShell tidak mendukung operator `<`, jadi berkas dimuat lewat
//    perintah `source` milik klien mysql. Proses dijalankan dari direktori
//    berkas agar nama berkas cukup (tanpa path berspasi).
$perintah = [$mysql, '--user=root', $tujuan, '--execute=source '.basename($sql)];
$pipa = [];
$proses = proc_open($perintah, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipa, dirname($sql));
// Pipa harus dibaca SEBELUM proc_close(); setelah ditutup, resource tidak valid.
$keluaran = stream_get_contents($pipa[1]);
$galat = stream_get_contents($pipa[2]);
fclose($pipa[1]);
fclose($pipa[2]);
$keluar = proc_close($proses);
@unlink($sql);

echo "Pulihkan berkas = {$berkas}\nUkuran SQL     = {$ukuran} byte\nKode keluar    = {$keluar}\n";
if (trim((string) $galat) !== '') {
    echo 'Galat stderr   = '.trim($galat)."\n";
}
if ($keluar !== 0) {
    exit(1);
}

// 4. Bandingkan jumlah baris tiap tabel.
$ambil = fn ($db) => $pdo->query(
    'SELECT table_name FROM information_schema.tables WHERE table_schema = '.$pdo->quote($db)." AND table_type = 'BASE TABLE' ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

$tabelSumber = $ambil($sumber);
$tabelTujuan = $ambil($tujuan);
$beda = 0;
printf("\n%-34s %10s %10s %8s\n", 'TABEL', 'SUMBER', 'PULIH', '');
foreach ($tabelSumber as $t) {
    $a = (int) $pdo->query("SELECT COUNT(*) FROM `{$sumber}`.`{$t}`")->fetchColumn();
    if (! in_array($t, $tabelTujuan, true)) {
        printf("%-34s %10d %10s %8s\n", $t, $a, 'HILANG', 'BEDA');
        $beda++;

        continue;
    }
    $b = (int) $pdo->query("SELECT COUNT(*) FROM `{$tujuan}`.`{$t}`")->fetchColumn();
    if ($a !== $b) {
        printf("%-34s %10d %10d %8s\n", $t, $a, $b, 'BEDA');
        $beda++;
    }
}
echo "\nTabel sumber = ".count($tabelSumber).', tabel pulih = '.count($tabelTujuan).", tabel berbeda = {$beda}\n";

// 5. Integritas jurnal pada hasil pulih.
$tb = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT jurnal_id FROM `{$tujuan}`.jurnal_detail GROUP BY jurnal_id HAVING ABS(SUM(debet)-SUM(kredit))>0.005) x")->fetchColumn();
$saldo = $pdo->query("SELECT SUM(saldo) FROM `{$tujuan}`.rekening")->fetchColumn();
echo "Jurnal tidak balance pada hasil pulih = {$tb}\nTotal saldo rekening pulih = {$saldo}\n";

exit($beda === 0 && $tb === 0 ? 0 : 1);
