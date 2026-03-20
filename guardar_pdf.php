<?php
// guardar_pdf.php
session_start();
require_once 'includes/db.php'; // Ajusta la ruta según tu estructura

header('Content-Type: application/json');

// Verificar que sea una petición POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'error' => 'Método no permitido'
    ]);
    exit;
}

// Verificar que se haya enviado un archivo
if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
    $error = isset($_FILES['pdf_file']) ? 'Error al subir el archivo' : 'No se recibió ningún archivo';
    echo json_encode([
        'success' => false,
        'error' => $error
    ]);
    exit;
}

$carpeta = $_POST['carpeta'] ?? 'reportes_ventas';
$archivo = $_FILES['pdf_file'];
$tipoPDF = $_POST['tipo'] ?? 'general';
$proveedor = $_POST['proveedor'] ?? '';
$total_registros = intval($_POST['total_registros'] ?? 0);

// Validar que sea un PDF
$extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
if ($extension !== 'pdf') {
    echo json_encode([
        'success' => false,
        'error' => 'El archivo debe ser PDF'
    ]);
    exit;
}

// Crear la carpeta si no existe
$ruta_carpeta = "uploads/" . $carpeta . "/";
if (!file_exists($ruta_carpeta)) {
    if (!mkdir($ruta_carpeta, 0777, true)) {
        echo json_encode([
            'success' => false,
            'error' => 'No se pudo crear la carpeta de destino'
        ]);
        exit;
    }
}

// Generar nombre único para evitar sobrescrituras
$nombre_archivo = $archivo['name'];
$ruta_completa = $ruta_carpeta . $nombre_archivo;

// Verificar si el archivo ya existe y generar nombre único
$contador = 1;
$info = pathinfo($nombre_archivo);
while (file_exists($ruta_completa)) {
    $nombre_archivo = $info['filename'] . "_" . $contador . "." . $info['extension'];
    $ruta_completa = $ruta_carpeta . $nombre_archivo;
    $contador++;
}

// Mover el archivo
if (move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
    
    // AHORA SÍ, guardar en base de datos (solo cuando se genera el PDF)
    $usuario_id = $_SESSION['usuario_id'] ?? 0;
    $usuario_nombre = $_SESSION['nombre'] ?? 'Sistema';
    $proveedor_val = $proveedor ?: 'todos';
    
    // Verificar si ya existe un registro con el mismo nombre para evitar duplicados
    $check_sql = "SELECT id FROM historial_reportes WHERE nombre_archivo = '$ruta_completa'";
    $check_result = $conn->query($check_sql);
    
    if ($check_result && $check_result->num_rows == 0) {
        // Guardar en base de datos solo si no existe
        $sql = "INSERT INTO historial_reportes (
            usuario_id, 
            usuario_nombre, 
            tipo_reporte, 
            modulo, 
            proveedor, 
            fecha_generacion, 
            total_registros, 
            nombre_archivo
        ) VALUES (
            $usuario_id, 
            '$usuario_nombre', 
            'pdf', 
            'reporte de ventas', 
            '$proveedor_val', 
            NOW(), 
            $total_registros, 
            '$ruta_completa'
        )";
        
        $conn->query($sql);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'PDF guardado correctamente',
        'ruta' => $ruta_completa,
        'nombre' => $nombre_archivo
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Error al mover el archivo'
    ]);
}
?>