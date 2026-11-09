<#
.SYNOPSIS
    Menyalakan ulang Apache + MySQL Laragon tanpa membuka GUI Laragon.

.DESCRIPTION
    Dipanggil otomatis oleh Scheduled Task `KoperasiSyariah-MulaiLayanan`
    (pemicu: saat login + pengulangan tiap 10 menit). Tujuannya agar sistem
    koperasi kembali melayani sendiri setelah listrik padam, Windows Update
    restart, atau proses Apache/MySQL mati mendadak.

    Skrip bersifat IDEMPOTEN: setiap layanan hanya dinyalakan bila port-nya
    belum Listen. Menjalankan berulang kali aman dan tidak membuat proses
    ganda.

    Catatan: Tailscale sudah berjalan sebagai layanan Windows (StartType
    Automatic), jadi tidak perlu dinyalakan dari sini.
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$Root    = Split-Path -Parent $PSScriptRoot
$LogFile = Join-Path $Root 'storage\logs\mulai-layanan.log'

function Write-Log {
    param([string] $Message)
    $line = "[{0}] {1}" -f (Get-Date).ToString('yyyy-MM-dd HH:mm:ss'), $Message
    try {
        $dir = Split-Path -Parent $LogFile
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
        Add-Content -Path $LogFile -Value $line -Encoding UTF8
    } catch { }
}

function Test-Port {
    param([int] $Port)
    return (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
            Select-Object -First 1) -ne $null
}

function Wait-Port {
    param([int] $Port, [int] $Detik)
    for ($i = 0; $i -lt $Detik; $i++) {
        if (Test-Port -Port $Port) { return $true }
        Start-Sleep -Seconds 1
    }
    return (Test-Port -Port $Port)
}

function Cari-Biner {
    param([string] $Pola)
    $kandidat = Get-ChildItem -Path $Pola -ErrorAction SilentlyContinue |
                Sort-Object FullName -Descending
    if ($null -eq $kandidat) { return $null }
    return ($kandidat | Select-Object -First 1).FullName
}

# --- MySQL (port 3306) ----------------------------------------------------
if (Test-Port -Port 3306) {
    Write-Log 'MySQL: sudah berjalan, dilewati.'
} else {
    $mysqld = Cari-Biner 'C:\laragon\bin\mysql\mysql-*\bin\mysqld.exe'
    $myIni  = Cari-Biner 'C:\laragon\bin\mysql\mysql-*\my.ini'

    if ($null -eq $mysqld -or $null -eq $myIni) {
        Write-Log 'MySQL: biner atau my.ini tidak ditemukan di C:\laragon\bin\mysql.'
    } else {
        Write-Log "MySQL: menyalakan $mysqld"
        Start-Process -FilePath $mysqld `
            -ArgumentList "--defaults-file=`"$myIni`"" `
            -WorkingDirectory (Split-Path -Parent (Split-Path -Parent $mysqld)) `
            -WindowStyle Hidden
        if (Wait-Port -Port 3306 -Detik 90) {
            Write-Log 'MySQL: port 3306 siap.'
        } else {
            Write-Log 'MySQL: GAGAL - port 3306 tidak Listen setelah 90 detik.'
        }
    }
}

# --- Apache (port 80) -----------------------------------------------------
if (Test-Port -Port 80) {
    Write-Log 'Apache: sudah berjalan, dilewati.'
} else {
    $httpd = Cari-Biner 'C:\laragon\bin\apache\httpd-*\bin\httpd.exe'

    if ($null -eq $httpd) {
        Write-Log 'Apache: biner httpd.exe tidak ditemukan di C:\laragon\bin\apache.'
    } else {
        # Folder akar Apache = tiga level di atas bin\httpd.exe; Laragon
        # menyalakannya dengan `-d <folder akar>` agar conf/ dan logs/ terbaca.
        $apacheHome = Split-Path -Parent (Split-Path -Parent $httpd)
        Write-Log "Apache: menyalakan $httpd -d $apacheHome"
        Start-Process -FilePath $httpd `
            -ArgumentList "-d `"$apacheHome`"" `
            -WorkingDirectory $apacheHome `
            -WindowStyle Hidden
        if (Wait-Port -Port 80 -Detik 60) {
            Write-Log 'Apache: port 80 siap.'
        } else {
            Write-Log 'Apache: GAGAL - port 80 tidak Listen setelah 60 detik.'
        }
    }
}

if ((Test-Port -Port 3306) -and (Test-Port -Port 80)) {
    Write-Log 'Selesai: MySQL + Apache melayani.'
    exit 0
}

Write-Log 'Selesai dengan kegagalan: salah satu layanan belum melayani.'
exit 1
