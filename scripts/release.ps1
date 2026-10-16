<#
    Sends what is committed on this branch to the "production" branch on GitHub, with the built screens included,
    for cPanel to pull and deploy. Run from the project folder:

        .\scripts\release.ps1

    The production branch lives in its own folder next to this one (..\adoma-release), so this folder, and the app
    running in XAMPP, is never switched or disturbed.
#>
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
Set-Location $root

if (git status --porcelain) { throw 'Commit your changes first: only committed work is released.' }
$source = git branch --show-current
$commit = git log -1 --format=%h

Write-Host "Building the screens..."
npm run build
if ($LASTEXITCODE -ne 0) { throw 'The build failed, so nothing was released.' }

git fetch origin
$release = Join-Path (Split-Path $root -Parent) 'adoma-release'
$onGitHub = [bool](git ls-remote --heads origin production)

if (-not (Test-Path $release)) {
    if ($onGitHub) { git worktree add $release production } else { git worktree add -b production $release $source }
    if ($LASTEXITCODE -ne 0) { throw 'Could not prepare the release folder.' }
}

Push-Location $release
try {
    if ($onGitHub) { git pull --ff-only origin production; if ($LASTEXITCODE -ne 0) { throw 'production on GitHub has changes this computer does not: sort that out first.' } }

    git merge --no-edit $source
    if ($LASTEXITCODE -ne 0) { throw "Could not merge $source into production." }

    # The built screens: ignored on every other branch, kept on this one.
    robocopy (Join-Path $root 'public\build') (Join-Path $release 'public\build') /MIR /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { throw 'Could not copy the built screens.' }
    git add -f public/build
    git add -A

    if (git status --porcelain) { git commit -m "Release $commit from $source" | Out-Null }

    git push -u origin production
    if ($LASTEXITCODE -ne 0) { throw 'Could not push to GitHub.' }
} finally {
    Pop-Location
}

Write-Host ""
Write-Host "Released $commit. Now in cPanel: Git Version Control > Manage > Pull or Deploy > Update from Remote, then Deploy HEAD Commit."
