<#
.SYNOPSIS
    Runner operasional server pengembangan Koperasi Syariah.

.DESCRIPTION
    Menjalankan `php artisan serve` dengan:
      1. Preflight check dependensi (php, vendor, ekstensi, DB, node bila perlu).
      2. Validasi variabel lingkungan wajib (.env) dan konfigurasi port.
      3. Penanganan error eksplisit untuk: PHP tidak ditemukan, dependensi hilang,
         APP_KEY kosong, koneksi basis data gagal, dan port sudah dipakai.
      4. Startup log ke storage/logs/server-runner.log (waktu start, PID, URL,
         semua peringatan). Log server itu sendiri tetap ke storage/logs/serve.log.
      5. Server dijalankan di depan (foreground) agar sinyal penghentian
         (Ctrl+C / SIGINT) diteruskan ke proses PHP. Ctrl+C = graceful shutdown;
         tidak ada proses anak yang tertinggal.

.PARAMETER HostAddress
    Alamat bind. Default 127.0.0.1 (hanya lokal). Gunakan 0.0.0.0 agar dapat
    diakses dari perangkat lain di LAN.

.PARAMETER Port
    Port TCP. Default 8000.

.PARAMETER SkipDbCheck
    Lewati verifikasi koneksi basis data (mis. saat bekerja offline).

.PARAMETER Force
    Tetap jalankan walau ada PERINGATAN (bukan ERROR).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\start-server.ps1
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\start-server.ps1 -HostAddress 0.0.0.0 -Port 8080
#>
[CmdletBinding()]
param(
    [string] $HostAddress = '127.0.0.1',
    [int]    $Port        = 8000,
    [switch] $SkipDbCheck,
    [switch] $Force
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

# --- Lokasi proyek (root = satu level di atas folder scripts) -------------
$Root      = Split-Path -Parent $PSScriptRoot
$LogFile   = Join-Path $Root 'storage\logs\server-runner.log'
$ServeLog  = Join-Path $Root 'storage\logs\serve.log'
$EnvFile   = Join-Path $Root '.env'
$VendorAutoload = Join-Path $Root 'vendor\autoload.php'

# --- Kumpulan pesan -------------------------------------------------------
$script:Errors   = New-Object System.Collections.ArrayList
$script:Warnings = New-Object System.Collections.ArrayList

function Write-Log {
    param(
        [Parameter(Mandatory)] [string] $Message,
        [ValidateSet('INFO','WARN','ERROR')] [string] $Level = 'INFO'
    )
    $stamp = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    $line  = "[$stamp] [$Level] $Message"

    $color = switch ($Level) {
        'ERROR' { 'Red' }
        'WARN'  { 'Yellow' }
        default { 'Gray' }
    }
    Write-Host $line -ForegroundColor $color

    # Logging tidak boleh membuat runner gagal - kegagalan tulis log diabaikan.
    try {
        $dir = Split-Path -Parent $LogFile
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
        Add-Content -Path $LogFile -Value $line -Encoding UTF8 -ErrorAction Stop
    } catch {
        Write-Host "[$stamp] [WARN] Tidak dapat menulis log ke $LogFile : $($_.Exception.Message)" -ForegroundColor Yellow
    }
}

function Add-Error   { param([string] $Message) [void]$script:Errors.Add($Message);   Write-Log -Message $Message -Level 'ERROR' }
function Add-Warning { param([string] $Message) [void]$script:Warnings.Add($Message); Write-Log -Message $Message -Level 'WARN'  }

function Get-EnvValue {
    param([Parameter(Mandatory)] [string] $File, [Parameter(Mandatory)] [string] $Key)
    if (-not (Test-Path $File)) { return $null }
    $pattern = '^\s*' + [regex]::Escape($Key) + '\s*=\s*(.*)$'
    foreach ($line in [System.IO.File]::ReadAllLines($File)) {
        $m = [regex]::Match($line, $pattern)
        if ($m.Success) {
            $value = $m.Groups[1].Value.Trim()
            # Buang komentar di belakang nilai dan tanda kutip pembungkus.
            if ($value -match '^"(.*)"\s*(#.*)?$') { return $Matches[1] }
            if ($value -match "^'(.*)'\s*(#.*)?$") { return $Matches[1] }
            if ($value -match '^([^#]*?)\s*#.*$')  { return $Matches[1].Trim() }
            return $value
        }
    }
    return $null
}

function Test-PortInUse {
    param([Parameter(Mandatory)] [int] $PortNumber)
    try {
        return (Get-NetTCPConnection -LocalPort $PortNumber -State Listen -ErrorAction Stop |
                Select-Object -First 1) -ne $null
    } catch {
        # Fallback bila cmdlet tidak tersedia / tidak ada hasil.
        try {
            $listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Loopback, $PortNumber)
            $listener.Start(); $listener.Stop()
            return $false
        } catch {
            return $true
        }
    }
}

