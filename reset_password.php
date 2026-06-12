<?php
session_start();

include 'includes/db.php';
include 'includes/csrf.php';
include 'includes/header.php';

$token = $_GET['token'] ?? '';

$stmt = $conn->prepare("
    SELECT email 
    FROM password_resets
    WHERE token = ? 
    AND expires_at > NOW()
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows !== 1) {
?>
<style>
body {
    min-height: 100vh;
    background: linear-gradient(135deg, #fff7ed, #f8fafc);
}

.reset-wrapper {
    min-height: calc(100vh - 80px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 16px;
}

.reset-card {
    width: 100%;
    max-width: 520px;
    background: #fff;
    border-radius: 24px;
    box-shadow: 0 18px 45px rgba(15, 23, 42, .12);
    overflow: hidden;
    border: 1px solid #eef2f7;
}

.reset-header-danger {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    padding: 34px 24px;
    text-align: center;
    color: #fff;
}

.reset-icon {
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

.reset-body {
    padding: 34px;
    text-align: center;
}

.btn-danger-soft {
    width: 100%;
    border-radius: 16px;
    padding: 14px;
    font-weight: 800;
}
</style>

<div class="reset-wrapper">
    <div class="reset-card">
        <div class="reset-header-danger">
            <div class="reset-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 class="mb-0 font-weight-bold">Enlace inválido</h3>
        </div>

        <div class="reset-body">
            <p class="text-muted mb-4">
                Este enlace ya fue utilizado o ha expirado.
            </p>

            <div class="my-4">
                <i class="fas fa-unlink fa-4x text-danger"></i>
            </div>

            <a href="forgot_password.php" class="btn btn-outline-danger btn-lg btn-danger-soft mb-3">
                <i class="fas fa-redo mr-1"></i> Solicitar nuevo enlace
            </a>

            <a href="login.php" class="text-muted d-block">
                <i class="fas fa-arrow-left mr-1"></i> Volver al inicio
            </a>
        </div>
    </div>
</div>
<?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $passwordPlain = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if ($passwordPlain !== $passwordConfirm) {
        $_SESSION['swal'] = [
            'icon' => 'error',
            'title' => 'Error',
            'text' => 'Las contraseñas no coinciden.'
        ];
    } elseif (strlen($passwordPlain) < 8) {
        $_SESSION['swal'] = [
            'icon' => 'warning',
            'title' => 'Contraseña muy corta',
            'text' => 'La contraseña debe contener al menos 8 caracteres.'
        ];
    } else {

        $stmt = $conn->prepare("
            SELECT email 
            FROM password_resets
            WHERE token = ? 
            AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $check = $stmt->get_result();

        if ($check->num_rows !== 1) {
            $_SESSION['swal'] = [
                'icon' => 'error',
                'title' => 'Enlace expirado',
                'text' => 'Este enlace ya no es válido. Solicita uno nuevo.'
            ];

            header('Location: forgot_password.php');
            exit;
        }

        $email = $check->fetch_assoc()['email'];

        $password = password_hash($passwordPlain, PASSWORD_BCRYPT);

        $stmt = $conn->prepare("UPDATE usuarios SET password = ? WHERE email = ?");
        $stmt->bind_param("ss", $password, $email);
        $stmt->execute();

        $stmt = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();

        $_SESSION['swal'] = [
            'icon'  => 'success',
            'title' => 'Contraseña restablecida',
            'text'  => 'Ya puedes iniciar sesión con tu nueva contraseña.'
        ];

        header('Location: login.php');
        exit;
    }
}
?>

<style>
body {
    min-height: 100vh;
    background:
        radial-gradient(circle at top left, rgba(249,115,22,.12), transparent 30%),
        linear-gradient(135deg, #fff7ed 0%, #f8fafc 45%, #ffffff 100%);
}

.reset-wrapper {
    min-height: calc(100vh - 80px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 42px 16px;
}

.reset-card {
    width: 100%;
    max-width: 540px;
    background: #fff;
    border-radius: 24px;
    box-shadow: 0 18px 45px rgba(15, 23, 42, .12);
    overflow: hidden;
    border: 1px solid #eef2f7;
}

.reset-header {
    background: linear-gradient(135deg, #f97316, #ea580c);
    padding: 34px 24px;
    text-align: center;
    color: #fff;
}

.reset-header .icon {
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

.reset-header h3 {
    margin: 0;
    font-size: 1.55rem;
    font-weight: 800;
}

.reset-body {
    padding: 34px;
}

.reset-body p {
    color: #64748b;
    text-align: center;
    margin-bottom: 8px;
}

.reset-body small {
    display: block;
    text-align: center;
    color: #94a3b8;
    margin-bottom: 26px;
}

.form-label-custom {
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 8px;
    font-size: .9rem;
}

.input-reset {
    position: relative;
}

.input-reset input {
    width: 100%;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 15px 54px 15px 16px;
    font-size: 1rem;
    outline: none;
    transition: all .2s ease;
}

.input-reset input:focus {
    border-color: #f97316;
    box-shadow: 0 0 0 4px rgba(249,115,22,.14);
}

.toggle-pass {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    border: none;
    background: #fff7ed;
    color: #f97316;
    width: 38px;
    height: 38px;
    border-radius: 12px;
    cursor: pointer;
}

.password-strength .progress {
    height: 8px;
    border-radius: 999px;
    overflow: hidden;
    background: #eef2f7;
}

.password-strength li {
    margin-bottom: 5px;
    transition: .2s;
}

.password-strength li.valid {
    color: #16a34a !important;
}

#passHelp {
    display: block;
    margin-top: 8px;
    font-weight: 600;
}

.btn-reset {
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

.btn-reset:hover {
    color: #fff;
    transform: translateY(-1px);
}

.btn-reset:disabled {
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
    .reset-wrapper {
        padding: 22px 14px;
        align-items: flex-start;
    }

    .reset-card {
        border-radius: 20px;
    }

    .reset-header {
        padding: 28px 18px;
    }

    .reset-header .icon {
        width: 62px;
        height: 62px;
        font-size: 26px;
    }

    .reset-header h3 {
        font-size: 1.35rem;
    }

    .reset-body {
        padding: 26px 20px;
    }
}
</style>

<div class="reset-wrapper">
    <div class="reset-card">

        <div class="reset-header">
            <div class="icon">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h3>Nueva contraseña</h3>
        </div>

        <div class="reset-body">

            <p>Crea una nueva contraseña para proteger tu cuenta.</p>
            <small>Utiliza una contraseña de al menos 8 caracteres.</small>

            <form method="POST" id="resetForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <div class="form-group mb-4">
                    <label class="form-label-custom">Nueva contraseña</label>

                    <div class="input-reset">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >

                        <button class="toggle-pass" type="button">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>

                    <div class="password-strength mt-3">
                        <div class="progress">
                            <div id="strengthBar" class="progress-bar bg-danger" style="width:0%"></div>
                        </div>

                        <small id="strengthText" class="text-muted d-block mt-2 mb-2">
                            Fortaleza de contraseña
                        </small>

                        <ul class="list-unstyled mt-2 mb-0 small">
                            <li id="rule-length" class="text-danger">
                                <i class="fas fa-times-circle"></i>
                                Mínimo 8 caracteres
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="form-group mb-4">
                    <label class="form-label-custom">Confirmar contraseña</label>

                    <div class="input-reset">
                        <input
                            type="password"
                            id="password_confirm"
                            name="password_confirm"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >

                        <button class="toggle-pass" type="button">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>

                    <small id="passHelp" class="d-none"></small>
                </div>

                <button type="submit" class="btn-reset">
                    <i class="fas fa-check-circle mr-1"></i>
                    Guardar contraseña
                </button>
            </form>

            <div class="back-login">
                <a href="login.php">
                    <i class="fas fa-arrow-left mr-1"></i>
                    Volver al inicio
                </a>
            </div>

        </div>
    </div>
</div>

<script src="adminlte/plugins/sweetalert2/sweetalert2.all.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {

    const form = document.getElementById('resetForm');
    const pass1 = document.getElementById('password');
    const pass2 = document.getElementById('password_confirm');
    const help = document.getElementById('passHelp');

    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    const ruleLength = document.getElementById('rule-length');

    if (!form) return;

    document.querySelectorAll('.toggle-pass').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = btn.closest('.input-reset').querySelector('input');
            const icon = btn.querySelector('i');

            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';

            icon.classList.toggle('fa-eye', !isPassword);
            icon.classList.toggle('fa-eye-slash', isPassword);
        });
    });

    function actualizarRegla(elemento, valido) {
        const icon = elemento.querySelector('i');

        if (valido) {
            elemento.classList.add('valid');
            elemento.classList.remove('text-danger');
            icon.classList.remove('fa-times-circle');
            icon.classList.add('fa-check-circle');
        } else {
            elemento.classList.remove('valid');
            elemento.classList.add('text-danger');
            icon.classList.remove('fa-check-circle');
            icon.classList.add('fa-times-circle');
        }
    }

    function validarFortaleza(password) {
        const length = password.length >= 8;

        actualizarRegla(ruleLength, length);

        if (password.length === 0) {
            strengthBar.style.width = '0%';
            strengthBar.className = 'progress-bar bg-danger';
            strengthText.textContent = 'Fortaleza de contraseña';
            return false;
        }

        if (password.length < 4) {
            strengthBar.style.width = '25%';
            strengthBar.className = 'progress-bar bg-danger';
            strengthText.textContent = 'Contraseña débil';
        } else if (password.length < 8) {
            strengthBar.style.width = '60%';
            strengthBar.className = 'progress-bar bg-warning';
            strengthText.textContent = 'Contraseña regular';
        } else if (password.length < 12) {
            strengthBar.style.width = '85%';
            strengthBar.className = 'progress-bar bg-info';
            strengthText.textContent = 'Contraseña buena';
        } else {
            strengthBar.style.width = '100%';
            strengthBar.className = 'progress-bar bg-success';
            strengthText.textContent = 'Contraseña muy segura';
        }

        return length;
    }

    function validarPasswords() {
        if (pass2.value.length === 0) {
            help.className = 'd-none';
            pass2.classList.remove('is-valid', 'is-invalid');
            return false;
        }

        if (pass1.value === pass2.value) {
            help.textContent = 'Las contraseñas coinciden';
            help.className = 'text-success';
            pass2.classList.remove('is-invalid');
            pass2.classList.add('is-valid');
            return true;
        }

        help.textContent = 'Las contraseñas no coinciden';
        help.className = 'text-danger';
        pass2.classList.remove('is-valid');
        pass2.classList.add('is-invalid');
        return false;
    }

    pass1.addEventListener('input', () => {
        validarFortaleza(pass1.value);
        validarPasswords();
    });

    pass2.addEventListener('input', validarPasswords);

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const passwordValida = validarFortaleza(pass1.value);
        const coincide = validarPasswords();

        if (!passwordValida) {
            Swal.fire({
                icon: 'warning',
                title: 'Contraseña muy corta',
                text: 'La contraseña debe contener al menos 8 caracteres.',
                confirmButtonColor: '#f97316'
            });
            return;
        }

        if (!coincide) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Las contraseñas no coinciden.',
                confirmButtonColor: '#f97316'
            });
            return;
        }

        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Guardando...';
        }

        Swal.fire({
            title: 'Actualizando contraseña...',
            text: 'Por favor espera',
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => Swal.showLoading()
        });

        setTimeout(() => form.submit(), 400);
    });
});
</script>

<?php if (!empty($_SESSION['swal'])): ?>
<script>
Swal.fire({
    icon: '<?= $_SESSION['swal']['icon'] ?>',
    title: '<?= $_SESSION['swal']['title'] ?>',
    text: '<?= $_SESSION['swal']['text'] ?? '' ?>',
    confirmButtonText: 'Aceptar',
    confirmButtonColor: '#f97316'
});
</script>
<?php unset($_SESSION['swal']); endif; ?>