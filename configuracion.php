<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('America/Mexico_City');

require_once 'includes/auth_guard.php';
require_once __DIR__ . '/includes/configuracion_password.php';
require_once __DIR__ . '/includes/configuracion_sesion.php';

try {
    $password_temporal_actual = cfgPasswordObtener($conn);
    $password_temporal_longitud = cfgPasswordLongitudMinima($conn);
} catch (Throwable $e) {
    error_log(
        'Configuración de contraseña temporal: '
        . $e->getMessage()
    );

    $password_temporal_actual = 'Pescadores1';
    $password_temporal_longitud = 8;
}

// PHPMailer: instala con composer require phpmailer/phpmailer
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$rol_sesion = permisos_normalizar_rol($_SESSION['rol'] ?? '');
$es_super_administrador = ($rol_sesion === 'super_administrador');
$es_administrador = permisos_puede_gestionar($rol_sesion);

/**
 * Un administrador normal nunca puede consultar ni modificar una cuenta
 * con rol super_administrador, aunque intente enviar una petición manual.
 */
function obtenerRolUsuarioPorId($conn, $id_usuario) {
    $id_usuario = (int)$id_usuario;

    if ($id_usuario <= 0) {
        return null;
    }

    $stmt = $conn->prepare("SELECT rol FROM usuarios WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $id_usuario);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $fila['rol'] ?? null;
}

function administradorPuedeGestionarUsuario($conn, $id_usuario, $es_super_administrador) {
    if ($es_super_administrador) {
        return true;
    }

    return obtenerRolUsuarioPorId($conn, $id_usuario) !== 'super_administrador';
}

$mensaje = '';
$tipo_mensaje = '';
$tab_activo = isset($_POST['tab_activo']) ? $_POST['tab_activo'] : (isset($_GET['tab']) ? $_GET['tab'] : 'general');

// ==================== RESPUESTA AJAX PARA NO SALIR DE LA PÁGINA ====================
function esPeticionAjax() {
    return (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    );
}

function responderAjax($ok, $mensaje, $tipo_mensaje = 'success', $tab_activo = 'general', $extra = []) {
    if (esPeticionAjax()) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(array_merge([
            'ok' => $ok,
            'mensaje' => $mensaje,
            'tipo_mensaje' => $tipo_mensaje,
            'tab_activo' => $tab_activo
        ], $extra), JSON_UNESCAPED_UNICODE);

        exit;
    }
}


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


// ==================== FUNCIÓN PARA ENVIAR ACCESOS POR CORREO ====================
function enviarCorreoAccesoUsuario($conn, $nombre, $email, $rol, $password_default) {
    if (!class_exists(PHPMailer::class)) {
        return ['ok' => false, 'error' => 'PHPMailer no está instalado. Ejecuta: composer require phpmailer/phpmailer'];
    }

    $stmt_config = $conn->prepare("SELECT * FROM configuracion_correo WHERE id = 1 AND activo = 1 LIMIT 1");
    $stmt_config->execute();
    $config = $stmt_config->get_result()->fetch_assoc();

    if (!$config) {
        return ['ok' => false, 'error' => 'No existe configuración SMTP activa.'];
    }

    $stmt_tienda = $conn->prepare("SELECT nombre, telefono, email, direccion, horario, logo FROM configuracion_galeria WHERE id = 1 LIMIT 1");
    $stmt_tienda->execute();
    $tienda = $stmt_tienda->get_result()->fetch_assoc() ?: [];

    $nombreTienda = $tienda['nombre'] ?? 'Tienda Pescadores';
    $telefonoTienda = $tienda['telefono'] ?? '';
    $correoTienda = $tienda['email'] ?? ($config['correo_origen'] ?? '');
    $horarioTienda = $tienda['horario'] ?? '';
    $logoRuta = trim($tienda['logo'] ?? '');

    $nombreSeguro = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
    $emailSeguro = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $rolSeguro = htmlspecialchars(ucfirst($rol), ENT_QUOTES, 'UTF-8');
    $passwordSeguro = htmlspecialchars($password_default, ENT_QUOTES, 'UTF-8');
    $nombreTiendaSeguro = htmlspecialchars($nombreTienda, ENT_QUOTES, 'UTF-8');
    $telefonoSeguro = htmlspecialchars($telefonoTienda, ENT_QUOTES, 'UTF-8');
    $correoTiendaSeguro = htmlspecialchars($correoTienda, ENT_QUOTES, 'UTF-8');
    $horarioSeguro = htmlspecialchars($horarioTienda, ENT_QUOTES, 'UTF-8');

    /*
     * Logo automático:
     * 1) Si existe como archivo local, se incrusta en el correo con CID.
     * 2) Si no se puede incrustar, intenta usar una URL pública construida con el dominio actual.
     */
    $logoHtml = '';
    $logoLocalPath = '';

    if ($logoRuta !== '') {
        $logoRutaLimpia = ltrim($logoRuta, '/');
        $posibleRutaLocal = __DIR__ . '/' . $logoRutaLimpia;

        if (file_exists($posibleRutaLocal)) {
            $logoLocalPath = $posibleRutaLocal;
        } elseif (file_exists($logoRuta)) {
            $logoLocalPath = $logoRuta;
        }
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp_usuario'];
        $mail->Password = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port = (int)$config['smtp_port'];
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($config['correo_origen'], $config['nombre_origen']);
        $mail->addAddress($email, $nombre);
        $mail->isHTML(true);
        $mail->Subject = "Tus accesos a {$nombreTienda}";

        if ($logoLocalPath !== '') {
            $cidLogo = 'logo_tienda_' . md5($logoLocalPath . time());
            $mail->addEmbeddedImage($logoLocalPath, $cidLogo, basename($logoLocalPath));

            $logoHtml = "
                <div style='margin:0 auto 20px auto;text-align:center;'>
                    <img src='cid:{$cidLogo}'
                         alt='{$nombreTiendaSeguro}'
                         style='max-width:190px;max-height:95px;width:auto;height:auto;display:inline-block;background:#ffffff;padding:10px;border-radius:14px;box-shadow:0 8px 20px rgba(15,23,42,.16);'>
                </div>
            ";
        } elseif ($logoRuta !== '') {
            $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? '';

            if ($host !== '') {
                $logoUrl = $protocolo . $host . '/' . ltrim($logoRuta, '/');
                $logoUrlSeguro = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');

                $logoHtml = "
                    <div style='margin:0 auto 20px auto;text-align:center;'>
                        <img src='{$logoUrlSeguro}'
                             alt='{$nombreTiendaSeguro}'
                             style='max-width:190px;max-height:95px;width:auto;height:auto;display:inline-block;background:#ffffff;padding:10px;border-radius:14px;box-shadow:0 8px 20px rgba(15,23,42,.16);'>
                    </div>
                ";
            }
        }

        $mail->Body = "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        </head>
        <body style='margin:0;padding:0;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;color:#1f2937;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='background:#f8fafc;padding:30px 12px;'>
                <tr>
                    <td align='center'>
                        <table width='100%' cellpadding='0' cellspacing='0' style='max-width:640px;background:#ffffff;border-radius:22px;overflow:hidden;box-shadow:0 18px 45px rgba(15,23,42,.14);'>
                            <tr>
                                <td style='background:linear-gradient(135deg,#f97316,#fb923c);padding:40px 28px;text-align:center;color:#ffffff;'>
                                    {$logoHtml}

                                    <h1 style='margin:0;font-size:30px;font-weight:800;'>Bienvenido(a)</h1>
                                    <p style='margin:10px 0 0;font-size:15px;opacity:.95;'>Tu cuenta fue creada correctamente en <strong>{$nombreTiendaSeguro}</strong></p>
                                </td>
                            </tr>
                            <tr>
                                <td style='padding:34px 30px;'>
                                    <p style='font-size:16px;margin:0 0 12px;'>Hola <strong>{$nombreSeguro}</strong>,</p>
                                    <p style='font-size:15px;line-height:1.6;margin:0 0 22px;color:#475569;'>Ya puedes ingresar al sistema con los siguientes datos de acceso:</p>

                                    <table width='100%' cellpadding='0' cellspacing='0' style='background:#fff7ed;border:1px solid #fed7aa;border-radius:16px;padding:0;margin:0 0 22px;'>
                                        <tr>
                                            <td style='padding:20px;'>
                                                <p style='margin:0 0 12px;font-size:15px;'><strong>Correo:</strong><br><span style='color:#f97316;font-weight:700;'>{$emailSeguro}</span></p>
                                                <p style='margin:0 0 12px;font-size:15px;'><strong>Contraseña temporal:</strong><br><span style='display:inline-block;margin-top:6px;background:#111827;color:#ffffff;padding:9px 14px;border-radius:10px;font-weight:800;letter-spacing:.5px;'>{$passwordSeguro}</span></p>
                                                <p style='margin:0;font-size:15px;'><strong>Rol asignado:</strong><br>{$rolSeguro}</p>
                                            </td>
                                        </tr>
                                    </table>

                                    <div style='background:#eff6ff;border-left:5px solid #3b82f6;border-radius:12px;padding:15px 16px;margin-bottom:22px;'>
                                        <p style='margin:0;font-size:14px;line-height:1.5;color:#1e3a8a;'>Por seguridad, al iniciar sesión el sistema te solicitará cambiar esta contraseña temporal.</p>
                                    </div>

                                    <p style='font-size:13px;color:#64748b;line-height:1.6;margin:0;'>
                                        {$nombreTiendaSeguro}<br>
                                        " . (!empty($horarioSeguro) ? "Horario: {$horarioSeguro}" : "") . "
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>";

        $mail->AltBody = "Hola {$nombre}. Tu cuenta fue creada en {$nombreTienda}. Correo: {$email}. Contraseña temporal: {$password_default}. Rol: {$rol}. Deberás cambiar tu contraseña al iniciar sesión.";
        $mail->send();

        return ['ok' => true, 'error' => ''];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
    }
}

