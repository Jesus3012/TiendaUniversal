<?php
// sin_permiso.php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$rol = mb_strtolower(trim($_SESSION['rol'] ?? ''), 'UTF-8');

$volver = 'login.php';

if ($rol === 'administrador') {
    $volver = 'dashboard_admin.php';
} elseif ($rol === 'vendedor') {
    $volver = 'dashboard_vendedor.php';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Sin permiso</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f8fafc;
        }

        .card {
            width: min(430px, 92%);
            background: white;
            padding: 34px;
            border-radius: 22px;
            text-align: center;
            box-shadow: 0 20px 55px rgba(15, 23, 42, 0.12);
            border: 1px solid rgba(249, 115, 22, 0.18);
        }

        .icon {
            width: 70px;
            height: 70px;
            margin: 0 auto 18px;
            border-radius: 20px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #fb923c, #f97316);
            color: white;
            font-size: 34px;
            font-weight: 900;
        }

        h1 {
            margin: 0 0 10px;
            color: #0f172a;
            font-size: 25px;
        }

        p {
            margin: 0 0 24px;
            color: #64748b;
            line-height: 1.5;
        }

        a {
            display: inline-block;
            padding: 12px 20px;
            border-radius: 14px;
            background: #f97316;
            color: white;
            text-decoration: none;
            font-weight: 800;
        }

        a:hover {
            background: #ea580c;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">!</div>
        <h1>Acceso no permitido</h1>
        <p>No tienes permisos para entrar a esta sección.</p>
        <a href="<?php echo htmlspecialchars($volver); ?>">Volver</a>
    </div>
</body>
</html>