<?php
// registrar_usuario.php - Panel para admin: crear, listar, editar, reset pwd, eliminar

include 'includes/session.php';      // session_start() seguro
include 'includes/db.php';
include 'includes/csrf.php';

// Verificar que es admin
if (!isset($_SESSION['usuario_id']) || strtolower($_SESSION['rol'] ?? '') !== 'administrador') {
    header("Location: login.php");
    exit;
}

// Variables para mensajes
$errors = [];
$success = '';

// Parámetros de seguridad / política
$min_password_length = 8;
$default_password = 'Pescadores1'; // Contraseña por defecto con P mayúscula

// Manejo de acciones POST: crear, editar, reset, eliminar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Crear usuario
    if (isset($_POST['action']) && $_POST['action'] === 'create') {

        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $rol = ($_POST['rol'] === 'administrador') ? 'administrador' : 'vendedor';
        $password_plain = $default_password; // Contraseña por defecto

        // Validaciones
        if ($nombre === '' || $email === '') {
            $errors[] = "Completa todos los campos.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Email no válido.";
        } else {

            // Insertar usuario
            $password_hashed = password_hash($password_plain, PASSWORD_DEFAULT);
            $debe_cambiar = 1; // 1 = debe cambiar la contraseña al iniciar sesión
            $created_by = $_SESSION['usuario_id'];

            $stmt = $conn->prepare("
                INSERT INTO usuarios (nombre, email, password, rol, activo, debe_cambiar_password, created_by)
                VALUES (?, ?, ?, ?, 1, ?, ?)
            ");

            if (!$stmt) {
                $errors[] = "Error en la base de datos: " . $conn->error;
            } else {
                $stmt->bind_param("ssssii", $nombre, $email, $password_hashed, $rol, $debe_cambiar, $created_by);
                
                if ($stmt->execute()) {
                    $success = "Usuario creado correctamente. Contraseña por defecto: <strong>Pescadores1</strong>";
                } else {
                    $errors[] = "No se pudo crear el usuario. ¿El correo ya existe?";
                }

                $stmt->close();
            }
        }
    }

    // Editar usuario
    if (isset($_POST['action']) && $_POST['action'] === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $rol = ($_POST['rol'] === 'administrador') ? 'administrador' : 'vendedor';
        $activo = isset($_POST['activo']) ? 1 : 0;

        if ($id <= 0 || $nombre === '' || $email === '') {
            $errors[] = "Datos inválidos para editar.";
        } else {
            $stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, email = ?, rol = ?, activo = ? WHERE id = ?");
            if (!$stmt) { $errors[] = "Error en DB: " . $conn->error; }
            else {
                $stmt->bind_param("sssii", $nombre, $email, $rol, $activo, $id);
                if ($stmt->execute()) {
                    $success = "Usuario actualizado correctamente.";
                } else {
                    $errors[] = "Error al actualizar. ¿Email duplicado?";
                }
                $stmt->close();
            }
        }
    }

    // Reset password (admin establece nueva contraseña)
    if (isset($_POST['action']) && $_POST['action'] === 'reset_pwd') {
        $id = intval($_POST['id'] ?? 0);
        $new_pwd = $_POST['new_password'] ?? '';

        if ($id <= 0 || $new_pwd === '') {
            $errors[] = "Datos inválidos para restablecer contraseña.";
        } elseif (strlen($new_pwd) < $min_password_length) {
            $errors[] = "La contraseña debe tener al menos $min_password_length caracteres.";
        } else {
            $hash = password_hash($new_pwd, PASSWORD_DEFAULT);
            $debe_cambiar = 1; // Al cambiar manualmente, también forzamos cambio
            $stmt = $conn->prepare("UPDATE usuarios SET password = ?, debe_cambiar_password = ? WHERE id = ?");
            if (!$stmt) { $errors[] = "Error en DB: " . $conn->error; }
            else {
                $stmt->bind_param("sii", $hash, $debe_cambiar, $id);
                if ($stmt->execute()) {
                    $success = "Contraseña restablecida correctamente. El usuario deberá cambiarla al iniciar sesión.";
                } else {
                    $errors[] = "No se pudo restablecer la contraseña.";
                }
                $stmt->close();
            }
        }
    }

    // Reset a contraseña por defecto (Pescadores1)
    if (isset($_POST['action']) && $_POST['action'] === 'reset_default') {
        $id = intval($_POST['id'] ?? 0);

        if ($id <= 0) {
            $errors[] = "ID inválido para restablecer contraseña.";
        } else {
            $default_password = 'Pescadores1';
            $hash = password_hash($default_password, PASSWORD_DEFAULT);
            $debe_cambiar = 1;
            
            $stmt = $conn->prepare("UPDATE usuarios SET password = ?, debe_cambiar_password = ? WHERE id = ?");
            if (!$stmt) { $errors[] = "Error en DB: " . $conn->error; }
            else {
                $stmt->bind_param("sii", $hash, $debe_cambiar, $id);
                if ($stmt->execute()) {
                    $success = "Contraseña restablecida a <strong>Pescadores1</strong>. El usuario deberá cambiarla al iniciar sesión.";
                } else {
                    $errors[] = "No se pudo restablecer la contraseña.";
                }
                $stmt->close();
            }
        }
    }

    // Eliminar usuario
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $errors[] = "ID inválido para eliminar.";
        } else {
            // Evitar que el admin se borre a sí mismo
            if ($id === $_SESSION['usuario_id']) {
                $errors[] = "No puedes eliminar tu propia cuenta mientras estás conectado.";
            } else {
                $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
                if (!$stmt) { $errors[] = "Error en DB: " . $conn->error; }
                else {
                    $stmt->bind_param("i", $id);
                    if ($stmt->execute()) {
                        $success = "Usuario eliminado correctamente.";
                    } else {
                        $errors[] = "No se pudo eliminar el usuario.";
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// Obtener lista de usuarios
$users = [];
$res = $conn->query("SELECT id, nombre, email, rol, activo, debe_cambiar_password, fecha_registro, created_by FROM usuarios ORDER BY id DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
    }
    $res->free();
}

include 'includes/header.php';
include 'includes/navbar.php';
?>

<style>
.content-wrapper {
    margin-top: 10px !important;   /* Altura del navbar */
}

/*  Asegura que el sidebar empiece debajo del navbar */
.main-sidebar {
    margin-top: 70px !important;
}

/*  Ajuste para las tarjetas dentro del contenido */
.content-wrapper .container {
    margin-top: 20px !important;
}

.layout-navbar-fixed .wrapper .content-wrapper {
    padding-top: 0 !important;
}

.main-sidebar {
    top: 0 !important;
    padding-top: 60px !important; /* Acomoda el sidebar debajo del navbar */
}

.content-wrapper .container {
    margin-top: 15px !important; 
}

/* Responsive */
@media (max-width: 768px) {
    .content-wrapper {
        margin-top: 80px !important;
    }
    .main-sidebar {
        margin-top: 80px !important;
    }
}

.badge-warning {
    background-color: #ffc107;
    color: #212529;
}

/* Mensaje de sin resultados */
.no-results {
    text-align: center;
    padding: 30px;
    background: #f8f9fa;
    border-radius: 8px;
    color: #6c757d;
    font-size: 1.1em;
}

.no-results i {
    font-size: 3em;
    margin-bottom: 15px;
    color: #adb5bd;
}

/* Tooltips personalizados */
[data-tooltip] {
    position: relative;
    cursor: help;
}

[data-tooltip]:before {
    content: attr(data-tooltip);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    padding: 5px 10px;
    background: rgba(0,0,0,0.8);
    color: white;
    border-radius: 4px;
    font-size: 12px;
    white-space: nowrap;
    display: none;
    z-index: 1000;
}

[data-tooltip]:hover:before {
    display: block;
}

/* Centrado para la columna Cambiar Pwd */
.text-center-pwd {
    text-align: center !important;
    vertical-align: middle;
}

.text-center-pwd .badge {
    display: inline-block;
    margin: 0 auto;
}

/* Estilo para el botón de ocultar alerta */
.alert-dismissible-custom {
    position: relative;
    padding-right: 4rem;
}

.btn-dismiss-alert {
    position: absolute;
    top: 50%;
    right: 1rem;
    transform: translateY(-50%);
    background: transparent;
    border: none;
    color: inherit;
    opacity: 0.5;
    cursor: pointer;
    transition: opacity 0.2s;
}

.btn-dismiss-alert:hover {
    opacity: 1;
}

/* Botón para mostrar alerta */
.btn-show-alert {
    transition: all 0.2s;
}

.btn-show-alert:hover {
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

/* Animaciones */
.fade-enter {
    opacity: 0;
}

.fade-enter-active {
    opacity: 1;
    transition: opacity 300ms ease-in;
}

/* Ajustes para alinear todo a la derecha */
.card-header {
    padding: 0.75rem 1.25rem;
}

.card-header .d-flex.align-items-center {
    margin-left: auto; /* Esto empuja todo el contenido a la derecha */
}

/* Estilo para el selector de registros por página */
#rowsPerPage {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
    border-radius: 0.2rem;
}

/* Estilo para el mensaje de no resultados */
.no-results {
    text-align: center;
    padding: 40px 20px;
}

.no-results i {
    font-size: 3rem;
    color: #ccc;
    margin-bottom: 15px;
}

/* Estilo para el footer de paginación - AHORA PEGADO A LA DERECHA */
.card-footer {
    background-color: rgba(0,0,0,0.03);
    padding: 0.75rem 1.25rem;
    display: flex;
    justify-content: flex-end !important; /* Forzado a la derecha */
}

/* Asegurar que la paginación esté bien alineada a la derecha */
.card-footer .d-flex.align-items-center {
    margin-left: auto;
}

/* Estilo para los números de página */
.pagination-sm .page-link {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

/* Responsive */
@media (max-width: 576px) {
    .card-header {
        flex-direction: column;
        align-items: flex-start !important;
    }
    
    .card-header .d-flex.align-items-center {
        margin-left: 0;
        margin-top: 10px;
        width: 100%;
        justify-content: space-between;
    }
    
    #searchInput {
        width: 150px !important;
    }
    
    .card-footer .d-flex.align-items-center {
        flex-direction: column;
        align-items: flex-end !important;
    }
    
    .card-footer .text-muted {
        margin-right: 0 !important;
        margin-bottom: 5px;
    }
}
</style>

<div class="content-wrapper">

    <?php if (!empty($success)): ?>
        <script>
            document.addEventListener("DOMContentLoaded", () => {
                Swal.fire({
                    icon: "success",
                    title: "Operación exitosa",
                    html: "<?php echo $success; ?>",
                    confirmButtonColor: "#28a745"
                });
            });
        </script>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <script>
            document.addEventListener("DOMContentLoaded", () => {
                Swal.fire({
                    icon: "error",
                    title: "Error",
                    html: "<?php foreach ($errors as $e) { echo htmlspecialchars($e) . '<br>'; } ?>",
                    confirmButtonColor: "#e74c3c"
                });
            });
        </script>
    <?php endif; ?>

    <div class="container">

        <!-- CARD CREAR USUARIO -->
        <div class="card card-outline card-warning shadow-lg mb-4">
            <!-- HEADER -->
            <div class="card-header bg-white">
                <h3 class="card-title font-weight-bold mb-0">
                    <i class="fas fa-user-plus text-warning mr-2"></i>
                    Crear nuevo usuario
                </h3>
            </div>

            <!-- BODY -->
            <div class="card-body">
                <!-- ALERTA INFORMATIVA OCULTABLE -->
                <div class="alert alert-info alert-dismissible-custom" id="infoAlertCreate">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Importante:</strong> La contraseña por defecto será: <strong>Pescadores1</strong>
                    <br>
                    <small class="text-muted">El usuario deberá cambiarla en su primer inicio de sesión por razones de seguridad.</small>
                    <button type="button" class="btn-dismiss-alert" onclick="dismissAlert('create')" data-tooltip="No volver a mostrar">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- BOTÓN PARA MOSTRAR ALERTA (oculto inicialmente) -->
                <div class="text-center mb-3" id="btnMostrarAlertaCreate" style="display: none;">
                    <button type="button" class="btn btn-sm btn-outline-info btn-show-alert" onclick="mostrarAlertaCreate()">
                        <i class="fas fa-question-circle mr-1"></i> Ver información importante
                    </button>
                </div>

                <form method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

                    <!-- NOMBRE -->
                    <div class="form-group">
                        <label>Nombre completo <i class="fas fa-question-circle text-muted" data-tooltip="Nombre completo del usuario"></i></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                            </div>
                            <input type="text"
                                name="nombre"
                                class="form-control"
                                placeholder="Ej. Juan Pérez"
                                required>
                        </div>
                    </div>

                    <!-- EMAIL -->
                    <div class="form-group">
                        <label>Correo electrónico <i class="fas fa-question-circle text-muted" data-tooltip="Correo único para iniciar sesión"></i></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                            </div>
                            <input type="email"
                                name="email"
                                class="form-control"
                                placeholder="usuario@correo.com"
                                required>
                        </div>
                    </div>

                    <!-- ROL -->
                    <div class="form-group">
                        <label>Rol del usuario <i class="fas fa-question-circle text-muted" data-tooltip="Define los permisos del usuario"></i></label>
                        <select name="rol" class="form-control">
                            <option value="vendedor">Vendedor – Acceso a ventas</option>
                            <option value="administrador">Administrador – Control total</option>
                        </select>
                    </div>

                    <!-- BOTÓN -->
                    <button type="submit" class="btn btn-warning btn-block btn-lg mt-4">
                        <i class="fas fa-user-plus mr-1"></i> Crear usuario
                    </button>
                </form>
            </div>
        </div>

        <!-- CARD USUARIOS EXISTENTES -->
        <div class="card card-outline card-primary shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                <h3 class="card-title mb-2 mb-sm-0">
                    <i class="fas fa-users mr-2"></i>Usuarios existentes
                </h3>
                
                <div class="d-flex align-items-center" style="gap: 10px;">
                    <div class="d-flex align-items-center">
                        <select id="rowsPerPage" class="form-control form-control-sm" style="width: 70px;">
                            <option value="5">5</option>
                            <option value="10" selected>10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    <input id="searchInput" type="text" class="form-control form-control-sm" style="width: 220px;" placeholder="Buscar usuario...">  
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="usersTable" class="table table-hover table-striped mb-0">
                        <thead class="bg-secondary">
                            <tr>
                                <!-- ID oculto visualmente pero necesario para funcionalidad -->
                                <th style="display: none;">ID</th>
                                <th>Usuario</th>
                                <th>Email</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th class="text-center-pwd">Cambiar Pwd</th>
                                <th>Registro</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>

                        <tbody id="tableBody">
                        <?php if (empty($users)): ?>
                            <tr id="noResultsRow">
                                <td colspan="8" class="no-results">
                                    <i class="fas fa-users-slash"></i>
                                    <h5>No se encontraron usuarios</h5>
                                    <p class="text-muted">Comienza creando un nuevo usuario usando el formulario superior.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $u): ?>
                            <tr class="user-row" data-user-id="<?= $u['id'] ?>">
                                <td style="display: none;"><?= $u['id'] ?></td>

                                <td>
                                    <strong><?= htmlspecialchars($u['nombre']) ?></strong>
                                </td>

                                <td><?= htmlspecialchars($u['email']) ?></td>

                                <td>
                                    <span class="badge badge-info text-capitalize">
                                        <?= $u['rol'] ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($u['activo']): ?>
                                        <span class="badge badge-success">Activo</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Inactivo</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center-pwd">
                                    <?php if ($u['debe_cambiar_password']): ?>
                                        <span class="badge badge-warning" data-tooltip="El usuario debe cambiar su contraseña">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>Pendiente
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-success" data-tooltip="Contraseña ya cambiada">
                                            <i class="fas fa-check mr-1"></i>Cambiada
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <small><?= date('d/m/Y', strtotime($u['fecha_registro'])) ?></small>
                                </td>

                                <td class="text-center">
                                    <div class="btn-group">
                                        <!-- EDITAR -->
                                        <button class="btn btn-sm btn-outline-primary"
                                                data-toggle="modal"
                                                data-target="#editUser<?= $u['id'] ?>"
                                                data-tooltip="Editar información del usuario">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <!-- CAMBIAR PASSWORD -->
                                        <button class="btn btn-sm btn-outline-warning"
                                                data-toggle="modal"
                                                data-target="#resetPwd<?= $u['id'] ?>"
                                                data-tooltip="Establecer contraseña personalizada">
                                            <i class="fas fa-key"></i>
                                        </button>

                                        <!-- RESETEAR A PESCADORES1 -->
                                        <form method="POST" action="registrar_usuario.php" class="d-inline" id="resetDefaultForm<?= $u['id'] ?>">
                                            <input type="hidden" name="action" value="reset_default">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">
                                            
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-info"
                                                    onclick="resetearDefault(<?= $u['id'] ?>)"
                                                    data-tooltip="Restablecer a Pescadores1">
                                                <i class="fas fa-undo-alt"></i>
                                            </button>
                                        </form>

                                        <!-- ELIMINAR -->
                                        <form method="POST" action="registrar_usuario.php" class="d-inline" id="deleteForm<?= $u['id'] ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">

                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    onclick="eliminarUsuario(<?= $u['id'] ?>)"
                                                    data-tooltip="Eliminar usuario permanentemente">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Controles de paginación PEGADOS A LA DERECHA -->
            <div class="card-footer d-flex justify-content-end align-items-center" style="border-top: 1px solid rgba(0,0,0,0.125); padding: 0.75rem 1.25rem;">
                <div class="d-flex align-items-center">
                    <div class="text-muted small mr-3">
                        Mostrando <span id="startRecord">0</span> - <span id="endRecord">0</span> de <span id="totalRecords">0</span>
                    </div>
                    <nav aria-label="Page navigation">
                        <ul class="pagination pagination-sm mb-0" id="pagination">
                            <li class="page-item disabled" id="prevPage">
                                <a class="page-link" href="#" aria-label="Anterior" onclick="changePage(currentPage - 1); return false;">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            <!-- Los números de página se generan dinámicamente aquí -->
                            <li class="page-item disabled" id="nextPage">
                                <a class="page-link" href="#" aria-label="Siguiente" onclick="changePage(currentPage + 1); return false;">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </div> <!-- container -->
</div> <!-- content-wrapper -->

<!-- ALERTA INFORMATIVA EN MODAL RESET PASSWORD -->
<?php foreach ($users as $u): ?>
<!-- Modal Reset Password (personalizado) -->
<div class="modal fade" id="resetPwd<?= $u['id'] ?>" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-md" role="document">
        <form method="POST" onsubmit="return confirmReset(event, this)" class="modal-content">

            <div class="modal-header bg-warning">
                <h5 class="modal-title">
                    <i class="fas fa-key mr-2"></i>Cambiar contraseña
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>

            <div class="modal-body bg-white">
                <input type="hidden" name="action" value="reset_pwd">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">

                <!-- ALERTA INFORMATIVA OCULTABLE EN MODAL - CORREGIDO: ID usa el formato completo -->
                <div class="alert alert-info alert-dismissible-custom" id="infoAlertmodal<?= $u['id'] ?>">
                    <i class="fas fa-info-circle mr-2"></i>
                    También puedes restablecer a <strong>Pescadores1</strong> usando el botón <i class="fas fa-undo-alt"></i> en la tabla.
                    <button type="button" class="btn-dismiss-alert" onclick="dismissAlert('modal<?= $u['id'] ?>')" data-tooltip="No volver a mostrar">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- BOTÓN PARA MOSTRAR ALERTA - CORREGIDO: ID usa el formato completo -->
                <div class="text-center mb-3" id="btnMostrarAlertamodal<?= $u['id'] ?>" style="display: none;">
                    <button type="button" class="btn btn-sm btn-outline-info btn-show-alert" onclick="mostrarAlertaModal(<?= $u['id'] ?>)">
                        <i class="fas fa-question-circle mr-1"></i> Ver información importante
                    </button>
                </div>

                <div class="form-group mt-3">
                    <label>Nueva contraseña <i class="fas fa-question-circle text-muted" data-tooltip="Mínimo 8 caracteres"></i></label>
                    <input type="password"
                        class="form-control"
                        name="new_password"
                        placeholder="Mínimo 8 caracteres"
                        required>
                </div>

                <small class="text-muted">
                    <i class="fas fa-info-circle mr-1"></i>
                    El usuario deberá cambiarla al iniciar sesión.
                </small>
            </div>

            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    Cancelar
                </button>
                <button class="btn btn-warning">
                    Cambiar contraseña
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Editar Usuario (sin cambios) -->
<div class="modal fade" id="editUser<?= $u['id'] ?>" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-md" role="document">
        <form method="POST" onsubmit="return confirmEdit(event, this)" class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-user-edit mr-2"></i>Editar usuario
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body bg-white">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <div class="form-group">
                    <label>Nombre <i class="fas fa-question-circle text-muted" data-tooltip="Nombre completo del usuario"></i></label>
                    <input class="form-control" name="nombre" value="<?= htmlspecialchars($u['nombre']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Email <i class="fas fa-question-circle text-muted" data-tooltip="Correo electrónico único"></i></label>
                    <input class="form-control" type="email" name="email" value="<?= htmlspecialchars($u['email']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Rol <i class="fas fa-question-circle text-muted" data-tooltip="Permisos del usuario"></i></label>
                    <select name="rol" class="form-control">
                        <option value="vendedor" <?= $u['rol']==='vendedor'?'selected':'' ?>>Vendedor</option>
                        <option value="administrador" <?= $u['rol']==='administrador'?'selected':'' ?>>Administrador</option>
                    </select>
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="activo" id="activo<?= $u['id'] ?>" <?= $u['activo']?'checked':'' ?>>
                    <label class="form-check-label" for="activo<?= $u['id'] ?>">Cuenta activa</label>
                    <i class="fas fa-question-circle text-muted ml-2" data-tooltip="Los usuarios inactivos no pueden iniciar sesión"></i>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// ==============================================
// ESTADO DE ALERTAS EN LOCALSTORAGE
// ==============================================

// Cargar estado de alertas
let dismissedAlerts = JSON.parse(localStorage.getItem('dismissedAlerts')) || [];

function isAlertDismissed(alertId) {
    return dismissedAlerts.includes(alertId);
}

function dismissAlert(alertId) {
    if (!dismissedAlerts.includes(alertId)) {
        dismissedAlerts.push(alertId);
        localStorage.setItem('dismissedAlerts', JSON.stringify(dismissedAlerts));
    }
    
    // Ocultar la alerta - CORREGIDO: Manejar correctamente los IDs
    if (alertId === 'create') {
        const alertElement = document.getElementById('infoAlertCreate');
        const btnContainer = document.getElementById('btnMostrarAlertaCreate');
        if (alertElement) {
            alertElement.style.display = 'none';
        }
        if (btnContainer) {
            btnContainer.style.display = 'block';
        }
    } else if (alertId.startsWith('modal')) {
        // Para alertas de modales
        const alertElement = document.getElementById(`infoAlert${alertId}`);
        const btnContainer = document.getElementById(`btnMostrarAlerta${alertId}`);
        if (alertElement) {
            alertElement.style.display = 'none';
        }
        if (btnContainer) {
            btnContainer.style.display = 'block';
        }
    }
}

// Función para mostrar alerta de crear usuario
function mostrarAlertaCreate() {
    // Mostrar la alerta
    const alertElement = document.getElementById('infoAlertCreate');
    if (alertElement) {
        alertElement.style.display = '';
    }
    
    // Ocultar el botón
    const btnContainer = document.getElementById('btnMostrarAlertaCreate');
    if (btnContainer) {
        btnContainer.style.display = 'none';
    }
    
    // Eliminar del localStorage para que vuelva a mostrarse
    const index = dismissedAlerts.indexOf('create');
    if (index > -1) {
        dismissedAlerts.splice(index, 1);
        localStorage.setItem('dismissedAlerts', JSON.stringify(dismissedAlerts));
    }
}

// Función para mostrar alerta en modal
function mostrarAlertaModal(userId) {
    const alertId = `modal${userId}`;
    
    // Mostrar la alerta
    const alertElement = document.getElementById(`infoAlert${alertId}`);
    if (alertElement) {
        alertElement.style.display = '';
    }
    
    // Ocultar el botón
    const btnContainer = document.getElementById(`btnMostrarAlerta${alertId}`);
    if (btnContainer) {
        btnContainer.style.display = 'none';
    }
    
    // Eliminar del localStorage
    const index = dismissedAlerts.indexOf(alertId);
    if (index > -1) {
        dismissedAlerts.splice(index, 1);
        localStorage.setItem('dismissedAlerts', JSON.stringify(dismissedAlerts));
    }
}

// Aplicar estado de alertas al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    // Ocultar alertas que ya fueron descartadas y mostrar sus botones
    dismissedAlerts.forEach(alertId => {
        if (alertId === 'create') {
            const alertElement = document.getElementById('infoAlertCreate');
            const btnContainer = document.getElementById('btnMostrarAlertaCreate');
            if (alertElement) {
                alertElement.style.display = 'none';
            }
            if (btnContainer) {
                btnContainer.style.display = 'block';
            }
        } else if (alertId.startsWith('modal')) {
            const alertElement = document.getElementById(`infoAlert${alertId}`);
            const btnContainer = document.getElementById(`btnMostrarAlerta${alertId}`);
            if (alertElement) {
                alertElement.style.display = 'none';
            }
            if (btnContainer) {
                btnContainer.style.display = 'block';
            }
        }
    });
});

