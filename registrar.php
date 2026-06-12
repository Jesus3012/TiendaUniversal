<?php
include('includes/db.php');
include('includes/header.php');

$nombre_tienda = 'Tienda';
$logo_tienda = 'includes/logo_login.png';

$config = $conn->query("
    SELECT nombre, logo
    FROM configuracion_galeria
    WHERE id = 1
    LIMIT 1
");

if ($config && $config->num_rows > 0) {
    $row = $config->fetch_assoc();

    if (!empty($row['nombre'])) {
        $nombre_tienda = $row['nombre'];
    }

    if (!empty($row['logo']) && file_exists($row['logo'])) {
        $logo_tienda = $row['logo'];
    }
}

$alerta = null;

if (isset($_POST['register'])) {
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password_plain = $_POST['password'] ?? '';
    $rol = 'vendedor';

    if ($nombre === '' || $email === '' || $password_plain === '') {
        $alerta = [
            'icon' => 'warning',
            'title' => 'Campos incompletos',
            'text' => 'Completa todos los campos para continuar.'
        ];
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $alerta = [
            'icon' => 'warning',
            'title' => 'Correo inválido',
            'text' => 'Ingresa un correo electrónico válido.'
        ];
    } elseif (strlen($password_plain) < 8) {
        $alerta = [
            'icon' => 'warning',
            'title' => 'Contraseña muy corta',
            'text' => 'La contraseña debe tener al menos 8 caracteres.'
        ];
    } else {
        $password = password_hash($password_plain, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            INSERT INTO usuarios (nombre, email, password, rol)
            VALUES (?, ?, ?, ?)
        ");

        if ($stmt) {
            $stmt->bind_param("ssss", $nombre, $email, $password, $rol);

            if ($stmt->execute()) {
                $alerta = [
                    'icon' => 'success',
                    'title' => '¡Registro exitoso!',
                    'text' => 'La cuenta fue creada correctamente.'
                ];
            } else {
                $alerta = [
                    'icon' => 'error',
                    'title' => 'Error',
                    'text' => 'El correo ya está registrado.'
                ];
            }

            $stmt->close();
        } else {
            $alerta = [
                'icon' => 'error',
                'title' => 'Error',
                'text' => 'No se pudo crear la cuenta.'
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Cuenta | <?= htmlspecialchars($nombre_tienda) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root{
            --orange:#ff7b00;
            --orange-dark:#e66f00;
            --orange-soft:#fff3e8;
            --text-dark:#111827;
            --text-muted:#6b7280;
            --border:#e5e7eb;
        }

        *{
            box-sizing:border-box;
        }

        body{
            margin:0;
            min-height:100vh;
            background:
                radial-gradient(circle at top left, rgba(255,123,0,.12), transparent 32%),
                linear-gradient(135deg,#fff3e8 0%,#fff8f1 45%,#ffffff 100%);
            display:flex;
            justify-content:center;
            align-items:center;
            padding:20px;
            font-family:"Source Sans Pro",-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
        }

        /* =========================
        CONTENEDOR PRINCIPAL
        ========================= */

        .register-box{
            width:100%;
            max-width:580px;
        }

        /* =========================
        LOGO
        ========================= */

        .login-logo{
            text-align:center;
            margin-bottom:12px;
        }

        .logo-wrap{
            width:65px;
            height:65px;
            margin:0 auto 8px;
            background:#fff;
            border-radius:8px;
            display:flex;
            align-items:center;
            justify-content:center;
            overflow:hidden;
            box-shadow:0 4px 12px rgba(0,0,0,.08);
        }

        .logo-wrap img{
            width:100%;
            height:100%;
            object-fit:contain;
            padding:6px;
        }

        .logo-initial{
            font-size:1.8rem;
            font-weight:900;
            color:var(--orange);
        }

        .login-logo h1{
            margin:0;
            font-size:2rem;
            font-weight:900;
            color:#111827;
        }

        .login-logo h1 span{
            color:var(--orange);
        }

        .login-logo p{
            margin:4px 0 0;
            color:#64748b;
            font-size:.95rem;
        }

        /* =========================
        TARJETA
        ========================= */

        .card{
            border:none;
            border-radius:6px;
            overflow:hidden;
            box-shadow:0 8px 24px rgba(0,0,0,.08);
        }

        .card-header-custom{
            background:linear-gradient(
                135deg,
                var(--orange),
                var(--orange-dark)
            );
            color:#fff;
            text-align:center;
            padding:12px;
        }

        .card-header-custom h3{
            margin:0;
            font-size:1.25rem;
            font-weight:800;
        }

        .card-header-custom small{
            display:block;
            margin-top:2px;
            color:#ffe5cc;
        }

        .card-body{
            padding:22px 28px;
        }

        /* =========================
        LABELS
        ========================= */

        .form-label-custom{
            display:block;
            margin-bottom:5px;
            color:#1e293b;
            font-size:.9rem;
            font-weight:700;
        }

        /* =========================
        INPUTS
        ========================= */

        .input-custom{
            position:relative;
            margin-bottom:12px;
        }

        .input-custom input{
            width:100%;
            height:44px;
            border:1px solid var(--border);
            border-radius:6px;
            padding:0 42px 0 14px;
            font-size:15px;
            outline:none;
            transition:.2s;
        }

        .input-custom input:focus{
            border-color:var(--orange);
            box-shadow:0 0 0 3px rgba(255,123,0,.12);
        }

        .input-custom i{
            position:absolute;
            right:14px;
            top:50%;
            transform:translateY(-50%);
            color:var(--orange);
        }

        /* =========================
        PASSWORD HELP
        ========================= */

        .password-help{
            margin-top:-4px;
            margin-bottom:12px;
            font-size:.82rem;
            color:#94a3b8;
        }

        /* =========================
        ROL
        ========================= */

        .role-reference-wrap{
            position:relative;
            margin-bottom:16px;
        }

        .role-reference{
            height:44px;
            border:1px solid #fed7aa;
            border-radius:6px;
            background:#fff8f2;
            color:var(--orange);
            font-weight:700;
            display:flex;
            align-items:center;
            padding:0 14px;
        }

        .role-reference-wrap i{
            position:absolute;
            right:14px;
            top:50%;
            transform:translateY(-50%);
            color:var(--orange);
        }

        /* =========================
        BOTON
        ========================= */

        .btn-orange{
            width:100%;
            height:46px;
            border:none;
            border-radius:6px;
            background:linear-gradient(
                135deg,
                var(--orange),
                var(--orange-dark)
            );
            color:#fff;
            font-size:15px;
            font-weight:800;
            box-shadow:0 6px 16px rgba(255,123,0,.20);
            transition:.2s;
        }

        .btn-orange:hover{
            transform:translateY(-1px);
            color:#fff;
        }

        /* =========================
        LOGIN LINK
        ========================= */

        .login-link{
            text-align:center;
            margin-top:16px;
            color:#64748b;
        }

        .login-link a{
            color:var(--orange);
            font-weight:800;
            text-decoration:none;
        }

        /* =========================
        RESPONSIVE
        ========================= */

        @media(max-width:768px){

            .register-box{
                max-width:100%;
            }

            .card-body{
                padding:20px;
            }
        }

        @media(max-width:480px){

            body{
                padding:12px;
                align-items:flex-start;
            }

            .login-logo h1{
                font-size:1.6rem;
            }

            .logo-wrap{
                width:58px;
                height:58px;
            }

            .card-body{
                padding:18px;
            }

            .input-custom input,
            .role-reference,
            .btn-orange{
                height:42px;
            }
        }
    </style>
</head>

<body>

<div class="register-box">

    <div class="login-logo">
        <div class="logo-wrap">
            <?php if (!empty($logo_tienda) && file_exists($logo_tienda)): ?>
                <img src="<?= htmlspecialchars($logo_tienda) ?>?v=<?= time() ?>" alt="<?= htmlspecialchars($nombre_tienda) ?>">
            <?php else: ?>
                <span class="logo-initial">
                    <?= htmlspecialchars(strtoupper(substr($nombre_tienda, 0, 1))) ?>
                </span>
            <?php endif; ?>
        </div>

        <h1><span>Crear</span> cuenta</h1>
        <p><?= htmlspecialchars($nombre_tienda) ?></p>
    </div>

    <div class="card">
        <div class="card-header-custom">
            <h3>
                <i class="fas fa-user-plus mr-1"></i>
                Registro
            </h3>
            <small>Completa tus datos para continuar</small>
        </div>

        <div class="card-body">
            <form method="POST">

                <label class="form-label-custom">Nombre completo</label>
                <div class="input-custom">
                    <input type="text" name="nombre" placeholder="Nombre completo" required autocomplete="name">
                    <i class="fas fa-user"></i>
                </div>

                <label class="form-label-custom">Correo electrónico</label>
                <div class="input-custom">
                    <input type="email" name="email" placeholder="correo@ejemplo.com" required autocomplete="email">
                    <i class="fas fa-envelope"></i>
                </div>

                <label class="form-label-custom">Contraseña</label>
                <div class="input-custom">
                    <input type="password" name="password" placeholder="Mínimo 8 caracteres" minlength="8" required autocomplete="new-password">
                    <i class="fas fa-lock"></i>
                </div>

                <div class="password-help">
                    <i class="fas fa-info-circle"></i>
                    La contraseña debe tener al menos 8 caracteres.
                </div>

                <label class="form-label-custom">Tipo de cuenta</label>
                <div class="role-reference-wrap">
                    <div class="role-reference">Vendedor</div>
                    <i class="fas fa-user-tag"></i>
                </div>

                <input type="hidden" name="rol" value="vendedor">

                <button type="submit" name="register" class="btn-orange">
                    <i class="fas fa-check-circle mr-1"></i>
                    Crear cuenta
                </button>
            </form>

            <p class="login-link">
                ¿Ya tienes cuenta?
                <a href="login.php">Inicia sesión</a>
            </p>
        </div>
    </div>
</div>

<?php if (!empty($alerta)): ?>
<script>
Swal.fire({
    icon: '<?= $alerta['icon'] ?>',
    title: '<?= addslashes($alerta['title']) ?>',
    text: '<?= addslashes($alerta['text']) ?>',
    confirmButtonText: 'Aceptar',
    confirmButtonColor: '#ff7b00'
});
</script>
<?php endif; ?>

</body>
</html>