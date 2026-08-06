$ErrorActionPreference = 'Stop'
$Host.UI.RawUI.WindowTitle = 'Pescadores - Puente local de báscula y cajón'

$configPath = Join-Path $PSScriptRoot 'config.json'
if (-not (Test-Path $configPath)) { throw "No se encontró config.json" }
$config = Get-Content $configPath -Raw | ConvertFrom-Json

$script:Estado = [ordered]@{
    ok = $true
    connected = $false
    stable = $false
    weightKg = 0.0
    raw = ''
    unit = 'kg'
    simulation = [bool]$config.bascula.simulation
    updatedAt = (Get-Date).ToString('o')
    message = 'Inicializando'
}
$script:BasculaPort = $null
$script:EventoSerial = $null

function Convertir-Secuencia([string]$valor) {
    if ($null -eq $valor) { return '' }
    return $valor.Replace('\\r', "`r").Replace('\\n', "`n").Replace('\\t', "`t")
}

function Convertir-PesoKg([double]$peso, [string]$unidad) {
    $unidadNormalizada = if ([string]::IsNullOrWhiteSpace($unidad)) { 'kg' } else { $unidad.ToLowerInvariant() }
    switch ($unidadNormalizada) {
        'g'  { return $peso / 1000.0 }
        'lb' { return $peso * 0.45359237 }
        'lbs'{ return $peso * 0.45359237 }
        default { return $peso }
    }
}

function Procesar-Lectura([string]$linea) {
    if ([string]::IsNullOrWhiteSpace($linea)) { return }
    $limpia = $linea.Trim()
    $regex = [string]$config.bascula.weightRegex
    $coincidencia = [regex]::Match($limpia, $regex)
    if (-not $coincidencia.Success) { return }

    $numeroTexto = $coincidencia.Value.Replace(',', '.')
    $peso = 0.0
    if (-not [double]::TryParse($numeroTexto, [Globalization.NumberStyles]::Float, [Globalization.CultureInfo]::InvariantCulture, [ref]$peso)) { return }

    $unidad = [string]$config.bascula.defaultUnit
    if ($limpia -match '(?i)\bkg\b') { $unidad = 'kg' }
    elseif ($limpia -match '(?i)\blbs?\b') { $unidad = 'lb' }
    elseif ($limpia -match '(?i)\bg\b') { $unidad = 'g' }

    $estable = [bool]$config.bascula.assumeStable
    foreach ($token in @($config.bascula.stableTokens)) {
        if ($token -and $limpia.ToUpperInvariant().Contains(([string]$token).ToUpperInvariant())) {
            $estable = $true
            break
        }
    }

    $script:Estado.connected = $true
    $script:Estado.stable = $estable
    $script:Estado.weightKg = [math]::Round((Convertir-PesoKg $peso $unidad), 3)
    $script:Estado.raw = $limpia
    $script:Estado.unit = 'kg'
    $script:Estado.simulation = $false
    $script:Estado.updatedAt = (Get-Date).ToString('o')
    $script:Estado.message = 'Lectura recibida'
}

function Abrir-Bascula {
    if (-not [bool]$config.bascula.enabled -or [bool]$config.bascula.simulation) {
        $script:Estado.connected = $true
        $script:Estado.stable = $true
        $script:Estado.weightKg = [double]$config.bascula.simulationWeightKg
        $script:Estado.simulation = $true
        $script:Estado.message = 'Modo simulación'
        return
    }

    $parity = [System.Enum]::Parse([System.IO.Ports.Parity], [string]$config.bascula.parity, $true)
    $stopBits = [System.Enum]::Parse([System.IO.Ports.StopBits], [string]$config.bascula.stopBits, $true)
    $script:BasculaPort = New-Object System.IO.Ports.SerialPort(
        [string]$config.bascula.port,
        [int]$config.bascula.baudRate,
        $parity,
        [int]$config.bascula.dataBits,
        $stopBits
    )
    $script:BasculaPort.NewLine = Convertir-Secuencia ([string]$config.bascula.delimiter)
    $script:BasculaPort.Encoding = [Text.Encoding]::GetEncoding([string]$config.bascula.encoding)
    $script:BasculaPort.Open()

    $script:EventoSerial = Register-ObjectEvent -InputObject $script:BasculaPort -EventName DataReceived -Action {
        try {
            $texto = $Event.Sender.ReadExisting()
            foreach ($linea in ($texto -split "`r?`n")) { Procesar-Lectura $linea }
        } catch {
            $script:Estado.connected = $false
            $script:Estado.message = $_.Exception.Message
        }
    }
    $script:Estado.connected = $true
    $script:Estado.message = "Conectada en $($config.bascula.port)"
}

