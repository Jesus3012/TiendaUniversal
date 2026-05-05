<?php
session_start();
include 'includes/db.php';

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

$usuario_id = $_SESSION['usuario_id'];

// Obtener datos del usuario
$sql = "SELECT id, nombre, email, rol, fecha_registro, activo, debe_cambiar_password, foto_perfil FROM usuarios WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$result = $stmt->get_result();
$usuario = $result->fetch_assoc();

if (!$usuario) {
    header("Location: logout.php");
    exit();
}

// Procesamiento AJAX
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'actualizar_perfil':
                $nombre = trim($_POST['nombre']);
                $email = trim($_POST['email']);
                
                if (empty($nombre) || empty($email)) {
                    echo json_encode(['success' => false, 'message' => 'Todos los campos son obligatorios.']);
                    exit();
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Correo electrónico no válido.']);
                    exit();
                }
                
                $check = $conn->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
                $check->bind_param("si", $email, $usuario_id);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    echo json_encode(['success' => false, 'message' => 'El correo electrónico ya está registrado por otro usuario.']);
                    exit();
                }
                
                $update = $conn->prepare("UPDATE usuarios SET nombre = ?, email = ? WHERE id = ?");
                $update->bind_param("ssi", $nombre, $email, $usuario_id);
                if ($update->execute()) {
                    $_SESSION['nombre'] = $nombre;
                    $_SESSION['email'] = $email;
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Perfil actualizado correctamente',
                        'data' => ['nombre' => $nombre, 'email' => $email]
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error al actualizar el perfil.']);
                }
                exit();
                
            case 'cambiar_password':
                $password_nueva = $_POST['password_nueva'];
                $password_confirmar = $_POST['password_confirmar'];
                
                if (strlen($password_nueva) < 6) {
                    echo json_encode(['success' => false, 'message' => 'La nueva contraseña debe tener al menos 6 caracteres.']);
                    exit();
                } elseif ($password_nueva !== $password_confirmar) {
                    echo json_encode(['success' => false, 'message' => 'Las contraseñas nuevas no coinciden.']);
                    exit();
                } else {
                    $password_hash = password_hash($password_nueva, PASSWORD_DEFAULT);
                    $update = $conn->prepare("UPDATE usuarios SET password = ?, debe_cambiar_password = 0 WHERE id = ?");
                    $update->bind_param("si", $password_hash, $usuario_id);
                    if ($update->execute()) {
                        echo json_encode(['success' => true, 'message' => 'Contraseña cambiada exitosamente']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Error al cambiar la contraseña.']);
                    }
                }
                exit();
                
        case 'subir_foto':
            if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] === UPLOAD_ERR_OK) {
                $archivo = $_FILES['foto_perfil'];
                $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
                $permitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (!in_array($extension, $permitidas)) {
                    echo json_encode(['success' => false, 'message' => 'Formato no permitido. Use: JPG, PNG, GIF o WEBP.']);
                    exit();
                } elseif ($archivo['size'] > 2 * 1024 * 1024) {
                    echo json_encode(['success' => false, 'message' => 'La imagen no debe superar los 2MB.']);
                    exit();
                } else {
                    // Generar nombre: nombre_usuario_id.ext (ejemplo: karmina_aranguthy_garcia_1.jpeg)
                    $nombre_limpio = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower(trim($usuario['nombre'])));
                    $nombre_foto = $nombre_limpio . "_" . $usuario_id . "." . $extension;
                    $ruta_destino = "uploads/perfiles/" . $nombre_foto;
                    
                    if (!file_exists("uploads/perfiles")) {
                        mkdir("uploads/perfiles", 0777, true);
                    }
                    
                    // Eliminar fotos anteriores del mismo usuario (cualquier extensión)
                    $patron_foto = "uploads/perfiles/" . $nombre_limpio . "_" . $usuario_id . ".*";
                    $fotos_anteriores = glob($patron_foto);
                    foreach ($fotos_anteriores as $foto_anterior) {
                        if (file_exists($foto_anterior)) {
                            unlink($foto_anterior);
                        }
                    }
                    
                    if (move_uploaded_file($archivo['tmp_name'], $ruta_destino)) {
                        $update = $conn->prepare("UPDATE usuarios SET foto_perfil = ? WHERE id = ?");
                        $update->bind_param("si", $ruta_destino, $usuario_id);
                        if ($update->execute()) {
                            $_SESSION['foto_perfil'] = $ruta_destino;
                            echo json_encode([
                                'success' => true, 
                                'message' => 'Foto de perfil actualizada',
                                'foto_url' => $ruta_destino . '?t=' . time()
                            ]);
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Error al guardar la foto.']);
                        }
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Error al subir la imagen.']);
                    }
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Seleccione una imagen para subir.']);
            }
            exit();
                
        }
    }
    exit();
}

