$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$php = Join-Path $root "tools\php\php.exe"
if (-not (Test-Path $php)) {
  $php = "php"
}
$hostName = "127.0.0.1"
$port = 8080
$doc = Join-Path $root "public"
$router = Join-Path $doc "router.php"
Write-Host "Starting Rin PHP at http://${hostName}:${port}"
& $php -S "${hostName}:${port}" -t $doc $router
