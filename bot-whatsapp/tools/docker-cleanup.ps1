# Limpeza segura do Docker — recupera espaco de imagens/cache que se acumulam a
# cada rebuild. NAO remove volumes (a sessao do WhatsApp e o estado ficam a salvo).
# Rode de vez em quando (ou agende no Agendador de Tarefas, semanal).
#
#   powershell -ExecutionPolicy Bypass -File tools\docker-cleanup.ps1
#
# Agendar (exemplo, 1x/semana as 04h):
#   schtasks /create /tn "DockerCleanup" /tr "powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\arletevieiraconfeitaria\bot-whatsapp\tools\docker-cleanup.ps1" /sc weekly /d SUN /st 04:00

$ErrorActionPreference = 'SilentlyContinue'
$docker = "C:\Program Files\Docker\Docker\resources\bin\docker.exe"
if (-not (Test-Path $docker)) { $docker = "docker" }

Write-Output ("[{0}] Limpeza Docker iniciada" -f (Get-Date -Format 'yyyy-MM-dd HH:mm'))
& $docker image prune -a -f      # imagens nao usadas por nenhum container (protege as ativas)
& $docker builder prune -f       # cache de build
& $docker container prune -f     # containers parados (os ativos com restart:always ficam)
# NUNCA passar --volumes: isso apagaria evolution_pg (sessao WhatsApp) e bot_state.
Write-Output "--- espaco no disco do Docker ---"
& $docker system df
Write-Output ("[{0}] Limpeza concluida" -f (Get-Date -Format 'yyyy-MM-dd HH:mm'))
