<?php
ob_start();
session_start();
require_once 'includes/csrf.php';
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login.php");
    exit;
}

include 'includes/header.php';
include 'includes/navbar.php';

// Obtener proveedores
$query = "SELECT * FROM proveedores WHERE activo = 1 ORDER BY nombre";
$result = $conn->query($query);
$todos_proveedores = [];
while ($row = $result->fetch_assoc()) {
    // Construir dirección completa para Google Maps
    $row['direccion_completa'] = trim(
        ($row['calle'] ?? '') . ' ' . 
        ($row['numero'] ?? '') . ', ' . 
        ($row['colonia'] ?? '') . ', ' . 
        ($row['ciudad'] ?? '') . ', ' . 
        ($row['estado'] ?? '') . ', ' . 
        ($row['codigo_postal'] ?? '') . ', ' . 
        ($row['pais'] ?? 'México')
    );
    $row['direccion_completa'] = trim(preg_replace('/,\s*,/', ',', $row['direccion_completa']), ', ');
    $row['direccion_completa'] = preg_replace('/^[, ]+|[, ]+$/', '', $row['direccion_completa']);
    $todos_proveedores[] = $row;
}

$mensaje = '';
$tipo_mensaje = '';

// Procesar actualización de proveedor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_proveedor') {
    csrf_check();
    
    $id = intval($_POST['id']);
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $calle = trim($_POST['calle'] ?? '');
    $numero = trim($_POST['numero'] ?? '');
    $colonia = trim($_POST['colonia'] ?? '');
    $ciudad = trim($_POST['ciudad'] ?? '');
    $estado = trim($_POST['estado'] ?? '');
    $codigo_postal = trim($_POST['codigo_postal'] ?? '');
    $pais = trim($_POST['pais'] ?? 'México');
    
    if ($nombre === '') {
        $mensaje = "El nombre del proveedor es obligatorio.";
        $tipo_mensaje = "error";
    } else {
        // Verificar si ya existe otro proveedor con el mismo nombre
        $stmt = $conn->prepare("SELECT id FROM proveedores WHERE nombre = ? AND id != ? AND activo = 1");
        $stmt->bind_param("si", $nombre, $id);
        $stmt->execute();
        $result_check = $stmt->get_result();
        
        if ($result_check->num_rows > 0) {
            $mensaje = "Ya existe otro proveedor con este nombre.";
            $tipo_mensaje = "error";
        } else {
            // Subir nuevo logo si se proporcionó
            $logo_path = null;
            if (!empty($_FILES['logo']['name'])) {
                $upload_dir = __DIR__.'/uploads/proveedores/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                // Limpiar nombre del proveedor para usarlo como nombre de archivo
                $nombre_limpio = preg_replace('/[^a-zA-Z0-9áéíóúüñÁÉÍÓÚÜÑ\s-]/u', '', $nombre);
                $nombre_limpio = preg_replace('/[\s]+/', '_', $nombre_limpio);
                $nombre_limpio = trim($nombre_limpio, '_');
                
                // Generar nombre único con el nombre del proveedor
                $logo_name = $nombre_limpio . '.' . $extension;
                $contador = 1;
                
                // Verificar si ya existe un archivo con el mismo nombre
                while (file_exists($upload_dir . $logo_name)) {
                    $logo_name = $nombre_limpio . '_' . $contador . '.' . $extension;
                    $contador++;
                }
                
                $logo_path = 'uploads/proveedores/' . $logo_name;
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $logo_name)) {
                    // Eliminar logo anterior si existe
                    $stmt_img = $conn->prepare("SELECT logo FROM proveedores WHERE id = ?");
                    $stmt_img->bind_param("i", $id);
                    $stmt_img->execute();
                    $res_img = $stmt_img->get_result();
                    $old_logo = $res_img->fetch_assoc();
                    if ($old_logo['logo'] && file_exists($old_logo['logo'])) {
                        unlink($old_logo['logo']);
                    }
                } else {
                    $logo_path = null;
                }
            }
            
            // Construir consulta de actualización
            if ($logo_path) {
                $stmt = $conn->prepare("UPDATE proveedores SET nombre=?, correo=?, telefono=?, calle=?, numero=?, colonia=?, ciudad=?, estado=?, codigo_postal=?, pais=?, logo=? WHERE id=?");
                $stmt->bind_param("sssssssssssi", $nombre, $correo, $telefono, $calle, $numero, $colonia, $ciudad, $estado, $codigo_postal, $pais, $logo_path, $id);
            } else {
                $stmt = $conn->prepare("UPDATE proveedores SET nombre=?, correo=?, telefono=?, calle=?, numero=?, colonia=?, ciudad=?, estado=?, codigo_postal=?, pais=? WHERE id=?");
                $stmt->bind_param("ssssssssssi", $nombre, $correo, $telefono, $calle, $numero, $colonia, $ciudad, $estado, $codigo_postal, $pais, $id);
            }
            
            if ($stmt->execute()) {
                $mensaje = "Proveedor actualizado correctamente.";
                $tipo_mensaje = "success";
            } else {
                $mensaje = "Error al actualizar el proveedor.";
                $tipo_mensaje = "error";
            }
        }
    }
}
?>

