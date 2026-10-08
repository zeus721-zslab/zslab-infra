# PC version of deploy-gateway.sh.
# Copies repo gateway/nginx/nginx.conf into GatewayDir's nginx.conf, keeping
# the same file (same inode-equivalent) because it is a Docker bind mount
# that does not follow file replacement (new inode).
#
# Usage: powershell -ExecutionPolicy Bypass -File scripts\deploy-gateway.ps1 -GatewayDir C:\path\to\gateway [-Container gateway_nginx] [-DryRun]

param(
    [string]$GatewayDir = $env:GATEWAY_DIR,
    [string]$Container = 'gateway_nginx',
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrEmpty($GatewayDir)) {
    Write-Host "ERROR: GatewayDir is required (pass -GatewayDir or set GATEWAY_DIR env var)."
    exit 1
}

# --- Step 1: resolve paths ---
$RepoRoot = Split-Path -Parent $PSScriptRoot
$SrcConf = Join-Path $RepoRoot 'gateway\nginx\nginx.conf'
$DstConf = Join-Path $GatewayDir 'nginx\nginx.conf'
$SrcCompose = Join-Path $RepoRoot 'gateway\docker-compose.yml'
$DstCompose = Join-Path $GatewayDir 'docker-compose.yml'

if (-not (Test-Path -LiteralPath $SrcConf)) {
    Write-Host "ERROR: source nginx.conf not found: $SrcConf"
    exit 1
}
if (-not (Test-Path -LiteralPath $DstConf)) {
    Write-Host "ERROR: destination nginx.conf not found: $DstConf"
    exit 1
}
Write-Host "Step 1: paths resolved (src=$SrcConf, dst=$DstConf)"

# --- Step 2: container running check ---
$Running = docker inspect -f '{{.State.Running}}' $Container
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: docker inspect failed for container '$Container'."
    exit 1
}
if ($Running -ne 'true') {
    Write-Host "ERROR: container '$Container' is not running."
    exit 1
}
Write-Host "Step 2: container '$Container' is running"

# --- Step 3: byte comparison ---
$SrcHash = (Get-FileHash -LiteralPath $SrcConf -Algorithm SHA256).Hash
$DstHash = (Get-FileHash -LiteralPath $DstConf -Algorithm SHA256).Hash
if ($SrcHash -eq $DstHash) {
    Write-Host "Step 3: no change"
    exit 0
}
Write-Host "Step 3: nginx.conf differs, proceeding"

if ($DryRun) {
    Write-Host "DryRun: would back up destination, overwrite in place, verify, nginx -t, and reload. No files were changed."

    if ((Test-Path -LiteralPath $SrcCompose) -and (Test-Path -LiteralPath $DstCompose)) {
        $SrcComposeHash = (Get-FileHash -LiteralPath $SrcCompose -Algorithm SHA256).Hash
        $DstComposeHash = (Get-FileHash -LiteralPath $DstCompose -Algorithm SHA256).Hash
        if ($SrcComposeHash -ne $DstComposeHash) {
            Write-Host "WARNING: docker-compose.yml differs between repo and GatewayDir (not copied)."
        } else {
            Write-Host "Step 9: docker-compose.yml matches"
        }
    } else {
        Write-Host "WARNING: docker-compose.yml comparison skipped (file missing on one side)."
    }
    exit 0
}

# --- Step 4: backup ---
$Timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$BackupPath = "$DstConf.bak-$Timestamp"
Copy-Item -LiteralPath $DstConf -Destination $BackupPath
Write-Host "Step 4: backup created at $BackupPath"

function Revert-FromBackup {
    [System.IO.File]::WriteAllBytes($DstConf, [System.IO.File]::ReadAllBytes($BackupPath))
}

# --- Step 5: overwrite destination in place (same file, no new inode) ---
[System.IO.File]::WriteAllBytes($DstConf, [System.IO.File]::ReadAllBytes($SrcConf))
Write-Host "Step 5: destination file overwritten in place"

# --- Step 6: verify container sees the new content ---
$ContainerHashLine = docker exec $Container sha256sum /etc/nginx/nginx.conf
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: docker exec sha256sum failed. Reverting from backup."
    Revert-FromBackup
    exit 1
}
$ContainerHash = ($ContainerHashLine -split '\s+')[0]
$SrcHashLower = (Get-FileHash -LiteralPath $SrcConf -Algorithm SHA256).Hash.ToLower()
if ($ContainerHash -ne $SrcHashLower) {
    Write-Host "ERROR: container file hash does not match source after write (bind mount followed a new inode). Reverting from backup."
    Revert-FromBackup
    exit 1
}
Write-Host "Step 6: container file hash matches source"

# --- Step 7: nginx -t ---
docker exec $Container nginx -t
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: nginx -t failed. Reverting from backup."
    Revert-FromBackup
    exit 1
}
Write-Host "Step 7: nginx -t passed"

# --- Step 8: reload ---
docker exec $Container nginx -s reload
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: nginx -s reload failed. Config already passed nginx -t; not reverting."
    exit 1
}
Write-Host "Step 8: nginx reloaded"

# --- Step 9: compose comparison (warning only, never copied) ---
if ((Test-Path -LiteralPath $SrcCompose) -and (Test-Path -LiteralPath $DstCompose)) {
    $SrcComposeHash = (Get-FileHash -LiteralPath $SrcCompose -Algorithm SHA256).Hash
    $DstComposeHash = (Get-FileHash -LiteralPath $DstCompose -Algorithm SHA256).Hash
    if ($SrcComposeHash -ne $DstComposeHash) {
        Write-Host "WARNING: docker-compose.yml differs between repo and GatewayDir (not copied)."
    } else {
        Write-Host "Step 9: docker-compose.yml matches"
    }
} else {
    Write-Host "WARNING: docker-compose.yml comparison skipped (file missing on one side)."
}

exit 0