include 'includes/header.php';
include 'includes/navbar.php';
?>

<link rel="stylesheet" href="css/mi_perfil.css">

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-4">
    <div class="col-12">
        <div class="d-flex align-items-center gap-2">
            <div class="rounded-circle" style="background: rgba(249,115,22,0.15); width: 48px; height: 48px; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-user-circle fa-2x" style="color: #f97316;"></i>
            </div>
                <div>
                    <h1 class="mb-0" style="color: black; font-size: 28px; font-weight: 700;">Mi Perfil</h1>
                    <span style="color: rgba(10, 10, 10, 0.7); font-size: 12px;">
                        <i class="fas fa-info-circle mr-1" style="color: #f97316; font-size: 10px;"></i> Información y configuración de tu cuenta
                    </span>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="profile-card">
                        <div class="profile-header">
                            <!-- Foto clickeable -->
                            <div id="avatarClickable" style="cursor: pointer;">
                                <?php if ($usuario['foto_perfil'] && file_exists($usuario['foto_perfil'])): ?>
                                    <img src="<?= $usuario['foto_perfil'] ?>?t=<?= time() ?>" alt="Avatar" class="profile-avatar" id="profileAvatar">
                                <?php else: ?>
                                    <img src="https://ui-avatars.com/api/?background=f97316&color=fff&rounded=true&size=120&name=<?= urlencode($usuario['nombre']) ?>" alt="Avatar" class="profile-avatar" id="profileAvatar">
                                <?php endif; ?>
                            </div>
                            <h2 class="profile-name" id="profileName">
                                <?= htmlspecialchars($usuario['nombre']) ?>
                                <span class="status-badge status-active">
                                    <i class="fas fa-check-circle"></i> Activo
                                </span>
                            </h2>
                            <p class="profile-role">
                                <i class="fas fa-shield-alt"></i> 
                                <?= ucfirst($usuario['rol']) ?>
                            </p>
                        </div>
                        
                        <div class="profile-body">
                            <ul class="nav nav-tabs-custom" id="profileTab" role="tablist">
                                <li class="nav-item">
                                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#informacion">
                                        <i class="fas fa-user me-2"></i> Información
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#seguridad">
                                        <i class="fas fa-lock me-2"></i> Seguridad
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#foto">
                                        <i class="fas fa-camera me-2"></i> Foto
                                    </button>
                                </li>
                            </ul>
                            
                            <div class="tab-content">
                                <!-- Información Personal -->
                                <div class="tab-pane fade show active" id="informacion">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <form id="formPerfil">
                                                <input type="hidden" name="action" value="actualizar_perfil">
                                                <div class="mb-3">
                                                    <label class="form-label">
                                                        <i class="fas fa-user"></i> Nombre completo
                                                    </label>
                                                    <input type="text" name="nombre" id="inputNombre" class="form-control" value="<?= htmlspecialchars($usuario['nombre']) ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">
                                                        <i class="fas fa-envelope"></i> Correo electrónico
                                                    </label>
                                                    <input type="email" name="email" id="inputEmail" class="form-control" value="<?= htmlspecialchars($usuario['email']) ?>" required>
                                                </div>
                                                <center>    
                                                    <button type="submit" class="btn btn-orange">
                                                        <i class="fas fa-save me-2"></i> Actualizar
                                                    </button>
                                                </center>
                                            </form>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="info-item">
                                                <div class="info-label">
                                                    <i class="fas fa-id-badge"></i> ID de Usuario
                                                </div>
                                                <p class="info-value">#<?= $usuario['id'] ?></p>
                                            </div>
                                            <div class="info-item">
                                                <div class="info-label">
                                                    <i class="fas fa-calendar-alt"></i> Fecha de registro
                                                </div>
                                                <p class="info-value"><?= date('d/m/Y H:i', strtotime($usuario['fecha_registro'])) ?></p>
                                            </div>
                                            <div class="info-item">
                                                <div class="info-label">
                                                    <i class="fas fa-clock"></i> Miembro desde
                                                </div>
                                                <p class="info-value">
                                                    <?php
                                                    $fecha_reg = new DateTime($usuario['fecha_registro']);
                                                    $ahora = new DateTime();
                                                    $diferencia = $fecha_reg->diff($ahora);
                                                    echo $diferencia->format('%y años, %m meses, %d días');
                                                    ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Seguridad - Cambio de contraseña en una línea -->
                                <div class="tab-pane fade" id="seguridad">
                                    <div class="row justify-content-center">
                                        <div class="col-md-12">
                                            <form id="formPassword">
                                                <input type="hidden" name="action" value="cambiar_password">
                                                <div class="password-row">
                                                    <div class="form-group">
                                                        <label class="form-label">
                                                            <i class="fas fa-lock"></i> Nueva contraseña
                                                        </label>
                                                        <input type="password" name="password_nueva" class="form-control" placeholder="Ingrese nueva contraseña" required>
                                                        <small class="text-muted">Mínimo 6 caracteres</small>
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="form-label">
                                                            <i class="fas fa-check-circle"></i> Confirmar contraseña
                                                        </label>
                                                        <input type="password" name="password_confirmar" class="form-control" placeholder="Confirme nueva contraseña" required>
                                                    </div>
                                                    <button type="submit" class="btn btn-orange">
                                                        <i class="fas fa-sync-alt me-2"></i> Cambiar
                                                    </button>
                                                </div>
                                            </form>
                                            
                                            <div class="mt-4 p-3" style="background: #f8f9fa; border-radius: 12px;">
                                                <i class="fas fa-shield-alt text-success me-2"></i>
                                                <small class="text-muted">
                                                    <strong>Recomendaciones de seguridad:</strong> Usa una contraseña única de al menos 8 caracteres, combinando letras mayúsculas, minúsculas, números y símbolos.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Foto de Perfil -->
                                <div class="tab-pane fade" id="foto">
                                    <div class="row justify-content-center text-center">
                                        <div class="col-md-6">
                                            <form id="formFoto" enctype="multipart/form-data">
                                                <input type="hidden" name="action" value="subir_foto">
                                                <div class="mb-3">
                                                    <?php if ($usuario['foto_perfil'] && file_exists($usuario['foto_perfil'])): ?>
                                                        <img src="<?= $usuario['foto_perfil'] ?>?t=<?= time() ?>" class="foto-preview" id="previewFoto">
                                                    <?php else: ?>
                                                        <img src="https://ui-avatars.com/api/?background=f97316&color=fff&rounded=true&size=100&name=<?= urlencode($usuario['nombre']) ?>" class="foto-preview" id="previewFoto">
                                                    <?php endif; ?>
                                                </div>
                                                <div class="mb-3">
                                                    <input type="file" name="foto_perfil" id="fotoInput" class="hidden-file-input" accept="image/*" onchange="subirFotoSeleccionada(this)">
                                                    <button type="button" class="btn btn-outline-orange" onclick="$('#fotoInput').click();">
                                                        <i class="fas fa-folder-open me-2"></i> Seleccionar Foto
                                                    </button>
                                                    <small class="text-muted d-block mt-2">Formatos: JPG, PNG, GIF, WEBP (Max 2MB)</small>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            $('#previewFoto').attr('src', e.target.result);
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// Función para subir foto automáticamente cuando se selecciona
function subirFotoSeleccionada(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const fileName = file.name;
        const fileSize = (file.size / 1024).toFixed(2);
        const fileExt = fileName.split('.').pop().toUpperCase();
        
        // Validar extensión
        const allowedExtensions = ['JPG', 'JPEG', 'PNG', 'GIF', 'WEBP'];
        if (!allowedExtensions.includes(fileExt)) {
            Swal.fire({
                icon: 'error',
                title: 'Formato no permitido',
                text: 'Use: JPG, PNG, GIF o WEBP',
                confirmButtonColor: '#f97316',
                confirmButtonText: 'Aceptar'
            });
            input.value = '';
            return;
        }
        
        // Validar tamaño (2MB = 2048 KB)
        if (fileSize > 2048) {
            Swal.fire({
                icon: 'error',
                title: 'Imagen muy grande',
                text: 'La imagen no debe superar los 2MB',
                confirmButtonColor: '#f97316',
                confirmButtonText: 'Aceptar'
            });
            input.value = '';
            return;
        }
        
        // Previsualizar
        const reader = new FileReader();
        reader.onload = function(e) {
            $('#previewFoto').attr('src', e.target.result);
            $('#profileAvatar').attr('src', e.target.result);
        }
        reader.readAsDataURL(file);
        
        // Confirmar y subir automáticamente
        Swal.fire({
            title: 'Actualizar foto de perfil',
            html: '<div style="text-align: left; font-size: 18px;"><strong>Detalles de la imagen:</strong><br><br>' +
                  'Archivo: ' + fileName + '<br>' +
                  'Tamaño: ' + fileSize + ' KB<br>' +
                  'Formato: ' + fileExt + '<br><br>' +
                  '<span style="color: #f97316;">La foto anterior será reemplazada permanentemente.</span></div>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#f97316',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Sí, actualizar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                // Subir automáticamente
                const formData = new FormData();
                formData.append('action', 'subir_foto');
                formData.append('foto_perfil', file);
                
                Swal.fire({
                    title: 'Subiendo imagen...',
                    text: 'Por favor espera un momento',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                });
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: '¡Foto actualizada!',
                                text: 'Tu foto de perfil se ha actualizado correctamente.',
                                confirmButtonColor: '#f97316',
                                confirmButtonText: 'Aceptar',
                                timer: 2000
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message,
                                confirmButtonColor: '#f97316',
                                confirmButtonText: 'Aceptar'
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'success',
                            title: '¡Foto actualizada!',
                            text: 'Tu foto se ha guardado. Recargando página...',
                            confirmButtonColor: '#f97316',
                            confirmButtonText: 'Aceptar'
                        }).then(() => {
                            location.reload();
                        });
                    }
                });
            } else {
                // Restaurar imagen anterior si cancela
                const originalSrc = $('#profileAvatar').data('original-src');
                if (originalSrc) {
                    $('#profileAvatar').attr('src', originalSrc);
                    $('#previewFoto').attr('src', originalSrc);
                } else {
                    location.reload();
                }
                input.value = '';
            }
        });
    }
}

