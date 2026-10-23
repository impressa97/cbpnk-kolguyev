# Pack the Kolguyev war mod with Arma Reforger Tools and drop it where the dedicated server loads it.
#   pwsh scripts/pack-war-mod.ps1 [-Source <mod>] [-LocalServerAddon <dir>]
# Workbench packs from a Windows path, so the WSL clone's mod is mirrored to -Mirror first.
param(
    [string]$Source = '\\wsl.localhost\Ubuntu\home\impressa\projects\cbpnk-kolguyev\mod',
    [string]$Mirror = 'C:\DEV\cbpnk-kolguyev\mod',
    [string]$Out = "$env:TEMP\cbpnk-war-pack",
    [string]$Game = 'A:\Steam\steamapps\common\Arma Reforger',
    [string]$Tools = 'A:\Steam\steamapps\common\Arma Reforger Tools\Workbench\ArmaReforgerWorkbenchSteamDiag.exe',
    [string]$LocalServerAddon = '\\wsl.localhost\Ubuntu\home\impressa\projects\cbpnk-kolguyev\server\profile\localaddons\CBPNKWar_53D18422A46F789F'
)
$ErrorActionPreference = 'Stop'

robocopy $Source $Mirror /MIR /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy failed ($LASTEXITCODE)" }
$global:LASTEXITCODE = 0  # 1-7 mean files were copied
Remove-Item -Recurse -Force $Out -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force $Out | Out-Null

Get-Process | Where-Object ProcessName -like '*Workbench*' | Stop-Process -Force -ErrorAction SilentlyContinue
Start-Sleep 2
$args = "-addonsDir `"$Game\addons`" -gproj `"$Mirror\addon.gproj`" -wbModule=ResourceManager -packAddon -packAddonDir `"$Out`""
$p = Start-Process -FilePath $Tools -ArgumentList $args -WorkingDirectory (Split-Path $Tools) -PassThru
if (-not $p.WaitForExit(300000)) { $p.Kill(); throw 'Workbench did not finish packing in 5 minutes' }

$log = Get-ChildItem "$([Environment]::GetFolderPath('MyDocuments'))\My Games\ArmaReforgerWorkbench\logs", 'A:\docs\My Games\ArmaReforgerWorkbench\logs' -Directory -ErrorAction SilentlyContinue |
    Sort-Object LastWriteTime -Descending | Select-Object -First 1
$errors = Select-String -Path (Join-Path $log.FullName console.log) -Pattern 'SCRIPT\s+\(E\)|cannot be packed|Can''t compile' | ForEach-Object Line
if ($errors) { $errors; throw 'Script errors: not packed' }
if (-not (Test-Path "$Out\data.pak")) { throw "No data.pak in $Out" }

if (Test-Path (Split-Path $LocalServerAddon)) {
    New-Item -ItemType Directory -Force $LocalServerAddon | Out-Null
    Copy-Item "$Out\*" $LocalServerAddon -Force
    "Copied to the local server: $LocalServerAddon"
}
Get-ChildItem $Out | Select-Object Name, Length
