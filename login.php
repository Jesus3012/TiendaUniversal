<?php
include('includes/db.php');
include('includes/header.php');
include('includes/session.php');
require_once('includes/csrf.php');

$max_attempts = 5;
$lock_time = 60; // 1 minuto

// Verificar si viene de una sesión expirada
$expired_message = '';
if (isset($_GET['expired']) && $_GET['expired'] == 1) {
    $expired_message = "
    Swal.fire({
        icon: 'info',
        title: 'Sesión expirada',
        text: 'Tu sesión ha expirado por inactividad. Por favor, inicia sesión nuevamente.',
        confirmButtonColor: '#f97316',
        timer: 3000,
        timerProgressBar: true
    });
    ";
}

// Inicializar contador en sesión
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_last_attempt'] = 0;
}

$swal = "";

// Función para recordar usuario (versión simplificada sin BD)
function rememberUser($email) {
    $expiry = time() + (86400 * 30); // 30 días
    
    // Crear token seguro
    $token = base64_encode(random_bytes(32));
    $token_hash = hash('sha256', $token);
    
    setcookie('remember_email', $email, $expiry, '/', '', true, true);
    setcookie('remember_token', $token, $expiry, '/', '', true, true);
    setcookie('remember_hash', $token_hash, $expiry, '/', '', true, true);
}

// Función para verificar cookie de recordar
function checkRememberMe() {
    if (isset($_COOKIE['remember_email']) && isset($_COOKIE['remember_token']) && isset($_COOKIE['remember_hash'])) {
        $email = $_COOKIE['remember_email'];
        $token = $_COOKIE['remember_token'];
        $stored_hash = $_COOKIE['remember_hash'];
        
        // Verificar que el token coincide con el hash
        if (hash('sha256', $token) === $stored_hash) {
            return $email;
        }
    }
    return false;
}

// Verificar cookie de recordar
$remembered_email = checkRememberMe();
if ($remembered_email && !isset($_SESSION['usuario_id'])) {
    // Auto-login con el email recordado (solo precargar, no auto-login por seguridad)
    // No hacemos auto-login por seguridad, solo precargamos el email
}