<link rel="stylesheet" href="css/proveedores.css">

<div class="content-wrapper">
    <div class="container-fluid">
        
        <div class="section-header">
            <div class="section-title">
                <i class="fas fa-truck"></i>
                <span>Proveedores</span>
            </div>
            <div class="section-divider"></div>
            <p class="text-muted mt-2 mb-0">Visualiza la ubicación de tus proveedores y edita su información</p>
        </div>
        
        <!-- Toolbar con buscador -->
        <div class="toolbar-filtros">
            <div class="buscador">
                <i class="fas fa-search"></i>
                <input type="text" id="buscadorInput" placeholder="Buscar proveedor por nombre, correo o teléfono..." autocomplete="off">
                <i class="fas fa-times limpiar-busqueda" id="limpiarBusqueda"></i>
            </div>
            <div class="resultados-info" id="resultadosInfo">
                Mostrando <span id="resultados_mostrados">0</span> de <span id="resultados_total">0</span> proveedores
            </div>
        </div>
        
        <!-- Grid de proveedores -->
        <div id="proveedoresContainer">
            <div class="proveedores-grid" id="proveedoresGrid">
                <!-- Las tarjetas se cargarán con JavaScript -->
            </div>
            
            <!-- Sin resultados -->
            <div id="sinResultadosMsg" class="sin-resultados" style="display: none;">
                <i class="fas fa-search"></i>
                <h5>No se encontraron proveedores</h5>
                <p>No hay proveedores que coincidan con tu búsqueda.</p>
            </div>
            
            <!-- Paginación -->
            <div class="pagination-wrapper" id="paginacionWrapper">
                <div class="pagination-info">
                    Mostrando <span id="paginacion_desde">0</span> a <span id="paginacion_hasta">0</span> de <span id="paginacion_total">0</span> proveedores
                </div>
                <nav>
                    <ul class="pagination" id="paginacion"></ul>
                </nav>
            </div>
        </div>
        
    </div>
</div>

<!-- MODAL PARA EDITAR PROVEEDOR -->
<div class="modal fade modal-editar" id="modalEditarProveedor" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" enctype="multipart/form-data" class="modal-content" id="formEditarProveedor">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit mr-2"></i> Editar Proveedor
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="update_proveedor">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" id="edit_id" name="id">
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label class="form-label">Nombre <span class="text-danger">*</span></label>
                            <input type="text" name="nombre" id="edit_nombre" class="form-control" required>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Correo electrónico</label>
                            <input type="email" name="correo" id="edit_correo" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Teléfono</label>
                            <input type="text" name="telefono" id="edit_telefono" class="form-control">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-8">
                        <div class="form-group">
                            <label class="form-label">Calle</label>
                            <input type="text" name="calle" id="edit_calle" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Número</label>
                            <input type="text" name="numero" id="edit_numero" class="form-control">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Colonia</label>
                    <input type="text" name="colonia" id="edit_colonia" class="form-control">
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Ciudad</label>
                            <input type="text" name="ciudad" id="edit_ciudad" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Estado</label>
                            <input type="text" name="estado" id="edit_estado" class="form-control">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Código Postal</label>
                            <input type="text" name="codigo_postal" id="edit_codigo_postal" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">País</label>
                            <select name="pais" id="edit_pais" class="form-control">
                                <option value="México">México</option>
                                <option value="Estados Unidos">Estados Unidos</option>
                                <option value="Canadá">Canadá</option>
                                <option value="España">España</option>
                                <option value="Colombia">Colombia</option>
                                <option value="Argentina">Argentina</option>
                                <option value="Chile">Chile</option>
                                <option value="Perú">Perú</option>
                                <option value="Otro">Otro</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Logo</label>
                    <input type="file" name="logo" id="edit_logo" class="form-control" accept="image/*" onchange="previewLogoEditar(event)">
                    <img id="previewLogoEdit" class="logo-preview-edit d-none">
                    <small class="text-muted">Formatos permitidos: JPG, PNG, GIF. El archivo se guardará con el nombre del proveedor.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Datos de proveedores desde PHP
