param(
    [Parameter(Mandatory=$true)][string]$Domain
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Frontend = Join-Path $Root "frontend"
$Release = Join-Path $Root "release\public_html"
$ApiRelease = Join-Path $Release "api"
$Domain = $Domain.TrimEnd('/')

Set-Content -Path (Join-Path $Frontend ".env") -Value "VITE_API_URL=$Domain/api`n" -Encoding UTF8
Push-Location $Frontend
try {
    npm ci
    npm run build
} finally {
    Pop-Location
}

if (Test-Path (Join-Path $Root "release")) { Remove-Item (Join-Path $Root "release") -Recurse -Force }
New-Item -ItemType Directory -Path $Release -Force | Out-Null
Copy-Item (Join-Path $Frontend "dist\*") $Release -Recurse -Force
Copy-Item (Join-Path $Root "deployment\public-root.htaccess") (Join-Path $Release ".htaccess") -Force

New-Item -ItemType Directory -Path $ApiRelease -Force | Out-Null
Get-ChildItem (Join-Path $Root "api") -Force | Where-Object { $_.Name -notin @('.env', 'storage') } | ForEach-Object {
    Copy-Item $_.FullName $ApiRelease -Recurse -Force
}
New-Item -ItemType Directory -Path (Join-Path $ApiRelease "storage\logs") -Force | Out-Null
Copy-Item (Join-Path $Root "api\.env.example") (Join-Path $ApiRelease ".env.production.example") -Force

$Zip = Join-Path $Root "release\DonorConnect-public-html.zip"
Add-Type -AssemblyName System.IO.Compression.FileSystem
if (Test-Path $Zip) { Remove-Item $Zip -Force }
[System.IO.Compression.ZipFile]::CreateFromDirectory($Release, $Zip)
Write-Host "Production package created at: $Zip" -ForegroundColor Green
Write-Host "Create api/.env on the server using api/.env.production.example as the template." -ForegroundColor Yellow
