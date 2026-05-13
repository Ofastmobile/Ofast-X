$plugin = "ofast-toolkit"
$version = "1.0.0"

$source = ".\"
$destination = ".\dist\$plugin"

Write-Host "Copying files to $destination..."
$robocopyParams = @(
    $source,
    $destination,
    "/MIR",
    "/XD", ".git", ".vscode", "node_modules", "dist",
    "/XF", ".gitignore", "*.map", "build.ps1", "REPO_READY_CHECKLIST.txt", "kind.txt", "footer.txt", "LICENSING_GUIDE.md"
)
& robocopy @robocopyParams | Out-Null

Write-Host "Waiting a few seconds for file locks to clear..."
Start-Sleep -Seconds 3

$zipPath = ".\dist\$plugin-$version.zip"
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

Write-Host "Zipping the plugin to $zipPath..."
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory((Resolve-Path $destination).ProviderPath, (Join-Path (Resolve-Path ".\dist").ProviderPath "$plugin-$version.zip"))

Write-Host "Build complete: $zipPath"
