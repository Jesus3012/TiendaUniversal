<?php
// Incluir sesión y conexión (NO volver a llamar session_start)
require_once 'includes/session.php';

// CORREGIR: Si el ID no está en sesión pero el nombre sí, buscarlo
if (!isset($_SESSION['id']) && isset($_SESSION['nombre']) && isset($conn)) {
    $nombre_sesion = $_SESSION['nombre'];
    $sql_buscar = "SELECT id FROM usuarios WHERE nombre = ?";
    $stmt_buscar = $conn->prepare($sql_buscar);
    $stmt_buscar->bind_param("s", $nombre_sesion);
    $stmt_buscar->execute();
    $result_buscar = $stmt_buscar->get_result();
    if ($result_buscar && $result_buscar->num_rows > 0) {
        $row_buscar = $result_buscar->fetch_assoc();
        $_SESSION['id'] = $row_buscar['id'];
    }
    $stmt_buscar->close();
}

$nombre = $_SESSION['nombre'] ?? 'Usuario';
$rol = $_SESSION['rol'] ?? 'Sin rol';
$user_id = $_SESSION['id'] ?? 0;

// Obtener configuración de la tienda
$config = [];
$sql_config = "SELECT nombre, telefono, email, direccion, horario, logo FROM configuracion_galeria WHERE id = 1";
$result_config = $conn->query($sql_config);
if ($result_config && $result_config->num_rows > 0) {
    $config = $result_config->fetch_assoc();
}

// ========== VERSIÓN CORREGIDA PARA LA FOTO DE PERFIL ==========
$foto_perfil = '';
$tiene_foto = false;

// Buscar directamente el archivo en la carpeta
$ruta_directa = 'uploads/perfiles/perfil_1.jpeg';
if (file_exists($ruta_directa)) {
    $foto_perfil = $ruta_directa;
    $tiene_foto = true;
} else {
    // Buscar con otras extensiones
    $extensiones = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'JPG', 'JPEG', 'PNG'];
    foreach ($extensiones as $ext) {
        $ruta = 'uploads/perfiles/perfil_1.' . $ext;
        if (file_exists($ruta)) {
            $foto_perfil = $ruta;
            $tiene_foto = true;
            break;
        }
    }
}

// Si no se encontró, usar la ruta de la BD
if (!$tiene_foto && $user_id > 0) {
    $sql_foto = "SELECT foto_perfil FROM usuarios WHERE id = ?";
    $stmt = $conn->prepare($sql_foto);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result_foto = $stmt->get_result();
    if ($result_foto && $result_foto->num_rows > 0) {
        $user_data = $result_foto->fetch_assoc();
        if (!empty($user_data['foto_perfil']) && file_exists($user_data['foto_perfil'])) {
            $foto_perfil = $user_data['foto_perfil'];
            $tiene_foto = true;
        }
    }
    $stmt->close();
}

// Obtener inicial del nombre
$inicial = '';
if (!empty($nombre)) {
    $inicial = mb_strtoupper(mb_substr(trim($nombre), 0, 1, 'UTF-8'), 'UTF-8');
}

$tienda_nombre = $config['nombre'] ?? 'Tienda Pescadores';
$tienda_logo = '';
$logo_exists = false;

$extensiones_logo = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

foreach ($extensiones_logo as $ext) {
    $ruta_logo = 'img/panel_principal.' . $ext;

    if (file_exists($ruta_logo)) {
        $tienda_logo = $ruta_logo;
        $logo_exists = true;
        break;
    }
}

$logo_version = $logo_exists ? filemtime($tienda_logo) : time();
?>

<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars($tienda_nombre); ?> - Sistema de Ventas</title>

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  
  <!-- Estilos del Navbar -->
  <link rel="stylesheet" href="css/navbar-styles.css">
