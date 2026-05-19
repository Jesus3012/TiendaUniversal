<?php

date_default_timezone_set('America/Mexico_City');

session_start();
require_once 'includes/db.php';

// Verificar si el usuario está logueado y es administrador
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'administrador') {
    header('Location: login.php');
    exit;
}

include 'includes/header.php';
include 'includes/navbar.php';


$mensaje = '';
$tipo_mensaje = '';
$tab_activo = isset($_POST['tab_activo']) ? $_POST['tab_activo'] : (isset($_GET['tab']) ? $_GET['tab'] : 'general');

// ==================== FUNCIÓN PARA RESPALDOS SIN MYSQLDUMP ====================
function backupDatabase($conn, $backup_dir = 'backups/') {
    if (!file_exists($backup_dir)) {
        mkdir($backup_dir, 0777, true);
    }
    
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backup_dir . $filename;
    
    // Obtener todas las tablas
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    
    $sql_content = "-- Backup generado el " . date('Y-m-d H:i:s') . "\n";
    $sql_content .= "-- Base de datos: " . DB_NAME . "\n\n";
    $sql_content .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    $sql_content .= "SET time_zone = \"+00:00\";\n\n";
    
    foreach ($tables as $table) {
        // Obtener estructura de la tabla
        $result = $conn->query("SHOW CREATE TABLE `$table`");
        $row = $result->fetch_row();
        $sql_content .= "-- Estructura de tabla `$table`\n";
        $sql_content .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql_content .= $row[1] . ";\n\n";
        
        // Obtener datos de la tabla
        $result = $conn->query("SELECT * FROM `$table`");
        if ($result->num_rows > 0) {
            $sql_content .= "-- Datos de tabla `$table`\n";
            while ($row_data = $result->fetch_assoc()) {
                $columns = array_keys($row_data);
                $values = array_map(function($value) use ($conn) {
                    if ($value === null) return 'NULL';
                    return "'" . $conn->real_escape_string($value) . "'";
                }, array_values($row_data));
                
                $sql_content .= "INSERT INTO `$table` (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n";
            }
            $sql_content .= "\n";
        }
    }
    
    // Guardar archivo
    if (file_put_contents($filepath, $sql_content)) {
        return ['success' => true, 'filename' => $filename];
    }
    return ['success' => false, 'error' => 'No se pudo escribir el archivo'];
}