// ==============================================
// BUSCADOR CON MENSAJE DE SIN RESULTADOS
// ==============================================

document.getElementById('searchInput').addEventListener('keyup', function() {
    const filter = this.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#usersTable tbody tr.user-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const match = text.includes(filter);
        row.style.display = match ? '' : 'none';
        if (match) visibleCount++;
    });

    // Mostrar mensaje de sin resultados si es necesario
    let noResultsRow = document.getElementById('noResultsRow');
    
    if (visibleCount === 0 && rows.length > 0) {
        if (!noResultsRow) {
            const tbody = document.getElementById('tableBody');
            const tr = document.createElement('tr');
            tr.id = 'noResultsRow';
            tr.innerHTML = `
                <td colspan="8" class="no-results">
                    <i class="fas fa-search"></i>
                    <h5>No se encontraron resultados</h5>
                    <p class="text-muted">Intenta con otros términos de búsqueda.</p>
                </td>
            `;
            tbody.appendChild(tr);
        }
    } else if (noResultsRow && visibleCount > 0) {
        noResultsRow.remove();
    }
});

// ==============================================
// FUNCIONES DE ACCIONES (SweetAlert)
// ==============================================

/* ELIMINAR USUARIO */
function eliminarUsuario(id) {
    Swal.fire({
        title: "¿Eliminar usuario?",
        text: "El usuario será eliminado permanentemente. Esta acción no se puede deshacer.",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#e74c3c",
        cancelButtonColor: "#6c757d",
        confirmButtonText: "Sí, eliminar",
        cancelButtonText: "Cancelar",
        showLoaderOnConfirm: true,
        preConfirm: () => {
            document.getElementById('deleteForm' + id).submit();
        }
    });

    return false;
}