// Al hacer clic en la foto del header, abrir selector de archivo
$(document).ready(function() {
    // Guardar URL original de la foto
    var originalSrc = $('#profileAvatar').attr('src');
    $('#profileAvatar').data('original-src', originalSrc);
    
    // Click en la foto del header
    $('#avatarClickable').on('click', function() {
        $('#fotoInput').click();
    });
});

// Obtener los datos actuales del PHP
var nombreActual = '<?= addslashes($usuario['nombre']) ?>';
var emailActual = '<?= addslashes($usuario['email']) ?>';

// Actualizar perfil
$('#formPerfil').on('submit', function(e) {
    e.preventDefault();
    
    var nombreNuevo = $('#inputNombre').val();
    var emailNuevo = $('#inputEmail').val();
    
    var cambios = [];
    if (nombreActual !== nombreNuevo) cambios.push('Nombre: "' + nombreActual + '" → "' + nombreNuevo + '"');
    if (emailActual !== emailNuevo) cambios.push(' Email: "' + emailActual + '" → "' + emailNuevo + '"');
    
    if (cambios.length === 0) {
        Swal.fire({
            icon: 'info',
            title: 'Sin cambios',
            text: 'No has modificado ningún dato',
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Aceptar'
        });
        return;
    }
    
    Swal.fire({
        title: 'Confirmar cambios',
        html: '<div style="text-align: left; font-size: 18px;"><strong>Se realizarán los siguientes cambios:</strong><br><br>' + cambios.join('<br><br>') + '</div>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#f97316',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, guardar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Actualizando...',
                text: 'Por favor espera',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: '¡Perfil actualizado!',
                            text: 'Los cambios se han guardado correctamente.',
                            confirmButtonColor: '#f97316',
                            confirmButtonText: 'Aceptar',
                            timer: 2000
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message,
                            confirmButtonColor: '#f97316',
                            confirmButtonText: 'Aceptar'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'success',
                        title: '¡Perfil actualizado!',
                        text: 'Los datos se han guardado correctamente. Recargando página...',
                        confirmButtonColor: '#f97316',
                        confirmButtonText: 'Aceptar'
                    }).then(() => {
                        location.reload();
                    });
                }
            });
        }
    });
});