</head>
<body>

  <!-- Mobile Hamburger Button -->
  <button class="mobile-hamburger" id="mobileHamburger">
    <i class="fas fa-bars"></i>
  </button>

  <!-- Mobile Sidebar -->
  <div class="mobile-overlay" id="mobileOverlay"></div>
  <div class="mobile-sidebar" id="mobileSidebar">
    <div class="mobile-sidebar-header">
      <div class="logo-area">
        <?php if ($logo_exists): ?>
          <img src="<?php echo htmlspecialchars($tienda_logo); ?>?v=<?php echo $logo_version; ?>" alt="Logo">
        <?php else: ?>
          <i class="fas fa-fish" style="color: white; font-size: 24px;"></i>
        <?php endif; ?>
        <h3><?php echo htmlspecialchars($tienda_nombre); ?></h3>
      </div>
      <button class="mobile-sidebar-close" id="mobileSidebarClose">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="mobile-sidebar-content">
      <div class="mobile-user-info">
        <?php if ($tiene_foto && !empty($foto_perfil) && file_exists($foto_perfil)): ?>
          <img src="<?php echo htmlspecialchars($foto_perfil); ?>?t=<?php echo filemtime($foto_perfil); ?>" alt="Avatar" class="mobile-user-avatar">
        <?php else: ?>
          <div class="mobile-user-initial"><?php echo htmlspecialchars($inicial); ?></div>
        <?php endif; ?>
        <div class="mobile-user-details">
          <strong><?php echo htmlspecialchars($nombre); ?></strong>
          <span><?php echo ucfirst(htmlspecialchars($rol)); ?></span>
        </div>
      </div>

      <div class="mobile-nav-links">
        <?php if ($rol === 'administrador'): ?>
          <a href="dashboard_admin.php"><i class="fas fa-home fa-anim"></i> Inicio</a>
          <a href="corte_caja.php"><i class="fas fa-money-bill-wave"></i> Corte de Caja</a>
          <a href="dashboard_ventas.php"><i class="fas fa-cash-register"></i> Registrar Venta</a>
          <a href="historial_ventas.php"><i class="fas fa-hand-holding-usd"></i> Historial de ventas</a>
          <a href="dashboard_inventario.php"><i class="fas fa-boxes"></i> Inventario</a>
          <a href="dashboard_productos.php"><i class="fas fa-box"></i> Registrar Productos</a>
          <a href="proveedores.php"><i class="fas fa-truck"></i> Proveedores</a>
          <a href="historial_reportes.php"><i class="fas fa-file-alt"></i>Reportes</a>
          <a href="historial_stock.php"><i class="fas fa-history"></i> Historial Movimientos Stock</a>
          <a href="ver_ventas.php"><i class="fas fa-chart-line"></i> Estadisticas</a>
          <a href="configuracion.php"><i class="fas fa-cogs"></i> Configuración</a>
          <a href="asignar_productos_vendedor.php"><i class="fas fa-user-tag"></i> Asignar Productos</a>
          <a href="mi_perfil.php"><i class="fas fa-user"></i> Mi Perfil</a>
              <!-- <a href="venta_admin.php"><i class="fas fa-cash-register"></i> Registrar Ventas</a> -->

        <?php elseif ($rol === 'vendedor'): ?>
          <a href="dashboard_vendedor.php"><i class="fas fa-home"></i> Panel Vendedor</a>
          <a href="ventas.php"><i class="fas fa-cash-register"></i> Registrar Venta</a>
          <a href="historial_ventas.php"><i class="fas fa-hand-holding-usd"></i> Historial de ventas</a>
          <a href="inventario.php"><i class="fas fa-boxes"></i> Inventario</a>
          <a href="dashboard_reportes_ventas.php"><i class="fas fa-file-alt"></i> Reportes</a>
          <a href="vendedor_ajustes_productos.php"><i class="fas fa-cog"></i> Ajustes de Productos</a>
          <a href="mi_perfil.php"><i class="fas fa-user"></i> Mi Perfil</a>
        <?php endif; ?>
        
        <a href="logout.php" style="margin-top: 12px;"><i class="fas fa-sign-out-alt"></i> Cerrar sesión</a>
      </div>
    </div>
  </div>

  <!-- Desktop Sidebar -->
