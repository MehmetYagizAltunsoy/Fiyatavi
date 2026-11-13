# Fiyatavi PHP surumu: Windows'ta tek tikla kurulum.
# Yaptiklari: PHP 8.3'u indirir (%USERPROFILE%\php), gerekli eklentileri acar, HTTPS sertifikasini ayarlar,
# .env dosyasini hazirlar, siteyi ve fiyat cekme isini baslatacak kisayollari olusturur.
$ErrorActionPreference = "Stop"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$proje = Split-Path -Parent $MyInvocation.MyCommand.Path
$php = Join-Path $env:USERPROFILE "php"
$exe = Join-Path $php "php.exe"

function Adim($m) { Write-Host ""; Write-Host "==> $m" -ForegroundColor Cyan }

Adim "1/5 PHP indiriliyor"
if (-not (Test-Path $exe)) {
  $zip = Join-Path $env:TEMP "php83.zip"
  $url = $null
  try {
    $rel = Invoke-RestMethod "https://windows.php.net/downloads/releases/releases.json"
    $key = ($rel."8.3".PSObject.Properties.Name | Where-Object { $_ -like "nts-*x64" } | Select-Object -First 1)
    $url = "https://windows.php.net/downloads/releases/" + $rel."8.3".$key.zip.path
  } catch { Write-Host "  surum listesi alinamadi, 'latest' adresi denenecek" }
  if (-not $url) { $url = "https://windows.php.net/downloads/releases/latest/php-8.3-nts-Win32-vs16-x64-latest.zip" }
  Write-Host "  $url"
  Invoke-WebRequest $url -OutFile $zip -UseBasicParsing
  if (Test-Path $php) { Remove-Item $php -Recurse -Force }
  Expand-Archive $zip -DestinationPath $php -Force
  Remove-Item $zip -Force
} else { Write-Host "  zaten kurulu: $php" }

Adim "2/5 php.ini ayarlaniyor"
$ini = Join-Path $php "php.ini"
if (-not (Test-Path $ini)) { Copy-Item (Join-Path $php "php.ini-development") $ini }
$cacert = Join-Path $php "cacert.pem"
if (-not (Test-Path $cacert)) { Invoke-WebRequest "https://curl.se/ca/cacert.pem" -OutFile $cacert -UseBasicParsing }
$t = Get-Content $ini -Raw
$t = [regex]::Replace($t, '(?m)^;\s*extension=(curl|fileinfo|mbstring|openssl|pdo_sqlite|sqlite3)\s*$', 'extension=$1')
$t = [regex]::Replace($t, '(?m)^;\s*extension_dir\s*=\s*"ext"\s*$', 'extension_dir = "ext"')
$t = [regex]::Replace($t, '(?m)^;?\s*curl\.cainfo\s*=.*$', ('curl.cainfo = "' + $cacert + '"'))
$t = [regex]::Replace($t, '(?m)^;?\s*openssl\.cafile\s*=.*$', ('openssl.cafile = "' + $cacert + '"'))
Set-Content $ini $t -Encoding ASCII

Adim "3/5 PHP denemesi"
& $exe -v
$mods = (& $exe -m) -join " "
$eksik = @("curl","mbstring","openssl","pdo_sqlite","sqlite3") | Where-Object { $mods -notmatch $_ }
if ($eksik) { Write-Host "EKSIK ESKLENTI: $($eksik -join ', ') (php.ini kontrol edilmeli)" -ForegroundColor Red; exit 1 }
Write-Host "  gerekli eklentiler acik" -ForegroundColor Green
$yol = [Environment]::GetEnvironmentVariable("Path", "User")
if ($yol -notlike "*$php*") { [Environment]::SetEnvironmentVariable("Path", "$yol;$php", "User"); Write-Host "  PHP, PATH'e eklendi (yeni terminallerde gecerli)" }

Adim "4/5 Ayar dosyasi (.env)"
$env1 = Join-Path $proje ".env"
if (-not (Test-Path $env1)) {
  Copy-Item (Join-Path $proje ".env.example") $env1
  $t = Get-Content $env1 -Raw -Encoding UTF8
  $t = [regex]::Replace($t, '(?m)^SITE_URL=.*$', 'SITE_URL=http://localhost:8000')
  $t = [regex]::Replace($t, '(?m)^NODE_ENV=.*$', 'NODE_ENV=development')
  [IO.File]::WriteAllText($env1, $t, (New-Object Text.UTF8Encoding($false)))
  Write-Host "  .env olusturuldu. ITAD_API_KEY satirini doldurman gerekiyor." -ForegroundColor Yellow
  Start-Process notepad.exe $env1
} else { Write-Host "  .env zaten var" }

Adim "5/5 Kisayollar"
$bat1 = @"
@echo off
cd /d "%~dp0"
"$exe" -S localhost:8000 router.php
pause
"@
$bat2 = @"
@echo off
cd /d "%~dp0"
:d
"$exe" cron.php
timeout /t 15 >nul
goto d
"@
Set-Content (Join-Path $proje "baslat-site.bat") $bat1 -Encoding ASCII
Set-Content (Join-Path $proje "baslat-fiyatlar.bat") $bat2 -Encoding ASCII
Write-Host ""
Write-Host "Kurulum bitti." -ForegroundColor Green
Write-Host "  1) .env icinde ITAD_API_KEY'i doldur ve kaydet"
Write-Host "  2) baslat-site.bat dosyasina cift tikla  ->  tarayicida http://localhost:8000"
Write-Host "  3) baslat-fiyatlar.bat dosyasina cift tikla (acik kalsin, surekli fiyat ceker)"