// ==================== PROCESAR ACCIONES ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $usuario_id = $_SESSION['usuario_id'];
    $ip = $_SERVER['REMOTE_ADDR'];
    
    // Actualizar configuración general
    if ($action === 'update_general') {
        $nombre = trim($_POST['nombre'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $horario = trim($_POST['horario'] ?? '');
        
        $stmt = $conn->prepare("UPDATE configuracion_galeria SET nombre = ?, telefono = ?, email = ?, direccion = ?, horario = ? WHERE id = 1");
        $stmt->bind_param("sssss", $nombre, $telefono, $email, $direccion, $horario);
        
        if ($stmt->execute()) {
            // Procesar logo
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {

                $upload_dir = 'img/';

                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                // Eliminar logos anteriores
                foreach (glob($upload_dir . 'panel_principal.*') as $old_file) {
                    if (is_file($old_file)) {
                        unlink($old_file);
                    }
                }

                $extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));

                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                if (in_array($extension, $allowed)) {

                    $filename = 'panel_principal.' . $extension;
                    $target_path = $upload_dir . $filename;

                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {

                        $stmt_logo = $conn->prepare("UPDATE configuracion_galeria SET logo = ? WHERE id = 1");
                        $stmt_logo->bind_param("s", $target_path);
                        $stmt_logo->execute();
                    }
                }
            }

            // Procesar imagen del Dashboard
            if (isset($_FILES['imagen_dashboard']) && $_FILES['imagen_dashboard']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'img/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // ELIMINAR ARCHIVOS ANTERIORES (todos los dashboard_principal.*)
                $old_files = glob($upload_dir . 'dashboard_principal.*');
                foreach ($old_files as $old_file) {
                    if (is_file($old_file)) {
                        unlink($old_file);
                    }
                }
                
                $extension = strtolower(pathinfo($_FILES['imagen_dashboard']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($extension, $allowed)) {
                    $final_extension = $extension;
                    $filename = 'dashboard_principal.' . $final_extension;
                    $target_path = $upload_dir . $filename;
                    
                    // Optimizar imagen
                    if ($extension === 'png') {
                        $src = imagecreatefrompng($_FILES['imagen_dashboard']['tmp_name']);
                        $width = imagesx($src);
                        $height = imagesy($src);
                        $dst = imagecreatetruecolor($width, $height);
                        $white = imagecolorallocate($dst, 255, 255, 255);
                        imagefill($dst, 0, 0, $white);
                        imagecopy($dst, $src, 0, 0, 0, 0, $width, $height);
                        imagejpeg($dst, $target_path, 85);
                        imagedestroy($src);
                        imagedestroy($dst);
                    } elseif ($extension === 'jpeg' || $extension === 'jpg') {
                        $src = imagecreatefromjpeg($_FILES['imagen_dashboard']['tmp_name']);
                        imagejpeg($src, $target_path, 85);
                        imagedestroy($src);
                    } elseif ($extension === 'gif') {
                        move_uploaded_file($_FILES['imagen_dashboard']['tmp_name'], $target_path);
                    } elseif ($extension === 'webp') {
                        move_uploaded_file($_FILES['imagen_dashboard']['tmp_name'], $target_path);
                    }
                    
                    // Guardar ruta en BD
                    $stmt_dashboard = $conn->prepare("UPDATE configuracion_galeria SET imagen_dashboard = ? WHERE id = 1");
                    $stmt_dashboard->bind_param("s", $target_path);
                    $stmt_dashboard->execute();
                }
            }
            
            $mensaje = "Configuración general actualizada correctamente.";
            $tipo_mensaje = "success";
            
            $stmt_audit = $conn->prepare("INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, 'Actualizar Configuración General', 'Actualizó la configuración general de la tienda', ?)");
            $stmt_audit->bind_param("is", $usuario_id, $ip);
            $stmt_audit->execute();
        }   else {
            $mensaje = "Error al actualizar la configuración.";
            $tipo_mensaje = "danger";
        }
        $tab_activo = 'general';
    }
    
    // Actualizar configuración de correo
    elseif ($action === 'update_email') {
        $smtp_host = trim($_POST['smtp_host'] ?? '');
        $smtp_port = intval($_POST['smtp_port'] ?? 587);
        $smtp_usuario = trim($_POST['smtp_usuario'] ?? '');
        $smtp_secure = trim($_POST['smtp_secure'] ?? 'tls');
        $correo_origen = trim($_POST['correo_origen'] ?? '');
        $nombre_origen = trim($_POST['nombre_origen'] ?? '');
        $smtp_password = trim($_POST['smtp_password'] ?? '');
        
        if (!empty($smtp_password)) {
            $stmt = $conn->prepare("UPDATE configuracion_correo SET smtp_host = ?, smtp_port = ?, smtp_usuario = ?, smtp_password = ?, smtp_secure = ?, correo_origen = ?, nombre_origen = ? WHERE id = 1");
            $stmt->bind_param("sisssss", $smtp_host, $smtp_port, $smtp_usuario, $smtp_password, $smtp_secure, $correo_origen, $nombre_origen);
        } else {
            $stmt = $conn->prepare("UPDATE configuracion_correo SET smtp_host = ?, smtp_port = ?, smtp_usuario = ?, smtp_secure = ?, correo_origen = ?, nombre_origen = ? WHERE id = 1");
            $stmt->bind_param("sissss", $smtp_host, $smtp_port, $smtp_usuario, $smtp_secure, $correo_origen, $nombre_origen);
        }
        
        if ($stmt->execute()) {
            $mensaje = "Configuración de correo actualizada correctamente.";
            $tipo_mensaje = "success";
            
            $stmt_audit = $conn->prepare("INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, 'Actualizar Configuración SMTP', 'Actualizó la configuración de correo', ?)");
            $stmt_audit->bind_param("is", $usuario_id, $ip);
            $stmt_audit->execute();
        } else {
            $mensaje = "Error al actualizar la configuración de correo.";
            $tipo_mensaje = "danger";
        }
        $tab_activo = 'correo';
    }
    
    // Crear usuario
    elseif ($action === 'crear_usuario') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $rol = trim($_POST['rol'] ?? 'vendedor');
        $activo = intval($_POST['activo'] ?? 1);
        $password_default = 'Pescadores1';
        $password_hash = password_hash($password_default, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("INSERT INTO usuarios (nombre, email, password, rol, activo, created_by, debe_cambiar_password) VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("ssssii", $nombre, $email, $password_hash, $rol, $activo, $usuario_id);
        
        if ($stmt->execute()) {
            $mensaje = "Usuario creado correctamente. Contraseña por defecto: <strong>Pescadores1</strong>";
            $tipo_mensaje = "success";
            
            $stmt_audit = $conn->prepare("INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, 'Crear Usuario', ?, ?)");
            $detalle = "Creó el usuario: $nombre ($email)";
            $stmt_audit->bind_param("iss", $usuario_id, $detalle, $ip);
            $stmt_audit->execute();
        } else {
            $mensaje = "Error al crear el usuario.";
            $tipo_mensaje = "danger";
        }
        $tab_activo = 'usuarios';
    }
    
    // Editar usuario
    elseif ($action === 'editar_usuario') {
        $id_usuario = intval($_POST['id_usuario'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $rol = trim($_POST['rol'] ?? 'vendedor');
        $activo = intval($_POST['activo'] ?? 1);
        $password = trim($_POST['password'] ?? '');
        
        if (!empty($password)) {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, email = ?, rol = ?, activo = ?, password = ? WHERE id = ?");
            $stmt->bind_param("sssisi", $nombre, $email, $rol, $activo, $password_hash, $id_usuario);
        } else {
            $stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, email = ?, rol = ?, activo = ? WHERE id = ?");
            $stmt->bind_param("sssii", $nombre, $email, $rol, $activo, $id_usuario);
        }
        
        if ($stmt->execute()) {
            $mensaje = "Usuario actualizado correctamente.";
            $tipo_mensaje = "success";
            
            $stmt_audit = $conn->prepare("INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, 'Editar Usuario', ?, ?)");
            $detalle = "Editó el usuario ID: $id_usuario";
            $stmt_audit->bind_param("iss", $usuario_id, $detalle, $ip);
            $stmt_audit->execute();
        } else {
            $mensaje = "Error al actualizar el usuario.";
            $tipo_mensaje = "danger";
        }
        $tab_activo = 'usuarios';
    }
    
    // Resetear contraseña
    elseif ($action === 'reset_password') {
        $id_usuario = intval($_POST['id_usuario'] ?? 0);
        $password_default = 'Pescadores1';
        $password_hash = password_hash($password_default, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE usuarios SET password = ?, debe_cambiar_password = 1 WHERE id = ?");
        $stmt->bind_param("si", $password_hash, $id_usuario);
        
        if ($stmt->execute()) {
            $mensaje = "Contraseña restablecida a: <strong>Pescadores1</strong>. El usuario deberá cambiarla al iniciar sesión.";
            $tipo_mensaje = "success";
            
            $stmt_audit = $conn->prepare("INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, 'Resetear Contraseña', ?, ?)");
            $detalle = "Restableció la contraseña del usuario ID: $id_usuario";
            $stmt_audit->bind_param("iss", $usuario_id, $detalle, $ip);
            $stmt_audit->execute();
        } else {
            $mensaje = "Error al restablecer la contraseña.";
            $tipo_mensaje = "danger";
        }
        $tab_activo = 'usuarios';
    }
    
    // Eliminar usuario
    elseif ($action === 'eliminar_usuario') {
        $id_usuario = intval($_POST['id_usuario'] ?? 0);
        
        if ($id_usuario == $_SESSION['usuario_id']) {
            $mensaje = "No puedes eliminar tu propio usuario.";
            $tipo_mensaje = "danger";
        } else {
            $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
            $stmt->bind_param("i", $id_usuario);
            
            if ($stmt->execute()) {
                $mensaje = "Usuario eliminado correctamente.";
                $tipo_mensaje = "success";
                
                $stmt_audit = $conn->prepare("INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, 'Eliminar Usuario', ?, ?)");
                $detalle = "Eliminó el usuario ID: $id_usuario";
                $stmt_audit->bind_param("iss", $usuario_id, $detalle, $ip);
                $stmt_audit->execute();
            } else {
                $mensaje = "Error al eliminar el usuario.";
                $tipo_mensaje = "danger";
            }
        }
        $tab_activo = 'usuarios';
    }
    
    // Crear proveedor
    elseif ($action === 'crear_proveedor') {
        $nombre = trim($_POST['nombre'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $activo = intval($_POST['activo'] ?? 1);
        $calle = trim($_POST['calle'] ?? '');
        $numero = trim($_POST['numero'] ?? '');
        $colonia = trim($_POST['colonia'] ?? '');
        $ciudad = trim($_POST['ciudad'] ?? '');
        $estado = trim($_POST['estado'] ?? '');
        $codigo_postal = trim($_POST['codigo_postal'] ?? '');
        
        $stmt = $conn->prepare("INSERT INTO proveedores (nombre, correo, telefono, activo, calle, numero, colonia, ciudad, estado, codigo_postal) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssissssss", $nombre, $correo, $telefono, $activo, $calle, $numero, $colonia, $ciudad, $estado, $codigo_postal);
        
        if ($stmt->execute()) {
            $mensaje = "Proveedor creado correctamente.";
            $tipo_mensaje = "success";
            
            $stmt_audit = $conn->prepare("INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, 'Crear Proveedor', ?, ?)");
            $detalle = "Creó el proveedor: $nombre";
            $stmt_audit->bind_param("iss", $usuario_id, $detalle, $ip);
            $stmt_audit->execute();
        } else {
            $mensaje = "Error al crear el proveedor.";
            $tipo_mensaje = "danger";
        }
        $tab_activo = 'proveedores';
    }
    
    // Editar proveedor
    elseif ($action === 'editar_proveedor') {
        $id_proveedor = intval($_POST['id_proveedor'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $activo = intval($_POST['activo'] ?? 1);
        $calle = trim($_POST['calle'] ?? '');
        $numero = trim($_POST['numero'] ?? '');
        $colonia = trim($_POST['colonia'] ?? '');
        $ciudad = trim($_POST['ciudad'] ?? '');
        $estado = trim($_POST['estado'] ?? '');
        $codigo_postal = trim($_POST['codigo_postal'] ?? '');
        
        $stmt = $conn->prepare("UPDATE proveedores SET nombre = ?, correo = ?, telefono = ?, activo = ?, calle = ?, numero = ?, colonia = ?, ciudad = ?, estado = ?, codigo_postal = ? WHERE id = ?");
        $stmt->bind_param("sssissssssi", $nombre, $correo, $telefono, $activo, $calle, $numero, $colonia, $ciudad, $estado, $codigo_postal, $id_proveedor);
        
        if ($stmt->execute()) {
            $mensaje = "Proveedor actualizado correctamente.";
            $tipo_mensaje = "success";
            
            $stmt_audit = $conn->prepare("INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, 'Editar Proveedor', ?, ?)");
            $detalle = "Editó el proveedor ID: $id_proveedor";
            $stmt_audit->bind_param("iss", $usuario_id, $detalle, $ip);
            $stmt_audit->execute();
        } else {
            $mensaje = "Error al actualizar el proveedor.";
            $tipo_mensaje = "danger";
        }
        $tab_activo = 'proveedores';
    }
    
    // Eliminar proveedor
    elseif ($action === 'eliminar_proveedor') {
        $id_proveedor = intval($_POST['id_proveedor'] ?? 0);
        
        $stmt = $conn->prepare("DELETE FROM proveedores WHERE id = ?");
        $stmt->bind_param("i", $id_proveedor);
        
        if ($stmt->execute()) {
            $mensaje = "Proveedor eliminado correctamente.";
            $tipo_mensaje = "success";
            
            $stmt_audit = $conn->prepare("INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, 'Eliminar Proveedor', ?, ?)");
            $detalle = "Eliminó el proveedor ID: $id_proveedor";
            $stmt_audit->bind_param("iss", $usuario_id, $detalle, $ip);
            $stmt_audit->execute();
        } else {
            $mensaje = "Error al eliminar el proveedor.";
            $tipo_mensaje = "danger";
        }
        $tab_activo = 'proveedores';
    }
    
    // Crear respaldo de BD (VERSIÓN PHP PURA)
    elseif ($action === 'backup_db') {
        $resultado = backupDatabase($conn);
        
        if ($resultado['success']) {
            $mensaje = "Respaldo creado correctamente: " . $resultado['filename'];
            $tipo_mensaje = "success";
            
            $stmt_audit = $conn->prepare("INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, 'Respaldo BD', ?, ?)");
            $detalle = "Creó un respaldo de la base de datos: " . $resultado['filename'];
            $stmt_audit->bind_param("iss", $usuario_id, $detalle, $ip);
            $stmt_audit->execute();
        } else {
            $mensaje = "Error al crear el respaldo: " . ($resultado['error'] ?? 'Error desconocido');
            $tipo_mensaje = "danger";
        }
        $tab_activo = 'backup';
    }
    
    // Limpiar auditoría antigua
    elseif ($action === 'limpiar_auditoria') {
        $stmt = $conn->prepare("DELETE FROM auditoria WHERE fecha < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        $stmt->execute();
        
        $eliminados = $stmt->affected_rows;
        $mensaje = "Se eliminaron $eliminados registros de auditoría antiguos (más de 90 días).";
        $tipo_mensaje = "success";
        
        $stmt_audit = $conn->prepare("INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, 'Limpiar Auditoría', ?, ?)");
        $detalle = "Limpió registros de auditoría antiguos. Eliminados: $eliminados";
        $stmt_audit->bind_param("iss", $usuario_id, $detalle, $ip);
        $stmt_audit->execute();
        $tab_activo = 'auditoria';
    }
}

// ==================== OBTENER DATOS PARA VISTAS ====================

// Configuración General
$result = $conn->query("SELECT * FROM configuracion_galeria WHERE id = 1");
$config_general = $result->fetch_assoc();
if (!$config_general) {
    $conn->query("INSERT INTO configuracion_galeria (id, nombre) VALUES (1, 'Pescadores de la Prehistoria') ON DUPLICATE KEY UPDATE nombre = nombre");
    $result = $conn->query("SELECT * FROM configuracion_galeria WHERE id = 1");
    $config_general = $result->fetch_assoc();
}
$logo_path = $config_general['logo'] ?? '';

// Configuración Correo
$result = $conn->query("SELECT * FROM configuracion_correo WHERE id = 1");
$config_correo = $result->fetch_assoc();
if (!$config_correo) {
    $conn->query("INSERT INTO configuracion_correo (id, smtp_host, smtp_port, smtp_secure, nombre_origen) VALUES (1, 'smtp.gmail.com', 587, 'tls', 'Tienda Pescadores')");
    $result = $conn->query("SELECT * FROM configuracion_correo WHERE id = 1");
    $config_correo = $result->fetch_assoc();
}

// Paginación para Usuarios
$pagina_usuarios = isset($_GET['pagina_usuarios']) ? max(1, intval($_GET['pagina_usuarios'])) : 1;
$por_pagina = 10;
$offset_usuarios = ($pagina_usuarios - 1) * $por_pagina;

$total_usuarios_result = $conn->query("SELECT COUNT(*) as total FROM usuarios");
$total_usuarios = $total_usuarios_result->fetch_assoc()['total'];

$stmt_usuarios = $conn->prepare("SELECT * FROM usuarios ORDER BY id DESC LIMIT ? OFFSET ?");
$stmt_usuarios->bind_param("ii", $por_pagina, $offset_usuarios);
$stmt_usuarios->execute();
$usuarios = $stmt_usuarios->get_result();

// Paginación para Proveedores
$pagina_proveedores = isset($_GET['pagina_proveedores']) ? max(1, intval($_GET['pagina_proveedores'])) : 1;
$offset_proveedores = ($pagina_proveedores - 1) * $por_pagina;

$total_proveedores_result = $conn->query("SELECT COUNT(*) as total FROM proveedores");
$total_proveedores = $total_proveedores_result->fetch_assoc()['total'];

$stmt_proveedores = $conn->prepare("SELECT * FROM proveedores ORDER BY id DESC LIMIT ? OFFSET ?");
$stmt_proveedores->bind_param("ii", $por_pagina, $offset_proveedores);
$stmt_proveedores->execute();
$proveedores = $stmt_proveedores->get_result();

// Lista de respaldos
$backups = [];
$backup_dir = 'backups/';
if (is_dir($backup_dir)) {
    $files = scandir($backup_dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            $filepath = $backup_dir . $file;
            $backups[] = [
                'nombre' => $file,
                'fecha' => date('d/m/Y H:i:s', filemtime($filepath)),
                'tamaño' => round(filesize($filepath) / 1024, 2) . ' KB'
            ];
        }
    }
    rsort($backups);
}

// Paginación para Auditoría
$pagina_auditoria = isset($_GET['pagina_auditoria']) ? max(1, intval($_GET['pagina_auditoria'])) : 1;
$offset_auditoria = ($pagina_auditoria - 1) * $por_pagina;

$total_auditoria_result = $conn->query("SELECT COUNT(*) as total FROM auditoria");
$total_auditoria = $total_auditoria_result->fetch_assoc()['total'];

$stmt_auditoria = $conn->prepare("
    SELECT a.*, u.nombre as usuario_nombre 
    FROM auditoria a 
    LEFT JOIN usuarios u ON a.usuario_id = u.id 
    ORDER BY a.fecha DESC 
    LIMIT ? OFFSET ?
");
$stmt_auditoria->bind_param("ii", $por_pagina, $offset_auditoria);
$stmt_auditoria->execute();
$auditoria = $stmt_auditoria->get_result();

// Función para generar paginación
function generarPaginacion($total, $por_pagina, $pagina_actual, $parametro, $tab_actual) {
    $total_paginas = ceil($total / $por_pagina);
    if ($total_paginas <= 1) return '';
    
    $html = '<nav><ul class="pagination justify-content-center">';
    
    if ($pagina_actual > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="?tab='.$tab_actual.'&'.$parametro.'='.($pagina_actual-1).'">Anterior</a></li>';
    }
    
    $inicio = max(1, $pagina_actual - 2);
    $fin = min($total_paginas, $pagina_actual + 2);
    
    if ($inicio > 1) $html .= '<li class="page-item"><a class="page-link" href="?tab='.$tab_actual.'&'.$parametro.'=1">1</a></li><li class="page-item disabled"><span class="page-link">...</span></li>';
    
    for ($i = $inicio; $i <= $fin; $i++) {
        $active = ($i == $pagina_actual) ? 'active' : '';
        $html .= '<li class="page-item '.$active.'"><a class="page-link" href="?tab='.$tab_actual.'&'.$parametro.'='.$i.'">'.$i.'</a></li>';
    }
    
    if ($fin < $total_paginas) $html .= '<li class="page-item disabled"><span class="page-link">...</span></li><li class="page-item"><a class="page-link" href="?tab='.$tab_actual.'&'.$parametro.'='.$total_paginas.'">'.$total_paginas.'</a></li>';
    
    if ($pagina_actual < $total_paginas) {
        $html .= '<li class="page-item"><a class="page-link" href="?tab='.$tab_actual.'&'.$parametro.'='.($pagina_actual+1).'">Siguiente</a></li>';
    }
    
    $html .= '</ul></nav>';
    return $html;
}

// ==================== FECHA DE ACTUALIZACIÓN ====================
$ultima_actualizacion = "Abril 2026"; // Valor por defecto

// Intentar obtener fecha del último commit desde GitHub
function obtenerFechaCommit() {
    $api_url = "https://api.github.com/repos/Jesus3012/TiendaPescadores/commits?per_page=1";
    
    // Intentar con cURL
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PHP');
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200 && $response) {
            $commits = json_decode($response, true);
            if (!empty($commits) && isset($commits[0]['commit']['committer']['date'])) {
                $fecha = strtotime($commits[0]['commit']['committer']['date']);
                date_default_timezone_set('America/Mexico_City');
                return date('d/m/Y H:i:s', $fecha);
            }
        }
    }
    
    // Si cURL falla, intentar con file_get_contents
    if (ini_get('allow_url_fopen')) {
        $opts = ['http' => ['method' => 'GET', 'header' => 'User-Agent: PHP']];
        $context = stream_context_create($opts);
        $response = @file_get_contents($api_url, false, $context);
        if ($response) {
            $commits = json_decode($response, true);
            if (!empty($commits) && isset($commits[0]['commit']['committer']['date'])) {
                $fecha = strtotime($commits[0]['commit']['committer']['date']);
                date_default_timezone_set('America/Mexico_City');
                return date('d/m/Y H:i:s', $fecha);
            }
        }
    }
    
    return false;
}

// Ejecutar la función y asignar valor
$fecha_obtenida = obtenerFechaCommit();
if ($fecha_obtenida) {
    $ultima_actualizacion = $fecha_obtenida;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Configuración - Pescadores de la Prehistoria</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        /* =====================================================
           ESTILOS NARANJA - MÓDULO DE CONFIGURACIÓN
        ===================================================== */
        :root {
            --primary: #f97316;
            --primary-dark: #ea580c;
            --primary-light: #ffedd5;
            --success: #22c55e;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
        }

        .content-wrapper {
            min-height: 100vh;
            padding: 24px;
            background: linear-gradient(135deg, #fef9f1 0%, #f8fafc 100%);
        }

        .content-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
        }
        .content-header h1 i { color: #f97316; }

        .card {
            border-radius: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border: 1px solid #eef2f6;
            margin-bottom: 24px;
        }
        .card-header {
            background: white;
            border-bottom: 2px solid #f97316;
            padding: 1rem 1.5rem;
            border-radius: 20px 20px 0 0;
        }
        .card-header .card-title {
            font-weight: 700;
            color: #1e293b;
            font-size: 1.1rem;
        }
        .card-header .card-title i { color: #f97316; }
        .card-body { padding: 1.5rem; }

        .form-group { margin-bottom: 1rem; }
        .form-group label {
            font-weight: 600;
            font-size: 0.75rem;
            color: #475569;
            margin-bottom: 0.3rem;
        }
        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            padding: 0.5rem 0.8rem;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
            outline: none;
        }

        .btn-primary { background: #f97316; border-color: #f97316; border-radius: 10px; }
        .btn-primary:hover { background: #ea580c; transform: translateY(-1px); }
        .btn-success { background: #22c55e; border-color: #22c55e; border-radius: 10px; }
        .btn-danger { background: #ef4444; border-color: #ef4444; border-radius: 10px; }
        .btn-warning { background: #f59e0b; border-color: #f59e0b; color: white; border-radius: 10px; }
        .btn-secondary { background: #64748b; border-color: #64748b; border-radius: 10px; }
        .btn-info { background: #3b82f6; border-color: #3b82f6; color: white; border-radius: 10px; }
        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.75rem; }

        .acciones-btns {
            display: flex;
            gap: 5px;
            flex-wrap: nowrap;
            justify-content: flex-start;
        }
        .acciones-btns .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            white-space: nowrap;
        }

        .table {
            margin-bottom: 0;
            background: white;
        }
        .table thead th {
            background: #f97316;
            color: white;
            font-weight: 600;
            padding: 12px 16px;
            border-bottom: none;
            font-size: 0.85rem;
        }
        .table tbody td {
            padding: 10px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #eef2f6;
            font-size: 0.85rem;
        }
        .table tbody tr:hover td { background-color: #fef9f1; }

        .badge-success { background: #22c55e; color: white; }
        .badge-danger { background: #ef4444; color: white; }
        .badge-warning { background: #f59e0b; color: white; }
        .badge-info { background: #3b82f6; color: white; }
        .badge-secondary { background: #64748b; color: white; }

        .logo-preview {
            max-width: 120px;
            max-height: 80px;
            border-radius: 10px;
            border: 2px solid #e2e8f0;
            padding: 5px;
            background: white;
            object-fit: contain;
        }

        .nav-tabs {
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            list-style: none;
            padding-left: 0;
            background: white;
            border-radius: 16px 16px 0 0;
            padding: 0.5rem 1rem 0 1rem;
        }
        .nav-tabs .nav-item {
            margin-bottom: -2px;
        }
        .nav-tabs .nav-link {
            display: block;
            padding: 0.75rem 1.5rem;
            color: #64748b;
            font-weight: 600;
            border: none;
            background: transparent;
            border-radius: 12px 12px 0 0;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
        }
        .nav-tabs .nav-link:hover {
            color: #f97316;
            background: rgba(249, 115, 22, 0.1);
        }
        .nav-tabs .nav-link.active {
            color: #f97316;
            background: white;
            border-bottom: 3px solid #f97316;
        }
        .nav-tabs .nav-link i {
            margin-right: 8px;
        }

        .tab-content {
            margin-top: 0;
        }
        .tab-pane {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        .tab-pane.active {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .acerca-card {
            background: linear-gradient(135deg, #fff 0%, #fef9f1 100%);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            margin-top: 20px;
        }
        .acerca-logo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 20px;
            border: 4px solid #f97316;
            padding: 5px;
            background: white;
        }
        .acerca-titulo {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 10px;
        }
        .acerca-subtitulo {
            color: #f97316;
            font-size: 1rem;
            margin-bottom: 20px;
        }
        .acerca-info {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .acerca-info-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #eef2f6;
        }
        .acerca-info-item:last-child { border-bottom: none; }
        .acerca-info-label { font-weight: 600; color: #475569; }
        .acerca-info-value { color: #1e293b; }

        .image-preview-container {
            margin-top: 10px;
        }
        #logoPreview {
            max-width: 150px;
            max-height: 100px;
            border-radius: 10px;
            border: 2px solid #f97316;
            padding: 5px;
            background: #fef9f1;
            display: none;
        }

        .card-header-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .btn-header {
            margin-left: auto;
        }

        .pagination {
            margin-top: 1rem;
            margin-bottom: 0;
        }
        .page-link {
            color: #f97316;
            border-radius: 8px;
            margin: 0 2px;
        }
        .page-item.active .page-link {
            background-color: #f97316;
            border-color: #f97316;
        }
        .page-link:hover {
            color: #ea580c;
        }

        .table-responsive {
            overflow-x: auto;
        }

        @media (max-width: 768px) {
            .content-wrapper { padding: 16px; }
            .nav-tabs .nav-link { padding: 0.5rem 1rem; font-size: 0.85rem; }
            .table td, .table th { font-size: 0.7rem; padding: 8px; }
            .card-header-flex { flex-direction: column; align-items: stretch; }
            .btn-header { margin-left: 0; width: 100%; }
            .acciones-btns { flex-wrap: wrap; }
        }

        /* ==================== PROTEGER SIDEBAR DE ESTILOS EXTERNOS ==================== */
        /* Evita que Bootstrap u otros estilos pinten el sidebar de azul */
        .sidebar-custom .nav-link,
        .sidebar-custom .nav-link:hover,
        .sidebar-custom .nav-link:focus,
        .sidebar-custom .nav-link.active,
        .nav-links a,
        .nav-links a:hover,
        .nav-links a:focus,
        .submenu-toggle,
        .submenu-toggle:hover,
        .submenu-items a,
        .submenu-items a:hover {
            color: white !important;
            background: transparent !important;
            background-color: transparent !important;
        }
    </style>
</head>
<body>
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-12">
                    <h1><i class="fas fa-cog mr-2"></i> Panel de Configuración</h1>
                    <small class="text-muted">Administra todos los aspectos del sistema</small>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <!-- Tabs de navegación -->
            <ul class="nav nav-tabs" id="configTabs" role="tablist">
                <li class="nav-item"><button class="nav-link <?= $tab_activo == 'general' ? 'active' : '' ?>" data-tab="general"><i class="fas fa-store"></i> General</button></li>
                <li class="nav-item"><button class="nav-link <?= $tab_activo == 'correo' ? 'active' : '' ?>" data-tab="correo"><i class="fas fa-envelope"></i> Correo</button></li>
                <li class="nav-item"><button class="nav-link <?= $tab_activo == 'usuarios' ? 'active' : '' ?>" data-tab="usuarios"><i class="fas fa-users"></i> Usuarios</button></li>
                <li class="nav-item"><button class="nav-link <?= $tab_activo == 'proveedores' ? 'active' : '' ?>" data-tab="proveedores"><i class="fas fa-truck"></i> Proveedores</button></li>
                <li class="nav-item"><button class="nav-link <?= $tab_activo == 'backup' ? 'active' : '' ?>" data-tab="backup"><i class="fas fa-database"></i> Respaldos</button></li>
                <li class="nav-item"><button class="nav-link <?= $tab_activo == 'auditoria' ? 'active' : '' ?>" data-tab="auditoria"><i class="fas fa-history"></i> Auditoría</button></li>
            </ul>

            <div class="tab-content">
                <form method="POST" id="tabForm">
                    <input type="hidden" name="tab_activo" id="tab_activo_input" value="<?= $tab_activo ?>">
                </form>
                
                <!-- TAB GENERAL -->
                <div class="tab-pane <?= $tab_activo == 'general' ? 'active' : '' ?>" id="tab-general" data-tab-content="general">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-store mr-2"></i> Información de la Tienda</h3>
                        </div>
                        <form method="POST" enctype="multipart/form-data" id="formGeneral">
                            <input type="hidden" name="tab_activo" value="general">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Nombre de la tienda</label>
                                            <input type="text" class="form-control" name="nombre" value="<?= htmlspecialchars($config_general['nombre'] ?? 'Pescadores de la Prehistoria') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Teléfono</label>
                                            <input type="text" class="form-control" name="telefono" value="<?= htmlspecialchars($config_general['telefono'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Correo electrónico</label>
                                            <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($config_general['email'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Horario de atención</label>
                                            <input type="text" class="form-control" name="horario" value="<?= htmlspecialchars($config_general['horario'] ?? 'Lunes a Domingo 10:00 - 20:00') ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Dirección</label>
                                    <textarea class="form-control" name="direccion" rows="3"><?= htmlspecialchars($config_general['direccion'] ?? '') ?></textarea>
                                </div>
                                
                                <!-- Sección de Logo mejorada -->
<!-- Sección de Logo y Dashboard mejorada -->
<div class="form-group">
    <label class="font-weight-bold mb-3">Imágenes de la tienda</label>
    <div class="row">
        <!-- Logo de la tienda -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body text-center">
                    <h6 class="card-title mb-3"><i class="fas fa-image mr-2"></i> Logo de la tienda</h6>
                    <div class="border rounded p-3 bg-light mb-3" style="min-height: 150px; display: flex; align-items: center; justify-content: center;">
                        <?php if ($logo_path && file_exists($logo_path)): ?>
                            <img src="<?= $logo_path ?>?v=<?= time() ?>" class="img-fluid" style="max-height: 100px; max-width: 100%; object-fit: contain;" id="currentLogo">
                        <?php else: ?>
                            <div class="text-center" id="currentLogo">
                                <i class="fas fa-store fa-3x text-muted"></i>
                                <p class="small text-muted mt-1 mb-0">Sin logo</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="custom-file">
                        <input type="file" name="logo" accept="image/*" id="logoInput" class="custom-file-input" onchange="previewLogo(this, 'logoPreview', 'logoPreviewContainer')">
                        <label class="custom-file-label" for="logoInput">Seleccionar logo</label>
                        <small class="text-muted d-block mt-1">Formatos: JPG, PNG, GIF, WEBP</small>
                    </div>
                    <div id="logoPreviewContainer" style="display: none;" class="mt-3">
                        <label class="text-muted small">Vista previa:</label>
                        <img id="logoPreview" class="img-fluid border rounded p-1" style="max-height: 80px;">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Imagen del Dashboard Principal -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body text-center">
                    <h6 class="card-title mb-3"><i class="fas fa-tachometer-alt mr-2"></i> Imagen del Dashboard</h6>
                    <div class="border rounded p-3 bg-light mb-3" style="min-height: 150px; display: flex; align-items: center; justify-content: center;">
                        <?php 
                        $dashboard_img = $config_general['imagen_dashboard'] ?? '';
                        if ($dashboard_img && file_exists($dashboard_img)): ?>
                            <img src="<?= $dashboard_img ?>?v=<?= time() ?>" class="img-fluid" style="max-height: 100px; max-width: 100%; object-fit: contain;" id="currentDashboardImg">
                        <?php else: ?>
                            <div class="text-center" id="currentDashboardImg">
                                <i class="fas fa-chart-line fa-3x text-muted"></i>
                                <p class="small text-muted mt-1 mb-0">Imagen por defecto</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="custom-file">
                        <input type="file" name="imagen_dashboard" accept="image/*" id="dashboardInput" class="custom-file-input" onchange="previewLogo(this, 'dashboardPreview', 'dashboardPreviewContainer')">
                        <label class="custom-file-label" for="dashboardInput">Seleccionar imagen</label>
                        <small class="text-muted d-block mt-1">Formatos: JPG, PNG, GIF, WEBP (Tamaño recomendado: 1200x400px)</small>
                    </div>
                    <div id="dashboardPreviewContainer" style="display: none;" class="mt-3">
                        <label class="text-muted small">Vista previa:</label>
                        <img id="dashboardPreview" class="img-fluid border rounded p-1" style="max-height: 80px;">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
                            </div>
                            <div class="card-footer text-right">
                                <button type="submit" name="action" value="update_general" class="btn btn-primary">
                                    <i class="fas fa-save mr-1"></i> Guardar Cambios
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Acerca del Sistema -->
                    <div class="acerca-card">
                        <div class="text-center">
                            <div id="acercaLogoContainer">
                                <?php if ($logo_path && file_exists($logo_path)): ?>
                                    <img src="<?= $logo_path ?>?v=<?= time() ?>" class="acerca-logo" id="acercaLogo">
                                <?php else: ?>
                                    <div class="acerca-logo d-flex align-items-center justify-content-center mx-auto bg-light" id="acercaLogo">
                                        <i class="fas fa-fish fa-4x" style="color: #f97316;"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <h2 class="acerca-titulo"><?= htmlspecialchars($config_general['nombre'] ?? 'Pescadores de la Prehistoria') ?></h2>
                            <p class="acerca-subtitulo">Sistema de Gestión de Inventario y Ventas</p>
                        </div>
                        <div class="acerca-info">
    <h5 class="mb-3" style="color: #f97316;"><i class="fas fa-info-circle mr-2"></i>Información del Sistema</h5>
    <div class="acerca-info-item">
        <span class="acerca-info-label"><i class="fas fa-calendar-alt mr-2"></i>Última actualización:</span>
        <span class="acerca-info-value"><?= $ultima_actualizacion ?></span>
    </div>
    <div class="acerca-info-item">
        <span class="acerca-info-label"><i class="fas fa-user-cog mr-2"></i>Desarrollado por:</span>
        <span class="acerca-info-value">Jesus Martinez Vidal</span>
    </div>
    <div class="acerca-info-item">
        <span class="acerca-info-label"><i class="fas fa-envelope mr-2"></i>Contacto:</span>
        <span class="acerca-info-value">soportepescadores@gmail.com</span>
    </div>
    <div class="acerca-info-item">
        <span class="acerca-info-label"><i class="fas fa-phone-alt mr-2"></i>Teléfono:</span>
        <span class="acerca-info-value">+52 222 980 4687</span>
    </div>
</div>
                        <div class="alert alert-info mt-3 text-center">
                            <i class="fas fa-copyright mr-2"></i> 2026 Pescadores de la Prehistoria - Todos los derechos reservados.
                        </div>
                    </div>
                </div>

                <!-- TAB CORREO -->
                <div class="tab-pane <?= $tab_activo == 'correo' ? 'active' : '' ?>" id="tab-correo" data-tab-content="correo">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-envelope mr-2"></i> Configuración SMTP</h3>
                        </div>
                        <form method="POST" id="formCorreo">
                            <input type="hidden" name="tab_activo" value="correo">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6"><div class="form-group"><label>Servidor SMTP</label><input type="text" class="form-control" name="smtp_host" value="<?= htmlspecialchars($config_correo['smtp_host'] ?? 'smtp.gmail.com') ?>"></div></div>
                                    <div class="col-md-6"><div class="form-group"><label>Puerto SMTP</label><input type="number" class="form-control" name="smtp_port" value="<?= htmlspecialchars($config_correo['smtp_port'] ?? 587) ?>"></div></div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6"><div class="form-group"><label>Usuario SMTP</label><input type="text" class="form-control" name="smtp_usuario" value="<?= htmlspecialchars($config_correo['smtp_usuario'] ?? '') ?>"></div></div>
                                    <div class="col-md-6"><div class="form-group"><label>Contraseña SMTP</label><input type="password" class="form-control" name="smtp_password" placeholder="Dejar en blanco para mantener actual"><small class="text-muted">Dejar en blanco para mantener actual</small></div></div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6"><div class="form-group"><label>Seguridad</label><select class="form-control" name="smtp_secure"><option value="tls" <?= ($config_correo['smtp_secure'] ?? 'tls') == 'tls' ? 'selected' : '' ?>>TLS</option><option value="ssl" <?= ($config_correo['smtp_secure'] ?? '') == 'ssl' ? 'selected' : '' ?>>SSL</option></select></div></div>
                                    <div class="col-md-6"><div class="form-group"><label>Nombre de origen</label><input type="text" class="form-control" name="nombre_origen" value="<?= htmlspecialchars($config_correo['nombre_origen'] ?? 'Tienda Pescadores') ?>"></div></div>
                                </div>
                                <div class="form-group"><label>Correo de origen</label><input type="email" class="form-control" name="correo_origen" value="<?= htmlspecialchars($config_correo['correo_origen'] ?? '') ?>"></div>
                            </div>
                            <div class="card-footer text-right">
                                <button type="submit" name="action" value="update_email" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Guardar</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- TAB USUARIOS -->
                <div class="tab-pane <?= $tab_activo == 'usuarios' ? 'active' : '' ?>" id="tab-usuarios" data-tab-content="usuarios">
                    <div class="card">
                        <div class="card-header card-header-flex">
                            <h3 class="card-title"><i class="fas fa-users mr-2"></i> Gestión de Usuarios</h3>
                            <button class="btn btn-success btn-sm btn-header" onclick="abrirModalUsuario()">
                                <i class="fas fa-plus mr-1"></i> Nuevo Usuario
                            </button>
                        </div>
                        <div class="card-body table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr><th>Nombre</th><th>Email</th><th>Rol</th><th>Estado</th><th>Cambio Pwd</th><th>Fecha Registro</th><th>Acciones</th></tr>
                                </thead>
                                <tbody>
                                    <?php while ($u = $usuarios->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($u['nombre']) ?></td>
                                        <td><?= htmlspecialchars($u['email']) ?></td>
                                        <td><span class="badge <?= $u['rol'] == 'administrador' ? 'badge-danger' : 'badge-info' ?>"><?= $u['rol'] ?></span></td>
                                        <td><span class="badge <?= $u['activo'] ? 'badge-success' : 'badge-danger' ?>"><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
                                        <td><?= $u['debe_cambiar_password'] ? '<span class="badge badge-warning"><i class="fas fa-exclamation-triangle mr-1"></i> Pendiente</span>' : '<span class="badge badge-success"><i class="fas fa-check mr-1"></i> Actualizada</span>' ?></td>
                                        <td><?= date('d/m/Y', strtotime($u['fecha_registro'])) ?></td>
                                        <td>
                                            <div class="acciones-btns">
                                                <button class="btn btn-sm btn-primary" onclick='editarUsuario(<?= json_encode($u) ?>)' title="Editar"><i class="fas fa-edit"></i></button>
                                                <button class="btn btn-sm btn-warning" onclick='resetearPassword(<?= $u['id'] ?>, "<?= addslashes($u['nombre']) ?>")' title="Restablecer"><i class="fas fa-key"></i></button>
                                                <?php if ($u['id'] != $_SESSION['usuario_id']): ?>
                                                <button class="btn btn-sm btn-danger" onclick="eliminarUsuario(<?= $u['id'] ?>, '<?= addslashes($u['nombre']) ?>')" title="Eliminar"><i class="fas fa-trash"></i></button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                            <?= generarPaginacion($total_usuarios, $por_pagina, $pagina_usuarios, 'pagina_usuarios', 'usuarios') ?>
                        </div>
                    </div>
                </div>

                <!-- TAB PROVEEDORES -->
                <div class="tab-pane <?= $tab_activo == 'proveedores' ? 'active' : '' ?>" id="tab-proveedores" data-tab-content="proveedores">
                    <div class="card">
                        <div class="card-header card-header-flex">
                            <h3 class="card-title"><i class="fas fa-truck mr-2"></i> Gestión de Proveedores</h3>
                            <button class="btn btn-success btn-sm btn-header" onclick="abrirModalProveedor()">
                                <i class="fas fa-plus mr-1"></i> Nuevo Proveedor
                            </button>
                        </div>
                        <div class="card-body table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr><th>Nombre</th><th>Correo</th><th>Teléfono</th><th>Dirección</th><th>Estado</th><th>Acciones</th></tr>
                                </thead>
                                <tbody>
                                    <?php while ($p = $proveedores->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($p['nombre']) ?></td>
                                        <td><?= htmlspecialchars($p['correo'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($p['telefono'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($p['calle'] ?? '') ?> <?= htmlspecialchars($p['numero'] ?? '') ?>, <?= htmlspecialchars($p['colonia'] ?? '') ?></td>
                                        <td><span class="badge <?= $p['activo'] ? 'badge-success' : 'badge-danger' ?>"><?= $p['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
                                        <td>
                                            <div class="acciones-btns">
                                                <button class="btn btn-sm btn-primary" onclick='editarProveedor(<?= json_encode($p) ?>)' title="Editar"><i class="fas fa-edit"></i></button>
                                                <button class="btn btn-sm btn-danger" onclick="eliminarProveedor(<?= $p['id'] ?>, '<?= addslashes($p['nombre']) ?>')" title="Eliminar"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                            <?= generarPaginacion($total_proveedores, $por_pagina, $pagina_proveedores, 'pagina_proveedores', 'proveedores') ?>
                        </div>
                    </div>
                </div>

                <!-- TAB RESPALDOS -->
                <div class="tab-pane <?= $tab_activo == 'backup' ? 'active' : '' ?>" id="tab-backup" data-tab-content="backup">
                    <div class="card">
                        <div class="card-header"><h3 class="card-title"><i class="fas fa-database mr-2"></i> Respaldos de Base de Datos</h3></div>
                        <div class="card-body">
                            <div class="mb-4">
                                <button type="button" class="btn btn-success" id="btnBackup">
                                    <i class="fas fa-download mr-1"></i> Crear Nuevo Respaldo
                                </button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead><tr><th>Nombre del archivo</th><th>Fecha</th><th>Tamaño</th><th>Acciones</th></tr></thead>
                                    <tbody>
                                        <?php if (empty($backups)): ?>
                                        <tr><td colspan="4" class="text-center py-4"><i class="fas fa-folder-open fa-2x text-muted mb-2 d-block"></i>No hay respaldos disponibles</td></tr>
                                        <?php else: foreach ($backups as $b): ?>
                                        <tr><td><i class="fas fa-file-archive mr-2 text-warning"></i> <?= $b['nombre'] ?></td><td><?= $b['fecha'] ?></td><td><?= $b['tamaño'] ?></td><td><a href="backups/<?= $b['nombre'] ?>" class="btn btn-sm btn-primary" download><i class="fas fa-download"></i> Descargar</a></td></tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB AUDITORÍA -->
                <div class="tab-pane <?= $tab_activo == 'auditoria' ? 'active' : '' ?>" id="tab-auditoria" data-tab-content="auditoria">
                    <div class="card">
                        <div class="card-header">
                            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                                <h3 class="card-title" style="margin: 0;"><i class="fas fa-history mr-2"></i> Registro de Auditoría</h3>
                                <form method="POST" id="formLimpiarAuditoria" style="margin: 0;">
                                    <input type="hidden" name="tab_activo" value="auditoria">
                                    <button type="submit" name="action" value="limpiar_auditoria" class="btn btn-warning btn-sm"><i class="fas fa-trash-alt mr-1"></i> Limpiar Antiguos</button>
                                </form>
                            </div>
                        </div>
                        <div class="card-body table-responsive">
                            <?php if ($auditoria->num_rows > 0): ?>
                                <table class="table table-hover">
                                    <thead>
                                        <tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Detalle</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($a = $auditoria->fetch_assoc()): ?>
                                        <tr>
                                            <td><?= date('d/m/Y H:i:s', strtotime($a['fecha'])) ?></td>
                                            <td><?= htmlspecialchars($a['usuario_nombre'] ?? 'Sistema') ?></td>
                                            <td><span class="badge badge-info"><?= htmlspecialchars($a['accion']) ?></span></td>
                                            <td><?= htmlspecialchars($a['detalle'] ?? '-') ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                                <?= generarPaginacion($total_auditoria, $por_pagina, $pagina_auditoria, 'pagina_auditoria', 'auditoria') ?>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-clipboard-list fa-4x text-muted mb-3 d-block"></i>
                                    <h5 class="text-muted">No hay registros de auditoría</h5>
                                    <p class="text-muted small">Los eventos del sistema se mostrarán aquí cuando ocurran.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Usuario -->
<div class="modal fade" id="modalUsuario" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="POST" id="formUsuario">
        <input type="hidden" name="tab_activo" value="usuarios">
        <div class="modal-header" style="border-bottom: 2px solid #f97316;"><h5 class="modal-title"><i class="fas fa-user mr-2"></i><span id="modalUsuarioTitulo">Nuevo Usuario</span></h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
        <div class="modal-body">
            <input type="hidden" name="action" id="usuarioAction" value="crear_usuario">
            <input type="hidden" name="id_usuario" id="id_usuario">
            <div class="form-group"><label>Nombre completo</label><input type="text" class="form-control" name="nombre" id="usuario_nombre" required></div>
            <div class="form-group"><label>Correo electrónico</label><input type="email" class="form-control" name="email" id="usuario_email" required></div>
            <div class="form-group"><label>Contraseña</label><input type="password" class="form-control" name="password" id="usuario_password" placeholder="Dejar en blanco"><small class="text-muted" id="passwordHelp">Contraseña por defecto: <strong>Pescadores1</strong></small></div>
            <div class="form-group"><label>Rol</label><select class="form-control" name="rol" id="usuario_rol"><option value="vendedor">Vendedor</option><option value="administrador">Administrador</option></select></div>
            <div class="form-group"><label>Estado</label><select class="form-control" name="activo" id="usuario_activo"><option value="1">Activo</option><option value="0">Inactivo</option></select></div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary" id="btnGuardarUsuario"><i class="fas fa-save mr-1"></i> Guardar</button><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button></div>
    </form>
</div></div></div>

<!-- Modal Proveedor -->
<div class="modal fade" id="modalProveedor" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST" id="formProveedor">
        <input type="hidden" name="tab_activo" value="proveedores">
        <div class="modal-header" style="border-bottom: 2px solid #f97316;"><h5 class="modal-title"><i class="fas fa-truck mr-2"></i><span id="modalProveedorTitulo">Nuevo Proveedor</span></h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
        <div class="modal-body">
            <input type="hidden" name="action" id="proveedorAction" value="crear_proveedor">
            <input type="hidden" name="id_proveedor" id="id_proveedor">
            <div class="row"><div class="col-md-6"><div class="form-group"><label>Nombre</label><input type="text" class="form-control" name="nombre" id="proveedor_nombre" required></div></div><div class="col-md-6"><div class="form-group"><label>Correo</label><input type="email" class="form-control" name="correo" id="proveedor_correo"></div></div></div>
            <div class="row"><div class="col-md-6"><div class="form-group"><label>Teléfono</label><input type="text" class="form-control" name="telefono" id="proveedor_telefono"></div></div><div class="col-md-6"><div class="form-group"><label>Estado</label><select class="form-control" name="activo" id="proveedor_activo"><option value="1">Activo</option><option value="0">Inactivo</option></select></div></div></div>
            <div class="row"><div class="col-md-6"><div class="form-group"><label>Calle</label><input type="text" class="form-control" name="calle" id="proveedor_calle"></div></div><div class="col-md-2"><div class="form-group"><label>Número</label><input type="text" class="form-control" name="numero" id="proveedor_numero"></div></div><div class="col-md-4"><div class="form-group"><label>Colonia</label><input type="text" class="form-control" name="colonia" id="proveedor_colonia"></div></div></div>
            <div class="row"><div class="col-md-4"><div class="form-group"><label>Ciudad</label><input type="text" class="form-control" name="ciudad" id="proveedor_ciudad"></div></div><div class="col-md-4"><div class="form-group"><label>Estado (ubicación)</label><input type="text" class="form-control" name="estado" id="proveedor_estado"></div></div><div class="col-md-4"><div class="form-group"><label>Código Postal</label><input type="text" class="form-control" name="codigo_postal" id="proveedor_codigo_postal"></div></div></div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary" id="btnGuardarProveedor"><i class="fas fa-save mr-1"></i> Guardar</button><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button></div>
    </form>
</div></div></div>

<script>
// ==================== FUNCIÓN GENÉRICA PARA ENVIAR FORMULARIOS CON FETCH ====================
async function enviarFormularioFetch(form, actionValue) {
    const formData = new FormData(form);
    formData.append('action', actionValue);
    
    // Si el formulario tiene enctype multipart, usamos FormData directamente
    const isMultipart = form.getAttribute('enctype') === 'multipart/form-data';
    
    let body;
    let headers = {};
    
    if (isMultipart) {
        body = formData;
    } else {
        headers['Content-Type'] = 'application/x-www-form-urlencoded';
        body = new URLSearchParams(formData).toString();
    }
    
    const response = await fetch(window.location.href, {
        method: 'POST',
        headers: headers,
        body: body
    });
    
    return response;
}

// ==================== FORMULARIO GENERAL ====================
const formGeneral = document.getElementById('formGeneral');
if (formGeneral) {
    formGeneral.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const result = await Swal.fire({
            title: '¿Guardar cambios?',
            text: 'Se actualizará la configuración general.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Sí, guardar',
            cancelButtonText: 'Cancelar'
        });
        
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Guardando...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            try {
                await enviarFormularioFetch(formGeneral, 'update_general');
                Swal.fire({
                    title: '¡Éxito!',
                    text: 'Configuración general actualizada correctamente.',
                    icon: 'success',
                    confirmButtonColor: '#f97316'
                }).then(() => {
                    window.location.href = window.location.pathname + '?tab=general';
                });
            } catch (error) {
                Swal.fire({
                    title: 'Error',
                    text: 'No se pudo guardar la configuración.',
                    icon: 'error',
                    confirmButtonColor: '#f97316'
                });
            }
        }
    });
}

// ==================== FORMULARIO CORREO ====================
const formCorreo = document.getElementById('formCorreo');
if (formCorreo) {
    formCorreo.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const result = await Swal.fire({
            title: '¿Guardar configuración de correo?',
            text: 'Se actualizarán los datos SMTP.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Sí, guardar',
            cancelButtonText: 'Cancelar'
        });
        
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Guardando...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            try {
                await enviarFormularioFetch(formCorreo, 'update_email');
                Swal.fire({
                    title: '¡Éxito!',
                    text: 'Configuración de correo actualizada correctamente.',
                    icon: 'success',
                    confirmButtonColor: '#f97316'
                }).then(() => {
                    window.location.href = window.location.pathname + '?tab=correo';
                });
            } catch (error) {
                Swal.fire({
                    title: 'Error',
                    text: 'No se pudo guardar la configuración.',
                    icon: 'error',
                    confirmButtonColor: '#f97316'
                });
            }
        }
    });
}

// ==================== FORMULARIO USUARIO (MODAL) ====================
const formUsuario = document.getElementById('formUsuario');
if (formUsuario) {
    formUsuario.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const action = document.getElementById('usuarioAction').value;
        const esNuevo = action === 'crear_usuario';
        
        const result = await Swal.fire({
            title: esNuevo ? '¿Crear usuario?' : '¿Editar usuario?',
            text: esNuevo ? 'Se creará un nuevo usuario con contraseña por defecto.' : 'Se actualizarán los datos del usuario.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Sí, guardar',
            cancelButtonText: 'Cancelar'
        });
        
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Guardando...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            try {
                await enviarFormularioFetch(formUsuario, action);
                $('#modalUsuario').modal('hide');
                Swal.fire({
                    title: '¡Éxito!',
                    text: esNuevo ? 'Usuario creado correctamente.' : 'Usuario actualizado correctamente.',
                    icon: 'success',
                    confirmButtonColor: '#f97316'
                }).then(() => {
                    window.location.href = window.location.pathname + '?tab=usuarios';
                });
            } catch (error) {
                Swal.fire({
                    title: 'Error',
                    text: 'No se pudo guardar el usuario.',
                    icon: 'error',
                    confirmButtonColor: '#f97316'
                });
            }
        }
    });
}

// ==================== FORMULARIO PROVEEDOR (MODAL) ====================
const formProveedor = document.getElementById('formProveedor');
if (formProveedor) {
    formProveedor.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const action = document.getElementById('proveedorAction').value;
        const esNuevo = action === 'crear_proveedor';
        
        const result = await Swal.fire({
            title: esNuevo ? '¿Crear proveedor?' : '¿Editar proveedor?',
            text: 'Se guardarán los datos del proveedor.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Sí, guardar',
            cancelButtonText: 'Cancelar'
        });
        
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Guardando...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            try {
                await enviarFormularioFetch(formProveedor, action);
                $('#modalProveedor').modal('hide');
                Swal.fire({
                    title: '¡Éxito!',
                    text: esNuevo ? 'Proveedor creado correctamente.' : 'Proveedor actualizado correctamente.',
                    icon: 'success',
                    confirmButtonColor: '#f97316'
                }).then(() => {
                    window.location.href = window.location.pathname + '?tab=proveedores';
                });
            } catch (error) {
                Swal.fire({
                    title: 'Error',
                    text: 'No se pudo guardar el proveedor.',
                    icon: 'error',
                    confirmButtonColor: '#f97316'
                });
            }
        }
    });
}

// ==================== FORMULARIO LIMPIAR AUDITORÍA ====================
const formLimpiarAuditoria = document.getElementById('formLimpiarAuditoria');
if (formLimpiarAuditoria) {
    formLimpiarAuditoria.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const result = await Swal.fire({
            title: '¿Limpiar auditoría antigua?',
            text: 'Se eliminarán registros de más de 90 días. Esta acción no se puede deshacer.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f59e0b',
            confirmButtonText: 'Sí, limpiar',
            cancelButtonText: 'Cancelar'
        });
        
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Limpiando...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            try {
                await enviarFormularioFetch(formLimpiarAuditoria, 'limpiar_auditoria');
                Swal.fire({
                    title: '¡Completado!',
                    text: 'Se eliminaron los registros antiguos de auditoría.',
                    icon: 'success',
                    confirmButtonColor: '#f97316'
                }).then(() => {
                    window.location.href = window.location.pathname + '?tab=auditoria';
                });
            } catch (error) {
                Swal.fire({
                    title: 'Error',
                    text: 'No se pudo limpiar la auditoría.',
                    icon: 'error',
                    confirmButtonColor: '#f97316'
                });
            }
        }
    });
}

// ==================== BOTÓN DE BACKUP ====================
const btnBackup = document.getElementById('btnBackup');
if (btnBackup) {
    btnBackup.addEventListener('click', async function(e) {
        e.preventDefault();
        
        const result = await Swal.fire({
            title: '¿Crear respaldo?',
            text: 'Se generará un respaldo completo de la base de datos.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#22c55e',
            confirmButtonText: 'Sí, crear',
            cancelButtonText: 'Cancelar'
        });
        
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Generando respaldo...',
                text: 'Por favor espera',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=backup_db&tab_activo=backup'
                });
                
                if (response.ok) {
                    Swal.fire({
                        title: '¡Respaldo creado!',
                        text: 'El respaldo se ha generado correctamente.',
                        icon: 'success',
                        confirmButtonColor: '#f97316'
                    }).then(() => {
                        window.location.href = window.location.pathname + '?tab=backup';
                    });
                }
            } catch (error) {
                Swal.fire({
                    title: 'Error',
                    text: 'Hubo un problema al crear el respaldo',
                    icon: 'error',
                    confirmButtonColor: '#f97316'
                });
            }
        }
    });
}

// ==================== FUNCIONES DE TABS ====================
document.querySelectorAll('[data-tab]').forEach(button => {
    button.addEventListener('click', function(e) {
        e.preventDefault();
        const tabId = this.getAttribute('data-tab');
        cambiarTab(tabId);
    });
});

function cambiarTab(tabId) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tabId);
    window.history.pushState({}, '', url);
    
    document.querySelectorAll('.nav-link').forEach(btn => btn.classList.remove('active'));
    document.querySelector(`[data-tab="${tabId}"]`).classList.add('active');
    
    document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
    document.getElementById(`tab-${tabId}`).classList.add('active');
    
    document.getElementById('tab_activo_input').value = tabId;
}

// ==================== FUNCIONES DE IMAGEN ====================
function previewLogo(input, previewId, containerId) {
    const previewContainer = document.getElementById(containerId);
    const previewImg = document.getElementById(previewId);
    const fileLabel = input.nextElementSibling;
    
    if (input.files && input.files[0]) {
        const fileName = input.files[0].name;
        fileLabel.textContent = fileName;
        
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewImg.style.display = 'block';
            if (previewContainer) previewContainer.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    } else {
        fileLabel.textContent = 'Seleccionar archivo';
        previewImg.style.display = 'none';
        if (previewContainer) previewContainer.style.display = 'none';
    }
}

// ==================== FUNCIONES USUARIOS ====================
function abrirModalUsuario() {
    document.getElementById('modalUsuarioTitulo').textContent = 'Nuevo Usuario';
    document.getElementById('usuarioAction').value = 'crear_usuario';
    document.getElementById('id_usuario').value = '';
    document.getElementById('usuario_nombre').value = '';
    document.getElementById('usuario_email').value = '';
    document.getElementById('usuario_password').value = '';
    document.getElementById('usuario_rol').value = 'vendedor';
    document.getElementById('usuario_activo').value = '1';
    document.getElementById('passwordHelp').innerHTML = 'Contraseña por defecto: <strong>Pescadores1</strong>';
    $('#modalUsuario').modal('show');
}

function editarUsuario(usuario) {
    document.getElementById('modalUsuarioTitulo').textContent = 'Editar Usuario';
    document.getElementById('usuarioAction').value = 'editar_usuario';
    document.getElementById('id_usuario').value = usuario.id;
    document.getElementById('usuario_nombre').value = usuario.nombre;
    document.getElementById('usuario_email').value = usuario.email;
    document.getElementById('usuario_password').value = '';
    document.getElementById('usuario_rol').value = usuario.rol;
    document.getElementById('usuario_activo').value = usuario.activo;
    document.getElementById('passwordHelp').innerHTML = 'Dejar en blanco para mantener la contraseña actual';
    $('#modalUsuario').modal('show');
}

function resetearPassword(id, nombre) {
    Swal.fire({
        title: '¿Restablecer contraseña?',
        html: `Usuario: <strong>${nombre}</strong><br>La contraseña se establecerá como: <strong>Pescadores1</strong>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f59e0b',
        confirmButtonText: 'Sí, restablecer',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            form.innerHTML = `
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id_usuario" value="${id}">
                <input type="hidden" name="tab_activo" value="usuarios">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function eliminarUsuario(id, nombre) {
    Swal.fire({
        title: '¿Eliminar usuario?',
        html: `Usuario: <strong>${nombre}</strong><br>Esta acción no se puede deshacer.`,
        icon: 'error',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            form.innerHTML = `
                <input type="hidden" name="action" value="eliminar_usuario">
                <input type="hidden" name="id_usuario" value="${id}">
                <input type="hidden" name="tab_activo" value="usuarios">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// ==================== FUNCIONES PROVEEDORES ====================
function abrirModalProveedor() {
    document.getElementById('modalProveedorTitulo').textContent = 'Nuevo Proveedor';
    document.getElementById('proveedorAction').value = 'crear_proveedor';
    document.getElementById('id_proveedor').value = '';
    document.getElementById('proveedor_nombre').value = '';
    document.getElementById('proveedor_correo').value = '';
    document.getElementById('proveedor_telefono').value = '';
    document.getElementById('proveedor_activo').value = '1';
    document.getElementById('proveedor_calle').value = '';
    document.getElementById('proveedor_numero').value = '';
    document.getElementById('proveedor_colonia').value = '';
    document.getElementById('proveedor_ciudad').value = '';
    document.getElementById('proveedor_estado').value = '';
    document.getElementById('proveedor_codigo_postal').value = '';
    $('#modalProveedor').modal('show');
}

function editarProveedor(proveedor) {
    document.getElementById('modalProveedorTitulo').textContent = 'Editar Proveedor';
    document.getElementById('proveedorAction').value = 'editar_proveedor';
    document.getElementById('id_proveedor').value = proveedor.id;
    document.getElementById('proveedor_nombre').value = proveedor.nombre;
    document.getElementById('proveedor_correo').value = proveedor.correo || '';
    document.getElementById('proveedor_telefono').value = proveedor.telefono || '';
    document.getElementById('proveedor_activo').value = proveedor.activo;
    document.getElementById('proveedor_calle').value = proveedor.calle || '';
    document.getElementById('proveedor_numero').value = proveedor.numero || '';
    document.getElementById('proveedor_colonia').value = proveedor.colonia || '';
    document.getElementById('proveedor_ciudad').value = proveedor.ciudad || '';
    document.getElementById('proveedor_estado').value = proveedor.estado || '';
    document.getElementById('proveedor_codigo_postal').value = proveedor.codigo_postal || '';
    $('#modalProveedor').modal('show');
}

function eliminarProveedor(id, nombre) {
    Swal.fire({
        title: '¿Eliminar proveedor?',
        html: `Proveedor: <strong>${nombre}</strong><br>Esta acción no se puede deshacer.`,
        icon: 'error',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            form.innerHTML = `
                <input type="hidden" name="action" value="eliminar_proveedor">
                <input type="hidden" name="id_proveedor" value="${id}">
                <input type="hidden" name="tab_activo" value="proveedores">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// ==================== ALERTAS CON SWEETALERT ====================
<?php if ($mensaje): ?>
Swal.fire({
    title: '<?= $tipo_mensaje === 'success' ? '¡Éxito!' : ($tipo_mensaje === 'danger' ? '¡Error!' : 'Información') ?>',
    html: '<?= addslashes($mensaje) ?>',
    icon: '<?= $tipo_mensaje === 'success' ? "success" : ($tipo_mensaje === "danger" ? "error" : "info") ?>',
    confirmButtonColor: '#f97316',
    confirmButtonText: 'Aceptar'
});
<?php endif; ?>

</script>
</body>
</html>