param(
    [Parameter(Mandatory=$true)]
    [string]$AccessPath,

    [string]$OutputDir = "data/access_csv"
)

$ErrorActionPreference = "Stop"

if (!(Test-Path -LiteralPath $AccessPath)) {
    Write-Host "No existe el archivo indicado: $AccessPath"
    exit 1
}

if (!(Test-Path -LiteralPath $OutputDir)) {
    New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null
}

$tables = @(
    "TABCUAR",
    "BDFUERZA",
    "TABLUGAR",
    "TABDIR",
    "DIR",
    "DOTA",
    "POLPLANI",
    "VACANTES",
    "CARGOS",
    "TABRAN",
    "TABSTATUS"
)

$providers = @(
    "Microsoft.ACE.OLEDB.16.0",
    "Microsoft.ACE.OLEDB.12.0",
    "Microsoft.Jet.OLEDB.4.0"
)

$conn = $null
$lastError = $null

foreach ($provider in $providers) {
    try {
        $connectionString = "Provider=$provider;Data Source=$AccessPath;Persist Security Info=False;"
        $conn = New-Object System.Data.OleDb.OleDbConnection($connectionString)
        $conn.Open()
        Write-Host "Conexion OK usando proveedor: $provider"
        break
    } catch {
        $lastError = $_.Exception.Message
        $conn = $null
    }
}

if ($null -eq $conn) {
    Write-Host "No se pudo abrir la base."
    Write-Host "Ultimo error: $lastError"
    exit 1
}

foreach ($table in $tables) {
    try {
        Write-Host "Exportando $table..."
        $cmd = $conn.CreateCommand()
        $cmd.CommandText = "SELECT * FROM [$table]"
        $adapter = New-Object System.Data.OleDb.OleDbDataAdapter($cmd)
        $dataTable = New-Object System.Data.DataTable
        [void]$adapter.Fill($dataTable)

        $outFile = Join-Path $OutputDir "$table.csv"
        $dataTable | Export-Csv -Path $outFile -NoTypeInformation -Encoding UTF8
        Write-Host "OK: $outFile ($($dataTable.Rows.Count) registros)"
    } catch {
        Write-Host ("Error exportando " + $table + " - " + $_.Exception.Message)
    }
}

$conn.Close()
Write-Host "Exportacion finalizada. Revise la carpeta: $OutputDir"