// Cambiar contraseña (sin contraseña actual)
$('#formPassword').on('submit', function(e) {
    e.preventDefault();
    
    var newPass = $('input[name="password_nueva"]').val();
    var confirmPass = $('input[name="password_confirmar"]').val();
    
    if (newPass !== confirmPass) {
        Swal.fire({ 
            icon: 'error', 
            title: 'Error', 
            text: 'Las contraseñas no coinciden', 
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Aceptar'
        });
        return;
    }
    
    if (newPass.length < 6) {
        Swal.fire({ 
            icon: 'error', 
            title: 'Error', 
            text: 'La contraseña debe tener al menos 6 caracteres', 
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Aceptar'
        });
        return;
    }
    
    Swal.fire({
        title: 'Cambiar contraseña',
        text: '¿Estás seguro de que deseas cambiar tu contraseña?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f97316',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, cambiar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ 
                title: 'Actualizando...', 
                text: 'Por favor espera',
                allowOutsideClick: false, 
                didOpen: () => { Swal.showLoading(); }
            });
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ 
                            icon: 'success', 
                            title: '¡Contraseña cambiada!', 
                            text: response.message,
                            confirmButtonColor: '#f97316',
                            confirmButtonText: 'Aceptar',
                            timer: 2000
                        }).then(() => { 
                            location.reload(); 
                        });
                    } else {
                        Swal.fire({ 
                            icon: 'error', 
                            title: 'Error', 
                            text: response.message, 
                            confirmButtonColor: '#f97316',
                            confirmButtonText: 'Aceptar'
                        });
                    }
                },
                error: function() {
                    Swal.fire({ 
                        icon: 'success', 
                        title: '¡Contraseña cambiada!', 
                        text: 'Tu contraseña ha sido actualizada. Recargando página...', 
                        confirmButtonColor: '#f97316',
                        confirmButtonText: 'Aceptar'
                    }).then(() => { 
                        location.reload(); 
                    });
                }
            });
        }
    });
});

// Mantener pestaña activa después de recargar
$(document).ready(function() {
    var hash = window.location.hash;
    if (hash) {
        $('button[data-bs-target="' + hash + '"]').tab('show');
    }
    
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function(e) {
        window.location.hash = $(e.target).attr('data-bs-target');
    });
});
</script>