<aside class="sidebar-custom" id="sidebar">
  <div class="sidebar-header">
    <div class="logo-area">
      <?php if ($logo_exists): ?>
        <img src="<?php echo htmlspecialchars($tienda_logo); ?>?v=<?php echo $logo_version; ?>" alt="Logo">
      <?php else: ?>
        <i class="fas fa-fish" style="color: white; font-size: 24px;"></i>
      <?php endif; ?>
      <span><?php echo htmlspecialchars($tienda_nombre); ?></span>
    </div>
    <button id="toggleBtn" class="toggle-btn" title="Colapsar menú">
      <i class="fas fa-chevron-left"></i>
    </button>
  </div>

    <div class="user-info">
      <?php if ($tiene_foto && !empty($foto_perfil) && file_exists($foto_perfil)): ?>
        <img src="<?php echo htmlspecialchars($foto_perfil); ?>?t=<?php echo filemtime($foto_perfil); ?>" alt="Avatar" class="user-avatar">
      <?php else: ?>
        <div class="user-initial"><?php echo htmlspecialchars($inicial); ?></div>
      <?php endif; ?>
      <div class="user-details">
        <strong><?php echo htmlspecialchars($nombre); ?></strong>
        <span><?php echo ucfirst(htmlspecialchars($rol)); ?></span>
      </div>
    </div>

    <div class="nav-links">
      <?php if ($rol === 'administrador'): ?>
        <a href="dashboard_admin.php"><i class="fas fa-home"></i><span>Inicio</span></a>
        <a href="corte_caja.php"><i class="fas fa-money-bill-wave"></i><span>Corte de Caja</span></a>
        <a href="dashboard_ventas.php"><i class="fas fa-cash-register"></i><span>Registrar Venta</span></a>
        <a href="dashboard_inventario.php"><i class="fas fa-boxes"></i><span>Inventario</span></a>
        <a href="dashboard_productos.php"><i class="fas fa-box"></i><span>Registrar Productos</span></a>
        <a href="historial_ventas.php"><i class="fas fa-hand-holding-usd"></i><span>Historial de ventas</span></a>
        <a href="proveedores.php"><i class="fas fa-truck"></i><span>Proveedores</span></a>
        <a href="historial_reportes.php"><i class="fas fa-file-alt"></i><span>Reportes</span></a>
        <a href="historial_stock.php"><i class="fas fa-history"></i><span>Historial Movimientos Stock</span></a>
        <a href="ver_ventas.php"><i class="fas fa-chart-line"></i><span>Estadisticas</span></a>
        <a href="asignar_productos_vendedor.php"><i class="fas fa-user-tag"></i><span>Asignar Productos</span></a>
        <a href="configuracion.php"><i class="fas fa-cogs"></i><span>Configuración</span></a>
        <a href="mi_perfil.php"><i class="fas fa-user"></i><span>Mi Perfil</span></a>
            <!-- <a href="venta_admin.php"><i class="fas fa-cash-register"></i><span>Registrar Ventas</span></a> -->
        </div>

      <?php elseif ($rol === 'vendedor'): ?>
        <a href="dashboard_vendedor.php"><i class="fas fa-home"></i><span>Panel Vendedor</span></a>
        <a href="ventas.php"><i class="fas fa-cash-register"></i><span>Registrar Venta</span></a>
        <a href="historial_ventas.php"><i class="fas fa-hand-holding-usd"></i><span>Historial de ventas</span></a>
        <a href="inventario.php"><i class="fas fa-boxes"></i><span>Inventario</span></a>
        <a href="dashboard_reportes_ventas.php"><i class="fas fa-file-alt"></i><span>Reportes</span></a>
        <a href="vendedor_ajustes_productos.php"><i class="fas fa-cog"></i><span>Ajustes de Productos</span></a>
        <a href="mi_perfil.php"><i class="fas fa-user"></i><span>Mi Perfil</span></a>
      <?php endif; ?>
    </div>

    <a class="logout" href="logout.php">
      <i class="fas fa-sign-out-alt"></i>
      <span>Cerrar Sesión</span>
    </a>
  </aside>

  <!-- Scripts -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Desktop sidebar toggle
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('toggleBtn');
        const contentWrapper = document.querySelector('.content-wrapper');
        
        if (toggleBtn && sidebar && contentWrapper) {
            const toggleIcon = toggleBtn.querySelector('i');
            
            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('closed');
                
                if (sidebar.classList.contains('closed')) {
                    toggleIcon.classList.remove('fa-chevron-left');
                    toggleIcon.classList.add('fa-chevron-right');
                    contentWrapper.style.marginLeft = 'var(--sidebar-collapsed)';
                } else {
                    toggleIcon.classList.remove('fa-chevron-right');
                    toggleIcon.classList.add('fa-chevron-left');
                    contentWrapper.style.marginLeft = 'var(--sidebar-width)';
                }
            });
        }

        // Submenu toggles
        document.querySelectorAll('.submenu-toggle').forEach(toggle => {
            toggle.addEventListener('click', function() {
                const parent = this.parentElement;
                if (parent) parent.classList.toggle('open');
            });
        });

        // Mobile menu functionality
        const mobileHamburger = document.getElementById('mobileHamburger');
        const mobileSidebar = document.getElementById('mobileSidebar');
        const mobileOverlay = document.getElementById('mobileOverlay');
        const mobileSidebarClose = document.getElementById('mobileSidebarClose');

        if (mobileHamburger && mobileSidebar && mobileOverlay && mobileSidebarClose) {
            function openMobileSidebar() {
                mobileSidebar.classList.add('open');
                mobileOverlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            }

            function closeMobileSidebar() {
                mobileSidebar.classList.remove('open');
                mobileOverlay.classList.remove('active');
                document.body.style.overflow = '';
            }

            mobileHamburger.addEventListener('click', openMobileSidebar);
            mobileSidebarClose.addEventListener('click', closeMobileSidebar);
            mobileOverlay.addEventListener('click', closeMobileSidebar);
        }

        // Responsive behavior
        function handleResponsive() {
            const sidebar = document.getElementById('sidebar');
            const contentWrapper = document.querySelector('.content-wrapper');
            
            if (window.innerWidth < 768) {
                if (sidebar) sidebar.classList.remove('closed');
                if (contentWrapper) contentWrapper.style.marginLeft = '0';
            } else {
                const mobileSidebar = document.getElementById('mobileSidebar');
                const mobileOverlay = document.getElementById('mobileOverlay');
                if (mobileSidebar && mobileOverlay) {
                    mobileSidebar.classList.remove('open');
                    mobileOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
                
                if (sidebar && contentWrapper) {
                    if (sidebar.classList.contains('closed')) {
                        contentWrapper.style.marginLeft = 'var(--sidebar-collapsed)';
                    } else {
                        contentWrapper.style.marginLeft = 'var(--sidebar-width)';
                    }
                }
            }
        }

        window.addEventListener('resize', handleResponsive);
        handleResponsive();
    });
</script>
</body>
</html>