const todosProveedores = <?php echo json_encode($todos_proveedores); ?>;

// Variables de paginación
let proveedoresFiltrados = [];
let paginaActual = 1;
let elementosPorPagina = 8;

// Función para renderizar las tarjetas
function renderizarTarjetas() {
    const grid = document.getElementById('proveedoresGrid');
    const sinResultados = document.getElementById('sinResultadosMsg');
    const paginacionWrapper = document.getElementById('paginacionWrapper');
    
    if (proveedoresFiltrados.length === 0) {
        grid.innerHTML = '';
        sinResultados.style.display = 'block';
        paginacionWrapper.style.display = 'none';
        document.getElementById('resultados_mostrados').textContent = '0';
        document.getElementById('resultados_total').textContent = '0';
        return;
    }
    
    sinResultados.style.display = 'none';
    paginacionWrapper.style.display = 'flex';
    
    // Calcular paginación
    const totalPaginas = Math.ceil(proveedoresFiltrados.length / elementosPorPagina);
    const inicio = (paginaActual - 1) * elementosPorPagina;
    const fin = Math.min(inicio + elementosPorPagina, proveedoresFiltrados.length);
    const proveedoresPagina = proveedoresFiltrados.slice(inicio, fin);
    
    // Actualizar contadores
    document.getElementById('resultados_mostrados').textContent = proveedoresFiltrados.length;
    document.getElementById('resultados_total').textContent = todosProveedores.length;
    document.getElementById('paginacion_desde').textContent = inicio + 1;
    document.getElementById('paginacion_hasta').textContent = fin;
    document.getElementById('paginacion_total').textContent = proveedoresFiltrados.length;
    
    // Generar HTML de las tarjetas
    let html = '';
    for (const prov of proveedoresPagina) {
        const direccionMapa = encodeURIComponent(prov.direccion_completa || 'Mexico');
        const tieneDireccion = prov.direccion_completa && prov.direccion_completa !== '';
        
        // Escapar datos para usarlos en onclick
        const provId = prov.id;
        const provNombre = escapeHtml(prov.nombre);
        const provCorreo = escapeHtml(prov.correo || '');
        const provTelefono = escapeHtml(prov.telefono || '');
        const provCalle = escapeHtml(prov.calle || '');
        const provNumero = escapeHtml(prov.numero || '');
        const provColonia = escapeHtml(prov.colonia || '');
        const provCiudad = escapeHtml(prov.ciudad || '');
        const provEstado = escapeHtml(prov.estado || '');
        const provCodigoPostal = escapeHtml(prov.codigo_postal || '');
        const provPais = escapeHtml(prov.pais || 'México');
        const provLogo = prov.logo || '';
        
        html += `
        <div class="proveedor-card">
            <div class="card-header-proveedor" onclick='abrirModalEditarSimple(${prov.id}, "${provNombre}", "${provCorreo}", "${provTelefono}", "${provCalle}", "${provNumero}", "${provColonia}", "${provCiudad}", "${provEstado}", "${provCodigoPostal}", "${provPais}", "${provLogo}")'>
                ${prov.logo && prov.logo !== '' ? 
                    `<img src="${prov.logo}" class="proveedor-logo" alt="${provNombre}">` : 
                    `<div class="proveedor-inicial">${prov.nombre.substring(0, 2).toUpperCase()}</div>`
                }
                <div class="proveedor-nombre">${provNombre}</div>
            </div>
            <div class="card-body-proveedor">
                <div class="proveedor-info">
                    <i class="fas fa-envelope"></i>
                    <span>${prov.correo || 'No especificado'}</span>
                </div>
                <div class="proveedor-info">
                    <i class="fas fa-phone"></i>
                    <span>${prov.telefono || 'No especificado'}</span>
                </div>
                <div class="proveedor-info">
                    <i class="fas fa-map-marker-alt"></i>
                    <span>${escapeHtml(prov.direccion_completa || 'Dirección no especificada')}</span>
                </div>
                ${tieneDireccion ? `
                <div class="mini-mapa">
                    <iframe 
                        src="https://maps.google.com/maps?q=${direccionMapa}&z=15&output=embed"
                        allowfullscreen=""
                        loading="lazy">
                    </iframe>
                </div>
                ` : ''}
                <button class="btn-editar" onclick="event.stopPropagation(); abrirModalEditarSimple(${prov.id}, '${provNombre}', '${provCorreo}', '${provTelefono}', '${provCalle}', '${provNumero}', '${provColonia}', '${provCiudad}', '${provEstado}', '${provCodigoPostal}', '${provPais}', '${provLogo}')">
                    <i class="fas fa-edit mr-1"></i> Editar proveedor
                </button>
            </div>
        </div>`;
    }
    
    grid.innerHTML = html;
    generarPaginacion(totalPaginas);
}