/* RESETEAR A PESCADORES1 */
function resetearDefault(id) {
    Swal.fire({
        title: "¿Restablecer contraseña?",
        html: "La contraseña se establecerá como <strong>Pescadores1</strong><br>El usuario deberá cambiarla al iniciar sesión.",
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: "#17a2b8",
        cancelButtonColor: "#6c757d",
        confirmButtonText: "Sí, restablecer",
        cancelButtonText: "Cancelar",
        showLoaderOnConfirm: true,
        preConfirm: () => {
            document.getElementById('resetDefaultForm' + id).submit();
        }
    });

    return false;
}

/* EDITAR USUARIO */
function confirmEdit(event, form) {
    event.preventDefault();
    
    Swal.fire({
        title: "¿Guardar cambios?",
        text: "Estás a punto de actualizar este usuario.",
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#6c757d",
        confirmButtonText: "Guardar",
        cancelButtonText: "Cancelar"
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });

    return false;
}

/* RESETEAR CONTRASEÑA PERSONALIZADA */
function confirmReset(event, form) {
    event.preventDefault();
    
    // Validar que la contraseña no sea vacía
    const password = form.querySelector('input[name="new_password"]').value;
    if (password.length < 8) {
        Swal.fire({
            icon: "error",
            title: "Error",
            text: "La contraseña debe tener al menos 8 caracteres"
        });
        return false;
    }

    // Validar que no sea igual a Pescadores1
    if (password === 'Pescadores1') {
        Swal.fire({
            icon: "warning",
            title: "Advertencia",
            text: "La nueva contraseña no puede ser igual a la contraseña por defecto"
        });
        return false;
    }

    // Validación simplificada - solo longitud mínima
    // Ya no requiere mayúsculas, minúsculas ni números específicos

    Swal.fire({
        title: "¿Cambiar contraseña?",
        text: "La contraseña será cambiada inmediatamente.",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#f39c12",
        cancelButtonColor: "#6c757d",
        confirmButtonText: "Cambiar",
        cancelButtonText: "Cancelar"
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });

    return false;
}

