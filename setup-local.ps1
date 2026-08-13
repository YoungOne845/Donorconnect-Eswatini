param(
    [string]$DbPassword = "",
    [switch]$SkipNpmInstall
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$ApiEnvExample = Join-Path $Root "api\.env.example"
$ApiEnv = Join-Path $Root "api\.env"
$FrontendEnvExample = Join-Path $Root "frontend\.env.example"
$FrontendEnv = Join-Path $Root "frontend\.env"

function New-RandomSecret {
    $bytes = New-Object byte[] 48
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($bytes)
}

if (-not (Test-Path $ApiEnv)) {
    $content = Get-Content $ApiEnvExample -Raw
    $content = $content.Replace("replace_with_a_random_64_character_secret", (New-RandomSecret))
    $content = $content.Replace("replace_with_a_different_random_64_character_secret", (New-RandomSecret))
    $content = $content.Replace("replace_with_another_random_64_character_secret", (New-RandomSecret))
    $content = $content.Replace("replace_this_before_using_setup_endpoint", (New-RandomSecret))
    $content = $content -replace '(?m)^DB_PASSWORD=.*$', "DB_PASSWORD=$DbPassword"
    Set-Content -Path $ApiEnv -Value $content -Encoding UTF8
    Write-Host "Created api/.env with fresh random security keys." -ForegroundColor Green
} else {
    Write-Host "api/.env already exists; it was not overwritten." -ForegroundColor Yellow
}

if (-not (Test-Path $FrontendEnv)) {
    Copy-Item $FrontendEnvExample $FrontendEnv
    Write-Host "Created frontend/.env." -ForegroundColor Green
}

if (-not $SkipNpmInstall) {
    Push-Location (Join-Path $Root "frontend")
    try {
        npm install
    } finally {
        Pop-Location
    }
}

Write-Host ""
Write-Host "Local configuration complete." -ForegroundColor Cyan
Write-Host "Next: import database/schema.sql in phpMyAdmin, then create the first administrator." -ForegroundColor Cyan