// Función para escapar HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Función para generar la paginación
function generarPaginacion(totalPaginas) {
    const paginacionUl = document.getElementById('paginacion');
    paginacionUl.innerHTML = '';
    
    if (totalPaginas <= 1) return;
    
    // Botón anterior
    const liPrev = document.createElement('li');
    liPrev.className = `page-item ${paginaActual === 1 ? 'disabled' : ''}`;
    liPrev.innerHTML = `<a class="page-link" ${paginaActual !== 1 ? 'onclick="cambiarPagina(' + (paginaActual - 1) + ')"' : ''}>«</a>`;
    paginacionUl.appendChild(liPrev);
    
    // Números de página
    const maxBotones = 5;
    let inicioPaginas = Math.max(1, paginaActual - Math.floor(maxBotones / 2));
    let finPaginas = Math.min(totalPaginas, inicioPaginas + maxBotones - 1);
    
    if (finPaginas - inicioPaginas + 1 < maxBotones && inicioPaginas > 1) {
        inicioPaginas = Math.max(1, finPaginas - maxBotones + 1);
    }
    
    if (inicioPaginas > 1) {
        const liFirst = document.createElement('li');
        liFirst.className = 'page-item';
        liFirst.innerHTML = '<a class="page-link" onclick="cambiarPagina(1)">1</a>';
        paginacionUl.appendChild(liFirst);
        if (inicioPaginas > 2) {
            const liDots = document.createElement('li');
            liDots.className = 'page-item disabled';
            liDots.innerHTML = '<span class="page-link">...</span>';
            paginacionUl.appendChild(liDots);
        }
    }
    
    for (let i = inicioPaginas; i <= finPaginas; i++) {
        const li = document.createElement('li');
        li.className = `page-item ${i === paginaActual ? 'active' : ''}`;
        li.innerHTML = `<a class="page-link" onclick="cambiarPagina(${i})">${i}</a>`;
        paginacionUl.appendChild(li);
    }
    
    if (finPaginas < totalPaginas) {
        if (finPaginas < totalPaginas - 1) {
            const liDots = document.createElement('li');
            liDots.className = 'page-item disabled';
            liDots.innerHTML = '<span class="page-link">...</span>';
            paginacionUl.appendChild(liDots);
        }
        const liLast = document.createElement('li');
        liLast.className = 'page-item';
        liLast.innerHTML = `<a class="page-link" onclick="cambiarPagina(${totalPaginas})">${totalPaginas}</a>`;
        paginacionUl.appendChild(liLast);
    }
    
    // Botón siguiente
    const liNext = document.createElement('li');
    liNext.className = `page-item ${paginaActual === totalPaginas ? 'disabled' : ''}`;
    liNext.innerHTML = `<a class="page-link" ${paginaActual !== totalPaginas ? 'onclick="cambiarPagina(' + (paginaActual + 1) + ')"' : ''}>»</a>`;
    paginacionUl.appendChild(liNext);
}

