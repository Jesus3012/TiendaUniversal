<?php
// guardar_historial_reporte.php
include 'includes/db.php';

function guardarHistorialReporte($conn, $data) {
    // Verificar que la conexión sea válida
    if (!$conn) {
        error_log("Error: Conexión a BD no válida en guardarHistorialReporte");
        return false;
    }
    
    $query = "INSERT INTO historial_reportes (
        usuario_id, 
        usuario_nombre, 
        tipo_reporte, 
        modulo, 
        proveedor, 
        fecha_generacion, 
        total_registros, 
        nombre_archivo
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        error_log("Error preparando consulta: " . $conn->error);
        return false;
    }
    
    // modulo siempre será 'reporte proveedor' para este tipo de reportes
    $modulo = 'reporte proveedor';
    
    $stmt->bind_param(
        "isssssis", // i: integer, s: string, s: string, s: string, s: string, s: string, i: integer, s: string
        $data['usuario_id'],
        $data['usuario_nombre'],
        $data['tipo_reporte'],
        $modulo,
        $data['proveedor'],
        $data['fecha_generacion'],
        $data['total_registros'],
        $data['nombre_archivo']
    );
    
    $result = $stmt->execute();
    
    if (!$result) {
        error_log("Error ejecutando consulta: " . $stmt->error);
        return false;
    }
    
    $stmt->close();
    return true;
}
?>