$ErrorActionPreference = "Stop"
$zip = "D:\blog\tools\php.zip"
$dest = "D:\blog\tools\php"
if (-not (Test-Path $zip)) { Write-Error "missing php.zip"; exit 1 }
if (Test-Path $dest) { Remove-Item $dest -Recurse -Force }
New-Item -ItemType Directory -Force -Path $dest | Out-Null
Expand-Archive -LiteralPath $zip -DestinationPath $dest -Force
$phpExe = Join-Path $dest "php.exe"
if (-not (Test-Path $phpExe)) {
  $inner = Get-ChildItem $dest -Directory | Select-Object -First 1
  if ($inner) {
    Get-ChildItem $inner.FullName | Move-Item -Destination $dest
  }
}
if (-not (Test-Path $phpExe)) { Write-Error "php.exe not found"; exit 1 }
Copy-Item (Join-Path $dest "php.ini-development") (Join-Path $dest "php.ini") -Force
$ini = Join-Path $dest "php.ini"
$content = Get-Content $ini -Raw
$content = $content -replace ';?\s*extension_dir\s*=\s*"ext"', 'extension_dir = "ext"'
foreach ($ext in @("curl","fileinfo","gd","mbstring","openssl","pdo_sqlite","sqlite3")) {
  $content = $content -replace (";?extension=$ext"), "extension=$ext"
}
$caDir = Join-Path $dest "extras\ssl"
New-Item -ItemType Directory -Force -Path $caDir | Out-Null
$ca = Join-Path $caDir "cacert.pem"
& curl.exe -L --fail --retry 3 -A "Mozilla/5.0" -o $ca "https://curl.se/ca/cacert.pem"
if ($LASTEXITCODE -ne 0 -or -not (Test-Path $ca) -or ((Get-Item $ca).Length -lt 1000)) {
  Write-Host "cacert download failed, continuing without it"
} else {
  $caPath = $ca.Replace("\", "/")
  if ($content -notmatch "curl.cainfo") {
    $content += "`r`ncurl.cainfo = `"$caPath`"`r`nopenssl.cafile = `"$caPath`"`r`n"
  }
}
Set-Content -Path $ini -Value $content -Encoding ASCII
& $phpExe -v
& $phpExe -m
Write-Host "PHP setup done"