// Variables globales para paginación
let currentPage = 1;
let rowsPerPage = 10;
let filteredRows = [];
let allRows = [];

document.addEventListener('DOMContentLoaded', function() {
    // Inicializar datos
    const tableBody = document.getElementById('tableBody');
    const rows = tableBody.querySelectorAll('tr.user-row');
    allRows = Array.from(rows);
    
    // Si hay filas de usuarios, inicializar paginación
    if (allRows.length > 0) {
        filterTable();
    } else {
        // Ocultar footer de paginación si no hay usuarios
        document.querySelector('.card-footer').style.display = 'none';
    }
    
    // Configurar event listeners
    document.getElementById('searchInput').addEventListener('keyup', function() {
        currentPage = 1; // Reset a primera página al buscar
        filterTable();
    });
    
    document.getElementById('rowsPerPage').addEventListener('change', function() {
        rowsPerPage = parseInt(this.value);
        currentPage = 1; // Reset a primera página al cambiar registros por página
        filterTable();
    });
});

function filterTable() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
    
    // Filtrar filas
    if (searchTerm === '') {
        filteredRows = [...allRows];
    } else {
        filteredRows = allRows.filter(row => {
            const cells = row.querySelectorAll('td');
            // Buscar en todas las celdas visibles (excluir ID y acciones)
            for (let i = 1; i < cells.length - 1; i++) {
                const cellText = cells[i] ? cells[i].textContent.toLowerCase() : '';
                if (cellText.includes(searchTerm)) {
                    return true;
                }
            }
            return false;
        });
    }
    
    // Actualizar total de registros
    document.getElementById('totalRecords').textContent = filteredRows.length;
    
    // Actualizar paginación
    updatePagination();
}

