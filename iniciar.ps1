# =====================================================================
#  Levanta el sistema en local desde PowerShell:   .\iniciar.ps1
#  Abre:  http://127.0.0.1:8000
#
#  (No usamos "php artisan serve" porque en PowerShell de Windows falla con
#   "Unable to start curl module": Laravel descarta la variable SystemRoot.)
# =====================================================================
param([int]$Puerto = 8000)

Set-Location $PSScriptRoot

# PHP con PostgreSQL (Herd PHP 8.5); si no está, el "php" del sistema
$php = "$env:USERPROFILE\.config\herd\bin\php85\php.exe"
if (-not (Test-Path $php)) { $php = (Get-Command php -ErrorAction Stop).Source }

if (-not (Test-Path "public\build\manifest.json")) {
    Write-Host "Compilando el frontend (npm run build)..." -ForegroundColor Yellow
    npm run build
}

& $php artisan migrate --force | Out-Null
Write-Host ""
Write-Host "  Sistema en  http://127.0.0.1:$Puerto   (Ctrl+C para detener)" -ForegroundColor Green
Write-Host "  Usuario: su correo  |  Contrasena: Ficct2026  |  Admin: admin@ficct.edu.bo" -ForegroundColor Gray
Write-Host ""

Push-Location public
try {
    & $php -S "127.0.0.1:$Puerto" "..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php"
} finally {
    Pop-Location
}