function Get-PortOwner {
    param([Parameter(Mandatory)] [int] $PortNumber)
    try {
        $conn = Get-NetTCPConnection -LocalPort $PortNumber -State Listen -ErrorAction Stop | Select-Object -First 1
        if ($null -eq $conn) { return $null }
        $proc = Get-Process -Id $conn.OwningProcess -ErrorAction SilentlyContinue
        if ($null -eq $proc) { return "PID $($conn.OwningProcess)" }
        return "$($proc.ProcessName) (PID $($proc.Id))"
    } catch { return $null }
}

Write-Host ''
Write-Log -Message "=== Koperasi Syariah - Server Runner ==="
Write-Log -Message "Root proyek: $Root"
Write-Log -Message "Target bind: http://${HostAddress}:$Port"

# === 1. DEPENDENSI ========================================================

# 1a. PHP CLI
$phpExe = $null
$phpCmd = Get-Command php -ErrorAction SilentlyContinue
if ($null -ne $phpCmd) {
    $phpExe = $phpCmd.Source
} else {
    # Laragon tidak selalu memasukkan PHP ke PATH - cari instalasi Laragon.
    $kandidat = Get-ChildItem 'C:\laragon\bin\php' -Directory -ErrorAction SilentlyContinue |
                Sort-Object Name -Descending
    foreach ($dir in $kandidat) {
        $exe = Join-Path $dir.FullName 'php.exe'
        if (Test-Path $exe) { $phpExe = $exe; break }
    }
    if ($null -eq $phpExe) {
        Add-Error "PHP CLI tidak ditemukan di PATH maupun di C:\laragon\bin\php."
        Add-Error "Perbaikan: tambahkan folder PHP ke PATH, atau jalankan Laragon > Menu > PHP > Version."
    } else {
        Add-Warning "PHP tidak ada di PATH; memakai instalasi Laragon: $phpExe"
    }
}

if ($null -ne $phpExe) {
    $phpVersion = (& $phpExe -r "echo PHP_VERSION;" 2>&1 | Out-String).Trim()
    Write-Log -Message "PHP: $phpVersion ($phpExe)"
    if ([version]($phpVersion -replace '[^0-9\.].*$','') -lt [version]'8.2.0') {
        Add-Error "Versi PHP $phpVersion tidak memenuhi syarat composer.json (^8.2)."
    } else {
        Write-Log -Message "PHP memenuhi syarat composer.json (^8.2)."
    }

    # Ekstensi wajib untuk runtime aplikasi ini.
    $wajib = 'pdo_mysql','openssl','mbstring','tokenizer','xml','ctype','json','fileinfo','bcmath'
    $dimuat = (& $phpExe -r "echo implode(',', get_loaded_extensions());" 2>&1 | Out-String).Trim()
    $setDimuat = $dimuat.Split(',') | ForEach-Object { $_.Trim().ToLower() }
    $missing = @($wajib | Where-Object { $setDimuat -notcontains $_.ToLower() })
    if ($missing.Count -gt 0) {
        Add-Error "Ekstensi PHP wajib tidak aktif: $($missing -join ', '). Aktifkan di php.ini lalu restart server."
    } else {
        Write-Log -Message "Semua ekstensi wajib aktif: $($wajib -join ', ')."
    }
}

