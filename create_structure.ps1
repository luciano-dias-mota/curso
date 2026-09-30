# Execute na pasta C:\xampp\htdocs
$project = "pmmta-academy"

$dirs = @(
    "$project\app\Core",
    "$project\app\Controllers\Admin",
    "$project\app\Models",
    "$project\app\Middleware",
    "$project\app\Services",
    "$project\app\Repositories",
    "$project\app\Helpers",
    "$project\app\Views\layouts",
    "$project\app\Views\home",
    "$project\app\Views\auth",
    "$project\app\Views\student",
    "$project\app\Views\admin",
    "$project\app\Views\errors",
    "$project\config",
    "$project\database\migrations",
    "$project\public\assets\css",
    "$project\public\assets\js",
    "$project\public\assets\images\avatars",
    "$project\public\assets\images\achievements",
    "$project\public\assets\images\backgrounds",
    "$project\public\assets\images\course",
    "$project\public\assets\icons",
    "$project\public\uploads",
    "$project\routes",
    "$project\storage\logs",
    "$project\storage\cache"
)

$files = @(
    "$project\.env",
    "$project\.env.example",
    "$project\.gitignore",
    "$project\composer.json",
    "$project\README.md",
    "$project\public\index.php",
    "$project\public\.htaccess",
    "$project\config\app.php",
    "$project\config\database.php",
    "$project\routes\web.php",
    "$project\routes\admin.php",
    "$project\routes\api.php",
    "$project\database\schema.sql"
)

$dirs | ForEach-Object {
    New-Item -ItemType Directory -Force -Path $_ | Out-Null
}

$files | ForEach-Object {
    if (-not (Test-Path $_)) {
        New-Item -ItemType File -Force -Path $_ | Out-Null
    }
}

New-Item -ItemType File -Force -Path "$project\storage\cache\.gitkeep" | Out-Null
New-Item -ItemType File -Force -Path "$project\public\uploads\.gitkeep" | Out-Null

Write-Host "Estrutura criada em: $(Resolve-Path $project)" -ForegroundColor Green
