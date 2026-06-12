<?php
session_start();

include 'includes/db.php';
include 'includes/csrf.php';
include('includes/header.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'includes/PHPMailer/src/Exception.php';
require 'includes/PHPMailer/src/PHPMailer.php';
require 'includes/PHPMailer/src/SMTP.php';

// ======================================
// LIMPIEZA AUTOMÁTICA DE TOKENS VENCIDOS
// ======================================
$conn->query("DELETE FROM password_resets WHERE expires_at <= NOW()");

// ===============================
// CONFIGURACIÓN DE CORREO
// ===============================
$configCorreo = null;

$stmtCorreo = $conn->prepare("
    SELECT smtp_host, smtp_port, smtp_usuario, smtp_password, smtp_secure,
           correo_origen, nombre_origen
    FROM configuracion_correo
    WHERE activo = 1
    ORDER BY id ASC
    LIMIT 1
");

if ($stmtCorreo) {
    $stmtCorreo->execute();
    $resCorreo = $stmtCorreo->get_result();

    if ($resCorreo && $resCorreo->num_rows > 0) {
        $configCorreo = $resCorreo->fetch_assoc();
    }

    $stmtCorreo->close();
}

// ===============================
// RATE LIMIT
// ===============================
if (!isset($_SESSION['recover_attempts'])) {
    $_SESSION['recover_attempts'] = 0;
    $_SESSION['recover_time'] = time();
}

if (time() - $_SESSION['recover_time'] < 60 && $_SESSION['recover_attempts'] >= 3) {
    $_SESSION['swal'] = [
        'icon' => 'error',
        'title' => 'Demasiados intentos',
        'html' => 'Espera un minuto antes de intentarlo de nuevo.'
    ];

    header('Location: forgot_password.php');
    exit;
}

if (time() - $_SESSION['recover_time'] > 60) {
    $_SESSION['recover_attempts'] = 0;
    $_SESSION['recover_time'] = time();
}

// ===============================
// PROCESAR FORMULARIO
// ===============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $_SESSION['recover_attempts']++;

    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['swal'] = [
            'icon' => 'warning',
            'title' => 'Correo inválido',
            'html' => 'Ingresa un correo electrónico válido.'
        ];

        header('Location: forgot_password.php');
        exit;
    }

    if (!$configCorreo) {
        $_SESSION['swal'] = [
            'icon' => 'error',
            'title' => 'Correo no configurado',
            'html' => 'No hay una configuración de correo activa. Contacta al administrador.'
        ];

        header('Location: forgot_password.php');
        exit;
    }

    // Validar usuario
    $stmt = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
    if (!$stmt) {
        die("Error SQL: " . $conn->error);
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        $_SESSION['swal'] = [
            'icon'  => 'error',
            'title' => 'Correo no registrado',
            'html'  => 'El correo ingresado no se encuentra en nuestro sistema.'
        ];

        header('Location: forgot_password.php');
        exit;
    }

    $stmt->close();

    // Eliminar tokens anteriores
    $stmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();

    // Crear token
    $token = bin2hex(random_bytes(32));

    $stmt = $conn->prepare("
        INSERT INTO password_resets (email, token, expires_at)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
    ");

    if (!$stmt) {
        die("Error SQL: " . $conn->error);
    }

    $stmt->bind_param("ss", $email, $token);
    $stmt->execute();
    $stmt->close();

    // URL dinámica del sistema
    $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $carpeta = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

    $resetLink = $protocolo . '://' . $host . $carpeta . '/reset_password.php?token=' . urlencode($token);

    // Enviar correo
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $configCorreo['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $configCorreo['smtp_usuario'];
        $mail->Password   = $configCorreo['smtp_password'];
        $mail->Port       = (int)$configCorreo['smtp_port'];
        $mail->CharSet    = 'UTF-8';

        if ($configCorreo['smtp_secure'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];

        $nombreOrigen = $configCorreo['nombre_origen'] ?: 'Tienda Pescadores';

        $mail->setFrom($configCorreo['correo_origen'], $nombreOrigen);
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Restablecer tu contraseña';

        $mail->Body = '
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>Recuperar contraseña</title>
        </head>
        <body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td align="center" style="padding:35px 15px;">
                        <table width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 12px 32px rgba(15,23,42,.12);">
                            <tr>
                                <td style="background:linear-gradient(135deg,#f97316,#ea580c);padding:30px;text-align:center;">
                                    <h1 style="color:#ffffff;margin:0;font-size:24px;font-weight:800;">
                                        '.$nombreOrigen.'
                                    </h1>
                                    <p style="color:#ffedd5;margin:8px 0 0;font-size:14px;">
                                        Recuperación de contraseña
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <td style="padding:34px;">
                                    <h2 style="color:#111827;margin:0 0 14px;font-size:22px;">
                                        Restablecer contraseña
                                    </h2>

                                    <p style="color:#4b5563;font-size:15px;line-height:1.7;margin:0;">
                                        Hemos recibido una solicitud para restablecer tu contraseña.
                                        Haz clic en el botón de abajo para continuar.
                                    </p>

                                    <div style="text-align:center;margin:34px 0;">
                                        <a href="'.$resetLink.'" style="background:linear-gradient(135deg,#f97316,#ea580c);color:#ffffff;padding:14px 28px;text-decoration:none;font-size:16px;border-radius:999px;display:inline-block;font-weight:bold;">
                                            Restablecer contraseña
                                        </a>
                                    </div>

                                    <p style="color:#6b7280;font-size:14px;margin:0;">
                                        Este enlace es válido por <strong>15 minutos</strong>.
                                    </p>

                                    <hr style="border:none;border-top:1px solid #eeeeee;margin:28px 0;">

                                    <p style="color:#9ca3af;font-size:12px;line-height:1.6;margin:0;">
                                        Si el botón no funciona, copia y pega este enlace:<br>
                                        <a href="'.$resetLink.'" style="color:#f97316;word-break:break-all;">
                                            '.$resetLink.'
                                        </a>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <td style="background:#fff7ed;padding:16px;text-align:center;">
                                    <small style="color:#9a3412;">
                                        © '.date('Y').' '.$nombreOrigen.' · Todos los derechos reservados
                                    </small>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>';

        $mail->send();

        $_SESSION['swal'] = [
            'icon'  => 'success',
            'title' => 'Correo enviado',
            'html'  => 'Revisa tu bandeja de entrada para continuar.'
        ];

    } catch (Exception $e) {
        $_SESSION['swal'] = [
            'icon'  => 'error',
            'title' => 'Error',
            'html'  => 'No se pudo enviar el correo. Intenta más tarde.'
        ];
    }

    header('Location: forgot_password.php');
    exit;
}
?>

<style>
body {
    min-height: 100vh;
    background:
        radial-gradient(circle at top left, rgba(249,115,22,.12), transparent 30%),
        linear-gradient(135deg, #fff7ed 0%, #f8fafc 45%, #ffffff 100%);
}

.recover-wrapper {
    min-height: calc(100vh - 80px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 42px 16px;
}

.recover-card {
    width: 100%;
    max-width: 520px;
    background: #fff;
    border-radius: 24px;
    box-shadow: 0 18px 45px rgba(15, 23, 42, .12);
    overflow: hidden;
    border: 1px solid #eef2f7;
}

.recover-header {
    background: linear-gradient(135deg, #f97316, #ea580c);
    padding: 34px 24px;
    text-align: center;
    color: #fff;
}

.recover-header .icon {
    width: 72px;
    height: 72px;
    margin: 0 auto 14px;
    border-radius: 22px;
    background: rgba(255,255,255,.18);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
}

.recover-header h3 {
    margin: 0;
    font-size: 1.55rem;
    font-weight: 800;
}

.recover-body {
    padding: 34px;
}

.recover-body p {
    color: #64748b;
    text-align: center;
    margin-bottom: 26px;
}

.form-label-custom {
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 8px;
    font-size: .9rem;
}

.input-recover {
    position: relative;
}

.input-recover i {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #f97316;
}

.input-recover input {
    width: 100%;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 15px 16px 15px 46px;
    font-size: 1rem;
    outline: none;
    transition: all .2s ease;
}

.input-recover input:focus {
    border-color: #f97316;
    box-shadow: 0 0 0 4px rgba(249,115,22,.14);
}

.btn-orange {
    width: 100%;
    margin-top: 22px;
    border: none;
    border-radius: 16px;
    padding: 15px;
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: #fff;
    font-weight: 800;
    box-shadow: 0 12px 26px rgba(249,115,22,.28);
    transition: all .2s ease;
}

.btn-orange:hover {
    color: #fff;
    transform: translateY(-1px);
}

.btn-orange:disabled {
    opacity: .75;
    cursor: not-allowed;
    transform: none;
}

.back-login {
    text-align: center;
    margin-top: 24px;
}

.back-login a {
    color: #64748b;
    font-weight: 600;
    text-decoration: none;
}

.back-login a:hover {
    color: #f97316;
}

@media (max-width: 576px) {
    .recover-wrapper {
        padding: 22px 14px;
        align-items: flex-start;
    }

    .recover-card {
        border-radius: 20px;
    }

    .recover-header {
        padding: 28px 18px;
    }

    .recover-header .icon {
        width: 62px;
        height: 62px;
        font-size: 26px;
    }

    .recover-header h3 {
        font-size: 1.35rem;
    }

    .recover-body {
        padding: 26px 20px;
    }
}
</style>

<div class="recover-wrapper">
    <div class="recover-card">

        <div class="recover-header">
            <div class="icon">
                <i class="fas fa-unlock-alt"></i>
            </div>
            <h3>Recuperar contraseña</h3>
        </div>

        <div class="recover-body">
            <p>
                Ingresa tu correo y te enviaremos un enlace para restablecer tu contraseña.
            </p>

            <form method="POST" id="recoverForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <label class="form-label-custom">Correo electrónico</label>

                <div class="input-recover">
                    <i class="fas fa-envelope"></i>
                    <input
                        type="email"
                        name="email"
                        placeholder="ejemplo@correo.com"
                        required
                        autocomplete="email"
                    >
                </div>

                <button type="submit" class="btn-orange">
                    <i class="fas fa-paper-plane mr-1"></i>
                    Enviar enlace
                </button>
            </form>

            <div class="back-login">
                <a href="login.php">
                    <i class="fas fa-arrow-left"></i>
                    Volver al inicio de sesión
                </a>
            </div>
        </div>

    </div>
</div>

<script src="adminlte/plugins/sweetalert2/sweetalert2.all.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('recoverForm');

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            const btn = form.querySelector('button[type="submit"]');

            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Enviando...';
            }

            Swal.fire({
                title: 'Enviando enlace...',
                text: 'Por favor espera',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            setTimeout(() => {
                form.submit();
            }, 500);
        });
    }
});
</script>

<?php if (!empty($_SESSION['swal'])): ?>
<script>
Swal.fire({
    icon: '<?= $_SESSION['swal']['icon'] ?>',
    title: '<?= $_SESSION['swal']['title'] ?>',
    html: '<?= $_SESSION['swal']['html'] ?>',
    confirmButtonText: 'Aceptar',
    confirmButtonColor: '#f97316'
});
</script>
<?php unset($_SESSION['swal']); endif; ?>