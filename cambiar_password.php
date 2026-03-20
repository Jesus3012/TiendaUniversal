<?php
include 'includes/session.php';
include 'includes/header.php';
include 'includes/navbar.php';
?>

<style>
/* Mejoras sutiles manteniendo la estructura */
.card {
    border-radius: 15px !important;
    overflow: hidden;
}

.card-header {
    border-bottom: none;
    padding: 1.2rem 1.5rem;
}

.card-header h3 {
    font-size: 1.4rem;
    font-weight: 600;
}

.card-body {
    padding: 2rem;
}

.form-group {
    margin-bottom: 1.8rem;
}

.form-group label {
    font-weight: 500;
    color: #495057;
    margin-bottom: 0.5rem;
    font-size: 0.95rem;
}

.input-group {
    border: 1px solid #dee2e6;
    border-radius: 10px;
    overflow: hidden;
    transition: all 0.2s ease;
}

.input-group:focus-within {
    border-color: #f39c12;
    box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1);
}

.input-group .form-control {
    border: none;
    padding: 0.6rem 1rem;
}

.input-group .form-control:focus {
    box-shadow: none;
}

.input-group-append .btn {
    border: none;
    background: white;
    color: #6c757d;
    padding: 0.6rem 1rem;
    border-left: 1px solid #dee2e6;
    border-radius: 0;
}

.input-group-append .btn:hover {
    color: #f39c12;
    background: #f8f9fa;
}

/* Medidor de fortaleza */
.progress {
    border-radius: 20px;
    background-color: #e9ecef;
}

.progress-bar {
    transition: width 0.3s ease;
    border-radius: 20px;
}


/* Alertas */
.alert {
    border-radius: 10px;
    border-left-width: 4px !important;
    background: #f8f9fa;
    padding: 1rem 1.2rem;
}

.alert i {
    color: #f39c12;
}

/* Footer */
.card-footer {
    border-top: 1px solid #dee2e6;
    padding: 1.2rem 2rem;
}

.btn-warning {
    border: none;
    padding: 0.6rem 2rem;
    font-weight: 500;
    border-radius: 25px;
    transition: all 0.2s ease;
}

.btn-warning:hover {
    background: #e67e22;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(243, 156, 18, 0.3);
}

.btn-warning:active {
    transform: translateY(0);
}

/* Responsive */
@media (max-width: 768px) {
    .card-body {
        padding: 1.5rem;
    }
    
    .card-header h3 {
        font-size: 1.2rem;
    }
}
</style>

<div class="content-wrapper">
    <section class="content pt-4">
        <div class="container-fluid">

            <div class="row justify-content-center">
                <div class="col-xl-10 col-lg-9 col-md-10 col-sm-12">

                    <div class="card shadow-lg border-0">
                        
                        <div class="card-header bg-warning text-white">
                            <h3 class="card-title">
                                <i class="fas fa-shield-alt mr-2"></i>
                                Seguridad de la cuenta
                            </h3>
                        </div>

                        <form id="formPassword" method="POST">
                            <div class="card-body">

                                <div class="alert alert-light border-left border-warning mb-4">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-info-circle mr-2"></i>
                                        <small>Por tu seguridad, utiliza una contraseña fuerte y evita reutilizar contraseñas anteriores.</small>
                                    </div>
                                </div>

                                <!-- Contraseña actual -->
                                <div class="form-group">
                                    <label>
                                        <i class="fas fa-lock mr-1" style="color: #f39c12; font-size: 0.9rem;"></i>
                                        Contraseña actual
                                    </label>
                                    <div class="input-group">
                                        <input type="password" name="actual" id="actual" class="form-control" 
                                               placeholder="Ingresa tu contraseña actual" required>
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-outline-secondary toggle-pass" data-target="actual">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Nueva contraseña -->
                                <div class="form-group">
                                    <label>
                                        <i class="fas fa-key mr-1" style="color: #f39c12; font-size: 0.9rem;"></i>
                                        Nueva contraseña
                                    </label>
                                    <div class="input-group">
                                        <input type="password" name="nueva" id="nueva" class="form-control" 
                                               placeholder="Crea una nueva contraseña" required>
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-outline-secondary toggle-pass" data-target="nueva">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- MEDIDOR DE FORTALEZA -->
                                    <div class="progress mt-2" style="height: 6px;">
                                        <div id="strengthBar" class="progress-bar" style="width: 0%;"></div>
                                    </div>
                                    <small id="strengthText" class="form-text text-muted">
                                        <i class="fas fa-info-circle mr-1" style="font-size: 0.8rem;"></i>
                                        Ingresa una contraseña
                                    </small>
                                </div>

                                <!-- Confirmar contraseña -->
                                <div class="form-group">
                                    <label>
                                        <i class="fas fa-check-circle mr-1" style="color: #f39c12; font-size: 0.9rem;"></i>
                                        Confirmar nueva contraseña
                                    </label>
                                    <div class="input-group">
                                        <input type="password" name="confirmar" id="confirmar" class="form-control" 
                                               placeholder="Repite tu nueva contraseña" required>
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-outline-secondary toggle-pass" data-target="confirmar">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <small id="confirmFeedback" class="form-text" style="min-height: 24px;"></small>
                                </div>

                                <div class="alert alert-light border-left border-warning">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-sign-out-alt mr-2"></i>
                                        <small class="text-muted">
                                            Se cerrará tu sesión después de actualizar la contraseña por razones de seguridad.
                                        </small>
                                    </div>
                                </div>

                            </div>

                            <div class="card-footer bg-white text-right">
                                <button type="submit" class="btn btn-warning px-4" id="btnSubmit">
                                    <i class="fas fa-save mr-1"></i>
                                    Actualizar contraseña
                                </button>
                            </div>
                        </form>

                    </div>

                </div>
            </div>

        </div>
    </section>
