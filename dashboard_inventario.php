<?php
date_default_timezone_set('America/Mexico_City');

require_once 'includes/auth_guard.php';
require_once 'includes/header.php';
require_once 'includes/navbar.php';

$rol_usuario = permisos_normalizar_rol($_SESSION['rol'] ?? 'vendedor');

// Obtener proveedores para el modal
$proveedores = [];
$query = $conn->query("SELECT DISTINCT proveedor FROM productos WHERE activo = 1 AND proveedor IS NOT NULL AND proveedor != '' ORDER BY proveedor");
while ($row = $query->fetch_assoc()) {
    $proveedores[] = $row['proveedor'];
}

// Obtener categorías para el modal
$categorias = [];
$query = $conn->query("SELECT DISTINCT categoria FROM productos WHERE activo = 1 AND categoria IS NOT NULL AND categoria != '' ORDER BY categoria");
while ($row = $query->fetch_assoc()) {
    $categorias[] = $row['categoria'];
}
?>

<!-- Estilos adicionales -->
<style>
/* =====================================================
   ESTILOS DASHBOARD NARANJA - INVENTARIO
===================================================== */
:root {
    --primary: #f97316;
    --primary-dark: #ea580c;
    --primary-light: #ffedd5;
    --success: #22c55e;
    --danger: #ef4444;
    --warning: #f59e0b;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-600: #4b5563;
    --gray-800: #1f2937;
}