function updatePagination() {
    const totalPages = Math.ceil(filteredRows.length / rowsPerPage);
    
    // Ocultar todas las filas primero
    allRows.forEach(row => row.style.display = 'none');
    
    // Mostrar filas de la página actual
    if (filteredRows.length > 0) {
        const start = (currentPage - 1) * rowsPerPage;
        const end = Math.min(start + rowsPerPage, filteredRows.length);
        
        for (let i = start; i < end; i++) {
            filteredRows[i].style.display = '';
        }
        
        // Actualizar contadores
        document.getElementById('startRecord').textContent = start + 1;
        document.getElementById('endRecord').textContent = end;
    } else {
        document.getElementById('startRecord').textContent = 0;
        document.getElementById('endRecord').textContent = 0;
    }
    
    // Generar números de página
    const pagination = document.getElementById('pagination');
    const prevPage = document.getElementById('prevPage');
    const nextPage = document.getElementById('nextPage');
    
    // Eliminar todas las páginas excepto anterior y siguiente
    while (pagination.children.length > 2) {
        pagination.removeChild(pagination.children[1]);
    }
    
    // Agregar números de página
    for (let i = 1; i <= totalPages; i++) {
        const li = document.createElement('li');
        li.className = `page-item ${i === currentPage ? 'active' : ''}`;
        
        const link = document.createElement('a');
        link.className = 'page-link';
        link.href = '#';
        link.textContent = i;
        link.onclick = function(e) {
            e.preventDefault();
            changePage(i);
        };
        
        li.appendChild(link);
        pagination.insertBefore(li, nextPage);
    }
    
    // Actualizar estado de botones anterior/siguiente
    prevPage.className = `page-item ${currentPage === 1 ? 'disabled' : ''}`;
    nextPage.className = `page-item ${currentPage === totalPages || totalPages === 0 ? 'disabled' : ''}`;
    
    // Mostrar/ocultar mensaje de no resultados
    const noResultsMsg = document.getElementById('noSearchResults');
    if (filteredRows.length === 0 && allRows.length > 0) {
        if (!noResultsMsg) {
            const tableBody = document.getElementById('tableBody');
            const tr = document.createElement('tr');
            tr.id = 'noSearchResults';
            tr.innerHTML = `
                <td colspan="8" class="no-results">
                    <i class="fas fa-search"></i>
                    <h5>No se encontraron resultados</h5>
                    <p class="text-muted">No hay usuarios que coincidan con "<strong>${document.getElementById('searchInput').value}</strong>"</p>
                </td>
            `;
            tableBody.appendChild(tr);
        }
    } else if (noResultsMsg) {
        noResultsMsg.remove();
    }
}

function changePage(page) {
    const totalPages = Math.ceil(filteredRows.length / rowsPerPage);
    
    if (page < 1 || page > totalPages || totalPages === 0) {
        return;
    }
    
    currentPage = page;
    updatePagination();
}

// ==============================================
// NOTIFICACIONES
// ==============================================

<?php if (!empty($success)): ?>
Swal.fire({
    icon: "success",
    title: "Éxito",
    html: "<?= $success ?>",
    timer: 3000,
    showConfirmButton: true
});
<?php endif; ?>

<?php if (!empty($errors)): ?>
Swal.fire({
    icon: "error",
    title: "Error",
    html: "<?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>",
});
<?php endif; ?>
</script>