// ==================== PROCESAR ACCIONES ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $usuario_id = $_SESSION['usuario_id'];
    $ip = $_SERVER['REMOTE_ADDR'];

    // Actualizar duración y seguridad de la sesión
    if ($action === 'update_session') {
        if (!$es_administrador) {
            $mensaje = 'No tienes permiso para modificar la duración de sesión.';
            $tipo_mensaje = 'danger';
        } else {
            $resultado_sesion = cfgSesionGuardar(
                $conn,
                [
                    'inactividad_minutos' => $_POST['inactividad_minutos'] ?? 30,
                    'aviso_minutos' => $_POST['aviso_minutos'] ?? 2,
                    'duracion_maxima_horas' => $_POST['duracion_maxima_horas'] ?? 12,
                    'aviso_activo' => isset($_POST['aviso_activo']) ? 1 : 0,
                ],
                (int) $usuario_id
            );

            $mensaje = $resultado_sesion['mensaje'];
            $tipo_mensaje = $resultado_sesion['ok'] ? 'success' : 'danger';
        }

        $tab_activo = 'sesion';
    }

    // Actualizar contraseña temporal del portal
    elseif ($action === 'update_default_password') {
        $nueva_password = trim(
            (string) ($_POST['password_default_nueva'] ?? '')
        );

        $confirmar_password = trim(
            (string) ($_POST['password_default_confirmar'] ?? '')
        );

        if (!$es_super_administrador) {
            $mensaje = 'Solo el superadministrador puede cambiar '
                . 'la contraseña temporal del portal.';
            $tipo_mensaje = 'danger';
        } elseif ($nueva_password !== $confirmar_password) {
            $mensaje = 'Las contraseñas temporales no coinciden.';
            $tipo_mensaje = 'danger';
        } else {
            $validacion_password = cfgPasswordValidar(
                $nueva_password,
                $password_temporal_longitud
            );

            if (!$validacion_password['ok']) {
                $mensaje = $validacion_password['mensaje'];
                $tipo_mensaje = 'danger';
            } elseif (
                hash_equals(
                    $password_temporal_actual,
                    $nueva_password
                )
            ) {
                $mensaje = 'La nueva contraseña es igual '
                    . 'a la configuración actual.';
                $tipo_mensaje = 'warning';
            } else {
                try {
                    cfgPasswordGuardar(
                        $conn,
                        $nueva_password,
                        (int) $usuario_id,
                        $password_temporal_longitud
                    );

                    $password_temporal_actual = $nueva_password;

                    $mensaje = "
                        <div style='text-align:center; width:100%;'>
                            <strong>Contraseña temporal actualizada.</strong>
                        </div>
                    ";

                    $tipo_mensaje = 'success';

                    $stmt_audit = $conn->prepare("
                        INSERT INTO auditoria (
                            usuario_id,
                            accion,
                            detalle,
                            ip
                        )
                        VALUES (
                            ?,
                            'ACTUALIZAR_PASSWORD_TEMPORAL',
                            'Actualizó la contraseña temporal del portal',
                            ?
                        )
                    ");

                    if ($stmt_audit) {
                        $stmt_audit->bind_param(
                            'is',
                            $usuario_id,
                            $ip
                        );
                        $stmt_audit->execute();
                        $stmt_audit->close();
                    }
                } catch (Throwable $e) {
                    $mensaje = 'No fue posible actualizar la contraseña: '
                        . htmlspecialchars(
                            $e->getMessage(),
                            ENT_QUOTES,
                            'UTF-8'
                        );

                    $tipo_mensaje = 'danger';
                }
            }
        }

        $tab_activo = 'usuarios';
    }

    // Actualizar configuración general
    elseif ($action === 'update_general') {
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
    
    // Crear usuario + notificación por correo
    elseif ($action === 'crear_usuario') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $rol = trim($_POST['rol'] ?? 'vendedor');
        $activo = intval($_POST['activo'] ?? 1);
        $password_default = $password_temporal_actual;
        $password_default_html = htmlspecialchars(
            $password_default,
            ENT_QUOTES,
            'UTF-8'
        );

        $password_hash = password_hash(
            $password_default,
            PASSWORD_DEFAULT
        );

        $roles_permitidos = $es_super_administrador
            ? ['vendedor', 'administrador', 'super_administrador']
            : ['vendedor', 'administrador'];

        if (!in_array($rol, $roles_permitidos, true)) {
            $mensaje = "No tienes permiso para asignar ese rol.";
            $tipo_mensaje = "danger";
        } elseif ($nombre === '' || $email === '') {
            $mensaje = "Debes capturar nombre y correo electrónico.";
            $tipo_mensaje = "danger";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $mensaje = "El correo electrónico no tiene un formato válido.";
            $tipo_mensaje = "danger";
        } else {
            $stmt_check = $conn->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
            $stmt_check->bind_param("s", $email);
            $stmt_check->execute();
            $existe_usuario = $stmt_check->get_result()->fetch_assoc();

            if ($existe_usuario) {
                $mensaje = "Ya existe un usuario registrado con el correo <strong>" . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "</strong>.";
                $tipo_mensaje = "danger";
            } else {
                $stmt = $conn->prepare("INSERT INTO usuarios (nombre, email, password, rol, activo, created_by, debe_cambiar_password) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $stmt->bind_param("ssssii", $nombre, $email, $password_hash, $rol, $activo, $usuario_id);

                if ($stmt->execute()) {
                    $correo = enviarCorreoAccesoUsuario($conn, $nombre, $email, $rol, $password_default);
                    $email_html = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

                    if ($correo['ok']) {
                        $mensaje = "
                            <div style='text-align:left'>
                                <strong>Usuario creado correctamente.</strong><br>
                                Se envió la notificación con los datos de acceso a:<br>
                                <span style='color:#f97316;font-weight:800'>{$email_html}</span>
                            </div>
                        ";
                        $tipo_mensaje = "success";
                    } else {
                        $error_correo = htmlspecialchars($correo['error'], ENT_QUOTES, 'UTF-8');
                        $mensaje = "
                            <div style='text-align:left'>
                                <strong>Usuario creado, pero no se pudo enviar el correo.</strong><br><br>
                                <strong>Correo:</strong> {$email_html}<br>
                                <strong>Contraseña temporal:</strong> {$password_default_html}<br><br>
                                <small><strong>Error SMTP:</strong> {$error_correo}</small>
                            </div>
                        ";
                        $tipo_mensaje = "warning";
                    }

                    $stmt_audit = $conn->prepare("INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, 'Crear Usuario', ?, ?)");
                    $detalle = "Creó el usuario: $nombre ($email)" . ($correo['ok'] ? " y se envió correo de acceso" : " pero falló el correo de acceso");
                    $stmt_audit->bind_param("iss", $usuario_id, $detalle, $ip);
                    $stmt_audit->execute();
                } else {
                    $mensaje = "Error al crear el usuario.";
                    $tipo_mensaje = "danger";
                }
            }
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

        $roles_permitidos = $es_super_administrador
            ? ['vendedor', 'administrador', 'super_administrador']
            : ['vendedor', 'administrador'];

        if (!administradorPuedeGestionarUsuario($conn, $id_usuario, $es_super_administrador)) {
            $mensaje = "No tienes permiso para consultar ni modificar esta cuenta.";
            $tipo_mensaje = "danger";
        } elseif (!in_array($rol, $roles_permitidos, true)) {
            $mensaje = "No tienes permiso para asignar ese rol.";
            $tipo_mensaje = "danger";
        } elseif ($nombre === '' || $email === '') {
            $mensaje = "Debes capturar nombre y correo electrónico.";
            $tipo_mensaje = "danger";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $mensaje = "El correo electrónico no tiene un formato válido.";
            $tipo_mensaje = "danger";
        } else {
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
        }
        $tab_activo = 'usuarios';
    }

    // Resetear contraseña
    elseif ($action === 'reset_password') {
        $id_usuario = intval($_POST['id_usuario'] ?? 0);
        $password_default = $password_temporal_actual;
        $password_default_html = htmlspecialchars(
            $password_default,
            ENT_QUOTES,
            'UTF-8'
        );

        $password_hash = password_hash(
            $password_default,
            PASSWORD_DEFAULT
        );

        if (!administradorPuedeGestionarUsuario($conn, $id_usuario, $es_super_administrador)) {
            $mensaje = "No tienes permiso para restablecer la contraseña de esta cuenta.";
            $tipo_mensaje = "danger";
        } else {
            $stmt = $conn->prepare("UPDATE usuarios SET password = ?, debe_cambiar_password = 1 WHERE id = ?");
            $stmt->bind_param("si", $password_hash, $id_usuario);

            if ($stmt->execute()) {
                $mensaje = "Contraseña restablecida a: "
                    . "<strong>{$password_default_html}</strong>. "
                    . "El usuario deberá cambiarla al iniciar sesión.";
                $tipo_mensaje = "success";

                $stmt_audit = $conn->prepare("INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, 'Resetear Contraseña', ?, ?)");
                $detalle = "Restableció la contraseña del usuario ID: $id_usuario";
                $stmt_audit->bind_param("iss", $usuario_id, $detalle, $ip);
                $stmt_audit->execute();
            } else {
                $mensaje = "Error al restablecer la contraseña.";
                $tipo_mensaje = "danger";
            }
        }
        $tab_activo = 'usuarios';
    }

    // Eliminar usuario
    elseif ($action === 'eliminar_usuario') {
        $id_usuario = intval($_POST['id_usuario'] ?? 0);

        if ($id_usuario == $_SESSION['usuario_id']) {
            $mensaje = "No puedes eliminar tu propio usuario.";
            $tipo_mensaje = "danger";
        } elseif (!administradorPuedeGestionarUsuario($conn, $id_usuario, $es_super_administrador)) {
            $mensaje = "No tienes permiso para eliminar esta cuenta.";
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

// Si la acción vino por fetch/AJAX, regresamos JSON y evitamos renderizar toda la página
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && esPeticionAjax()) {
    $ok_ajax = in_array($tipo_mensaje, ['success', 'warning', 'info'], true);
    responderAjax($ok_ajax, $mensaje, $tipo_mensaje, $tab_activo);
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

// Configuración de sesión
$config_sesion = cfgSesionObtener($conn);

// Paginación para Usuarios
$pagina_usuarios = isset($_GET['pagina_usuarios']) ? max(1, intval($_GET['pagina_usuarios'])) : 1;
$por_pagina = 10;
$offset_usuarios = ($pagina_usuarios - 1) * $por_pagina;

if ($es_super_administrador) {
    $total_usuarios_result = $conn->query("SELECT COUNT(*) as total FROM usuarios");
    $stmt_usuarios = $conn->prepare("SELECT * FROM usuarios ORDER BY id DESC LIMIT ? OFFSET ?");
} else {
    // El administrador normal no recibe cuentas super_administrador desde la BD.
    $total_usuarios_result = $conn->query("SELECT COUNT(*) as total FROM usuarios WHERE rol <> 'super_administrador'");
    $stmt_usuarios = $conn->prepare("SELECT * FROM usuarios WHERE rol <> 'super_administrador' ORDER BY id DESC LIMIT ? OFFSET ?");
}

$total_usuarios = (int)($total_usuarios_result->fetch_assoc()['total'] ?? 0);
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

include 'includes/header.php';
include 'includes/navbar.php';
?>

<!DOCTYPE html>
    <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
            <title>Panel de Configuración - Pescadores de la Prehistoria</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
            <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
        </head>
    <body>
        <link rel="stylesheet" href="css/configuracion.css?v=<?= time() ?>">
        <style>
            /* =========================================================
               ENCABEZADO DE CONFIGURACIÓN
               ========================================================= */
            .config-page-hero {
                display: flex;
                align-items: center;
                gap: 16px;
                padding: 20px 22px;
                border: 1px solid rgba(249, 115, 22, .14);
                border-radius: 18px;
                background:
                    radial-gradient(circle at top right, rgba(251, 146, 60, .16), transparent 34%),
                    linear-gradient(135deg, #ffffff 0%, #fffaf5 100%);
                box-shadow: 0 12px 28px rgba(15, 23, 42, .06);
            }

            .config-page-hero-icon {
                width: 54px;
                height: 54px;
                flex: 0 0 54px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 16px;
                color: #ffffff;
                background: linear-gradient(135deg, #f97316, #fb923c);
                box-shadow: 0 10px 22px rgba(249, 115, 22, .24);
                font-size: 23px;
            }

            .config-page-hero-copy h1 {
                margin: 0;
                color: #172033;
                font-size: clamp(24px, 2vw, 31px);
                font-weight: 850;
                line-height: 1.18;
            }

            .config-page-hero-copy p {
                margin: 5px 0 0;
                color: #64748b;
                font-size: 14px;
                line-height: 1.5;
            }

            /* =========================================================
               PESTAÑAS SUPERIORES
               ========================================================= */
            .nav-tabs-wrapper {
                margin-top: 18px;
                padding: 8px !important;
                overflow: visible !important;
                border: 1px solid #eef2f7 !important;
                border-radius: 18px !important;
                background: #ffffff !important;
                box-shadow: 0 10px 25px rgba(15, 23, 42, .06);
            }

            #configTabs.config-tabs-grid {
                display: grid !important;
                grid-template-columns: repeat(8, minmax(0, 1fr));
                gap: 8px;
                margin: 0 !important;
                padding: 0 !important;
                border: 0 !important;
            }

            #configTabs.config-tabs-grid .nav-item {
                width: 100%;
                min-width: 0;
                margin: 0 !important;
            }

            #configTabs.config-tabs-grid .nav-link {
                width: 100%;
                min-height: 52px;
                display: flex !important;
                align-items: center;
                justify-content: center;
                gap: 8px;
                margin: 0 !important;
                padding: 10px 8px !important;
                border: 1px solid transparent !important;
                border-radius: 12px !important;
                color: #5f6f86 !important;
                background: transparent !important;
                font-size: 14px;
                font-weight: 750;
                line-height: 1.2;
                text-align: center;
                white-space: nowrap;
                transition: transform .18s ease, background .18s ease, color .18s ease, box-shadow .18s ease;
            }

            #configTabs.config-tabs-grid .nav-link i {
                flex: 0 0 auto;
                color: #718198;
                font-size: 15px;
            }

            #configTabs.config-tabs-grid .nav-link:hover {
                color: #c2410c !important;
                background: #fff7ed !important;
                transform: translateY(-1px);
            }

            #configTabs.config-tabs-grid .nav-link.active {
                color: #c2410c !important;
                border-color: #fed7aa !important;
                background: linear-gradient(135deg, #fff7ed, #fffbf7) !important;
                box-shadow: 0 7px 18px rgba(249, 115, 22, .12);
            }

            #configTabs.config-tabs-grid .nav-link.active i {
                color: #f97316;
            }

            /* =========================================================
               CENTRO DE DOCUMENTOS LEGALES
               ========================================================= */
            .legal-center-page {
                display: grid;
                gap: 16px;
                padding-bottom: 5px;
            }

            .legal-center-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 18px;
                padding: 18px 20px;
                overflow: hidden;
                border: 1px solid rgba(249, 115, 22, .18);
                border-radius: 18px;
                background:
                    radial-gradient(circle at top right, rgba(251, 146, 60, .15), transparent 38%),
                    linear-gradient(135deg, #ffffff, #fffaf5);
                box-shadow: 0 10px 26px rgba(15, 23, 42, .055);
            }

            .legal-center-header-main {
                min-width: 0;
                display: flex;
                align-items: center;
                gap: 14px;
            }

            .legal-center-header-icon {
                width: 52px;
                height: 52px;
                flex: 0 0 52px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 15px;
                color: #ffffff;
                background: linear-gradient(135deg, #f97316, #fb923c);
                box-shadow: 0 9px 20px rgba(249, 115, 22, .22);
                font-size: 22px;
            }

            .legal-center-eyebrow {
                display: block;
                margin-bottom: 2px;
                color: #c2410c;
                font-size: 10px;
                font-weight: 900;
                letter-spacing: .09em;
                text-transform: uppercase;
            }

            .legal-center-header h2 {
                margin: 0;
                color: #172033;
                font-size: 24px;
                font-weight: 850;
                line-height: 1.2;
            }

            .legal-center-header p {
                margin: 4px 0 0;
                color: #64748b;
                font-size: 14px;
                line-height: 1.45;
            }

            .legal-center-badge {
                flex: 0 0 auto;
                display: inline-flex;
                align-items: center;
                gap: 7px;
                padding: 8px 11px;
                border: 1px solid #fed7aa;
                border-radius: 999px;
                color: #9a3412;
                background: #fff7ed;
                font-size: 11px;
                font-weight: 850;
                white-space: nowrap;
            }

            .legal-window-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 16px;
            }

            .legal-doc-window {
                position: relative;
                width: 100%;
                min-height: 178px;
                display: flex;
                align-items: stretch;
                padding: 0;
                overflow: hidden;
                border: 1px solid #e2e8f0;
                border-radius: 18px;
                color: inherit;
                background: #ffffff;
                box-shadow: 0 12px 28px rgba(15, 23, 42, .065);
                text-align: left;
                cursor: pointer;
                appearance: none;
                transition:
                    transform .18s ease,
                    border-color .18s ease,
                    box-shadow .18s ease;
            }

            .legal-doc-window::before {
                content: "";
                width: 7px;
                flex: 0 0 7px;
                background: linear-gradient(180deg, #f97316, #fb923c);
            }

            .legal-doc-window.legal-privacy-window::before {
                background: linear-gradient(180deg, #2563eb, #3b82f6);
            }

            .legal-doc-window:hover,
            .legal-doc-window:focus-visible {
                transform: translateY(-3px);
                border-color: #fdba74;
                box-shadow: 0 18px 36px rgba(15, 23, 42, .10);
                outline: none;
            }

            .legal-doc-window.legal-privacy-window:hover,
            .legal-doc-window.legal-privacy-window:focus-visible {
                border-color: #93c5fd;
            }

            .legal-doc-window-content {
                min-width: 0;
                flex: 1;
                display: flex;
                align-items: flex-start;
                gap: 16px;
                padding: 23px 22px;
            }

            .legal-doc-window-icon {
                width: 54px;
                height: 54px;
                flex: 0 0 54px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 16px;
                color: #ea580c;
                background: #fff7ed;
                font-size: 22px;
            }

            .legal-privacy-window .legal-doc-window-icon {
                color: #2563eb;
                background: #eff6ff;
            }

            .legal-doc-window-copy {
                min-width: 0;
                flex: 1;
            }

            .legal-doc-window-copy > span {
                display: block;
                margin-bottom: 4px;
                color: #94a3b8;
                font-size: 10px;
                font-weight: 900;
                letter-spacing: .08em;
                text-transform: uppercase;
            }

            .legal-doc-window-copy h3 {
                margin: 0;
                color: #172033;
                font-size: 20px;
                font-weight: 850;
                line-height: 1.25;
            }

            .legal-doc-window-copy p {
                margin: 8px 0 15px;
                color: #64748b;
                font-size: 13px;
                line-height: 1.55;
            }

            .legal-doc-window-action {
                display: inline-flex;
                align-items: center;
                gap: 7px;
                color: #c2410c;
                font-size: 12px;
                font-weight: 850;
            }

            .legal-privacy-window .legal-doc-window-action {
                color: #1d4ed8;
            }

            .legal-doc-window-action i {
                transition: transform .18s ease;
            }

            .legal-doc-window:hover .legal-doc-window-action i {
                transform: translateX(3px);
            }

            @media (max-width: 1250px) {
                #configTabs.config-tabs-grid {
                    grid-template-columns: repeat(4, minmax(0, 1fr));
                }
            }

            @media (max-width: 850px) {
                #configTabs.config-tabs-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }

                .legal-window-grid {
                    grid-template-columns: 1fr;
                }

                .legal-center-badge {
                    display: none;
                }
            }

            @media (max-width: 575px) {
                .config-page-hero {
                    padding: 16px;
                }

                .config-page-hero-icon {
                    width: 46px;
                    height: 46px;
                    flex-basis: 46px;
                    border-radius: 14px;
                    font-size: 19px;
                }

                #configTabs.config-tabs-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                    gap: 6px;
                }

                #configTabs.config-tabs-grid .nav-link {
                    justify-content: center;
                    min-height: 45px;
                    padding: 9px 7px !important;
                    font-size: 12px;
                    white-space: normal;
                }

                .legal-center-header {
                    padding: 16px;
                }

                .legal-center-header-icon {
                    width: 46px;
                    height: 46px;
                    flex-basis: 46px;
                    border-radius: 14px;
                    font-size: 19px;
                }

                .legal-center-header h2 {
                    font-size: 21px;
                }

                .legal-center-header p {
                    font-size: 13px;
                }

                .legal-doc-window {
                    min-height: 0;
                }

                .legal-doc-window-content {
                    gap: 13px;
                    padding: 18px 16px;
                }

                .legal-doc-window-icon {
                    width: 46px;
                    height: 46px;
                    flex-basis: 46px;
                    border-radius: 14px;
                    font-size: 19px;
                }

                .legal-doc-window-copy h3 {
                    font-size: 18px;
                }
            }
        
            /* =========================================================
               CONTRASEÑA TEMPORAL DEL PORTAL
               ========================================================= */
            .password-config-card {
                display: grid;
                grid-template-columns:
                    minmax(280px, .9fr)
                    minmax(380px, 1.1fr);
                gap: 20px;
                margin-bottom: 18px;
                padding: 20px;
                border: 1px solid #e6ebf2;
                border-radius: 16px;
                background: #ffffff;
                box-shadow: 0 8px 22px rgba(15, 23, 42, .055);
            }

            .password-config-info {
                min-width: 0;
                display: flex;
                align-items: flex-start;
                gap: 14px;
            }

            .password-config-icon {
                width: 46px;
                height: 46px;
                flex: 0 0 46px;
                display: grid;
                place-items: center;
                border-radius: 13px;
                color: #ea580c;
                background: #fff7ed;
                font-size: 18px;
            }

            .password-config-copy {
                min-width: 0;
            }

            .password-config-eyebrow {
                display: block;
                margin-bottom: 3px;
                color: #c2410c;
                font-size: 10px;
                font-weight: 900;
                letter-spacing: .07em;
                text-transform: uppercase;
            }

            .password-config-copy h3 {
                margin: 0;
                color: #172033;
                font-size: 18px;
                font-weight: 850;
            }

            .password-config-copy p {
                margin: 7px 0 12px;
                color: #64748b;
                font-size: 12.5px;
                line-height: 1.5;
            }

            .password-current-row {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 7px 9px;
                border: 1px solid #e5eaf0;
                border-radius: 9px;
                background: #f8fafc;
            }

            .password-current-row > span {
                color: #64748b;
                font-size: 11px;
                font-weight: 750;
            }

            .password-current-row code {
                color: #172033;
                background: transparent;
                font-size: 12px;
                font-weight: 850;
                letter-spacing: .4px;
            }

            .password-eye-btn,
            .password-field-toggle {
                border: 0;
                background: transparent;
                color: #718198;
                cursor: pointer;
            }

            .password-eye-btn:hover,
            .password-field-toggle:hover {
                color: #f97316;
            }

            .password-config-form {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
                align-content: start;
            }

            .password-config-field label {
                display: block;
                margin-bottom: 6px;
                color: #526178;
                font-size: 11px;
                font-weight: 800;
            }

            .password-input-wrap {
                position: relative;
            }

            .password-input-wrap .form-control {
                height: 42px;
                padding-right: 42px;
                border-radius: 10px;
            }

            .password-field-toggle {
                position: absolute;
                top: 50%;
                right: 11px;
                transform: translateY(-50%);
            }

            .password-config-actions {
                grid-column: 1 / -1;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
            }

            .password-config-actions small {
                color: #7c899a;
                font-size: 10.5px;
            }

            @media (max-width: 900px) {
                .password-config-card {
                    grid-template-columns: 1fr;
                }
            }

            @media (max-width: 575px) {
                .password-config-card {
                    gap: 16px;
                    padding: 16px;
                }

                .password-config-form {
                    grid-template-columns: 1fr;
                }

                .password-config-actions {
                    align-items: stretch;
                    flex-direction: column;
                }

                .password-config-actions .btn {
                    width: 100%;
                }
            }