// Función para cambiar de página
function cambiarPagina(pagina) {
    paginaActual = pagina;
    renderizarTarjetas();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Función para aplicar filtros de búsqueda
function aplicarFiltros() {
    const busqueda = document.getElementById('buscadorInput').value.toLowerCase().trim();
    
    if (busqueda === '') {
        proveedoresFiltrados = [...todosProveedores];
    } else {
        proveedoresFiltrados = todosProveedores.filter(prov => {
            return (prov.nombre && prov.nombre.toLowerCase().includes(busqueda)) ||
                   (prov.correo && prov.correo.toLowerCase().includes(busqueda)) ||
                   (prov.telefono && prov.telefono.toLowerCase().includes(busqueda));
        });
    }
    
    paginaActual = 1;
    renderizarTarjetas();
}

// Función para abrir modal de edición (versión simple sin JSON)
function abrirModalEditarSimple(id, nombre, correo, telefono, calle, numero, colonia, ciudad, estado, codigo_postal, pais, logo) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_nombre').value = nombre;
    document.getElementById('edit_correo').value = correo;
    document.getElementById('edit_telefono').value = telefono;
    document.getElementById('edit_calle').value = calle;
    document.getElementById('edit_numero').value = numero;
    document.getElementById('edit_colonia').value = colonia;
    document.getElementById('edit_ciudad').value = ciudad;
    document.getElementById('edit_estado').value = estado;
    document.getElementById('edit_codigo_postal').value = codigo_postal;
    document.getElementById('edit_pais').value = pais;
    
    // Limpiar preview de logo
    const preview = document.getElementById('previewLogoEdit');
    preview.classList.add('d-none');
    preview.src = '';
    
    $('#modalEditarProveedor').modal('show');
}

// Previsualizar logo en edición
function previewLogoEditar(event) {
    const img = document.getElementById('previewLogoEdit');
    img.src = URL.createObjectURL(event.target.files[0]);
    img.classList.remove('d-none');
}

// Mostrar mensaje con SweetAlert2 si hay mensaje PHP
<?php if (!empty($mensaje)): ?>
Swal.fire({
    icon: '<?= $tipo_mensaje ?>',
    title: '<?= $tipo_mensaje === 'success' ? 'Éxito' : 'Error' ?>',
    text: '<?= addslashes($mensaje) ?>',
    confirmButtonColor: '#f97316'
}).then(() => {
    if ('<?= $tipo_mensaje ?>' === 'success') {
        location.reload();
    }
});
<?php endif; ?>

// Envío del formulario de edición con SweetAlert
document.getElementById('formEditarProveedor').addEventListener('submit', function(e) {
    e.preventDefault();
    
    Swal.fire({
        title: 'Guardando...',
        text: 'Por favor espere',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    const formData = new FormData(this);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        if (text.includes('Proveedor actualizado correctamente')) {
            Swal.fire({
                icon: 'success',
                title: 'Éxito',
                text: 'Proveedor actualizado correctamente',
                confirmButtonColor: '#f97316'
            }).then(() => {
                location.reload();
            });
        } else if (text.includes('El nombre del proveedor es obligatorio') || text.includes('Ya existe otro proveedor')) {
            let errorMsg = text.match(/alert alert-danger[^>]*>([^<]+)/);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: errorMsg ? errorMsg[1] : 'Error al actualizar el proveedor',
                confirmButtonColor: '#f97316'
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Error al procesar la solicitud',
                confirmButtonColor: '#f97316'
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Error al procesar la solicitud',
            confirmButtonColor: '#f97316'
        });
    });
});

// Inicializar eventos
document.addEventListener('DOMContentLoaded', function() {
    // Inicializar todos los proveedores
    proveedoresFiltrados = [...todosProveedores];
    renderizarTarjetas();
    
    // Evento de búsqueda
    const buscadorInput = document.getElementById('buscadorInput');
    const limpiarBusqueda = document.getElementById('limpiarBusqueda');
    
    buscadorInput.addEventListener('keyup', function() {
        const valor = this.value;
        limpiarBusqueda.style.display = valor ? 'block' : 'none';
        aplicarFiltros();
    });
    
    limpiarBusqueda.addEventListener('click', function() {
        buscadorInput.value = '';
        this.style.display = 'none';
        aplicarFiltros();
        buscadorInput.focus();
    });
});
</script>