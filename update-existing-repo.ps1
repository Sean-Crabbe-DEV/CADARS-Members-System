param(
    [Parameter(Mandatory=$true)]
    [string]$RepositoryFolder
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$repo = (Resolve-Path $RepositoryFolder).Path
if (-not (Test-Path (Join-Path $repo '.git'))) {
    throw "The destination must be an existing local Git repository. Clone the GitHub repository first."
}
$src = Join-Path $root 'ChepstowRAYNET'
if (-not (Test-Path (Join-Path $src 'App/ChepstowRAYNETApp.swift'))) {
    throw "Missing source next to this script. Extract the complete release ZIP first."
}
$dest = Join-Path $repo 'ChepstowRAYNET'
if (Test-Path $dest) { Remove-Item $dest -Recurse -Force }
Copy-Item $src $dest -Recurse -Force
Copy-Item (Join-Path $root 'project.yml') (Join-Path $repo 'project.yml') -Force
$workflows = Join-Path $repo '.github/workflows'
New-Item -ItemType Directory -Force -Path $workflows | Out-Null
Copy-Item (Join-Path $root '.github/workflows/build-unsigned-ipa.yml') (Join-Path $workflows 'build-unsigned-ipa.yml') -Force
Write-Host "Copied v0.6 source and workflow into $repo"
Write-Host 'Review the changes: git status; git diff'
Write-Host 'Then commit/push manually. No Git changes were committed or pushed by this script.'
