<?php
/**
 * Archivo de configuración para el envío de correos
 */

function obtenerConfiguracionCorreo($conn) {
    $config = [
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => 587,
        'smtp_usuario' => '',
        'smtp_password' => '',
        'smtp_secure' => 'tls',
        'correo_origen' => '',
        'nombre_origen' => 'Tienda Pescadores',
        'activo' => false
    ];
    
    $sql = "SELECT * FROM configuracion_correo WHERE activo = 1 LIMIT 1";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $config['smtp_host'] = $row['smtp_host'];
        $config['smtp_port'] = $row['smtp_port'];
        $config['smtp_usuario'] = $row['smtp_usuario'];
        $config['smtp_password'] = $row['smtp_password'];
        $config['smtp_secure'] = $row['smtp_secure'];
        $config['correo_origen'] = $row['correo_origen'];
        $config['nombre_origen'] = $row['nombre_origen'];
        $config['activo'] = true;
    }
    
    return $config;
}

function enviarCorreoTicket($conn, $correo_destino, $ruta_adjunto, $folio) {
    $configCorreo = obtenerConfiguracionCorreo($conn);
    
    if (!$configCorreo['activo'] || empty($configCorreo['smtp_usuario'])) {
        return false;
    }
    
    // Obtener configuración de la tienda
    $sqlTienda = "SELECT nombre, telefono, email, direccion FROM configuracion_galeria WHERE id = 1";
    $resultTienda = $conn->query($sqlTienda);
    $tienda = $resultTienda->fetch_assoc();
    $nombreTienda = $tienda['nombre'] ?? 'Tienda Pescadores';
    $telefonoTienda = $tienda['telefono'] ?? '';
    $emailTienda = $tienda['email'] ?? '';
    $direccionTienda = $tienda['direccion'] ?? '';
    
    require_once('includes/PHPMailer/src/Exception.php');
    require_once('includes/PHPMailer/src/PHPMailer.php');
    require_once('includes/PHPMailer/src/SMTP.php');
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    $mail->isHTML(true);
    
    // Versión SIMPLE - texto plano con formato básico
    $cuerpoHTML = '
    <div style="font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px;">
        <div style="background-color: #f97316; padding: 15px; text-align: center; border-radius: 8px 8px 0 0;">
            <h2 style="color: white; margin: 0;">' . htmlspecialchars($nombreTienda) . '</h2>
        </div>
        
        <div style="padding: 20px;">
            <p style="text-align: center; font-size: 14px; color: #333;">
                <strong>Gracias por su compra</strong>
            </p>
            
            <p style="font-size: 12px; color: #555;">Su pedido ha sido registrado correctamente.</p>
            
            <div style="background-color: #fff7ed; padding: 10px; text-align: center; margin: 15px 0; border: 1px solid #ffedd5;">
                <strong style="color: #f97316;">FOLIO DE COMPRA</strong><br>
                <span style="color: #ea580c; font-size: 16px; font-weight: bold;">' . htmlspecialchars($folio) . '</span>
            </div>
            
            <table style="width: 100%; background-color: #f8fafc; padding: 10px;">
                <tr>
                    <td style="padding: 5px; font-size: 12px; color: #555;">Fecha:</td>
                    <td style="padding: 5px; font-size: 12px; color: #f97316; text-align: right;">' . date('d/m/Y H:i:s') . '</td>
                </tr>
                <tr>
                    <td style="padding: 5px; font-size: 12px; color: #555;">Metodo de pago:</td>
                    <td style="padding: 5px; font-size: 12px; color: #f97316; text-align: right;">Ver ticket adjunto</td>
                </tr>
            </table>
            
            <div style="background-color: #f1f5f9; padding: 10px; text-align: center; margin-top: 15px;">
                <p style="margin: 0; font-size: 11px; color: #555;">El ticket en formato PDF se encuentra adjunto a este correo.</p>
            </div>
        </div>
        
        <div style="background-color: #f8fafc; padding: 10px; text-align: center; font-size: 10px; color: #999; border-top: 1px solid #ddd;">
            ' . htmlspecialchars($nombreTienda) . '<br>
            ' . (!empty($telefonoTienda) ? 'Tel: ' . htmlspecialchars($telefonoTienda) . '<br>' : '') . '
            ' . (!empty($emailTienda) ? 'Email: ' . htmlspecialchars($emailTienda) . '<br>' : '') . '
            Este es un correo automatico, por favor no responder.
        </div>
    </div>';
    
    // Texto plano para clientes que no soportan HTML
    $textoPlano = "========================================\n";
    $textoPlano .= strtoupper($nombreTienda) . "\n";
    $textoPlano .= "========================================\n\n";
    $textoPlano .= "Gracias por su compra!\n\n";
    $textoPlano .= "Su pedido ha sido registrado correctamente.\n\n";
    $textoPlano .= "FOLIO DE COMPRA: " . $folio . "\n";
    $textoPlano .= "Fecha: " . date('d/m/Y H:i:s') . "\n\n";
    $textoPlano .= "El ticket en formato PDF se encuentra adjunto a este correo.\n\n";
    $textoPlano .= "----------------------------------------\n";
    $textoPlano .= $nombreTienda . "\n";
    if (!empty($telefonoTienda)) $textoPlano .= "Tel: " . $telefonoTienda . "\n";
    if (!empty($emailTienda)) $textoPlano .= "Email: " . $emailTienda . "\n";
    $textoPlano .= "----------------------------------------\n";
    $textoPlano .= "Este es un correo automatico, por favor no responder.\n";
    
    try {
        $mail->isSMTP();
        $mail->Host = $configCorreo['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $configCorreo['smtp_usuario'];
        $mail->Password = $configCorreo['smtp_password'];
        
        if ($configCorreo['smtp_secure'] === 'tls') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        }
        
        $mail->Port = $configCorreo['smtp_port'];
        $mail->setFrom($configCorreo['correo_origen'], $configCorreo['nombre_origen']);
        $mail->addAddress($correo_destino);
        $mail->Subject = "Ticket de compra - " . $nombreTienda;
        $mail->Body = $cuerpoHTML;
        $mail->AltBody = $textoPlano;
        
        if (file_exists($ruta_adjunto)) {
            $mail->addAttachment($ruta_adjunto);
        }
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Error al enviar correo: " . $e->getMessage());
        return false;
    }
}
?>