$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" | Where-Object { $_.ExecutablePath -like "*\blog\tools\php\php.exe" } | ForEach-Object {
  Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
}
Start-Sleep -Milliseconds 400
$db = Join-Path $root "storage\database.sqlite"
Remove-Item -Force -ErrorAction SilentlyContinue $db, "$db-wal", "$db-shm"
$images = Join-Path $root "storage\uploads\images"
if (Test-Path $images) { Remove-Item -Recurse -Force $images }
New-Item -ItemType Directory -Force -Path $images | Out-Null
Get-ChildItem (Join-Path $root "storage\cache") -File | Where-Object { $_.Name -ne ".gitkeep" } | Remove-Item -Force
Write-Host "storage reset"
