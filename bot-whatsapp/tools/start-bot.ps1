# Blindagem de boot do bot da doceria.
# Recupera do que derrubou o bot em set/2026: socket travado do Docker, engine
# parado, e container voltando com versao ANTIGA apos reboot.
# Fluxo: destrava o socket se preciso -> sobe o Docker -> compose up --build
# (garante a versao ATUAL do bot.js) -> loga o resultado.
#
# Rodar manual:
#   powershell -ExecutionPolicy Bypass -File tools\start-bot.ps1
# Agendar no LOGON (bot volta sozinho e atualizado apos reboot). Rode 1x no cmd:
#   schtasks /create /tn "StartDoceriaBot" /sc onlogon /rl highest /tr "powershell -ExecutionPolicy Bypass -WindowStyle Hidden -File C:\xampp\htdocs\arletevieiraconfeitaria\bot-whatsapp\tools\start-bot.ps1"

$ErrorActionPreference = 'SilentlyContinue'
$docker  = "C:\Program Files\Docker\Docker\resources\bin\docker.exe"
$desktop = "C:\Program Files\Docker\Docker\Docker Desktop.exe"
$botDir  = Split-Path $PSScriptRoot -Parent
$log     = Join-Path $PSScriptRoot 'start-bot.log'
function Log($m) { $line = ('[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m); Add-Content -Path $log -Value $line; Write-Output $line }

function EngineUp {
  & $docker info --format '{{.ServerVersion}}' 2>$null | Out-Null
  return ($LASTEXITCODE -eq 0)
}

Log 'start-bot: iniciando'

if (-not (EngineUp)) {
  Log 'engine fora do ar - recuperando'
  foreach ($p in @('Docker Desktop','com.docker.backend','com.docker.build','dockerd','vpnkit')) {
    Get-Process $p -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
  }
  Start-Sleep -Seconds 4
  $sock = Join-Path $env:LOCALAPPDATA 'docker-secrets-engine'
  if (Test-Path $sock) {
    try { Rename-Item -LiteralPath $sock -NewName ('docker-secrets-engine.bak-{0}' -f (Get-Date -Format 'yyyyMMddHHmmss')) -ErrorAction Stop; Log 'socket travado renomeado' } catch { Log ('rename socket falhou: ' + $_.Exception.Message) }
  }
  Start-Process $desktop
  Log 'Docker Desktop iniciado; aguardando engine'
  $deadline = (Get-Date).AddSeconds(240)
  while ((Get-Date) -lt $deadline) { Start-Sleep -Seconds 8; if (EngineUp) { break } }
}

if (EngineUp) {
  Log ('engine OK: ' + (& $docker info --format '{{.ServerVersion}}' 2>$null))
  Push-Location $botDir
  & $docker compose up -d --build 2>&1 | Select-Object -Last 4 | ForEach-Object { Log $_ }
  Pop-Location
  Log 'compose up --build concluido'
} else {
  Log 'FALHA: engine nao subiu. Pode precisar reiniciar o Windows.'
}
Log 'start-bot: fim'