# 1b. Dependensi Composer
if (-not (Test-Path $VendorAutoload)) {
    Add-Error "Folder vendor tidak lengkap (vendor/autoload.php tidak ada). Jalankan: composer install"
} else {
    Write-Log -Message "Dependensi Composer terpasang (vendor/autoload.php OK)."
}

# 1c. Berkas .env
if (-not (Test-Path $EnvFile)) {
    Add-Error ".env tidak ditemukan. Jalankan: Copy-Item .env.example .env ; php artisan key:generate"
}

# 1d. Dependensi Node/Vite - hanya diperlukan bila aset di-bundle.
$pakaiVite = $false
$viewsDir = Join-Path $Root 'resources\views'
if (Test-Path $viewsDir) {
    $blade = Get-ChildItem -Path $viewsDir -Filter '*.blade.php' -Recurse -File -ErrorAction SilentlyContinue
    if ($blade) {
        $pakaiVite = (Select-String -Path $blade.FullName -Pattern '@vite' -List -ErrorAction SilentlyContinue) -ne $null
    }
}
$buildDir = Join-Path $Root 'public\build'
if ($pakaiVite -and -not (Test-Path $buildDir)) {
    Add-Warning "View memakai @vite tetapi public/build belum ada. Jalankan: npm install ; npm run build"
} elseif (Test-Path $buildDir) {
    Write-Log -Message "Aset hasil build Vite tersedia (public/build)."
} else {
    Write-Log -Message "Proyek tidak memakai @vite - dependensi Node tidak diperlukan untuk runtime."
}

# === 2. VARIABEL LINGKUNGAN & PORT ========================================

if (Test-Path $EnvFile) {
    $appKey = Get-EnvValue -File $EnvFile -Key 'APP_KEY'
    if ([string]::IsNullOrWhiteSpace($appKey)) {
        Add-Error "APP_KEY kosong di .env - sesi, cookie, dan enkripsi tidak akan berfungsi. Jalankan: php artisan key:generate"
    } else {
        Write-Log -Message "APP_KEY terisi."
    }

    $appEnv   = Get-EnvValue -File $EnvFile -Key 'APP_ENV'
    $appDebug = Get-EnvValue -File $EnvFile -Key 'APP_DEBUG'
    Write-Log -Message "APP_ENV=$appEnv, APP_DEBUG=$appDebug"
    if ($appEnv -eq 'production' -and $appDebug -eq 'true') {
        Add-Warning "APP_DEBUG=true pada APP_ENV=production - jangan dipakai untuk server publik."
    }

    $dbHost = Get-EnvValue -File $EnvFile -Key 'DB_HOST'
    $dbPort = Get-EnvValue -File $EnvFile -Key 'DB_PORT'
    $dbName = Get-EnvValue -File $EnvFile -Key 'DB_DATABASE'
    $dbUser = Get-EnvValue -File $EnvFile -Key 'DB_USERNAME'
    Write-Log -Message "Basis data: $dbUser@${dbHost}:$dbPort/$dbName"

    # 2a. Port basis data harus dapat dijangkau sebelum server dinyalakan.
    if (-not $SkipDbCheck) {
        $dbReachable = $false
        try {
            $client = New-Object System.Net.Sockets.TcpClient
            $async  = $client.BeginConnect($dbHost, [int]$dbPort, $null, $null)
            $dbReachable = $async.AsyncWaitHandle.WaitOne(3000, $false) -and $client.Connected
            $client.Close()
        } catch { $dbReachable = $false }

        if (-not $dbReachable) {
            Add-Error "Tidak dapat terhubung ke MySQL di ${dbHost}:$dbPort. Pastikan service MySQL (Laragon) berjalan."
        } else {
            Write-Log -Message "MySQL merespons di ${dbHost}:$dbPort."
        }
    } else {
        Write-Log -Message "Verifikasi koneksi basis data dilewati (-SkipDbCheck)."
    }
}