.content-wrapper {
    min-height: 100vh;
    padding: 20px;
    background: linear-gradient(135deg, #fef9f1 0%, #f8fafc 100%);
}

/* Breadcrumb personalizado */
.custom-breadcrumb {
    background: #ffffff !important;
    border-radius: 16px;
    padding: 0.85rem 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    border: 1px solid #eef2f6;
}
.custom-breadcrumb .breadcrumb { margin-bottom: 0; }
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
.custom-breadcrumb .breadcrumb-item a:hover { color: #f97316; }
.custom-breadcrumb .breadcrumb-item.active {
    color: #f97316;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.custom-breadcrumb .breadcrumb-item i { font-size: 0.8rem; }
.custom-breadcrumb .breadcrumb-item + .breadcrumb-item::before {
    content: "›";
    font-size: 1.2rem;
    color: #cbd5e1;
    margin: 0 8px;
}
.custom-breadcrumb,
.custom-breadcrumb .breadcrumb,
.custom-breadcrumb .breadcrumb-item,
.custom-breadcrumb nav {
    background: #ffffff !important;
}

/* Tarjetas de selección */
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
.method-card:hover .method-icon i { color: white; }
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
}
.section-header { margin-bottom: 1.5rem; }
.section-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.section-title i { color: #f97316; font-size: 1.3rem; }
.section-divider {
    height: 3px;
    width: 50px;
    background: linear-gradient(90deg, #f97316, #ffb347);
    border-radius: 3px;
    margin-top: 0.5rem;
}

/* Estilos del Modal - Versión mejorada */
.modal-reporte .modal-content {
    border-radius: 20px;
    border: none;
    overflow: hidden;
}
.modal-reporte .modal-header {
    background: linear-gradient(135deg, #f97316, #ea580c);
    padding: 1rem 1.5rem;
    border-bottom: none;
}
.modal-reporte .modal-header .modal-title {
    color: white;
    font-weight: 600;
    font-size: 1.1rem;
}
/* CORRECCIÓN DEFINITIVA: Botón cerrar visible */
.modal-reporte .modal-header .btn-close {
    background-color: rgba(255, 255, 255, 0.2);
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23ffffff'%3e%3cpath d='M.293.293a1 1 0 011.414 0L8 6.586 14.293.293a1 1 0 111.414 1.414L9.414 8l6.293 6.293a1 1 0 01-1.414 1.414L8 9.414l-6.293 6.293a1 1 0 01-1.414-1.414L6.586 8 .293 1.707a1 1 0 010-1.414z'/%3e%3c/svg%3e");
    background-size: 0.8rem;
    background-position: center;
    background-repeat: no-repeat;
    border-radius: 50% !important;
    opacity: 1;
    border-radius: 6px;
    width: 2rem;
    height: 2rem;
    padding: 0;
    margin: -0.5rem -0.5rem -0.5rem auto;
    box-shadow: none;
    border: none;
}

.modal-reporte .modal-header .btn-close:hover {
    background-color: rgba(255, 255, 255, 0.3);
    opacity: 1;
}
.modal-reporte .modal-body {
    padding: 1.5rem;
}
.modal-reporte .form-label {
    font-weight: 500;
    color: #1e293b;
    margin-bottom: 0.4rem;
    font-size: 0.85rem;
}
.modal-reporte .form-control,
.modal-reporte .form-select {
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    padding: 0.5rem 0.8rem;
    font-size: 0.9rem;
    transition: all 0.2s ease;
}
.modal-reporte .form-control:focus,
.modal-reporte .form-select:focus {
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
    outline: none;
}
.modal-reporte .resumen-filtros {
    background: #f8fafc;
    border-radius: 12px;
    padding: 0.8rem 1rem;
    border: 1px solid #e2e8f0;
    margin-top: 1rem;
}
.modal-reporte .resumen-filtros .badge {
    background: #ffffff;
    color: #1e293b;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 500;
    border: 1px solid #e2e8f0;
    margin-right: 6px;
    margin-bottom: 4px;
    display: inline-block;
}
.modal-reporte .btn-cancelar {
    background: #f1f5f9;
    color: #475569;
    border-radius: 10px;
    padding: 0.5rem 1.2rem;
    font-weight: 500;
    font-size: 0.85rem;
    border: none;
    transition: all 0.2s;
}
.modal-reporte .btn-cancelar:hover {
    background: #e2e8f0;
}
.modal-reporte .btn-generar {
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: white;
    border-radius: 10px;
    padding: 0.5rem 1.5rem;
    font-weight: 500;
    font-size: 0.85rem;
    border: none;
    transition: all 0.2s;
}
.modal-reporte .btn-generar:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(249, 115, 22, 0.3);
}
.modal-reporte .btn-generar:disabled {
    opacity: 0.7;
    transform: none;
}
.modal-reporte hr {
    margin: 1rem 0;
    border-color: #eef2f6;
}

/* CORRECCIÓN: Fila de dos columnas mejorada */
.filtros-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}
.filtros-row .filtro-col {
    flex: 1;
    min-width: 0; /* Permite que el contenido se ajuste */
}

/* CORRECCIÓN: Asegurar que los selects tengan ancho completo */
.modal-reporte .form-select {
    width: 100%;
    min-width: 0;
}

/* Botón limpiar */
.btn-limpiar {
    background: transparent;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.3rem 0.8rem;
    font-size: 0.7rem;
    color: #64748b;
    transition: all 0.2s;
}
.btn-limpiar:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}

/* FUERZA separación entre cards en móvil */
@media (max-width: 768px) {
    .row.g-4 {
        display: flex;
        flex-direction: column;
        gap: 1.25rem !important;
    }
    
    .row.g-4 .col-md-6 {
        width: 100%;
        margin-bottom: 0;
    }
    
    .method-card {
        margin: 0 !important;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        transition: transform 0.2s;
    }
    
    .method-card:active {
        transform: scale(0.98);
    }
}
</style>

<div class="content-wrapper">
    <div class="container-fluid">
        
        <!-- BREADCRUMB BLANCO -->
        <div class="custom-breadcrumb">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="<?= $rol_usuario === 'administrador' ? 'dashboard_admin.php' : 'dashboard_vendedor.php' ?>">
                            <i class="fas fa-home"></i> Inicio
                        </a>
                    </li>
                    <li class="breadcrumb-item active">
                        <i class="fas fa-boxes"></i> Inventario
                    </li>
                </ol>
            </nav>
        </div>
        
        <div class="section-header mb-4">
            <div class="section-title">
                <i class="fas fa-boxes"></i>
                <span>Gestión de Inventario</span>
            </div>
            <div class="section-divider"></div>
            <p class="text-muted mt-2 mb-0">Selecciona el tipo de gestión de inventario que deseas realizar</p>
        </div>
        
        <div class="row g-4">
            <!-- Botón 1: Inventario General -->
            <div class="col-md-6">
                <div class="method-card" onclick="window.location.href='inventario_admin.php'">
                    <div class="method-icon">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <h3>Inventario General</h3>
                    <p>Gestiona todos los productos del sistema</p>
                    <div class="method-badge">
                        <i class="fas fa-chart-line"></i> Vista completa
                    </div>
                </div>
            </div>
            
            <!-- Botón 2: Inventario por Proveedor (ABRE MODAL) -->
            <div class="col-md-6">
                <div class="method-card" onclick="abrirModalReporte()">
                    <div class="method-icon">
                        <i class="fas fa-truck"></i>
                    </div>
                    <h3>Inventario por Proveedor</h3>
                    <p>Filtra productos por proveedor y genera reporte</p>
                    <div class="method-badge">
                        <i class="fas fa-file-pdf"></i> Generar reporte PDF
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</div>

<!-- MODAL PARA GENERAR REPORTE DE INVENTARIO FILTRADO -->
<div class="modal fade modal-reporte" id="modalReporteProveedor" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-pdf me-2"></i> Generar Reporte de Inventario
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            
            <form id="formGenerarReporte" method="GET" action="reporte_inventario_filtrado.php" target="_blank">
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        <i class="fas fa-info-circle me-1" style="color: #f97316;"></i>
                        Selecciona los filtros que deseas aplicar al reporte
                    </p>
                    
                    <!-- CORRECCIÓN: Fila 1 - Proveedor y Tipo (dos columnas con mejor espaciado) -->
                    <div class="filtros-row">
                        <div class="filtro-col">
                            <label class="form-label">
                                <i class="fas fa-truck" style="color: #f97316;"></i> Proveedor
                            </label>
                            <select name="proveedor" id="reporte_proveedor" class="form-select">
                                <option value="">Todos los proveedores</option>
                                <?php foreach ($proveedores as $prov): ?>
                                    <option value="<?= htmlspecialchars($prov) ?>"><?= htmlspecialchars($prov) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filtro-col">
                            <label class="form-label">
                                <i class="fas fa-boxes" style="color: #f97316;"></i> Tipo
                            </label>
                            <select name="tipo" id="reporte_tipo" class="form-select" style="width: 100%;">
                                <option value="todos">Todos (Productos e Insumos)</option>
                                <option value="producto">Solo Productos</option>
                                <option value="insumo">Solo Insumos</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Fila 2: Categoría -->
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-tags" style="color: #f97316;"></i> Categoría
                        </label>
                        <select name="categoria" id="reporte_categoria" class="form-select">
                            <option value="">Todas las categorías</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <hr>
                    
                    <!-- Resumen de filtros seleccionados -->
                    <div class="resumen-filtros">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="small fw-bold text-muted mb-0">
                                <i class="fas fa-filter" style="color: #f97316;"></i> Filtros seleccionados
                            </label>
                            <button type="button" class="btn-limpiar" id="btnLimpiarFiltros">
                                <i class="fas fa-eraser"></i> Limpiar
                            </button>
                        </div>
                        <div id="resumenTexto" class="small text-muted">
                            Ningún filtro seleccionado
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer border-0 pt-0 pb-4 px-4">
                    <button type="button" class="btn-cancelar" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancelar
                    </button>
                    <button type="submit" class="btn-generar" id="btnGenerarReporte">
                        <i class="fas fa-file-pdf me-1"></i> Generar PDF
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Función para abrir el modal
function abrirModalReporte() {
    document.getElementById('formGenerarReporte').reset();
    document.getElementById('resumenTexto').innerHTML = 'Ningún filtro seleccionado';
    const modal = new bootstrap.Modal(document.getElementById('modalReporteProveedor'));
    modal.show();
}

// Actualizar resumen de filtros en tiempo real
document.addEventListener('DOMContentLoaded', function() {
    const proveedorSelect = document.getElementById('reporte_proveedor');
    const tipoSelect = document.getElementById('reporte_tipo');
    const categoriaSelect = document.getElementById('reporte_categoria');
    const resumenSpan = document.getElementById('resumenTexto');
    const btnLimpiar = document.getElementById('btnLimpiarFiltros');
    
    function actualizarResumen() {
        let filtrosActivos = [];
        
        const proveedor = proveedorSelect.options[proveedorSelect.selectedIndex]?.text;
        if (proveedor && proveedor !== 'Todos los proveedores') {
            filtrosActivos.push(`<span class="badge">Proveedor: ${proveedor}</span>`);
        }
        
        const tipo = tipoSelect.options[tipoSelect.selectedIndex]?.text;
        if (tipo && tipo !== 'Todos (Productos e Insumos)') {
            filtrosActivos.push(`<span class="badge">Tipo: ${tipo}</span>`);
        }
        
        const categoria = categoriaSelect.options[categoriaSelect.selectedIndex]?.text;
        if (categoria && categoria !== 'Todas las categorías') {
            filtrosActivos.push(`<span class="badge">Categoría: ${categoria}</span>`);
        }
        
        if (filtrosActivos.length === 0) {
            resumenSpan.innerHTML = '<span class="text-muted">Ningún filtro seleccionado</span>';
        } else {
            resumenSpan.innerHTML = filtrosActivos.join(' ');
        }
    }
    
    if (btnLimpiar) {
        btnLimpiar.addEventListener('click', function() {
            proveedorSelect.value = '';
            tipoSelect.value = 'todos';
            categoriaSelect.value = '';
            actualizarResumen();
        });
    }
    
    proveedorSelect.addEventListener('change', actualizarResumen);
    tipoSelect.addEventListener('change', actualizarResumen);
    categoriaSelect.addEventListener('change', actualizarResumen);
    
    actualizarResumen();
});

// Feedback al generar - CON DESCARGA AUTOMÁTICA
const formGenerar = document.getElementById('formGenerarReporte');
if (formGenerar) {
    formGenerar.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const btn = document.getElementById('btnGenerarReporte');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Generando...';
        btn.disabled = true;
        
        const formData = new FormData(formGenerar);
        const params = new URLSearchParams(formData);
        const url = 'reporte_inventario_filtrado.php?' + params.toString();
        
        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Error HTTP: ' + response.status);
                }
                return response.blob();
            })
            .then(blob => {
                const blobUrl = URL.createObjectURL(blob);
                
                // Descargar
                const link = document.createElement('a');
                link.href = blobUrl;
                const fecha = new Date().toISOString().slice(0, 10);
                link.download = `reporte_inventario_${fecha}.pdf`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                // Abrir en nueva ventana
                window.open(blobUrl, '_blank');
                
                setTimeout(() => {
                    URL.revokeObjectURL(blobUrl);
                }, 3000);
                
                btn.innerHTML = originalText;
                btn.disabled = false;
                
                Swal.fire({
                    icon: 'success',
                    title: 'Reporte generado',
                    text: 'El archivo se ha descargado y se ha abierto en una nueva ventana',
                    timer: 2000,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top-end'
                });
            })
            .catch(error => {
                console.error('Error:', error);
                btn.innerHTML = originalText;
                btn.disabled = false;
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'No se pudo generar el reporte',
                    confirmButtonColor: '#f97316'
                });
            });
    });
}
</script>