</div>

<script>
// Elementos del DOM
const nueva = document.getElementById('nueva');
const confirmar = document.getElementById('confirmar');
const bar = document.getElementById('strengthBar');
const text = document.getElementById('strengthText');
const confirmFeedback = document.getElementById('confirmFeedback');

// Validar fortaleza de contraseña
nueva.addEventListener('input', () => {
    const val = nueva.value;
    let score = 0;
    
    // Criterios de validación
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    
    // Actualizar barra
    bar.className = 'progress-bar';
    
    const fortaleza = {
        0: { width: '10%', class: 'bg-danger', text: 'Muy débil' },
        1: { width: '25%', class: 'bg-danger', text: 'Débil' },
        2: { width: '50%', class: 'bg-warning', text: 'Media' },
        3: { width: '75%', class: 'bg-info', text: 'Buena' },
        4: { width: '100%', class: 'bg-success', text: '¡Fuerte!' }
    };
    
    const nivel = fortaleza[score] || fortaleza[0];
    
    bar.style.width = nivel.width;
    bar.classList.add(nivel.class);
    text.innerHTML = `<i class="fas fa-${score >= 3 ? 'check-circle' : 'info-circle'} mr-1" style="font-size: 0.8rem;"></i> ${nivel.text}`;
    
    // Validar confirmación si ya hay texto
    if (confirmar.value) {
        validarConfirmacion();
    }
});

// Validar que las contraseñas coincidan
function validarConfirmacion() {
    const nuevaVal = nueva.value;
    const confirmarVal = confirmar.value;
    
    if (confirmarVal === '') {
        confirmFeedback.innerHTML = '';
        return false;
    }
    
    if (nuevaVal === confirmarVal) {
        confirmFeedback.innerHTML = '<i class="fas fa-check-circle text-success mr-1"></i> <span class="text-success">Las contraseñas coinciden</span>';
        return true;
    } else {
        confirmFeedback.innerHTML = '<i class="fas fa-times-circle text-danger mr-1"></i> <span class="text-danger">Las contraseñas no coinciden</span>';
        return false;
    }
}

confirmar.addEventListener('input', validarConfirmacion);

// Toggle mostrar/ocultar contraseña
document.querySelectorAll('.toggle-pass').forEach(btn => {
    btn.addEventListener('click', () => {
        const targetId = btn.dataset.target;
        const input = document.getElementById(targetId);
        const icon = btn.querySelector('i');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });
});

// Envío del formulario
document.getElementById('formPassword').addEventListener('submit', function(e){
    e.preventDefault();
    
    const nuevaVal = nueva.value;
    const confirmarVal = confirmar.value;
    
    // Validar fortaleza mínima
    let score = 0;
    if (nuevaVal.length >= 8) score++;
    if (/[A-Z]/.test(nuevaVal)) score++;
    if (/[0-9]/.test(nuevaVal)) score++;
    if (/[^A-Za-z0-9]/.test(nuevaVal)) score++;
    
    if (score < 2) {
        Swal.fire({
            icon: 'warning',
            title: 'Contraseña insegura',
            text: 'Usa una contraseña más fuerte para continuar. Debe tener al menos 8 caracteres, incluir mayúsculas, números o caracteres especiales.',
            confirmButtonColor: '#f39c12'
        });
        return;
    }
    
    // Validar que coincidan
    if (nuevaVal !== confirmarVal) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Las contraseñas no coinciden.',
            confirmButtonColor: '#d33'
        });
        return;
    }
    
    // Mostrar loading
    Swal.fire({
        title: 'Procesando...',
        text: 'Por favor espera',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    const form = new FormData(this);
    
    fetch('procesar_cambio_password.php', {
        method: 'POST',
        body: form
    })
    .then(r => r.json())
    .then(data => {
        if(data.status === 'success'){
            Swal.fire({
                icon: 'success',
                title: '¡Contraseña actualizada!',
                text: data.msg || 'Por seguridad deberás iniciar sesión nuevamente.',
                confirmButtonText: 'Iniciar sesión',
                confirmButtonColor: '#28a745'
            }).then(() => {
                window.location.href = 'login.php';
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.msg || 'No se pudo actualizar la contraseña. Verifica tu contraseña actual.',
                confirmButtonColor: '#d33'
            });
        }
    })
    .catch(error => {
        Swal.fire({
            icon: 'error',
            title: 'Error de conexión',
            text: 'Ocurrió un problema al procesar tu solicitud.',
            confirmButtonColor: '#d33'
        });
        console.error('Error:', error);
    });
});
</script>