function Responder-Json($contexto, [int]$codigo, $objeto) {
    $json = $objeto | ConvertTo-Json -Depth 8 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    $contexto.Response.StatusCode = $codigo
    $contexto.Response.ContentType = 'application/json; charset=utf-8'
    $contexto.Response.Headers['Access-Control-Allow-Origin'] = '*'
    $contexto.Response.Headers['Access-Control-Allow-Headers'] = 'Content-Type, X-POS-Hardware-Token'
    $contexto.Response.Headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS'
    $contexto.Response.OutputStream.Write($bytes, 0, $bytes.Length)
    $contexto.Response.Close()
}

function Token-Valido($request) {
    $esperado = [string]$config.token
    if ([string]::IsNullOrEmpty($esperado)) { return $true }
    return [string]$request.Headers['X-POS-Hardware-Token'] -eq $esperado
}

function Abrir-Cajon {
    if (-not [bool]$config.cajon.enabled) { return @{ ok=$false; message='Cajón desactivado' } }
    $p = $null
    try {
        $p = New-Object System.IO.Ports.SerialPort(
            [string]$config.cajon.port,
            [int]$config.cajon.baudRate,
            [System.IO.Ports.Parity]::None,
            8,
            [System.IO.Ports.StopBits]::One
        )
        $p.Open()
        $p.Write([string]$config.cajon.command)
        Start-Sleep -Milliseconds ([int]$config.cajon.openMilliseconds)
        $p.Close()
        return @{ ok=$true; message='Cajón abierto correctamente' }
    } catch {
        if ($p -and $p.IsOpen) { $p.Close() }
        return @{ ok=$false; message=$_.Exception.Message }
    }
}

try { Abrir-Bascula } catch {
    $script:Estado.connected = $false
    $script:Estado.message = $_.Exception.Message
    Write-Host "No se pudo abrir la báscula: $($_.Exception.Message)" -ForegroundColor Yellow
}

$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add("http://127.0.0.1:$([int]$config.httpPort)/")
$listener.Start()
Write-Host "Puente local activo en http://127.0.0.1:$([int]$config.httpPort)" -ForegroundColor Green
Write-Host "Modo báscula: $($(if ([bool]$config.bascula.simulation) {'SIMULACIÓN'} else {$config.bascula.port}))"
Write-Host 'Presiona Ctrl+C para detener.'

try {
    while ($listener.IsListening) {
        $ctx = $listener.GetContext()
        $req = $ctx.Request
        $ruta = $req.Url.AbsolutePath.ToLowerInvariant()

        if ($req.HttpMethod -eq 'OPTIONS') {
            Responder-Json $ctx 200 @{ ok=$true }
            continue
        }
        if (-not (Token-Valido $req)) {
            Responder-Json $ctx 403 @{ ok=$false; message='Token local no válido' }
            continue
        }

        switch ($ruta) {
            '/bascula/peso' {
                if ([bool]$config.bascula.simulation) {
                    $script:Estado.connected = $true
                    $script:Estado.stable = $true
                    $script:Estado.weightKg = [double]$config.bascula.simulationWeightKg
                    $script:Estado.simulation = $true
                    $script:Estado.updatedAt = (Get-Date).ToString('o')
                }
                Responder-Json $ctx 200 $script:Estado
            }
            '/bascula/estado' { Responder-Json $ctx 200 $script:Estado }
            '/bascula/tara' {
                try {
                    if ([bool]$config.bascula.simulation) {
                        $config.bascula.simulationWeightKg = 0
                    } elseif ($script:BasculaPort -and $script:BasculaPort.IsOpen) {
                        $script:BasculaPort.Write((Convertir-Secuencia ([string]$config.bascula.tareCommand)))
                    }
                    Responder-Json $ctx 200 @{ ok=$true; message='Tara enviada' }
                } catch { Responder-Json $ctx 500 @{ ok=$false; message=$_.Exception.Message } }
            }
            '/bascula/simular' {
                $peso = 0.0
                $pesoTexto = [string]$req.QueryString['peso']
                if ([string]::IsNullOrWhiteSpace($pesoTexto)) { $pesoTexto = '0' }
                [double]::TryParse($pesoTexto.Replace(',','.'), [Globalization.NumberStyles]::Float, [Globalization.CultureInfo]::InvariantCulture, [ref]$peso) | Out-Null
                $config.bascula.simulationWeightKg = [math]::Max(0, [math]::Round($peso, 3))
                Responder-Json $ctx 200 @{ ok=$true; weightKg=$config.bascula.simulationWeightKg }
            }
            '/abrir-cajon' {
                $resultado = Abrir-Cajon
                Responder-Json $ctx $(if ($resultado.ok) {200} else {500}) $resultado
            }
            default { Responder-Json $ctx 404 @{ ok=$false; message='Ruta no encontrada' } }
        }
    }
} finally {
    if ($script:EventoSerial) { Unregister-Event -SourceIdentifier $script:EventoSerial.SourceIdentifier -ErrorAction SilentlyContinue }
    if ($script:BasculaPort -and $script:BasculaPort.IsOpen) { $script:BasculaPort.Close() }
    $listener.Stop()
}
