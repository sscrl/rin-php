$ErrorActionPreference = "Stop"
Set-Location D:\blog\frontend
npm install
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
npm run build
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
Write-Host "frontend build done"
Get-ChildItem D:\blog\public | Select-Object Name, Length
