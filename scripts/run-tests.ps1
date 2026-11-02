<#
.SYNOPSIS
    Runner pengujian Koperasi Syariah — WAJIB membersihkan cache lebih dahulu.

.DESCRIPTION
    Skrip ini adalah SATU-SATUNYA jalur resmi menjalankan `php artisan test`
    pada proyek ini. Ia menegakkan prosedur berikut secara otomatis:

      1. PRA-UJI  : `php artisan optimize:clear` dijalankan lebih dahulu,
                    TANPA pengecualian. Bila gagal, pengujian dibatalkan.
      2. BUKTI    : Cache yang sudah bersih diverifikasi ulang (berkas
                    bootstrap/cache/*.php harus kosong). Bila masih ada sisa,
                    pengujian dibatalkan.
      3. UJI      : `php artisan test` dijalankan setelah gerbang di atas lolos.

    ALASAN (penting, jangan dilewati)
    ---------------------------------
    `php artisan config:cache` menyimpan konfigurasi hasil render ke
    bootstrap/cache/config.php. Berkas itu membekukan seluruh isi .env —
    termasuk APP_KEY. Ketika suite dijalankan tanpa pembersihan, PHPUnit
    memakai APP_KEY yang sudah kedaluwarsa, token CSRF seluruh form tidak
    lagi cocok, dan RATUSAN test gagal serentak dengan galat 419.
    Kegagalan semacam itu BUKAN bug produk, melainkan artefak cache yang
    tidak teratur. Karena itu cache wajib direset pada setiap sesi pengujian.

.PARAMETER Filter
    Teruskan ke `php artisan test --filter=<Filter>` (mis. nama kelas/tes).

.PARAMETER Tests
    Daftar berkas/folder test tertentu (mis. tests/Feature/DenyutSinkronisasiRealtimeTest.php).

.PARAMETER TanpaBersih
    Untuk pengembangan cepat berulang (DI LUAR jalur resmi). Menjalankan
    pembersihan tetap diwajibkan pada sesi pengujian pertama; pemakaian
    berulang hanya boleh bila tidak ada perubahan .env/config.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1 -Filter DenyutSinkronisasiRealtimeTest
#>
[CmdletBinding()]
param(
    [string]   $Filter,
    [string[]] $Tests,
    [switch]   $TanpaBersih
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

# --- Lokasi proyek (root = satu level di atas folder scripts) ---------------
$Root          = Split-Path -Parent $PSScriptRoot
$Artisan       = Join-Path $Root 'artisan'
$CacheDir      = Join-Path $Root 'bootstrap\cache'
$LogFile       = Join-Path $Root 'storage\logs\test-runner.log'

# --- Utilitas --------------------------------------------------------------
function Write-Log {
    param(
        [Parameter(Mandatory)] [string] $Message,
        [ValidateSet('INFO','WARN','ERROR','OK')] [string] $Level = 'INFO'
    )
    $stamp = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    $line  = "[$stamp] [$Level] $Message"

    $color = switch ($Level) {
        'ERROR' { 'Red' }
        'WARN'  { 'Yellow' }
        'OK'    { 'Green' }
        default { 'Gray' }
    }
    Write-Host $line -ForegroundColor $color

    try {
        $dir = Split-Path -Parent $LogFile
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
        Add-Content -Path $LogFile -Value $line -Encoding UTF8 -ErrorAction Stop
    } catch {
        Write-Host "[$stamp] [WARN] Tidak dapat menulis log ke $LogFile : $($_.Exception.Message)" -ForegroundColor Yellow
    }
}

function Resolve-Php {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($null -ne $cmd) { return $cmd.Source }

    # Laragon tidak selalu memasukkan PHP ke PATH.
    $kandidat = Get-ChildItem 'C:\laragon\bin\php' -Directory -ErrorAction SilentlyContinue |
                Sort-Object Name -Descending
    foreach ($dir in $kandidat) {
        $exe = Join-Path $dir.FullName 'php.exe'
        if (Test-Path $exe) { return $exe }
    }
    return $null
}

# --- 0. Preflight ----------------------------------------------------------
$phpExe = Resolve-Php
if ($null -eq $phpExe) {
    Write-Log -Message "PHP CLI tidak ditemukan (PATH maupun C:\laragon\bin\php)." -Level 'ERROR'
    exit 1
}

if (-not (Test-Path $Artisan)) {
    Write-Log -Message "artisan tidak ditemukan di $Artisan." -Level 'ERROR'
    exit 1
}

Write-Host ''
Write-Log -Message "=== Koperasi Syariah - Test Runner ==="
Write-Log -Message "Root proyek : $Root"
Write-Log -Message "PHP         : $phpExe"

$phpVersion = (& $phpExe -r "echo PHP_VERSION;" 2>&1 | Out-String).Trim()
Write-Log -Message "Versi PHP   : $phpVersion"

# --- 1. GERBANG WAJIB: reset seluruh cache --------------------------------
# Tidak dapat dilewati kecuali -TanpaBersih diberikan secara eksplisit.
if (-not $TanpaBersih) {
    Write-Log -Message "Langkah 1/3 - Menjalankan 'php artisan optimize:clear' (WAJIB)..." -Level 'INFO'

    $keluaran = & $phpExe $Artisan optimize:clear 2>&1 | Out-String
    $kode     = $LASTEXITCODE

    if ($kode -ne 0) {
        Write-Host $keluaran
        Write-Log -Message "optimize:clear GAGAL (exit $kode). Pengujian DIBATALKAN." -Level 'ERROR'
        Write-Log -Message "Suites yang berjalan tanpa reset cache dapat gagal massal (419 CSRF) akibat config ter-cache." -Level 'ERROR'
        exit 1
    }
    Write-Log -Message "optimize:clear selesai. config, cache, compiled, events, routes, views telah direset." -Level 'OK'
} else {
    Write-Log -Message "Langkah 1/3 - DILEWATI (-TanpaBersih). Gunakan hanya untuk iterasi tanpa perubahan config/.env." -Level 'WARN'
}

# --- 2. VERIFIKASI BUKTI: tidak ada cache konfigurasi tertinggal ----------
# Yang diperiksa hanya artefak cache yang benar-benar berbahaya:
#   config.php, routes-*.php, events.php.
# `packages.php` dan `services.php` adalah manifest paket hasil
# `package:discover` yang SENGAJA dibiarkan `optimize:clear` dan dibutuhkan
# Laravel untuk booting — keduanya tidak pernah dianggap kotor.
$polaBahaya = @('config.php', 'routes-*.php', 'events.php')
$sisa = @()
if (Test-Path $CacheDir) {
    foreach ($pola in $polaBahaya) {
        $sisa += @(Get-ChildItem -Path $CacheDir -Filter $pola -File -ErrorAction SilentlyContinue)
    }
}

if ($sisa.Count -gt 0) {
    Write-Log -Message "Cache konfigurasi belum bersih - masih ada $($sisa.Count) berkas di bootstrap\cache:" -Level 'ERROR'
    foreach ($f in $sisa) { Write-Log -Message "  - $($f.Name)" -Level 'ERROR' }
    Write-Log -Message "Jalankan 'php artisan optimize:clear' secara manual lalu ulangi. Pengujian DIBATALKAN." -Level 'ERROR'
    exit 1
}
Write-Log -Message "Langkah 2/3 - Verifikasi cache bersih: tidak ada config.php / routes-*.php / events.php di bootstrap\cache." -Level 'OK'

# --- 3. Jalankan pengujian -------------------------------------------------
$argumen = @($Artisan, 'test')
if ($Filter) { $argumen += "--filter=$Filter" }
if ($Tests)  { $argumen += $Tests }

Write-Log -Message ("Langkah 3/3 - Menjalankan: php artisan test " + ($argumen[2..($argumen.Count - 1)] -join ' ')) -Level 'INFO'
Write-Host ''

$mulai = Get-Date
& $phpExe @argumen
$kodeUji = $LASTEXITCODE
if ($null -eq $kodeUji) { $kodeUji = 0 }

$durasi = (Get-Date) - $mulai
Write-Host ''

if ($kodeUji -eq 0) {
    Write-Log -Message ("Pengujian LULUS. Durasi: {0:hh\:mm\:ss}." -f $durasi) -Level 'OK'
} else {
    Write-Log -Message ("Pengujian GAGAL (exit $kodeUji). Durasi: {0:hh\:mm\:ss}." -f $durasi) -Level 'ERROR'
}

exit $kodeUji
