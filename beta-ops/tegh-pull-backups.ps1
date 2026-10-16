# Tegh beta: download the encrypted backups (tegh-*.tar.age) from the hosting server to this Windows laptop.
#
# The server makes them with tegh-backup.sh and TEGH_ENCRYPT_TO=<your age public key>. Only your private key
# (tegh-backup-key.txt, which never leaves this laptop) can open them. This script only downloads: it never sends
# anything to the server and never deletes anything there.
#
# Uses the SSH and SCP programs built into Windows 10/11 (OpenSSH Client). Run it by hand once, then schedule it:
#   powershell -NoProfile -ExecutionPolicy Bypass -File "$HOME\TeghBackup\tegh-pull-backups.ps1"
#
# Fill in the four settings below (or pass them as parameters).
param(
    [string]$Server    = "YOUR-SSH-USER@YOUR-SSH-HOST",      # IONOS: Hosting > SSH access, e.g. u12345678@access-5012345678.webspace-host.com
    [int]   $Port      = 22,
    [string]$KeyFile   = "$HOME\.ssh\tegh_ionos",            # the private SSH key whose public half is on the server
    [string]$RemoteDir = "tegh-backups/encrypted",           # TEGH_ENCRYPTED_OUT on the server, relative to its home folder
    [string]$LocalDir  = "$HOME\TeghBackup\encrypted",
    [int]   $KeepDays  = 90                                  # encrypted copies older than this are removed from the laptop
)
$ErrorActionPreference = 'Stop'
New-Item -ItemType Directory -Force -Path $LocalDir | Out-Null
$log = Join-Path $LocalDir 'pull.log'
function Log([string]$text) { $line = "$(Get-Date -Format s)  $text"; Add-Content -Path $log -Value $line; Write-Output $line }

$ssh = @('-i', $KeyFile, '-p', "$Port", '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=30')
$names = & ssh @ssh $Server "ls -1 $RemoteDir 2>/dev/null"
if ($LASTEXITCODE -ne 0) { Log "FAIL could not list $RemoteDir on $Server (check the SSH login and key)"; exit 1 }

$new = 0; $failed = 0
foreach ($name in $names) {
    $name = "$name".Trim()
    if ($name -notmatch '^tegh-\d{8}-\d{6}\.tar\.age$') { continue }     # complete files only (never .part)
    $dest = Join-Path $LocalDir $name
    if (Test-Path $dest) { continue }
    $tmp = "$dest.part"
    & scp -i $KeyFile -P "$Port" -o BatchMode=yes -q "${Server}:$RemoteDir/$name" $tmp
    if ($LASTEXITCODE -eq 0 -and (Get-Item $tmp).Length -gt 0) { Move-Item -Force $tmp $dest; $new++; Log "downloaded $name ($([math]::Round((Get-Item $dest).Length / 1KB)) KB)" }
    else { Remove-Item -ErrorAction SilentlyContinue $tmp; $failed++; Log "FAIL download of $name" }
}
Get-ChildItem $LocalDir -Filter 'tegh-*.tar.age' | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$KeepDays) } | ForEach-Object { Remove-Item $_.FullName; Log "removed old copy $($_.Name)" }
$total = (Get-ChildItem $LocalDir -Filter 'tegh-*.tar.age').Count
Log "done: $new new, $failed failed, $total encrypted backups on this laptop"
if ($failed -gt 0) { exit 1 }