</style>

            <div class="content-wrapper">
                <section class="content-header">
                    <div class="container-fluid">
                        <div class="row mb-2">
                            <div class="col-12">
                                <div class="config-page-hero">
                                    <div class="config-page-hero-icon">
                                        <i class="fas fa-cog"></i>
                                    </div>

                                    <div class="config-page-hero-copy">
                                        <h1>Panel de Configuración</h1>
                                        <p>Administra la tienda, los usuarios, los respaldos y la información legal del sistema.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="content">
                    <div class="container-fluid">
                        <!-- Tabs de navegación -->
                        <div class="nav-tabs-wrapper">
                            <ul class="nav nav-tabs config-tabs-grid" id="configTabs" role="tablist">
                                <li class="nav-item"><button class="nav-link <?= $tab_activo == 'general' ? 'active' : '' ?>" data-tab="general"><i class="fas fa-store"></i> General</button></li>
                                <li class="nav-item"><button class="nav-link <?= $tab_activo == 'sesion' ? 'active' : '' ?>" data-tab="sesion"><i class="fas fa-shield-halved"></i> Sesión</button></li>
                                <li class="nav-item"><button class="nav-link <?= $tab_activo == 'correo' ? 'active' : '' ?>" data-tab="correo"><i class="fas fa-envelope"></i> Correo</button></li>
                                <li class="nav-item"><button class="nav-link <?= $tab_activo == 'usuarios' ? 'active' : '' ?>" data-tab="usuarios"><i class="fas fa-users"></i> Usuarios</button></li>
                                <li class="nav-item"><button class="nav-link <?= $tab_activo == 'proveedores' ? 'active' : '' ?>" data-tab="proveedores"><i class="fas fa-truck"></i> Proveedores</button></li>
                                <li class="nav-item"><button class="nav-link <?= $tab_activo == 'backup' ? 'active' : '' ?>" data-tab="backup"><i class="fas fa-database"></i> Respaldos</button></li>
                                <li class="nav-item"><button class="nav-link <?= $tab_activo == 'auditoria' ? 'active' : '' ?>" data-tab="auditoria"><i class="fas fa-history"></i> Auditoría</button></li>
                                <li class="nav-item"><button class="nav-link <?= $tab_activo == 'legal' ? 'active' : '' ?>" data-tab="legal" title="Privacidad y términos"><i class="fas fa-file-signature"></i>Terminos y Con.</button></li>
                            </ul>
                        </div>

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
                                        <div class="imagenes-grid">
                                            <!-- Logo de la tienda -->
                                            <div class="imagen-card">
                                                <h6 class="mb-3"><i class="fas fa-image mr-2"></i> Logo de la tienda</h6>
                                                <div class="imagen-preview">
                                                    <?php if ($logo_path && file_exists($logo_path)): ?>
                                                        <img src="<?= $logo_path ?>?v=<?= time() ?>" id="currentLogo">
                                                    <?php else: ?>
                                                        <div class="text-center" id="currentLogo">
                                                            <i class="fas fa-store fa-3x text-muted"></i>
                                                            <p class="small text-muted mt-2 mb-0">Sin logo</p>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="custom-file">
                                                    <input type="file" name="logo" accept="image/*" id="logoInput" class="custom-file-input" onchange="previewLogo(this, 'logoPreview', 'logoPreviewContainer')">
                                                    <label class="custom-file-label" for="logoInput">Seleccionar logo</label>
                                                    <small class="text-muted d-block mt-2">Formatos: JPG, PNG, GIF, WEBP</small>
                                                </div>
                                                <div id="logoPreviewContainer" style="display: none;" class="mt-3">
                                                    <label class="text-muted small">Vista previa:</label>
                                                    <img id="logoPreview" class="img-fluid border rounded p-1" style="max-height: 60px;">
                                                </div>
                                            </div>
                                            
                                            <!-- Imagen del Dashboard Principal -->
                                            <div class="imagen-card">
                                                <h6 class="mb-3"><i class="fas fa-tachometer-alt mr-2"></i> Imagen del Dashboard</h6>
                                                <div class="imagen-preview">
                                                    <?php 
                                                    $dashboard_img = $config_general['imagen_dashboard'] ?? '';
                                                    if ($dashboard_img && file_exists($dashboard_img)): ?>
                                                        <img src="<?= $dashboard_img ?>?v=<?= time() ?>" id="currentDashboardImg">
                                                    <?php else: ?>
                                                        <div class="text-center" id="currentDashboardImg">
                                                            <i class="fas fa-chart-line fa-3x text-muted"></i>
                                                            <p class="small text-muted mt-2 mb-0">Imagen por defecto</p>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="custom-file">
                                                    <input type="file" name="imagen_dashboard" accept="image/*" id="dashboardInput" class="custom-file-input" onchange="previewLogo(this, 'dashboardPreview', 'dashboardPreviewContainer')">
                                                    <label class="custom-file-label" for="dashboardInput">Seleccionar imagen</label>
                                                    <small class="text-muted d-block mt-2">Formatos: JPG, PNG, GIF, WEBP</small>
                                                </div>
                                                <div id="dashboardPreviewContainer" style="display: none;" class="mt-3">
                                                    <label class="text-muted small">Vista previa:</label>
                                                    <img id="dashboardPreview" class="img-fluid border rounded p-1" style="max-height: 60px;">
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

                                <!-- Acerca del Sistema - Versión Compacta -->
                                <div class="acerca-card">
                                    <div class="acerca-header">
                                        <div class="acerca-logo">
                                            <?php if ($logo_path && file_exists($logo_path)): ?>
                                                <img src="<?= $logo_path ?>?v=<?= time() ?>" alt="Logo">
                                            <?php else: ?>
                                                <i class="fas fa-fish"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="acerca-info-header">
                                            <h3><?= htmlspecialchars($config_general['nombre'] ?? 'Pescadores de la Prehistoria') ?></h3>
                                            <p>Sistema de Gestión de Inventario y Ventas</p>
                                        </div>
                                    </div>
                                    
                                    <div class="acerca-body">
                                        <div class="acerca-row">
                                            <div class="acerca-col">
                                                <i class="fas fa-calendar-alt"></i>
                                                <span class="label">Última actualización:</span>
                                                <span class="value"><?= $ultima_actualizacion ?></span>
                                            </div>
                                            <div class="acerca-col">
                                                <i class="fas fa-code-branch"></i>
                                                <span class="label">Versión:</span>
                                                <span class="value">2.0.0</span>
                                            </div>
                                        </div>
                                        <div class="acerca-row">
                                            <div class="acerca-col">
                                                <i class="fas fa-user"></i>
                                                <span class="label">Desarrollado por:</span>
                                                <span class="value">Jesus Martinez Vidal</span>
                                            </div>
                                            <div class="acerca-col">
                                                <i class="fas fa-envelope"></i>
                                                <span class="label">Contacto:</span>
                                                <span class="value">soportepescadores@gmail.com</span>
                                            </div>
                                        </div>
                                        <div class="acerca-row">
                                            <div class="acerca-col">
                                                <i class="fas fa-phone-alt"></i>
                                                <span class="label">Teléfono:</span>
                                                <span class="value">+52 222 980 4687</span>
                                            </div>
                                            <div class="acerca-col">
                                                <i class="fas fa-copyright"></i>
                                                <span class="label">Derechos:</span>
                                                <span class="value">2026</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>


                <!-- TAB SESIÓN -->
                <div class="tab-pane <?= $tab_activo == 'sesion' ? 'active' : '' ?>" id="tab-sesion" data-tab-content="sesion">
                    <section class="session-config-shell">
                        <div class="session-config-intro">
                            <div class="session-config-intro-icon">
                                <i class="fas fa-shield-halved"></i>
                            </div>
                            <div class="session-config-intro-copy">
                                <span>Seguridad del portal</span>
                                <h2>Duración de la sesión</h2>
                                <p>
                                    Define cuánto tiempo puede permanecer abierta una cuenta sin actividad.
                                    Esta configuración se aplica a administradores, superadministradores y vendedores.
                                </p>
                            </div>
                            <div class="session-config-current">
                                <small>Configuración actual</small>
                                <strong><?= (int) $config_sesion['inactividad_minutos'] ?> min</strong>
                                <span>por inactividad</span>
                            </div>
                        </div>

                        <form method="POST" id="formSessionConfig" class="session-config-card">
                            <input type="hidden" name="tab_activo" value="sesion">

                            <div class="session-config-grid">
                                <div class="session-config-field">
                                    <div class="session-config-field-icon session-icon-orange">
                                        <i class="fas fa-stopwatch"></i>
                                    </div>
                                    <div class="session-config-field-copy">
                                        <label for="inactividad_minutos">Tiempo de inactividad</label>
                                        <p>Cierra la cuenta cuando no se detecta actividad real.</p>
                                    </div>
                                    <div class="session-number-wrap">
                                        <input
                                            type="number"
                                            id="inactividad_minutos"
                                            name="inactividad_minutos"
                                            min="1"
                                            max="1440"
                                            step="1"
                                            value="<?= (int) $config_sesion['inactividad_minutos'] ?>"
                                            required
                                        >
                                        <span>min</span>
                                    </div>
                                </div>

                                <div class="session-config-field">
                                    <div class="session-config-field-icon session-icon-blue">
                                        <i class="fas fa-bell"></i>
                                    </div>
                                    <div class="session-config-field-copy">
                                        <label for="aviso_minutos">Avisar antes de cerrar</label>
                                        <p>Muestra una alerta con la opción de continuar trabajando.</p>
                                    </div>
                                    <div class="session-number-wrap">
                                        <input
                                            type="number"
                                            id="aviso_minutos"
                                            name="aviso_minutos"
                                            min="0"
                                            max="60"
                                            step="1"
                                            value="<?= (int) $config_sesion['aviso_minutos'] ?>"
                                            required
                                        >
                                        <span>min</span>
                                    </div>
                                </div>

                                <div class="session-config-field">
                                    <div class="session-config-field-icon session-icon-purple">
                                        <i class="fas fa-hourglass-half"></i>
                                    </div>
                                    <div class="session-config-field-copy">
                                        <label for="duracion_maxima_horas">Duración máxima absoluta</label>
                                        <p>Obliga a iniciar sesión de nuevo aunque exista actividad. Usa 0 para desactivarla.</p>
                                    </div>
                                    <div class="session-number-wrap">
                                        <input
                                            type="number"
                                            id="duracion_maxima_horas"
                                            name="duracion_maxima_horas"
                                            min="0"
                                            max="168"
                                            step="1"
                                            value="<?= (int) $config_sesion['duracion_maxima_horas'] ?>"
                                            required
                                        >
                                        <span>h</span>
                                    </div>
                                </div>
                            </div>

                            <label class="session-warning-switch">
                                <div class="session-warning-switch-copy">
                                    <strong>Mostrar advertencia de expiración</strong>
                                    <span>Si se desactiva, la sesión se cerrará al vencer sin mostrar el aviso previo.</span>
                                </div>
                                <span class="session-switch-control">
                                    <input
                                        type="checkbox"
                                        name="aviso_activo"
                                        value="1"
                                        <?= (int) $config_sesion['aviso_activo'] === 1 ? 'checked' : '' ?>
                                    >
                                    <span></span>
                                </span>
                            </label>

                            <div class="session-config-summary" id="sessionConfigSummary">
                                <i class="fas fa-circle-info"></i>
                                <span>
                                    La sesión se cerrará después de
                                    <strong id="sessionSummaryIdle"><?= (int) $config_sesion['inactividad_minutos'] ?> minutos</strong>
                                    sin actividad.
                                </span>
                            </div>

                            <div class="session-config-footer">
                                <div class="session-config-note">
                                    <i class="fas fa-database"></i>
                                    <span>
                                        Guardado en la base de datos para esta instalación.
                                        <?php if (!empty($config_sesion['updated_at'])): ?>
                                            Último cambio: <?= date('d/m/Y H:i', strtotime($config_sesion['updated_at'])) ?>.
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <button type="submit" class="btn btn-primary session-save-button">
                                    <i class="fas fa-save"></i>
                                    Guardar duración
                                </button>
                            </div>
                        </form>
                    </section>
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
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Seguridad</label>
                                            <select class="form-control select-corregido" name="smtp_secure">
                                                <option value="tls" <?= ($config_correo['smtp_secure'] ?? 'tls') == 'tls' ? 'selected' : '' ?>>TLS</option>
                                                <option value="ssl" <?= ($config_correo['smtp_secure'] ?? '') == 'ssl' ? 'selected' : '' ?>>SSL</option>
                                            </select>
                                        </div>
                                    </div>
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

                    <?php if ($es_super_administrador): ?>
                        <section class="password-config-card">
                            <div class="password-config-info">
                                <div class="password-config-icon">
                                    <i class="fas fa-key"></i>
                                </div>

                                <div class="password-config-copy">
                                    <span class="password-config-eyebrow">
                                        Seguridad de usuarios
                                    </span>

                                    <h3>Contraseña temporal del portal</h3>

                                    <p>
                                        Se utiliza al crear usuarios y al
                                        restablecer sus accesos. Después,
                                        cada usuario deberá cambiarla.
                                    </p>

                                    <div class="password-current-row">
                                        <span>Actual:</span>

                                        <code
                                            id="passwordDefaultActual"
                                            data-password="<?= htmlspecialchars(
                                                $password_temporal_actual,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                        >••••••••</code>

                                        <button
                                            type="button"
                                            class="password-eye-btn"
                                            id="togglePasswordDefault"
                                            aria-label="Mostrar u ocultar contraseña"
                                        >
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <form
                                method="POST"
                                id="formPasswordDefault"
                                class="password-config-form"
                                autocomplete="off"
                            >
                                <input
                                    type="hidden"
                                    name="tab_activo"
                                    value="usuarios"
                                >

                                <div class="password-config-field">
                                    <label for="password_default_nueva">
                                        Nueva contraseña temporal
                                    </label>

                                    <div class="password-input-wrap">
                                        <input
                                            type="password"
                                            class="form-control"
                                            name="password_default_nueva"
                                            id="password_default_nueva"
                                            minlength="<?= (int) $password_temporal_longitud ?>"
                                            maxlength="72"
                                            autocomplete="new-password"
                                            required
                                        >

                                        <button
                                            type="button"
                                            class="password-field-toggle"
                                            data-target="password_default_nueva"
                                            aria-label="Mostrar contraseña"
                                        >
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="password-config-field">
                                    <label for="password_default_confirmar">
                                        Confirmar contraseña
                                    </label>

                                    <div class="password-input-wrap">
                                        <input
                                            type="password"
                                            class="form-control"
                                            name="password_default_confirmar"
                                            id="password_default_confirmar"
                                            minlength="<?= (int) $password_temporal_longitud ?>"
                                            maxlength="72"
                                            autocomplete="new-password"
                                            required
                                        >

                                        <button
                                            type="button"
                                            class="password-field-toggle"
                                            data-target="password_default_confirmar"
                                            aria-label="Mostrar contraseña"
                                        >
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="password-config-actions">
                                    <small>
                                        Mínimo
                                        <?= (int) $password_temporal_longitud ?>
                                        caracteres, una letra y un número.
                                    </small>

                                    <button
                                        type="submit"
                                        class="btn btn-primary"
                                    >
                                        <i class="fas fa-save mr-1"></i>
                                        Guardar contraseña
                                    </button>
                                </div>
                            </form>
                        </section>
                    <?php endif; ?>

                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title mb-0"><i class="fas fa-users mr-2"></i> Gestión de Usuarios</h3>
                            <button class="btn btn-success btn-sm" onclick="abrirModalUsuario()">
                                <i class="fas fa-plus mr-1"></i> Nuevo Usuario
                            </button>
                        </div>
                        <div class="card-body table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>Email</th>
                                        <th>Rol</th>
                                        <th>Estado</th>
                                        <th>Cambio Pwd</th>
                                        <th>Fecha Registro</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($u = $usuarios->fetch_assoc()): ?>
                                    <tr>
                                        <td data-label="Nombre"><?= htmlspecialchars($u['nombre']) ?></td>
                                        <td data-label="Email"><?= htmlspecialchars($u['email']) ?></td>
                                        <td data-label="Rol">
                                            <?php
                                            $clase_rol = 'badge-info';
                                            $texto_rol = ucfirst((string)$u['rol']);

                                            if ($u['rol'] === 'administrador') {
                                                $clase_rol = 'badge-danger';
                                                $texto_rol = 'Administrador';
                                            } elseif ($u['rol'] === 'super_administrador') {
                                                $clase_rol = 'badge-dark';
                                                $texto_rol = 'Superadministrador';
                                            } elseif ($u['rol'] === 'vendedor') {
                                                $texto_rol = 'Vendedor';
                                            }
                                            ?>
                                            <span class="badge <?= $clase_rol ?>"><?= htmlspecialchars($texto_rol) ?></span>
                                        </td>
                                        <td data-label="Estado"><span class="badge <?= $u['activo'] ? 'badge-success' : 'badge-danger' ?>"><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
                                        <td data-label="Cambio Pwd"><?= $u['debe_cambiar_password'] ? '<span class="badge badge-warning"><i class="fas fa-exclamation-triangle mr-1"></i> Pendiente</span>' : '<span class="badge badge-success"><i class="fas fa-check mr-1"></i> Actualizada</span>' ?></td>
                                        <td data-label="Fecha Reg."><?= date('d/m/Y', strtotime($u['fecha_registro'])) ?></td>
                                        <td data-label="Acciones">
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
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title mb-0"><i class="fas fa-truck mr-2"></i> Gestión de Proveedores</h3>
                            <button class="btn btn-success btn-sm" onclick="abrirModalProveedor()">
                                <i class="fas fa-plus mr-1"></i> Nuevo Proveedor
                            </button>
                        </div>
                        <div class="card-body table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>Correo</th>
                                        <th>Teléfono</th>
                                        <th>Dirección</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($p = $proveedores->fetch_assoc()): ?>
                                    <tr>
                                        <td data-label="Nombre"><?= htmlspecialchars($p['nombre']) ?></td>
                                        <td data-label="Correo"><?= htmlspecialchars($p['correo'] ?? '-') ?></td>
                                        <td data-label="Teléfono"><?= htmlspecialchars($p['telefono'] ?? '-') ?></td>
                                        <td data-label="Dirección"><?= htmlspecialchars($p['calle'] ?? '') ?> <?= htmlspecialchars($p['numero'] ?? '') ?>, <?= htmlspecialchars($p['colonia'] ?? '') ?></td>
                                        <td data-label="Estado"><span class="badge <?= $p['activo'] ? 'badge-success' : 'badge-danger' ?>"><?= $p['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
                                        <td data-label="Acciones">
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

                <!-- TAB RESPALDOS - Con clases de Bootstrap -->
                <div class="tab-pane <?= $tab_activo == 'backup' ? 'active' : '' ?>" id="tab-backup" data-tab-content="backup">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title mb-0"><i class="fas fa-database mr-2"></i> Respaldos de Base de Datos</h3>
                            <button type="button" class="btn btn-success" id="btnBackup">
                                <i class="fas fa-download mr-1"></i> Crear Nuevo Respaldo
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr><th>Nombre del archivo</th><th>Fecha</th><th>Tamaño</th><th>Acciones</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($backups)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4"><i class="fas fa-folder-open fa-2x text-muted mb-2 d-block"></i>No hay respaldos disponibles</td>
                                        </tr>
                                        <?php else: foreach ($backups as $b): ?>
                                        <tr>
                                            <td data-label="Archivo"><i class="fas fa-file-archive mr-2 text-warning"></i> <?= $b['nombre'] ?></td>
                                            <td data-label="Fecha"><?= $b['fecha'] ?></td>
                                            <td data-label="Tamaño"><?= $b['tamaño'] ?></td>
                                            <td data-label="Acciones"><a href="backups/<?= $b['nombre'] ?>" class="btn btn-sm btn-primary" download><i class="fas fa-download"></i> Descargar</a></td>
                                        </tr>
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
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title mb-0"><i class="fas fa-history mr-2"></i> Registro de Auditoría</h3>
                            <form method="POST" id="formLimpiarAuditoria" class="mb-0">
                                <input type="hidden" name="tab_activo" value="auditoria">
                                <button type="submit" name="action" value="limpiar_auditoria" class="btn btn-warning btn-sm">
                                    <i class="fas fa-trash-alt mr-1"></i> Limpiar Antiguos
                                </button>
                            </form>
                        </div>
                        <div class="card-body table-responsive">
                            <?php if ($auditoria->num_rows > 0): ?>
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Usuario</th>
                                            <th>Acción</th>
                                            <th>Detalle</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($a = $auditoria->fetch_assoc()): ?>
                                        <tr>
                                            <td data-label="Fecha"><?= date('d/m/Y H:i:s', strtotime($a['fecha'])) ?></td>
                                            <td data-label="Usuario"><?= htmlspecialchars($a['usuario_nombre'] ?? 'Sistema') ?></td>
                                            <td data-label="Acción"><span class="badge badge-info"><?= htmlspecialchars($a['accion']) ?></span></td>
                                            <td data-label="Detalle"><?= htmlspecialchars($a['detalle'] ?? '-') ?></td>
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

                <!-- TAB PRIVACIDAD Y TÉRMINOS -->
                <div class="tab-pane <?= $tab_activo == 'legal' ? 'active' : '' ?>" id="tab-legal" data-tab-content="legal">
                    <section class="legal-center-page">
                        <header class="legal-center-header">
                            <div class="legal-center-header-main">
                                <div class="legal-center-header-icon">
                                    <i class="fas fa-scale-balanced"></i>
                                </div>

                                <div>
                                    <span class="legal-center-eyebrow">Centro legal</span>
                                    <h2>Privacidad y términos</h2>
                                    <p>
                                        Selecciona un documento para consultarlo en una ventana independiente.
                                    </p>
                                </div>
                            </div>

                            <div class="legal-center-badge">
                                <i class="fas fa-shield-alt"></i>
                                Documentos vigentes
                            </div>
                        </header>

                        <div class="legal-window-grid">
                            <button
                                type="button"
                                class="legal-doc-window legal-terms-window"
                                onclick="abrirDocumentoLegalConfiguracion('terminos')"
                                aria-label="Abrir Términos y Condiciones"
                            >
                                <div class="legal-doc-window-content">
                                    <div class="legal-doc-window-icon">
                                        <i class="fas fa-file-contract"></i>
                                    </div>

                                    <div class="legal-doc-window-copy">
                                        <span>Documento de uso</span>
                                        <h3>Términos y Condiciones</h3>
                                        <p>
                                            Consulta las reglas de acceso, operación, seguridad,
                                            confidencialidad y responsabilidades dentro del sistema.
                                        </p>

                                        <div class="legal-doc-window-action">
                                            Abrir documento
                                            <i class="fas fa-arrow-right"></i>
                                        </div>
                                    </div>
                                </div>
                            </button>

                            <button
                                type="button"
                                class="legal-doc-window legal-privacy-window"
                                onclick="abrirDocumentoLegalConfiguracion('privacidad')"
                                aria-label="Abrir Aviso de Privacidad"
                            >
                                <div class="legal-doc-window-content">
                                    <div class="legal-doc-window-icon">
                                        <i class="fas fa-user-shield"></i>
                                    </div>

                                    <div class="legal-doc-window-copy">
                                        <span>Protección de datos</span>
                                        <h3>Aviso de Privacidad</h3>
                                        <p>
                                            Consulta cómo se utilizan y protegen los datos,
                                            sus finalidades y los derechos relacionados con la información.
                                        </p>

                                        <div class="legal-doc-window-action">
                                            Abrir documento
                                            <i class="fas fa-arrow-right"></i>
                                        </div>
                                    </div>
                                </div>
                            </button>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Usuario -->
<div class="modal fade" id="modalUsuario" tabindex="-1" role="dialog" aria-labelledby="modalUsuarioLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="POST" id="formUsuario">
                <input type="hidden" name="tab_activo" value="usuarios">
                <div class="modal-header" style="border-bottom: 2px solid #f97316;">
                    <h5 class="modal-title" id="modalUsuarioLabel">
                        <i class="fas fa-user mr-2"></i><span id="modalUsuarioTitulo">Nuevo Usuario</span>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="usuarioAction" value="crear_usuario">
                    <input type="hidden" name="id_usuario" id="id_usuario">
                    <div class="form-group">
                        <label>Nombre completo</label>
                        <input type="text" class="form-control" name="nombre" id="usuario_nombre" required>
                    </div>
                    <div class="form-group">
                        <label>Correo electrónico</label>
                        <input type="email" class="form-control" name="email" id="usuario_email" required>
                    </div>
                    <div class="form-group">
                        <label>Contraseña</label>
                        <input type="password" class="form-control" name="password" id="usuario_password" placeholder="Dejar en blanco">
                        <small class="text-muted" id="passwordHelp">
                            Contraseña temporal configurada:
                            <strong>
                                <?= htmlspecialchars(
                                    $password_temporal_actual,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </strong>
                        </small>
                    </div>
                    <div class="form-group">
                        <label>Rol</label>
                        <select class="form-control" name="rol" id="usuario_rol">
                            <option value="vendedor">Vendedor</option>
                            <option value="administrador">Administrador</option>
                            <?php if ($es_super_administrador): ?>
                                <option value="super_administrador">Superadministrador</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select class="form-control" name="activo" id="usuario_activo">
                            <option value="1">Activo</option>
                            <option value="0">Inactivo</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary" id="btnGuardarUsuario">
                        <i class="fas fa-save mr-1"></i> Guardar
                    </button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Proveedor -->
<div class="modal fade" id="modalProveedor" tabindex="-1" role="dialog" aria-labelledby="modalProveedorLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="POST" id="formProveedor">
                <input type="hidden" name="tab_activo" value="proveedores">
                <div class="modal-header" style="border-bottom: 2px solid #f97316;">
                    <h5 class="modal-title" id="modalProveedorLabel">
                        <i class="fas fa-truck mr-2"></i><span id="modalProveedorTitulo">Nuevo Proveedor</span>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="proveedorAction" value="crear_proveedor">
                    <input type="hidden" name="id_proveedor" id="id_proveedor">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Nombre</label>
                                <input type="text" class="form-control" name="nombre" id="proveedor_nombre" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Correo</label>
                                <input type="email" class="form-control" name="correo" id="proveedor_correo">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Teléfono</label>
                                <input type="text" class="form-control" name="telefono" id="proveedor_telefono">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Estado</label>
                                <select class="form-control" name="activo" id="proveedor_activo">
                                    <option value="1">Activo</option>
                                    <option value="0">Inactivo</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Calle</label>
                                <input type="text" class="form-control" name="calle" id="proveedor_calle">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Número</label>
                                <input type="text" class="form-control" name="numero" id="proveedor_numero">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Colonia</label>
                                <input type="text" class="form-control" name="colonia" id="proveedor_colonia">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Ciudad</label>
                                <input type="text" class="form-control" name="ciudad" id="proveedor_ciudad">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Estado (ubicación)</label>
                                <input type="text" class="form-control" name="estado" id="proveedor_estado">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Código Postal</label>
                                <input type="text" class="form-control" name="codigo_postal" id="proveedor_codigo_postal">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary" id="btnGuardarProveedor">
                        <i class="fas fa-save mr-1"></i> Guardar
                    </button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ==================== DOCUMENTOS LEGALES ====================
function seleccionarDocumentoLegalVisible(tipoDocumento) {
    const documento =
        tipoDocumento === 'privacidad'
            ? 'privacidad'
            : 'terminos';

    const overlays = Array.from(
        document.querySelectorAll('#legalOverlay')
    );

    const overlay =
        overlays.find(function(elemento) {
            return elemento.classList.contains('legal-visible');
        })
        || overlays[0];

    if (!overlay) {
        return;
    }

    const panelId =
        documento === 'privacidad'
            ? 'legalPanelPrivacidad'
            : 'legalPanelTerminos';

    const tabId =
        documento === 'privacidad'
            ? 'legalTabPrivacidad'
            : 'legalTabTerminos';

    overlay
        .querySelectorAll('.legal-tab')
        .forEach(function(tab) {
            tab.classList.toggle(
                'activa',
                tab.id === tabId
            );
        });

    overlay
        .querySelectorAll('.legal-panel')
        .forEach(function(panel) {
            panel.classList.toggle(
                'activo',
                panel.id === panelId
            );
        });

    const cuerpo =
        overlay.querySelector('.legal-modal-body');

    if (cuerpo) {
        cuerpo.scrollTop = 0;
    }
}

function abrirDocumentoLegalConfiguracion(tipoDocumento) {
    const documento =
        tipoDocumento === 'privacidad'
            ? 'privacidad'
            : 'terminos';

    const funcionDirecta =
        documento === 'privacidad'
            ? window.mostrarAvisoPrivacidad
            : window.mostrarTerminosLegales;

    if (typeof funcionDirecta === 'function') {
        funcionDirecta();
    } else if (
        typeof window.mostrarDocumentosLegales === 'function'
    ) {
        window.mostrarDocumentosLegales(
            true,
            documento
        );
    } else {
        Swal.fire({
            title: 'Documento no disponible',
            text: 'No fue posible cargar los documentos legales. Recarga la página.',
            icon: 'error',
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Aceptar'
        });

        return;
    }

    /*
     * Se fuerza la pestaña seleccionada en el modal visible.
     * Se ejecuta dos veces para evitar que otro script la cambie
     * nuevamente a Términos durante la apertura.
     */
    requestAnimationFrame(function() {
        seleccionarDocumentoLegalVisible(documento);
    });

    setTimeout(function() {
        seleccionarDocumentoLegalVisible(documento);
    }, 80);
}

// ==================== FUNCIÓN GENÉRICA PARA ENVIAR FORMULARIOS CON FETCH ====================
async function enviarFormularioFetch(form, actionValue) {
    const formData = new FormData(form);

    if (actionValue) {
        formData.set('action', actionValue);
    }

    const isMultipart = form.getAttribute('enctype') === 'multipart/form-data';

    let body;
    let headers = {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
    };

    if (isMultipart) {
        body = formData;
    } else {
        headers['Content-Type'] = 'application/x-www-form-urlencoded';
        body = new URLSearchParams(formData).toString();
    }

    const response = await fetch(window.location.href, {
        method: 'POST',
        headers: headers,
        body: body,
        credentials: 'same-origin'
    });

    const raw = await response.text();

    let data;
    try {
        data = JSON.parse(raw);
    } catch (e) {
        console.error('Respuesta no JSON del servidor:', raw);
        throw new Error('El servidor no regresó una respuesta válida. Revisa errores PHP o sesión expirada.');
    }

    if (!response.ok || data.ok === false) {
        const error = new Error(data.mensaje || 'No se pudo completar la operación.');
        error.data = data;
        throw error;
    }

    return data;
}

function mostrarAlertaServidor(data, fallbackTitle = 'Resultado') {
    const tipo = data.tipo_mensaje || (data.ok ? 'success' : 'danger');

    Swal.fire({
        title: tipo === 'success' ? '¡Listo!' : (tipo === 'warning' ? 'Aviso' : '¡Error!'),
        html: data.mensaje || fallbackTitle,
        icon: tipo === 'success' ? 'success' : (tipo === 'warning' ? 'warning' : 'error'),
        confirmButtonColor: '#f97316',
        confirmButtonText: 'Aceptar',
        background: '#ffffff',
        color: '#1f2937',
        customClass: {
            popup: 'swal2-config-popup',
            confirmButton: 'swal2-config-confirm'
        }
    }).then(() => {
        const tab = data.tab_activo || 'usuarios';
        window.location.href = window.location.pathname + '?tab=' + encodeURIComponent(tab);
    });
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


// ==================== CONFIGURACIÓN DE SESIÓN ====================
const formSessionConfig = document.getElementById('formSessionConfig');
const sessionIdleInput = document.getElementById('inactividad_minutos');
const sessionWarningInput = document.getElementById('aviso_minutos');
const sessionMaximumInput = document.getElementById('duracion_maxima_horas');
const sessionWarningToggle = formSessionConfig?.querySelector('input[name="aviso_activo"]');
const sessionSummaryIdle = document.getElementById('sessionSummaryIdle');

function actualizarResumenSesion() {
    if (!formSessionConfig) return;

    const inactividad = Math.max(1, Number(sessionIdleInput?.value || 1));
    const aviso = Math.max(0, Number(sessionWarningInput?.value || 0));
    const maxima = Math.max(0, Number(sessionMaximumInput?.value || 0));
    const avisoActivo = Boolean(sessionWarningToggle?.checked);

    if (sessionSummaryIdle) {
        sessionSummaryIdle.textContent = `${inactividad} minuto${inactividad === 1 ? '' : 's'}`;
    }

    const summary = document.getElementById('sessionConfigSummary');
    if (summary) {
        const avisoTexto = avisoActivo && aviso > 0
            ? ` Se mostrará un aviso ${aviso} minuto${aviso === 1 ? '' : 's'} antes.`
            : ' No se mostrará un aviso previo.';
        const maximaTexto = maxima > 0
            ? ` La duración máxima será de ${maxima} hora${maxima === 1 ? '' : 's'}.`
            : ' No habrá una duración máxima absoluta.';

        summary.querySelector('span').innerHTML =
            `La sesión se cerrará después de <strong>${inactividad} minuto${inactividad === 1 ? '' : 's'}</strong> sin actividad.${avisoTexto}${maximaTexto}`;
    }
}

[sessionIdleInput, sessionWarningInput, sessionMaximumInput, sessionWarningToggle]
    .filter(Boolean)
    .forEach(function (control) {
        control.addEventListener('input', actualizarResumenSesion);
        control.addEventListener('change', actualizarResumenSesion);
    });

actualizarResumenSesion();

if (formSessionConfig) {
    formSessionConfig.addEventListener('submit', async function (event) {
        event.preventDefault();

        const inactividad = Number(sessionIdleInput?.value || 0);
        const aviso = Number(sessionWarningInput?.value || 0);
        const maxima = Number(sessionMaximumInput?.value || 0);
        const avisoActivo = Boolean(sessionWarningToggle?.checked);

        if (inactividad < 1 || inactividad > 1440) {
            Swal.fire({
                title: 'Tiempo no válido',
                text: 'La inactividad debe estar entre 1 y 1440 minutos.',
                icon: 'warning',
                confirmButtonColor: '#f97316'
            });
            return;
        }

        if (avisoActivo && aviso >= inactividad) {
            Swal.fire({
                title: 'Revisa el aviso',
                text: 'El aviso debe aparecer antes de que termine el tiempo de inactividad.',
                icon: 'warning',
                confirmButtonColor: '#f97316'
            });
            return;
        }

        if (maxima < 0 || maxima > 168) {
            Swal.fire({
                title: 'Duración máxima no válida',
                text: 'Captura un valor entre 0 y 168 horas.',
                icon: 'warning',
                confirmButtonColor: '#f97316'
            });
            return;
        }

        const confirmacion = await Swal.fire({
            title: '¿Actualizar duración de sesión?',
            html: `La sesión se cerrará después de <strong>${inactividad} minuto${inactividad === 1 ? '' : 's'}</strong> sin actividad.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#f97316',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Sí, guardar',
            cancelButtonText: 'Cancelar',
            reverseButtons: true
        });

        if (!confirmacion.isConfirmed) return;

        Swal.fire({
            title: 'Guardando configuración...',
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: function () { Swal.showLoading(); }
        });

        try {
            const data = await enviarFormularioFetch(formSessionConfig, 'update_session');
            mostrarAlertaServidor(data, 'Configuración de sesión actualizada.');
        } catch (error) {
            const data = error.data || {};
            Swal.fire({
                title: 'No fue posible guardar',
                html: data.mensaje || error.message || 'Revisa los valores capturados.',
                icon: 'error',
                confirmButtonColor: '#f97316'
            });
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

// ==================== CONTRASEÑA TEMPORAL DEL PORTAL ====================
const formPasswordDefault =
    document.getElementById('formPasswordDefault');

if (formPasswordDefault) {
    formPasswordDefault.addEventListener(
        'submit',
        async function (event) {
            event.preventDefault();

            const nueva =
                document.getElementById(
                    'password_default_nueva'
                )?.value || '';

            const confirmar =
                document.getElementById(
                    'password_default_confirmar'
                )?.value || '';

            if (nueva !== confirmar) {
                Swal.fire({
                    title: 'Las contraseñas no coinciden',
                    text: 'Revisa ambos campos antes de guardar.',
                    icon: 'warning',
                    confirmButtonColor: '#f97316'
                });
                return;
            }

            const confirmacion = await Swal.fire({
                title: '¿Cambiar contraseña temporal?',
                text:
                    'Los próximos usuarios creados o restablecidos '
                    + 'recibirán esta nueva contraseña.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#f97316',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Sí, actualizar',
                cancelButtonText: 'Cancelar',
                reverseButtons: true
            });

            if (!confirmacion.isConfirmed) {
                return;
            }

            Swal.fire({
                title: 'Guardando configuración...',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: function () {
                    Swal.showLoading();
                }
            });

            try {
                const data = await enviarFormularioFetch(
                    formPasswordDefault,
                    'update_default_password'
                );

                mostrarAlertaServidor(
                    data,
                    'Contraseña temporal actualizada.'
                );
            } catch (error) {
                const data = error.data || {};

                Swal.fire({
                    title: 'No fue posible guardar',
                    html:
                        data.mensaje
                        || error.message
                        || 'Revisa los datos capturados.',
                    icon: 'error',
                    confirmButtonColor: '#f97316'
                });
            }
        }
    );
}

const togglePasswordDefault =
    document.getElementById('togglePasswordDefault');

if (togglePasswordDefault) {
    togglePasswordDefault.addEventListener(
        'click',
        function () {
            const actual =
                document.getElementById(
                    'passwordDefaultActual'
                );

            if (!actual) {
                return;
            }

            const visible =
                actual.dataset.visible === '1';

            actual.textContent = visible
                ? '••••••••'
                : (actual.dataset.password || '');

            actual.dataset.visible =
                visible ? '0' : '1';

            const icono =
                togglePasswordDefault.querySelector('i');

            if (icono) {
                icono.classList.toggle('fa-eye', visible);
                icono.classList.toggle(
                    'fa-eye-slash',
                    !visible
                );
            }
        }
    );
}

document
    .querySelectorAll('.password-field-toggle')
    .forEach(function (boton) {
        boton.addEventListener('click', function () {
            const objetivo =
                document.getElementById(
                    boton.dataset.target || ''
                );

            if (!objetivo) {
                return;
            }

            const mostrar =
                objetivo.type === 'password';

            objetivo.type =
                mostrar ? 'text' : 'password';

            const icono = boton.querySelector('i');

            if (icono) {
                icono.classList.toggle(
                    'fa-eye',
                    !mostrar
                );
                icono.classList.toggle(
                    'fa-eye-slash',
                    mostrar
                );
            }
        });
    });

// ==================== FORMULARIO USUARIO (MODAL) ====================
const formUsuario = document.getElementById('formUsuario');
if (formUsuario) {
    formUsuario.addEventListener('submit', async function(e) {
        e.preventDefault();

        const action = document.getElementById('usuarioAction').value;
        const esNuevo = action === 'crear_usuario';
        const nombre = document.getElementById('usuario_nombre').value.trim();
        const email = document.getElementById('usuario_email').value.trim();

        if (!nombre || !email) {
            Swal.fire({
                title: 'Campos incompletos',
                text: 'Captura el nombre y correo del usuario.',
                icon: 'warning',
                confirmButtonColor: '#f97316'
            });
            return;
        }

        const result = await Swal.fire({
            title: esNuevo ? '¿Crear nuevo usuario?' : '¿Actualizar usuario?',
            html: esNuevo
                ? `Se creará el usuario <strong>${nombre}</strong> y se enviarán sus datos de acceso a:<br><strong style="color:#f97316">${email}</strong>`
                : `Se actualizarán los datos de <strong>${nombre}</strong>.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#f97316',
            cancelButtonColor: '#64748b',
            confirmButtonText: esNuevo ? 'Sí, crear y notificar' : 'Sí, actualizar',
            cancelButtonText: 'Cancelar',
            reverseButtons: true
        });

        if (result.isConfirmed) {
            Swal.fire({
                title: esNuevo ? 'Creando usuario...' : 'Actualizando usuario...',
                html: esNuevo ? 'Estamos guardando el usuario y enviando la notificación por correo.' : 'Estamos guardando los cambios.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const data = await enviarFormularioFetch(formUsuario, action);
                $('#modalUsuario').modal('hide');
                mostrarAlertaServidor(data, esNuevo ? 'Usuario creado correctamente.' : 'Usuario actualizado correctamente.');
            } catch (error) {
                console.error(error);
                const data = error.data || {
                    ok: false,
                    tipo_mensaje: 'danger',
                    mensaje: error.message || 'No se pudo guardar el usuario.',
                    tab_activo: 'usuarios'
                };
                Swal.fire({
                    title: data.tipo_mensaje === 'warning' ? 'Aviso' : '¡Error!',
                    html: data.mensaje,
                    icon: data.tipo_mensaje === 'warning' ? 'warning' : 'error',
                    confirmButtonColor: '#f97316',
                    confirmButtonText: 'Aceptar'
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
const PASSWORD_TEMPORAL_ACTUAL = <?= json_encode(
    $password_temporal_actual,
    JSON_HEX_TAG
    | JSON_HEX_APOS
    | JSON_HEX_AMP
    | JSON_HEX_QUOT
    | JSON_UNESCAPED_UNICODE
) ?>;

function escaparHtml(valor) {
    const elemento = document.createElement('div');
    elemento.textContent = String(valor ?? '');
    return elemento.innerHTML;
}

function abrirModalUsuario() {
    document.getElementById('modalUsuarioTitulo').textContent = 'Nuevo Usuario';
    document.getElementById('usuarioAction').value = 'crear_usuario';
    document.getElementById('id_usuario').value = '';
    document.getElementById('usuario_nombre').value = '';
    document.getElementById('usuario_email').value = '';
    document.getElementById('usuario_password').value = '';
    document.getElementById('usuario_rol').value = 'vendedor';
    document.getElementById('usuario_activo').value = '1';
    document.getElementById('passwordHelp').innerHTML =
        'Contraseña temporal configurada: <strong>'
        + escaparHtml(PASSWORD_TEMPORAL_ACTUAL)
        + '</strong>';
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
        html:
            `Usuario: <strong>${escaparHtml(nombre)}</strong>`
            + '<br>La contraseña se establecerá como: '
            + `<strong>${escaparHtml(PASSWORD_TEMPORAL_ACTUAL)}</strong>`,
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
    title: '<?= $tipo_mensaje === 'success' ? '¡Listo!' : ($tipo_mensaje === 'warning' ? 'Usuario creado con aviso' : ($tipo_mensaje === 'danger' ? '¡Error!' : 'Información')) ?>',
    html: `<?= str_replace(['`', "\r", "\n"], ['\\`', '', ''], $mensaje) ?>`,
    icon: '<?= $tipo_mensaje === 'success' ? "success" : ($tipo_mensaje === "warning" ? "warning" : ($tipo_mensaje === "danger" ? "error" : "info")) ?>',
    confirmButtonColor: '#f97316',
    confirmButtonText: 'Aceptar',
    background: '#ffffff',
    color: '#1f2937',
    customClass: {
        popup: 'swal2-config-popup',
        confirmButton: 'swal2-config-confirm'
    }
});
<?php endif; ?>

</script>
</body>
</html>