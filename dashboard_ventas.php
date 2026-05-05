<?php
require_once 'includes/session.php';
require_once 'includes/db.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

$nombre_usuario = $_SESSION['nombre'] ?? 'Usuario';
$rol_usuario = $_SESSION['rol'] ?? 'vendedor';

// Obtener configuración de la tienda
$config = [];
$sql_config = "SELECT nombre, logo FROM configuracion_galeria WHERE id = 1";
$result_config = $conn->query($sql_config);
if ($result_config && $result_config->num_rows > 0) {
    $config = $result_config->fetch_assoc();
}
$tienda_nombre = $config['nombre'] ?? 'Tienda Pescadores';
$tienda_logo = $config['logo'] ?? '';

// Variables para el breadcrumb
$pagina_actual = "Registrar venta";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ventas | <?= htmlspecialchars($tienda_nombre) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Estilos del Dashboard -->
    <link rel="stylesheet" href="css/dashboard-admin.css">
    
    <style>
        .ventas-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Tarjetas de selección de método */
        .method-card {
            background: white;
            border-radius: 28px;
            padding: 3rem 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid #eef2f6;
            height: 100%;
            position: relative;
            overflow: hidden;
        }
        
        .method-card:hover {
            transform: translateY(-8px);
            border-color: #f97316;
            box-shadow: 0 25px 40px -12px rgba(249, 115, 22, 0.3);
        }
        
        .method-icon {
            width: 100px;
            height: 100px;
            background: #fef3e8;
            border-radius: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem auto;
            transition: all 0.3s ease;
        }
        
        .method-card:hover .method-icon {
            background: #f97316;
            transform: scale(1.05);
        }
        
        .method-card:hover .method-icon i {
            color: white;
        }
        
        .method-icon i {
            font-size: 2.8rem;
            color: #f97316;
            transition: all 0.3s ease;
        }
        
        .method-card h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.75rem;
        }
        
        .method-card p {
            font-size: 0.9rem;
            color: #64748b;
            margin-bottom: 0;
        }
        
        .method-badge {
            position: absolute;
            bottom: 20px;
            right: 20px;
            font-size: 0.7rem;
            color: #94a3b8;
            background: #f8fafc;
            padding: 4px 12px;
            border-radius: 20px;
        }
        
        /* Breadcrumb personalizado */
        .custom-breadcrumb {
            background: white;
            border-radius: 16px;
            padding: 0.75rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            border: 1px solid #eef2f6;
        }
        
        .custom-breadcrumb .breadcrumb-item {
            font-size: 0.85rem;
        }
        
        .custom-breadcrumb .breadcrumb-item a {
            color: #64748b;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        
        .custom-breadcrumb .breadcrumb-item a:hover {
            color: #f97316;
        }
        
        .custom-breadcrumb .breadcrumb-item.active {
            color: #f97316;
            font-weight: 500;
        }
        
        .custom-breadcrumb .breadcrumb-item i {
            margin-right: 6px;
            font-size: 0.75rem;
        }
        
        /* Título del módulo */
        .section-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .section-title i {
            font-size: 1.8rem;
            color: #f97316;
        }
        
        .section-divider {
            height: 4px;
            width: 60px;
            background: #f97316;
            border-radius: 4px;
            margin: 15px 0 10px 0;
        }
        
        .section-header p {
            color: #64748b;
        }
        
        /* Fondo del content-wrapper */
        .content-wrapper {
            background: #fff7ea !important;
            min-height: 100vh;
            padding: 20px 0;
        }
        
        @media (max-width: 768px) {
            .method-card {
                padding: 2rem 1.5rem;
            }
            
            .method-icon {
                width: 80px;
                height: 80px;
            }
            
            .method-icon i {
                font-size: 2.2rem;
            }
            
            .method-card h3 {
                font-size: 1.2rem;
            }
            
            .section-title {
                font-size: 1.4rem;
            }
        }
    </style>
</head>
<body>

<?php require_once 'includes/navbar.php'; ?>

<div class="content-wrapper">
    <div class="container-fluid px-4 ventas-container">
        
        <!-- Breadcrumb -->
        <div class="custom-breadcrumb">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item">
                        <a href="<?= $rol_usuario === 'administrador' ? 'dashboard_admin.php' : 'dashboard_vendedor.php' ?>">
                            <i class="fas fa-home"></i> Inicio
                        </a>
                    </li>
                    <li class="breadcrumb-item active">
                        <i class="fas fa-cash-register"></i> <?= $pagina_actual ?>
                    </li>
                </ol>
            </nav>
        </div>
        
        <!-- Título -->
        <div class="section-header mb-4">
            <div class="section-title">
                <i class="fas fa-cash-register"></i>
                <span>Nueva Venta</span>
            </div>
            <div class="section-divider"></div>
            <p class="mt-2 mb-0">Selecciona el método de venta para continuar</p>
        </div>
        
        <!-- Tarjetas superiores (2 columnas) -->
        <div class="row g-4 justify-content-center">
            <!-- Método 1: Código de Barras -->
            <div class="col-md-6">
                <div class="method-card" onclick="window.location.href='ventas.php'">
                    <div class="method-icon">
                        <i class="fas fa-barcode"></i>
                    </div>
                    <h3>Venta por Código de Barras</h3>
                    <p>Escanea o ingresa el código de barras del producto</p>
                    <div class="method-badge">
                        <i class="fas fa-qrcode me-1"></i> Rápido y preciso
                    </div>
                </div>
            </div>
            
            <!-- Método 2: Conteo Manual -->
            <div class="col-md-6">
                <div class="method-card" onclick="window.location.href='ventas_proveedor.php'">
                    <div class="method-icon">
                        <i class="fas fa-calculator"></i>
                    </div>
                    <h3>Venta por Conteo Manual</h3>
                    <p>Busca y selecciona productos de la lista</p>
                    <div class="method-badge">
                        <i class="fas fa-search me-1"></i> Buscar producto
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tarjeta inferior centrada (Pedidos de Usuarios) - MISMO TAMAÑO -->
        <div class="row mt-4 justify-content-center">
            <div class="col-md-6">
                <div class="method-card" onclick="window.location.href='pedidos.php'">
                    <div class="method-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <h3>Pedidos de Usuarios</h3>
                    <p>Gestiona los pedidos realizados o por realizar de tus clientes</p>
                    <div class="method-badge">
                        <i class="fas fa-clock me-1"></i> Pendientes por atender
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>