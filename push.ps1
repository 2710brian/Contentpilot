param(
  [string]$Message = ""
)

$ErrorActionPreference = "Stop"

function Require-Git {
  if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    throw "Git er ikke installeret eller ikke i PATH."
  }
}

Require-Git

# Sørg for at vi kører i scriptets mappe (repo root)
Set-Location -Path $PSScriptRoot

# Vis kort status
git status -sb

# Ingen ændringer? Så stop
$porcelain = git status --porcelain
if ([string]::IsNullOrWhiteSpace($porcelain)) {
  Write-Host "Ingen ændringer at sende (working tree clean)."
  exit 0
}

# Stage alt
git add .

# Commit message
if ([string]::IsNullOrWhiteSpace($Message)) {
  $Message = Read-Host "Skriv en kort commit-besked"
}
if ([string]::IsNullOrWhiteSpace($Message)) {
  throw "Commit-besked må ikke være tom."
}

git commit -m $Message

# Push
git push

Write-Host "Færdig. Ændringer er sendt til GitHub."

