<?php
// Archivo: control_accesos.php

declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/permisos.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';

/** @var mysqli $conn */

$rolSesion = permisos_normalizar_rol($_SESSION['rol'] ?? '');

if (!permisos_puede_gestionar($rolSesion)) {
    permisos_denegar(
        $conn,
        'control_accesos.php',
        'No tienes permiso para administrar accesos.'
    );
}

$esSuperAdministrador = ($rolSesion === 'super_administrador');

if (!permisos_tablas_disponibles($conn)) {
    http_response_code(500);
    exit(
        'Primero ejecuta el archivo sql/instalar_control_accesos.sql '
        . 'en phpMyAdmin.'
    );
}

/*
 * El superadministrador puede editar Administrador y Vendedor.
 * El administrador solamente puede editar Vendedor y nunca sus
 * propios accesos, aunque manipule la URL o el formulario.
 */
$rolesEditables = $esSuperAdministrador
    ? [
        'administrador' => 'Administrador',
        'vendedor' => 'Vendedor',
    ]
    : [
        'vendedor' => 'Vendedor',
    ];

$rolPredeterminado = $esSuperAdministrador
    ? 'administrador'
    : 'vendedor';

$rolSeleccionado = permisos_normalizar_rol(
    $_GET['rol'] ?? $_POST['rol_objetivo'] ?? $rolPredeterminado
);

if (!isset($rolesEditables[$rolSeleccionado])) {
    $rolSeleccionado = $rolPredeterminado;
}

function validarCsrfControlAccesos(): void
{
    if (function_exists('csrf_check')) {
        csrf_check();
        return;
    }

    $tokenSesion = (string) ($_SESSION['csrf_token'] ?? '');
    $tokenPost = (string) ($_POST['csrf_token'] ?? '');

    if (
        $tokenSesion === '' ||
        $tokenPost === '' ||
        !hash_equals($tokenSesion, $tokenPost)
    ) {
        http_response_code(419);
        exit('La sesión de seguridad expiró. Recarga la página.');
    }
}

function clavesPredeterminadasPorRol(string $rol): array
{
    if ($rol === 'administrador') {
        return [
            'panel_admin',
            'corte_caja',
            'ventas',
            'historial_ventas',
            'inventario',
            'productos',
            'proveedores',
            'reportes',
            'historial_stock',
            'estadisticas',
            'asignar_productos',
            'configuracion',
            'mi_perfil',
        ];
    }

    return [
        'panel_vendedor',
        'ventas',
        'historial_ventas',
        'inventario',
        'ajustes_productos',
        'reportes',
        'mi_perfil',
    ];
}