// PROCESAR LOGIN
if (isset($_POST['login'])) {
    csrf_check();

    // Bloqueo temporal
    if ($_SESSION['login_attempts'] >= $max_attempts && (time() - $_SESSION['login_last_attempt']) < $lock_time) {
        $wait = $lock_time - (time() - $_SESSION['login_last_attempt']);
        $swal = "
        Swal.fire({
            icon: 'error',
            title: 'Demasiados intentos',
            text: 'Intenta de nuevo en {$wait} segundos',
            confirmButtonColor: '#f97316'
        });
        ";
    } else {

        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $remember = isset($_POST['remember']) ? true : false;

        $stmt = $conn->prepare("SELECT id, nombre, password, rol, activo, debe_cambiar_password FROM usuarios WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            // Cambiar el nombre de la variable para evitar conflicto
            $stmt->bind_result($id, $nombre, $hashed_password, $rol, $activo, $debe_cambiar_password);
            $stmt->fetch();

            if (!$activo) {
                $swal = "
                Swal.fire({
                    icon: 'warning',
                    title: 'Cuenta inactiva',
                    text: 'Tu cuenta aún no ha sido activada por un administrador',
                    confirmButtonColor: '#f97316'
                });
                ";
            } elseif (password_verify($password, $hashed_password)) {

                // Login exitoso
                $_SESSION['login_attempts'] = 0;
                session_regenerate_id(true);

                $_SESSION['usuario_id'] = $id;
                $_SESSION['nombre'] = $nombre;
                $_SESSION['rol'] = $rol;
                $_SESSION['last_activity'] = time();
                
                $_SESSION['debe_cambiar_password'] = $debe_cambiar_password;

                // Guardar cookie "Recordarme"
                if ($remember) {
                    rememberUser($email);
                } else {
                    // Eliminar cookies si existen
                    setcookie('remember_email', '', time() - 3600, '/');
                    setcookie('remember_token', '', time() - 3600, '/');
                    setcookie('remember_hash', '', time() - 3600, '/');
                }

                $dashboard = ($rol === 'administrador') ? 'dashboard_admin.php' : 'dashboard_vendedor.php';

                if ($debe_cambiar_password == 1) {
                    $mensaje = '<br><small class="text-warning">Por seguridad, deberás cambiar tu contraseña</small>';
                } else {
                    $mensaje = '<br><small>Accediendo al sistema...</small>';
                }

                echo "<script>
                    Swal.fire({
                        title: '¡Bienvenido!',
                        html: '<b>{$nombre}</b>{$mensaje}',
                        icon: 'success',
                        timer: 2000,
                        timerProgressBar: true,
                        allowOutsideClick: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    }).then(() => {
                        window.location.href = '{$dashboard}';
                    });
                </script>";
                exit;

            } else {
                $swal = "
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Contraseña incorrecta',
                    confirmButtonColor: '#d33'
                });
                ";
            }
        } else {
            $swal = "
            Swal.fire({
                icon: 'warning',
                title: 'Correo no registrado',
                text: 'Verifica tu correo electrónico',
                confirmButtonColor: '#f0ad4e'
            });
            ";
        }

        $stmt->close();

        $_SESSION['login_attempts'] += 1;
        $_SESSION['login_last_attempt'] = time();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Iniciar Sesión | Pescadores de la Prehistoria</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- SweetAlert -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* Fondo decorativo con olas */
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.08)" fill-opacity="1" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,122.7C672,117,768,139,864,154.7C960,171,1056,181,1152,165.3C1248,149,1344,107,1392,85.3L1440,64L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
            background-size: cover;
            pointer-events: none;
        }

        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            position: relative;
            z-index: 1;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            width: 100%;
            max-width: 480px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .login-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.2);
        }

        /* Header sin fondo naranja */
        .card-header {
            background: white;
            padding: 2rem;
            text-align: center;
            position: relative;
            border-bottom: 1px solid #f0e6e0;
        }

        .logo-wrapper {
            margin-bottom: 1rem;
        }

        /* Logo con estilo */
        .logo-img {
            width: 100px;
            height: 100px;
            object-fit: contain;
            box-shadow: 0 5px 15px rgba(249, 115, 22, 0.2);
            transition: transform 0.3s ease;
            background-color: #f9f9f9;
            padding: 8px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .logo-img:hover {
            transform: scale(1.05);
        }

        .logo-img img {
            width: 70px;
            height: 70px;
            object-fit: contain;
        }

        /* Letras en naranja vibrante */
        .card-header h2 {
            background: linear-gradient(135deg, #f97316, #ea580c);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-size: 1.6rem;
            font-weight: 800;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .card-header p {
            color: #f97316;
            margin: 0.5rem 0 0;
            font-size: 0.95rem;
            font-weight: 500;
        }

        .card-body {
            padding: 2.5rem;
        }

        /* INPUTS MEJORADOS - MÁS SÓLIDOS Y VIBRANTES */
        .input-group-custom {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .input-group-custom i:first-child {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #f97316;
            font-size: 1.1rem;
            transition: color 0.3s ease;
            z-index: 1;
        }

        .input-group-custom input {
            width: 100%;
            padding: 0.9rem 1rem 0.9rem 2.8rem;
            border: 2px solid #f97316;
            border-radius: 16px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #fffaf5;
            font-family: 'Inter', sans-serif;
            color: #2d2d2d;
        }

        .input-group-custom input:focus {
            outline: none;
            border-color: #ea580c;
            background: white;
            box-shadow: 0 0 0 4px rgba(249, 115, 22, 0.15);
        }

        .input-group-custom input::placeholder {
            color: #fba870;
            font-weight: 400;
        }

        .input-group-custom .toggle-password {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #f97316;
            transition: color 0.3s ease;
            z-index: 1;
        }

        .input-group-custom .toggle-password:hover {
            color: #ea580c;
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            font-size: 0.9rem;
            color: #5c4b3a;
        }

        /* Checkbox con palomita BLANCA */
        .checkbox-label input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            background-color: white;
            border: 2px solid #f97316;
            border-radius: 5px;
            position: relative;
            transition: all 0.2s ease;
        }

        .checkbox-label input[type="checkbox"]:checked {
            background-color: #f97316;
            border-color: #f97316;
        }

        .checkbox-label input[type="checkbox"]:checked::after {
            content: '✓';
            position: absolute;
            color: white;
            font-size: 12px;
            font-weight: bold;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
        }

        .checkbox-label input[type="checkbox"]:hover {
            border-color: #ea580c;
        }

        .forgot-link {
            color: #f97316;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .forgot-link:hover {
            color: #ea580c;
            text-decoration: underline;
        }

        .btn-login {
            width: 100%;
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white;
            border: none;
            padding: 1rem;
            border-radius: 16px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(249, 115, 22, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login .btn-text {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-login .spinner-border {
            width: 1.2rem;
            height: 1.2rem;
            border-width: 0.15rem;
        }

        .register-section {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #f0e6e0;
        }

        .register-section p {
            color: #8a7a6a;
            font-size: 0.9rem;
            margin: 0;
        }

        .register-link {
            color: #f97316;
            text-decoration: none;
            font-weight: 700;
            transition: color 0.3s ease;
        }

        .register-link:hover {
            color: #ea580c;
            text-decoration: underline;
        }

        /* Animaciones */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-card {
            animation: fadeInUp 0.6s ease-out;
        }

        /* Responsive */
        @media (max-width: 576px) {
            .login-container {
                padding: 1rem;
            }
            
            .card-body {
                padding: 1.5rem;
            }
            
            .card-header {
                padding: 1.5rem;
            }
            
            .card-header h2 {
                font-size: 1.3rem;
            }
            
            .logo-img {
                width: 80px;
                height: 80px;
            }
            
            .logo-img img {
                width: 55px;
                height: 55px;
            }
        }

        /* Modo oscuro */
        @media (prefers-color-scheme: dark) {
            body {
                background: linear-gradient(135deg, #2a2a2a 0%, #1a1a1a 100%);
            }
            
            .login-card {
                background: rgba(35, 35, 45, 0.98);
            }
            
            .card-header {
                background: #252530;
                border-bottom-color: #353540;
            }
            
            .card-header h2 {
                background: linear-gradient(135deg, #f97316, #ea580c);
                -webkit-background-clip: text;
                background-clip: text;
            }
            
            .card-header p {
                color: #f97316;
            }
            
            .logo-img {
                background-color: #252530;
                box-shadow: 0 5px 15px rgba(249, 115, 22, 0.2);
            }
            
            .input-group-custom input {
                background: #353540;
                border-color: #f97316;
                color: white;
            }
            
            .input-group-custom input:focus {
                border-color: #ea580c;
            }
            
            .input-group-custom input::placeholder {
                color: #fba870;
            }
            
            .checkbox-label {
                color: #aaaacc;
            }
            
            .register-section {
                border-top-color: #353540;
            }
            
            .register-section p {
                color: #aaaacc;
            }
        }
    </style>
    
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#f97316">
    <meta name="apple-mobile-web-app-capable" content="yes">
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <div class="card-header">
                <div class="logo-wrapper">
                    <div class="logo-img">
                        <img src="img/logo_galeria_pescadores.png" alt="Pescadores de la Prehistoria Logo" onerror="this.src='img/logo.png'; this.onerror=null;">
                    </div>
                </div>
                <h2>Pescadores de la Prehistoria</h2>
                <p>Inicia sesión para continuar</p>
            </div>
            
            <div class="card-body">
                <form method="POST">
                    <div class="input-group-custom">
                        <i class="fas fa-envelope"></i>
                        <input type="email" 
                               name="email" 
                               placeholder="ejemplo@correo.com" 
                               value="<?php echo isset($_COOKIE['remember_email']) ? htmlspecialchars($_COOKIE['remember_email']) : ''; ?>"
                               required
                               autocomplete="email">
                    </div>
                    
                    <div class="input-group-custom">
                        <i class="fas fa-lock"></i>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               placeholder="Ingresa tu contraseña" 
                               required
                               autocomplete="current-password">
                        <i class="fas fa-eye toggle-password" id="togglePassword"></i>
                    </div>
                    
                    <input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">
                    
                    <div class="checkbox-wrapper">
                        <label class="checkbox-label">
                            <input type="checkbox" name="remember" <?php echo isset($_COOKIE['remember_email']) ? 'checked' : ''; ?>>
                            <span>Recordarme</span>
                        </label>
                        <a href="forgot_password.php" class="forgot-link">
                            <i class="fas fa-unlock-alt me-1"></i> ¿Olvidaste tu contraseña?
                        </a>
                    </div>
                    
                    <button type="submit" name="login" class="btn-login" id="loginBtn">
                        <span class="btn-text">
                            <i class="fas fa-sign-in-alt"></i> INICIAR SESIÓN
                        </span>
                        <span class="spinner-border spinner-border-sm d-none" id="loader" role="status" aria-hidden="true"></span>
                    </button>
                </form>
                
                <div class="register-section">
                    <p>¿No tienes cuenta? 
                        <a href="registrar.php" class="register-link">Regístrate aquí</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- SweetAlert Messages -->
    <?php if (!empty($expired_message)): ?>
    <script><?= $expired_message ?></script>
    <?php endif; ?>
    
    <?php if (!empty($swal)): ?>
    <script><?= $swal ?></script>
    <?php endif; ?>
    
    <script>
        // Toggle password visibility
        const togglePassword = document.getElementById('togglePassword');
        const password = document.getElementById('password');
        
        if (togglePassword && password) {
            togglePassword.addEventListener('click', function() {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                this.classList.toggle('fa-eye-slash');
            });
        }
        
        // Loader al enviar formulario
        const form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function() {
                const btnText = document.querySelector('.btn-text');
                const loader = document.getElementById('loader');
                
                if (btnText && loader) {
                    btnText.classList.add('d-none');
                    loader.classList.remove('d-none');
                }
            });
        }
        
        // Service Worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js').catch(err => {
                console.log('Service Worker registration failed:', err);
            });
        }
    </script>
    
    <?php if (!empty($_SESSION['swal'])): ?>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        Swal.fire({
            icon: '<?= $_SESSION['swal']['icon'] ?>',
            title: '<?= $_SESSION['swal']['title'] ?>',
            html: '<?= $_SESSION['swal']['html'] ?>',
            timer: <?= $_SESSION['swal']['timer'] ?? 'null' ?>,
            timerProgressBar: true,
            showConfirmButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => {
                Swal.showLoading();
            },
            didClose: () => {
                <?php if (!empty($_SESSION['swal']['redirect'])): ?>
                    window.location.href = "<?= $_SESSION['swal']['redirect'] ?>";
                <?php endif; ?>
            }
        });
    });
    </script>
    <?php unset($_SESSION['swal']); endif; ?>
</body>
</html>