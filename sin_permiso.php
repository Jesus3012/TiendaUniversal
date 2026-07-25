<?php
require_once __DIR__ . '/includes/session.php';

$rol = strtolower(trim((string) ($_SESSION['rol'] ?? '')));
$destino = in_array(
    $rol,
    ['administrador', 'super_administrador'],
    true
)
    ? 'dashboard_admin.php'
    : 'dashboard_vendedor.php';

$modulo = trim((string) ($_GET['modulo'] ?? 'este módulo'));
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso restringido</title>
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
    >
    <style>
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            margin: 0;
            padding: 20px;
            font-family: Inter, Arial, sans-serif;
            color: #1e293b;
            background:
                radial-gradient(
                    circle at top,
                    rgba(249, 115, 22, .16),
                    transparent 38%
                ),
                #f8fafc;
        }
        .denied-card {
            width: min(440px, 100%);
            padding: 30px;
            text-align: center;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .10);
        }
        .denied-icon {
            width: 72px;
            height: 72px;
            display: grid;
            place-items: center;
            margin: 0 auto 18px;
            color: #fff;
            background: linear-gradient(135deg, #f97316, #ea580c);
            border-radius: 22px;
            font-size: 1.7rem;
        }
        h1 {
            margin: 0 0 9px;
            font-size: 1.5rem;
        }
        p {
            margin: 0 0 22px;
            color: #64748b;
            line-height: 1.55;
        }
        small {
            display: block;
            margin-bottom: 22px;
            color: #94a3b8;
        }
        a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
            padding: 10px 18px;
            color: #fff;
            background: #f97316;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 750;
        }
    </style>
</head>
<body>
    <main class="denied-card">
        <div class="denied-icon">
            <i class="fas fa-lock"></i>
        </div>

        <h1>Acceso restringido</h1>

        <p>
            Tu rol no tiene habilitado este módulo.
            Un administrador puede activarlo desde Control de accesos.
        </p>

        <small>
            <?= htmlspecialchars($modulo, ENT_QUOTES, 'UTF-8') ?>
        </small>

        <a href="<?= htmlspecialchars($destino, ENT_QUOTES, 'UTF-8') ?>">
            <i class="fas fa-arrow-left"></i>
            Volver al panel
        </a>
    </main>
</body>
</html>