function guardarPermisosRol(
    mysqli $conn,
    string $rol,
    array $modulosSeleccionados,
    int $usuarioActual
): void {
    $rolesPermitidos = ['administrador', 'vendedor'];

    if (!in_array($rol, $rolesPermitidos, true)) {
        throw new RuntimeException('El rol seleccionado no es editable.');
    }

    $resultado = $conn->query("
        SELECT id, clave
        FROM modulos_sistema
        WHERE activo = 1
        ORDER BY orden, nombre
    ");

    if (!$resultado) {
        throw new RuntimeException(
            'No fue posible consultar los módulos: ' . $conn->error
        );
    }

    $obligatorios = permisos_modulos_obligatorios($rol);
    $seleccionados = [];

    foreach ($modulosSeleccionados as $moduloId) {
        $moduloId = (int) $moduloId;

        if ($moduloId > 0) {
            $seleccionados[$moduloId] = true;
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO roles_modulos
            (rol, modulo_id, permitido, actualizado_por)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            permitido = VALUES(permitido),
            actualizado_por = VALUES(actualizado_por),
            updated_at = CURRENT_TIMESTAMP
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible preparar el guardado: ' . $conn->error
        );
    }

    while ($modulo = $resultado->fetch_assoc()) {
        $moduloId = (int) $modulo['id'];
        $clave = (string) $modulo['clave'];

        // Control de accesos no forma parte de los permisos editables.
        // Su entrada se controla directamente por rol en permisos.php.
        if ($clave === 'control_accesos') {
            $permitido = $rol === 'administrador' ? 1 : 0;
        } else {
            $permitido = isset($seleccionados[$moduloId])
                || in_array($clave, $obligatorios, true)
                ? 1
                : 0;
        }

        $stmt->bind_param(
            'siii',
            $rol,
            $moduloId,
            $permitido,
            $usuarioActual
        );

        if (!$stmt->execute()) {
            $errorStmt = $stmt->error;
            $stmt->close();

            throw new RuntimeException(
                'No fue posible guardar el módulo '
                . $clave
                . ': '
                . $errorStmt
            );
        }
    }

    $stmt->close();
}

function registrarAuditoriaAccesos(
    mysqli $conn,
    int $usuarioId,
    string $detalle
): void {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    $stmt = $conn->prepare("
        INSERT INTO auditoria
            (usuario_id, accion, detalle, ip)
        VALUES (?, 'ACTUALIZAR_CONTROL_ACCESOS', ?, ?)
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('iss', $usuarioId, $detalle, $ip);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCsrfControlAccesos();

    $accion = (string) ($_POST['action'] ?? '');
    $rolObjetivo = permisos_normalizar_rol(
        $_POST['rol_objetivo'] ?? ''
    );

    if (!isset($rolesEditables[$rolObjetivo])) {
        permisos_redirigir(
            'control_accesos.php?error=rol'
        );
    }

    try {
        $conn->begin_transaction();

        if ($accion === 'guardar_accesos') {
            $seleccionados = isset($_POST['modulos'])
                && is_array($_POST['modulos'])
                ? $_POST['modulos']
                : [];

            guardarPermisosRol(
                $conn,
                $rolObjetivo,
                $seleccionados,
                permisos_usuario_id()
            );

            registrarAuditoriaAccesos(
                $conn,
                permisos_usuario_id(),
                'Actualizó los módulos disponibles para el rol '
                . $rolObjetivo
            );
        } elseif ($accion === 'restaurar_accesos') {
            $claves = clavesPredeterminadasPorRol($rolObjetivo);
            $ids = [];

            if ($claves) {
                $marcadores = implode(
                    ',',
                    array_fill(0, count($claves), '?')
                );

                $tipos = str_repeat('s', count($claves));

                $stmtIds = $conn->prepare("
                    SELECT id
                    FROM modulos_sistema
                    WHERE clave IN ({$marcadores})
                ");

                if (!$stmtIds) {
                    throw new RuntimeException(
                        'No fue posible preparar la restauración.'
                    );
                }

                $parametros = [$tipos];

                foreach ($claves as $indice => $clave) {
                    $parametros[] = &$claves[$indice];
                }

                call_user_func_array(
                    [$stmtIds, 'bind_param'],
                    $parametros
                );

                $stmtIds->execute();
                $resultadoIds = $stmtIds->get_result();

                while ($filaId = $resultadoIds->fetch_assoc()) {
                    $ids[] = (int) $filaId['id'];
                }

                $stmtIds->close();
            }

            guardarPermisosRol(
                $conn,
                $rolObjetivo,
                $ids,
                permisos_usuario_id()
            );

            registrarAuditoriaAccesos(
                $conn,
                permisos_usuario_id(),
                'Restauró los accesos iniciales del rol '
                . $rolObjetivo
            );
        } else {
            throw new RuntimeException('La acción solicitada no es válida.');
        }

        $conn->commit();

        permisos_redirigir(
            'control_accesos.php?rol='
            . rawurlencode($rolObjetivo)
            . '&guardado=1'
        );
    } catch (Throwable $e) {
        $conn->rollback();
        error_log(
            'Error en control_accesos.php: ' . $e->getMessage()
        );

        permisos_redirigir(
            'control_accesos.php?rol='
            . rawurlencode($rolObjetivo)
            . '&error=guardar'
        );
    }
}

$stmtModulos = $conn->prepare("
    SELECT
        m.id,
        m.clave,
        m.nombre,
        m.descripcion,
        m.icono,
        m.grupo,
        m.ruta_principal,
        m.orden,
        COALESCE(rm.permitido, 0) AS permitido
    FROM modulos_sistema m
    LEFT JOIN roles_modulos rm
        ON rm.modulo_id = m.id
       AND rm.rol = ?
    WHERE m.activo = 1
      AND m.clave <> 'control_accesos'
    ORDER BY m.grupo, m.orden, m.nombre
");

$stmtModulos->bind_param('s', $rolSeleccionado);
$stmtModulos->execute();
$resultadoModulos = $stmtModulos->get_result();

$modulosPorGrupo = [];
$totalModulos = 0;
$totalPermitidos = 0;
$obligatorios = permisos_modulos_obligatorios($rolSeleccionado);

while ($modulo = $resultadoModulos->fetch_assoc()) {
    $grupo = trim((string) $modulo['grupo']) ?: 'General';
    $esObligatorio = in_array(
        $modulo['clave'],
        $obligatorios,
        true
    );

    if ($esObligatorio) {
        $modulo['permitido'] = 1;
    }

    $modulo['obligatorio'] = $esObligatorio;
    $modulosPorGrupo[$grupo][] = $modulo;
    $totalModulos++;

    if ((int) $modulo['permitido'] === 1) {
        $totalPermitidos++;
    }
}

$stmtModulos->close();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<link
    rel="stylesheet"
    href="css/control_accesos.css?v=<?= file_exists(__DIR__ . '/css/control_accesos.css') ? filemtime(__DIR__ . '/css/control_accesos.css') : time() ?>"
>

<div class="content-wrapper access-page">
    <main class="access-shell">
        <div class="access-breadcrumb">
            <a href="dashboard_admin.php">
                <i class="fas fa-house"></i> Inicio
            </a>
            <span>/</span>
            <strong>Control de accesos</strong>
        </div>

        <section class="access-hero">
            <div class="access-title">
                <div class="access-title-icon">
                    <i class="fas fa-user-shield"></i>
                </div>

                <div>
                    <h1>Control de accesos</h1>
                    <p>
                        Define qué módulos aparecen y pueden abrirse
                        para cada rol.
                    </p>
                </div>
            </div>

            <div class="access-count">
                <strong id="contadorPermitidos">
                    <?= (int) $totalPermitidos ?>
                </strong>
                <span>
                    de <?= (int) $totalModulos ?> módulos activos
                </span>
            </div>
        </section>

        <section class="role-panel">
            <div class="role-tabs">
                <?php foreach ($rolesEditables as $claveRol => $nombreRol): ?>
                    <a
                        href="?rol=<?= rawurlencode($claveRol) ?>"
                        class="role-tab <?= $rolSeleccionado === $claveRol ? 'active' : '' ?>"
                    >
                        <i class="fas <?= $claveRol === 'administrador' ? 'fa-user-gear' : 'fa-user-tag' ?>"></i>
                        <?= htmlspecialchars($nombreRol, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="access-note">
                <i class="fas fa-circle-info"></i>
                <div>
                    <?php if ($esSuperAdministrador): ?>
                        El <strong>superadministrador</strong> conserva acceso total
                        y puede configurar los módulos de Administrador y Vendedor.
                    <?php else: ?>
                        Como <strong>administrador</strong>, puedes configurar los módulos del vendedor.
                    <?php endif; ?>
                    Los módulos indispensables aparecen bloqueados para evitar
                    que se quede sin panel o perfil.
                </div>
            </div>
        </section>

        <form method="post" id="formAccesos">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"
            >
            <input
                type="hidden"
                name="rol_objetivo"
                value="<?= htmlspecialchars($rolSeleccionado, ENT_QUOTES, 'UTF-8') ?>"
            >

            <div class="access-toolbar">
                <label class="access-search">
                    <i class="fas fa-magnifying-glass"></i>
                    <input
                        type="search"
                        id="buscarModulo"
                        placeholder="Buscar módulo..."
                        autocomplete="off"
                    >
                </label>

                <div class="quick-buttons">
                    <button
                        type="button"
                        class="quick-btn"
                        id="activarTodos"
                    >
                        <i class="fas fa-check-double"></i>
                        Activar todos
                    </button>

                    <button
                        type="button"
                        class="quick-btn"
                        id="desactivarTodos"
                    >
                        <i class="fas fa-ban"></i>
                        Desactivar opcionales
                    </button>
                </div>
            </div>

            <div id="contenedorModulos">
                <?php foreach ($modulosPorGrupo as $grupo => $modulos): ?>
                    <section
                        class="module-group"
                        data-grupo="<?= htmlspecialchars(permisos_minusculas($grupo), ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <h2 class="module-group-title">
                            <?= htmlspecialchars($grupo, ENT_QUOTES, 'UTF-8') ?>
                        </h2>

                        <div class="module-grid">
                            <?php foreach ($modulos as $modulo): ?>
                                <?php
                                $textoBusqueda = permisos_minusculas(
                                    $modulo['nombre']
                                    . ' '
                                    . $modulo['descripcion']
                                    . ' '
                                    . $modulo['clave']
                                    . ' '
                                    . $grupo
                                );
                                ?>
                                <article
                                    class="module-card"
                                    data-search="<?= htmlspecialchars($textoBusqueda, ENT_QUOTES, 'UTF-8') ?>"
                                >
                                    <div class="module-icon">
                                        <i class="fas <?= htmlspecialchars($modulo['icono'], ENT_QUOTES, 'UTF-8') ?>"></i>
                                    </div>

                                    <div class="module-content">
                                        <h3>
                                            <?= htmlspecialchars($modulo['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                        </h3>

                                        <p>
                                            <?= htmlspecialchars($modulo['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        </p>

                                        <?php if (!empty($modulo['ruta_principal'])): ?>
                                            <small>
                                                <?= htmlspecialchars($modulo['ruta_principal'], ENT_QUOTES, 'UTF-8') ?>
                                            </small>
                                        <?php endif; ?>

                                        <?php if ($modulo['obligatorio']): ?>
                                            <span class="required-badge">
                                                <i class="fas fa-lock"></i>
                                                Obligatorio
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <label class="switch-control">
                                        <input
                                            type="checkbox"
                                            name="modulos[]"
                                            value="<?= (int) $modulo['id'] ?>"
                                            class="modulo-check"
                                            <?= (int) $modulo['permitido'] === 1 ? 'checked' : '' ?>
                                            <?= $modulo['obligatorio'] ? 'disabled' : '' ?>
                                        >
                                        <span class="switch-slider"></span>
                                    </label>

                                    <?php if ($modulo['obligatorio']): ?>
                                        <input
                                            type="hidden"
                                            name="modulos[]"
                                            value="<?= (int) $modulo['id'] ?>"
                                        >
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <footer class="access-footer">
                <div class="access-footer-info">
                    Configurando:
                    <strong>
                        <?= htmlspecialchars($rolesEditables[$rolSeleccionado], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                </div>

                <div class="access-footer-actions">
                    <button
                        type="submit"
                        name="action"
                        value="restaurar_accesos"
                        class="btn-restore"
                        formnovalidate
                        onclick="return confirmarRestauracion(event)"
                    >
                        <i class="fas fa-arrow-rotate-left"></i>
                        Restaurar
                    </button>

                    <button
                        type="submit"
                        name="action"
                        value="guardar_accesos"
                        class="btn-save"
                    >
                        <i class="fas fa-floppy-disk"></i>
                        Guardar cambios
                    </button>
                </div>
            </footer>
        </form>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checks = Array.from(
        document.querySelectorAll('.modulo-check')
    );

    const buscador = document.getElementById('buscarModulo');
    const contador = document.getElementById('contadorPermitidos');
    const activarTodos = document.getElementById('activarTodos');
    const desactivarTodos = document.getElementById(
        'desactivarTodos'
    );

    function actualizarContador() {
        contador.textContent = checks.filter(function (check) {
            return check.checked;
        }).length;
    }

    checks.forEach(function (check) {
        check.addEventListener('change', actualizarContador);
    });

    activarTodos.addEventListener('click', function () {
        checks.forEach(function (check) {
            if (!check.disabled) {
                check.checked = true;
            }
        });

        actualizarContador();
    });

    desactivarTodos.addEventListener('click', function () {
        checks.forEach(function (check) {
            if (!check.disabled) {
                check.checked = false;
            }
        });

        actualizarContador();
    });

    buscador.addEventListener('input', function () {
        const termino = buscador.value
            .toLocaleLowerCase('es')
            .trim();

        document.querySelectorAll('.module-card').forEach(
            function (card) {
                const coincide = !termino
                    || card.dataset.search.includes(termino);

                card.classList.toggle('is-hidden', !coincide);
            }
        );

        document.querySelectorAll('.module-group').forEach(
            function (grupo) {
                const visibles = grupo.querySelectorAll(
                    '.module-card:not(.is-hidden)'
                ).length;

                grupo.style.display = visibles > 0 ? '' : 'none';
            }
        );
    });

    actualizarContador();

    <?php if (isset($_GET['guardado'])): ?>
    Swal.fire({
        icon: 'success',
        title: 'Accesos actualizados',
        text: 'Los cambios se aplicarán al recargar el menú.',
        confirmButtonColor: '#f97316',
        timer: 2600,
        timerProgressBar: true
    });
    <?php elseif (isset($_GET['error'])): ?>
    Swal.fire({
        icon: 'error',
        title: 'No se guardaron los cambios',
        text: 'Revisa el registro de errores de PHP e inténtalo nuevamente.',
        confirmButtonColor: '#f97316'
    });
    <?php endif; ?>
});

function confirmarRestauracion(event) {
    event.preventDefault();

    const boton = event.currentTarget;
    const formulario = boton.form;

    Swal.fire({
        icon: 'question',
        title: '¿Restaurar accesos?',
        text: 'Se aplicará la configuración inicial del rol.',
        showCancelButton: true,
        confirmButtonText: 'Sí, restaurar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#f97316'
    }).then(function (resultado) {
        if (!resultado.isConfirmed) {
            return;
        }

        const action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        action.value = 'restaurar_accesos';

        formulario.appendChild(action);
        formulario.submit();
    });

    return false;
}
</script>
