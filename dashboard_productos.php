<?php
ob_start();
session_start();
require_once 'includes/csrf.php';
require_once 'includes/db.php';

$rol_actual = strtolower(trim((string)($_SESSION['rol'] ?? '')));
$roles_administrativos = ['administrador', 'super_administrador'];

if (
    !isset($_SESSION['usuario_id']) ||
    !in_array($rol_actual, $roles_administrativos, true)
) {
    header("Location: login.php");
    exit;
}


include 'includes/header.php';
include 'includes/navbar.php';
?>

<style>
/* =====================================================
   ESTILOS DASHBOARD PRODUCTOS - VERSIÓN SIMPLIFICADA
===================================================== */
:root {
    --primary: #f97316;
    --primary-dark: #ea580c;
    --primary-light: #ffedd5;
    --success: #22c55e;
    --success-dark: #16a34a;
}

.content-wrapper {
    min-height: 100vh;
    padding: 24px;
    background: linear-gradient(135deg, #fef9f1 0%, #f8fafc 100%);
}

/* Breadcrumb */
.custom-breadcrumb {
    background: #ffffff !important;
    border-radius: 16px;
    padding: 0.85rem 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    border: 1px solid #eef2f6;
}
.custom-breadcrumb .breadcrumb {
    margin-bottom: 0;
    background: transparent !important;
}
.custom-breadcrumb .breadcrumb-item {
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
}
.custom-breadcrumb .breadcrumb-item a {
    color: #64748b;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: color 0.2s ease;
}
.custom-breadcrumb .breadcrumb-item a:hover {
    color: #f97316;
}
.custom-breadcrumb .breadcrumb-item.active {
    color: #f97316;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.custom-breadcrumb .breadcrumb-item i {
    font-size: 0.8rem;
}
.custom-breadcrumb .breadcrumb-item + .breadcrumb-item::before {
    content: "›";
    font-size: 1.2rem;
    color: #cbd5e1;
    margin: 0 8px;
}

/* Header de sección */
.section-header {
    margin-bottom: 1.5rem;
}
.section-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.section-title i {
    color: #f97316;
    font-size: 1.3rem;
}
.section-divider {
    height: 3px;
    width: 50px;
    background: linear-gradient(90deg, #f97316, #ffb347);
    border-radius: 3px;
    margin-top: 0.5rem;
}

/* Tarjetas de acción - Tamaño mediano */
.action-cards {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 28px;
    margin-top: 30px;
    margin-bottom: 30px;
}
.action-card {
    background: white;
    border-radius: 24px;
    padding: 40px 30px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 2px solid #eef2f6;
    position: relative;
    overflow: hidden;
    min-height: 320px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.action-card:hover {
    transform: translateY(-8px);
    border-color: #f97316;
    box-shadow: 0 20px 30px -12px rgba(249, 115, 22, 0.25);
}
.action-icon {
    width: 90px;
    height: 90px;
    background: #fef3e8;
    border-radius: 45px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    transition: all 0.3s ease;
}
.action-card:hover .action-icon {
    background: #f97316;
    transform: scale(1.05);
}
.action-icon i {
    font-size: 2.8rem;
    color: #f97316;
    transition: all 0.3s ease;
}
.action-card:hover .action-icon i {
    color: white;
}
.action-card h3 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 10px;
}
.action-card p {
    font-size: 0.85rem;
    color: #64748b;
    margin-bottom: 0;
}
.action-badge {
    position: absolute;
    bottom: 16px;
    right: 16px;
    font-size: 0.7rem;
    color: #94a3b8;
}
.action-badge i {
    font-size: 0.65rem;
}

/* Botón flotante */
.btn-flotante {
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 55px;
    height: 55px;
    border-radius: 50%;
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: white;
    border: none;
    box-shadow: 0 4px 15px rgba(249, 115, 22, 0.4);
    cursor: pointer;
    transition: all 0.3s ease;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.btn-flotante i {
    font-size: 1.3rem;
}
.btn-flotante:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 20px rgba(249, 115, 22, 0.5);
}

/* Modal */
.modal-dashboard .modal-content {
    border-radius: 20px;
}
.modal-dashboard .modal-header {
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: white;
    border-radius: 20px 20px 0 0;
}
.modal-dashboard .modal-header .close {
    color: white;
    opacity: 0.8;
}
.modal-dashboard .modal-header .close:hover {
    opacity: 1;
}
.modal-dashboard .modal-footer {
    border-top: none;
    padding: 1rem 1.5rem 1.5rem;
}

/* Tarjetas mini para el modal */
.action-card-mini {
    background: white;
    border-radius: 16px;
    padding: 25px 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 1px solid #eef2f6;
    height: 100%;
}
.action-card-mini:hover {
    transform: translateY(-5px);
    border-color: #f97316;
    box-shadow: 0 8px 20px rgba(249, 115, 22, 0.1);
}
.action-icon-mini {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 12px;
}
.action-icon-mini i {
    font-size: 1.8rem;
}
.action-card-mini h4 {
    font-size: 1.1rem;
    font-weight: 700;
    margin-bottom: 6px;
    color: #1e293b;
}
.action-card-mini p {
    font-size: 0.75rem;
    color: #64748b;
    margin: 0;
}
.bg-primary-light { background: #fef3e8; }
.bg-primary-light i { color: #f97316; }
.bg-success-light { background: #dcfce7; }
.bg-success-light i { color: #22c55e; }

/* Responsive */
@media (max-width: 992px) {
    .action-card {
        padding: 30px 20px;
        min-height: 280px;
    }
    .action-icon {
        width: 75px;
        height: 75px;
    }
    .action-icon i {
        font-size: 2.3rem;
    }
    .action-card h3 {
        font-size: 1.3rem;
    }
}

@media (max-width: 768px) {
    .content-wrapper { padding: 16px; }
    .action-cards { 
        grid-template-columns: 1fr; 
        gap: 20px;
        margin-top: 20px;
    }
    .action-card {
        padding: 25px 20px;
        min-height: 240px;
    }
    .action-icon {
        width: 65px;
        height: 65px;
        margin-bottom: 15px;
    }
    .action-icon i {
        font-size: 2rem;
    }
    .action-card h3 {
        font-size: 1.2rem;
    }
    .btn-flotante {
        width: 48px;
        height: 48px;
        bottom: 20px;
        right: 20px;
    }
    .btn-flotante i {
        font-size: 1.1rem;
    }
}
</style>

<div class="content-wrapper">
    <div class="container-fluid">
        
        <!-- BREADCRUMB -->
        <div class="custom-breadcrumb">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="<?= in_array($rol_actual, $roles_administrativos, true) ? 'dashboard_admin.php' : 'dashboard_vendedor.php' ?>">
                            <i class="fas fa-home"></i> Inicio
                        </a>
                    </li>
                    <li class="breadcrumb-item active">
                        <i class="fas fa-boxes"></i> Gestión de Productos
                    </li>
                </ol>
            </nav>
        </div>
        
        <div class="section-header">
            <div class="section-title">
                <i class="fas fa-boxes"></i>
                <span>Gestión de Productos</span>
            </div>
            <div class="section-divider"></div>
            <p class="text-muted mt-2 mb-0">Administra tu inventario de productos e insumos</p>
        </div>
        
        <!-- Tarjetas de acción principales -->
        <div class="action-cards">
            <div class="action-card" onclick="abrirModalNuevo()">
                <div class="action-icon">
                    <i class="fas fa-plus-circle"></i>
                </div>
                <h3>Nuevo Producto / Insumo</h3>
                <p>Agrega un nuevo producto o insumo al inventario</p>
                <div class="action-badge">
                    <i class="fas fa-box"></i> Productos | <i class="fas fa-cubes"></i> Insumos
                </div>
            </div>
            <div class="action-card" onclick="window.location.href='ajustes_productos.php'">
                <div class="action-icon">
                    <i class="fas fa-sliders-h"></i>
                </div>
                <h3>Ajustes de Productos</h3>
                <p>Gestiona stock, precios y edita productos existentes</p>
                <div class="action-badge">
                    <i class="fas fa-edit"></i> Editar | <i class="fas fa-plus-circle"></i> Agregar stock
                </div>
            </div>
        </div>
        
    </div>
</div>

<!-- MODAL DE SELECCIÓN -->
<div class="modal fade modal-dashboard" id="modalSeleccion" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle mr-2"></i> ¿Qué deseas agregar?
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <div class="row">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <div class="action-card-mini" onclick="window.location.href='productos.php?tipo=producto&action=create'">
                            <div class="action-icon-mini bg-primary-light">
                                <i class="fas fa-box"></i>
                            </div>
                            <h4>Producto</h4>
                            <p>Artículos para la venta<br>Generan códigos de barras</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="action-card-mini" onclick="window.location.href='productos.php?tipo=insumo&action=create'">
                            <div class="action-icon-mini bg-success-light">
                                <i class="fas fa-cubes"></i>
                            </div>
                            <h4>Insumo</h4>
                            <p>Materiales para control interno<br>No generan códigos de barras</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Función para abrir modal de selección
function abrirModalNuevo() {
    $('#modalSeleccion').modal('show');
}
</script>