# 2b. Port aplikasi
if ($Port -lt 1 -or $Port -gt 65535) {
    Add-Error "Port $Port di luar rentang sah (1-65535)."
} elseif (Test-PortInUse -PortNumber $Port) {
    $owner = Get-PortOwner -PortNumber $Port
    Add-Error "Port $Port sudah digunakan oleh $owner. Pilih port lain, mis.: -Port 8001"
}

# === 3. GERBANG EKSEKUSI =================================================

if ($script:Errors.Count -gt 0) {
    Write-Host ''
    Write-Log -Message "Server DIBATALKAN - $($script:Errors.Count) error, $($script:Warnings.Count) peringatan." -Level 'ERROR'
    Write-Log -Message "Ringkasan error:" -Level 'ERROR'
    $i = 1
    foreach ($e in $script:Errors) { Write-Log -Message "  $i. $e" -Level 'ERROR'; $i++ }
    Write-Log -Message "Perbaiki item di atas lalu jalankan ulang skrip ini." -Level 'ERROR'
    exit 1
}

if ($script:Warnings.Count -gt 0 -and -not $Force) {
    Write-Host ''
    Write-Log -Message "Terdapat $($script:Warnings.Count) peringatan (lihat di atas)." -Level 'WARN'
    Write-Log -Message "Jalankan ulang dengan -Force untuk tetap melanjutkan." -Level 'WARN'
    exit 2
}

# === 4. JALANKAN SERVER ===================================================

$urlLokal = "http://${HostAddress}:$Port"
$urlLoopback = "http://127.0.0.1:$Port"

Write-Host ''
Write-Log -Message "Semua pemeriksaan lolos. Menyalakan server..."
Write-Log -Message "Alamat akses     : $urlLokal"
if ($HostAddress -eq '0.0.0.0') {
    Write-Log -Message "Akses dari LAN   : http://10.10.10.11:$Port (ganti sesuai IP mesin Anda)"
    Add-Warning "Bind 0.0.0.0 membuka server ke seluruh jaringan - hanya untuk jaringan tepercaya."
}
Write-Log -Message "Health endpoint  : $urlLoopback/up"
Write-Log -Message "Log akses server : $ServeLog"
Write-Log -Message "Log runner ini   : $LogFile"
Write-Log -Message "Hentikan server  : Ctrl+C (graceful shutdown)"
Write-Host ''

$started  = Get-Date
$exitCode = 0

try {
    # Server dijalankan di depan pada proses yang sama. Ctrl+C dari konsol
    # diteruskan ke proses PHP, sehingga proses induk maupun anak (server.php)
    # ikut berhenti - tidak ada proses anak yang tertinggal.
    & $phpExe (Join-Path $Root 'artisan') serve --host=$HostAddress --port=$Port 2>&1 |
        Tee-Object -FilePath $ServeLog -Append
    $exitCode = $LASTEXITCODE
    if ($null -eq $exitCode) { $exitCode = 0 }
}
catch {
    Write-Log -Message "Server berhenti karena galat: $($_.Exception.Message)" -Level 'ERROR'
    $exitCode = 1
}
finally {
    # Blok finally SELALU dijalankan, termasuk saat Ctrl+C (SIGINT) menghentikan
    # pipeline. Karena itu log penutup dan verifikasi pelepasan port tetap ditulis.
    $duration = (Get-Date) - $started
    Write-Log -Message ("Server berhenti. Exit code: $exitCode. Durasi: {0:hh\:mm\:ss}." -f $duration)

    $bebas = $false
    for ($i = 0; $i -lt 5 -and -not $bebas; $i++) {
        if (Test-PortInUse -PortNumber $Port) { Start-Sleep -Milliseconds 600 } else { $bebas = $true }
    }
    if ($bebas) {
        Write-Log -Message "Port $Port sudah dilepas - tidak ada proses anak yang tertinggal."
    } else {
        Write-Log -Message "Port $Port masih Listen setelah penghentian. Periksa proses yang tertinggal dengan: Get-NetTCPConnection -LocalPort $Port -State Listen" -Level 'WARN'
    }
}

exit $exitCode
