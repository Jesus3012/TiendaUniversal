<?php
// includes/navbar.php

// Dependencias directas. require_once evita cargas duplicadas y permite que
// Intelephense encuentre todas las funciones del módulo de permisos.
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permisos.php';

// ====================== FUNCIONES BASE ======================

if (!function_exists('navbar_redirect')) {
    function navbar_redirect($url)
    {
        if (!headers_sent()) {
            header("Location: " . $url);
            exit;
        }

        echo "<script>window.location.href = " . json_encode($url) . ";</script>";
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }
}

if (!function_exists('navbar_normalizar_rol')) {
    function navbar_normalizar_rol($rol)
    {
        return permisos_normalizar_rol($rol);
    }
}

if (!function_exists('navbar_usuario_id')) {
    function navbar_usuario_id()
    {
        return $_SESSION['usuario_id']
            ?? $_SESSION['id_usuario']
            ?? $_SESSION['id']
            ?? null;
    }
}

// ====================== RECUPERAR ID SI SOLO EXISTE EL NOMBRE ======================

if (
    empty($_SESSION['id']) &&
    empty($_SESSION['usuario_id']) &&
    isset($_SESSION['nombre']) &&
    isset($conn)
) {
    $nombre_sesion = $_SESSION['nombre'];

    $sql_buscar = "SELECT id FROM usuarios WHERE nombre = ? LIMIT 1";
    $stmt_buscar = $conn->prepare($sql_buscar);

    if ($stmt_buscar) {
        $stmt_buscar->bind_param("s", $nombre_sesion);
        $stmt_buscar->execute();
        $result_buscar = $stmt_buscar->get_result();

        if ($result_buscar && $result_buscar->num_rows > 0) {
            $row_buscar = $result_buscar->fetch_assoc();

            $_SESSION['id'] = (int)$row_buscar['id'];
            $_SESSION['usuario_id'] = (int)$row_buscar['id'];
        }

        $stmt_buscar->close();
    }
}

// ====================== PROTECCIÓN CENTRAL DE RUTAS ======================

$current_page = basename(parse_url($_SERVER['SCRIPT_NAME'], PHP_URL_PATH));

/**
 * Devuelve la clase "active" cuando la página actual pertenece
 * al módulo indicado.
 *
 * Puede recibir una sola página:
 * navbar_clase_activa('dashboard_admin.php')
 *
 * O varias páginas relacionadas con el mismo módulo:
 * navbar_clase_activa(['dashboard_ventas.php', 'ventas.php'])
 */
if (!function_exists('navbar_clase_activa')) {
    function navbar_clase_activa($paginas)
    {
        global $current_page;

        $pagina_actual = permisos_minusculas($current_page);

        $paginas = is_array($paginas) ? $paginas : [$paginas];

        foreach ($paginas as $pagina) {
            $pagina = permisos_minusculas(
                basename(parse_url((string) $pagina, PHP_URL_PATH))
            );

            if ($pagina_actual === $pagina) {
                return 'active';
            }
        }

        return '';
    }
}


$public_pages = permisos_paginas_publicas();

if (!in_array($current_page, $public_pages, true)) {
    permisos_proteger_ruta($conn, $current_page);
}

// ====================== DATOS DE USUARIO ======================

$nombre = $_SESSION['nombre'] ?? 'Usuario';
$rol = navbar_normalizar_rol($_SESSION['rol'] ?? 'Sin rol');
$user_id = navbar_usuario_id() ?? 0;

$es_admin = in_array($rol, ['administrador', 'super_administrador'], true);
$es_super_admin = ($rol === 'super_administrador');
$es_vendedor = ($rol === 'vendedor');

if (!function_exists('navbar_modulo_permitido')) {
    function navbar_modulo_permitido($claveModulo)
    {
        global $conn;

        return permisos_rol_tiene_modulo(
            $conn,
            $_SESSION['rol'] ?? '',
            $claveModulo
        );
    }
}

$ruta_inventario_menu = $es_vendedor
    ? 'inventario.php'
    : 'dashboard_inventario.php';

$ruta_reportes_menu = $es_vendedor
    ? 'dashboard_reportes_ventas.php'
    : 'historial_reportes.php';

// Obtener configuración de la tienda
$config = [];

if (isset($conn)) {
    $sql_config = "SELECT nombre, telefono, email, direccion, horario, logo FROM configuracion_galeria WHERE id = 1";
    $result_config = $conn->query($sql_config);

    if ($result_config && $result_config->num_rows > 0) {
        $config = $result_config->fetch_assoc();
    }
}

// ====================== FOTO DE PERFIL ======================

$foto_perfil = '';
$tiene_foto = false;

$ruta_directa = 'uploads/perfiles/perfil_1.jpeg';

if (file_exists($ruta_directa)) {
    $foto_perfil = $ruta_directa;
    $tiene_foto = true;
} else {
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

if (!$tiene_foto && $user_id > 0 && isset($conn)) {
    $sql_foto = "SELECT foto_perfil FROM usuarios WHERE id = ?";
    $stmt = $conn->prepare($sql_foto);

    if ($stmt) {
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
}

// Inicial del nombre
$inicial = '';

if (!empty($nombre)) {
    $nombreLimpio = trim((string) $nombre);

    if (
        function_exists('mb_substr') &&
        function_exists('mb_strtoupper')
    ) {
        $inicial = mb_strtoupper(
            mb_substr($nombreLimpio, 0, 1, 'UTF-8'),
            'UTF-8'
        );
    } else {
        $inicial = strtoupper(substr($nombreLimpio, 0, 1));
    }
}

// ====================== LOGO DE TIENDA ======================

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
  <?php
  $navbar_css_file = __DIR__ . '/../css/navbar-styles.css';
  $navbar_css_version = is_file($navbar_css_file)
      ? filemtime($navbar_css_file)
      : time();
  ?>
  <link rel="stylesheet" href="css/navbar-styles.css?v=<?php echo $navbar_css_version; ?>">
  <style>
    [data-modulo][data-permitido="0"] {
      display: none !important;
    }
  </style>
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
          <span><?php echo $es_super_admin ? 'Superadministrador' : ucfirst(htmlspecialchars($rol)); ?></span>
        </div>
      </div>

      <div class="mobile-nav-links">
          <a href="dashboard_admin.php" data-modulo="panel_admin" data-permitido="<?php echo navbar_modulo_permitido('panel_admin') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('dashboard_admin.php'); ?>"><i class="fas fa-house"></i> Inicio</a>
          <?php if (!$es_super_admin): ?>
          <a href="dashboard_vendedor.php" data-modulo="panel_vendedor" data-permitido="<?php echo navbar_modulo_permitido('panel_vendedor') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('dashboard_vendedor.php'); ?>"><i class="fas fa-gauge-high"></i> Panel vendedor</a>
          <?php endif; ?>
          <a href="corte_caja.php" data-modulo="corte_caja" data-permitido="<?php echo navbar_modulo_permitido('corte_caja') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('corte_caja.php'); ?>"><i class="fas fa-money-bill-wave"></i> Corte de caja</a>
          <a href="dashboard_ventas.php" data-modulo="ventas" data-permitido="<?php echo navbar_modulo_permitido('ventas') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa(['dashboard_ventas.php', 'ventas.php', 'venta_admin.php', 'ventas_proveedor.php', 'pedidos.php']); ?>"><i class="fas fa-cash-register"></i> Registrar venta</a>
          <a href="historial_ventas.php" data-modulo="historial_ventas" data-permitido="<?php echo navbar_modulo_permitido('historial_ventas') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('historial_ventas.php'); ?>"><i class="fas fa-receipt"></i> Historial de ventas</a>
          <a href="<?php echo htmlspecialchars($ruta_inventario_menu, ENT_QUOTES, 'UTF-8'); ?>" data-modulo="inventario" data-permitido="<?php echo navbar_modulo_permitido('inventario') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa(['dashboard_inventario.php', 'inventario_admin.php', 'inventario.php']); ?>"><i class="fas fa-boxes-stacked"></i> Inventario</a>
          <a href="dashboard_productos.php" data-modulo="productos" data-permitido="<?php echo navbar_modulo_permitido('productos') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa(['dashboard_productos.php', 'productos.php', 'ajustes_productos.php']); ?>"><i class="fas fa-box"></i> Productos</a>
          <a href="promociones.php" data-modulo="promociones" data-permitido="<?php echo navbar_modulo_permitido('promociones') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('promociones.php'); ?>"><i class="fas fa-tags"></i> Promociones</a>
          <a href="vendedor_ajustes_productos.php" data-modulo="ajustes_productos" data-permitido="<?php echo navbar_modulo_permitido('ajustes_productos') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('vendedor_ajustes_productos.php'); ?>"><i class="fas fa-sliders"></i> Ajustes de productos</a>
          <a href="proveedores.php" data-modulo="proveedores" data-permitido="<?php echo navbar_modulo_permitido('proveedores') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('proveedores.php'); ?>"><i class="fas fa-truck"></i> Proveedores</a>
          <a href="<?php echo htmlspecialchars($ruta_reportes_menu, ENT_QUOTES, 'UTF-8'); ?>" data-modulo="reportes" data-permitido="<?php echo navbar_modulo_permitido('reportes') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa(['historial_reportes.php', 'dashboard_reportes_ventas.php', 'reportes_vendedor.php', 'reporte_vendedor_productos.php']); ?>"><i class="fas fa-file-lines"></i> Reportes</a>
          <a href="historial_stock.php" data-modulo="historial_stock" data-permitido="<?php echo navbar_modulo_permitido('historial_stock') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('historial_stock.php'); ?>"><i class="fas fa-clock-rotate-left"></i> Historial de stock</a>
          <a href="ver_ventas.php" data-modulo="estadisticas" data-permitido="<?php echo navbar_modulo_permitido('estadisticas') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('ver_ventas.php'); ?>"><i class="fas fa-chart-line"></i> Estadísticas</a>
          <a href="asignar_productos_vendedor.php" data-modulo="asignar_productos" data-permitido="<?php echo navbar_modulo_permitido('asignar_productos') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('asignar_productos_vendedor.php'); ?>"><i class="fas fa-user-tag"></i> Asignar productos</a>
          <a href="configuracion.php" data-modulo="configuracion" data-permitido="<?php echo navbar_modulo_permitido('configuracion') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('configuracion.php'); ?>"><i class="fas fa-gears"></i> Configuración</a>
          <?php if ($es_admin): ?>
          <a href="control_accesos.php" data-modulo="control_accesos" data-permitido="1" class="<?php echo navbar_clase_activa('control_accesos.php'); ?>"><i class="fas fa-user-shield"></i> Control de accesos</a>
          <?php endif; ?>
          <a href="mi_perfil.php" data-modulo="mi_perfil" data-permitido="<?php echo navbar_modulo_permitido('mi_perfil') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('mi_perfil.php'); ?>"><i class="fas fa-user"></i> Mi perfil</a>

        <a href="logout.php" style="margin-top: 12px;">
          <i class="fas fa-sign-out-alt"></i> Cerrar sesión
        </a>
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
        <span><?php echo $es_super_admin ? 'Superadministrador' : ucfirst(htmlspecialchars($rol)); ?></span>
      </div>
    </div>

    <div class="nav-links">
        <a href="dashboard_admin.php" data-modulo="panel_admin" data-permitido="<?php echo navbar_modulo_permitido('panel_admin') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('dashboard_admin.php'); ?>"><i class="fas fa-house"></i><span>Inicio</span></a>
        <?php if (!$es_super_admin): ?>
        <a href="dashboard_vendedor.php" data-modulo="panel_vendedor" data-permitido="<?php echo navbar_modulo_permitido('panel_vendedor') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('dashboard_vendedor.php'); ?>"><i class="fas fa-gauge-high"></i><span>Panel vendedor</span></a>
        <?php endif; ?>
        <a href="corte_caja.php" data-modulo="corte_caja" data-permitido="<?php echo navbar_modulo_permitido('corte_caja') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('corte_caja.php'); ?>"><i class="fas fa-money-bill-wave"></i><span>Corte de caja</span></a>
        <a href="dashboard_ventas.php" data-modulo="ventas" data-permitido="<?php echo navbar_modulo_permitido('ventas') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa(['dashboard_ventas.php', 'ventas.php', 'venta_admin.php', 'ventas_proveedor.php', 'pedidos.php']); ?>"><i class="fas fa-cash-register"></i><span>Registrar venta</span></a>
        <a href="historial_ventas.php" data-modulo="historial_ventas" data-permitido="<?php echo navbar_modulo_permitido('historial_ventas') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('historial_ventas.php'); ?>"><i class="fas fa-receipt"></i><span>Historial de ventas</span></a>
        <a href="<?php echo htmlspecialchars($ruta_inventario_menu, ENT_QUOTES, 'UTF-8'); ?>" data-modulo="inventario" data-permitido="<?php echo navbar_modulo_permitido('inventario') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa(['dashboard_inventario.php', 'inventario_admin.php', 'inventario.php']); ?>"><i class="fas fa-boxes-stacked"></i><span>Inventario</span></a>
        <a href="dashboard_productos.php" data-modulo="productos" data-permitido="<?php echo navbar_modulo_permitido('productos') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa(['dashboard_productos.php', 'productos.php', 'ajustes_productos.php']); ?>"><i class="fas fa-box"></i><span>Productos</span></a>
        <a href="promociones.php" data-modulo="promociones" data-permitido="<?php echo navbar_modulo_permitido('promociones') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('promociones.php'); ?>"><i class="fas fa-tags"></i><span>Promociones</span></a>
        <a href="vendedor_ajustes_productos.php" data-modulo="ajustes_productos" data-permitido="<?php echo navbar_modulo_permitido('ajustes_productos') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('vendedor_ajustes_productos.php'); ?>"><i class="fas fa-sliders"></i><span>Ajustes de productos</span></a>
        <a href="proveedores.php" data-modulo="proveedores" data-permitido="<?php echo navbar_modulo_permitido('proveedores') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('proveedores.php'); ?>"><i class="fas fa-truck"></i><span>Proveedores</span></a>
        <a href="<?php echo htmlspecialchars($ruta_reportes_menu, ENT_QUOTES, 'UTF-8'); ?>" data-modulo="reportes" data-permitido="<?php echo navbar_modulo_permitido('reportes') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa(['historial_reportes.php', 'dashboard_reportes_ventas.php', 'reportes_vendedor.php', 'reporte_vendedor_productos.php']); ?>"><i class="fas fa-file-lines"></i><span>Reportes</span></a>
        <a href="historial_stock.php" data-modulo="historial_stock" data-permitido="<?php echo navbar_modulo_permitido('historial_stock') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('historial_stock.php'); ?>"><i class="fas fa-clock-rotate-left"></i><span>Historial de stock</span></a>
        <a href="ver_ventas.php" data-modulo="estadisticas" data-permitido="<?php echo navbar_modulo_permitido('estadisticas') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('ver_ventas.php'); ?>"><i class="fas fa-chart-line"></i><span>Estadísticas</span></a>
        <a href="asignar_productos_vendedor.php" data-modulo="asignar_productos" data-permitido="<?php echo navbar_modulo_permitido('asignar_productos') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('asignar_productos_vendedor.php'); ?>"><i class="fas fa-user-tag"></i><span>Asignar productos</span></a>
        <a href="configuracion.php" data-modulo="configuracion" data-permitido="<?php echo navbar_modulo_permitido('configuracion') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('configuracion.php'); ?>"><i class="fas fa-gears"></i><span>Configuración</span></a>
        <?php if ($es_admin): ?>
        <a href="control_accesos.php" data-modulo="control_accesos" data-permitido="1" class="<?php echo navbar_clase_activa('control_accesos.php'); ?>"><i class="fas fa-user-shield"></i><span>Control de accesos</span></a>
        <?php endif; ?>
        <a href="mi_perfil.php" data-modulo="mi_perfil" data-permitido="<?php echo navbar_modulo_permitido('mi_perfil') ? '1' : '0'; ?>" class="<?php echo navbar_clase_activa('mi_perfil.php'); ?>"><i class="fas fa-user"></i><span>Mi perfil</span></a>
    </div>

    <a class="logout" href="logout.php">
      <i class="fas fa-sign-out-alt"></i>
      <span>Cerrar Sesión</span>
    </a>
  </aside>

  <?php
  // Módulo legal: muestra el aviso únicamente cuando falta la aceptación.
  $archivo_legal = __DIR__ . '/documentos_legales.php';

  if (is_file($archivo_legal)) {
      require_once $archivo_legal;
  } else {
      error_log('No se encontró el módulo legal en: ' . $archivo_legal);
  }
  ?>

  <!-- Scripts -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      /*
       * Respaldo para marcar el módulo activo usando la URL real.
       * Esto evita problemas por caché, includes o configuraciones del servidor.
       */
      const currentFile = (
        window.location.pathname.split('/').pop() || ''
      ).toLowerCase();

      const modulePages = {
        'dashboard_admin.php': ['dashboard_admin.php'],
        'corte_caja.php': ['corte_caja.php'],
        'dashboard_ventas.php': [
          'dashboard_ventas.php',
          'ventas.php',
          'venta_admin.php'
        ],
        'dashboard_inventario.php': ['dashboard_inventario.php'],
        'dashboard_productos.php': ['dashboard_productos.php'],
        'promociones.php': ['promociones.php'],
        'historial_ventas.php': ['historial_ventas.php'],
        'proveedores.php': ['proveedores.php'],
        'historial_reportes.php': ['historial_reportes.php'],
        'historial_stock.php': ['historial_stock.php'],
        'ver_ventas.php': ['ver_ventas.php'],
        'asignar_productos_vendedor.php': [
          'asignar_productos_vendedor.php'
        ],
        'configuracion.php': ['configuracion.php'],
        'control_accesos.php': ['control_accesos.php'],
        'mi_perfil.php': ['mi_perfil.php'],
        'dashboard_vendedor.php': ['dashboard_vendedor.php'],
        'inventario.php': ['inventario.php'],
        'dashboard_reportes_ventas.php': [
          'dashboard_reportes_ventas.php'
        ],
        'vendedor_ajustes_productos.php': [
          'vendedor_ajustes_productos.php'
        ]
      };

      document.querySelectorAll(
        '.nav-links a[href], .mobile-nav-links a[href]'
      ).forEach(function(link) {
        const href = link.getAttribute('href');

        if (!href || href.startsWith('#')) {
          return;
        }

        let linkFile = '';

        try {
          linkFile = (
            new URL(href, window.location.href)
              .pathname
              .split('/')
              .pop() || ''
          ).toLowerCase();
        } catch (error) {
          linkFile = href.split('?')[0].split('/').pop().toLowerCase();
        }

        const relatedPages = modulePages[linkFile] || [linkFile];
        const isActive = relatedPages.includes(currentFile);

        link.classList.toggle('active', isActive);

        if (isActive) {
          link.setAttribute('aria-current', 'page');
        } else {
          link.removeAttribute('aria-current');
        }
      });

      const sidebar = document.getElementById('sidebar');
      const toggleBtn = document.getElementById('toggleBtn');
      const contentWrapper = document.querySelector('.content-wrapper');

      if (toggleBtn && sidebar && contentWrapper) {
        const toggleIcon = toggleBtn.querySelector('i');

        toggleBtn.addEventListener('click', function() {
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

      document.querySelectorAll('.submenu-toggle').forEach(function(toggle) {
        toggle.addEventListener('click', function() {
          const parent = this.parentElement;
          if (parent) {
            parent.classList.toggle('open');
          }
        });
      });

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

      function handleResponsive() {
        const sidebar = document.getElementById('sidebar');
        const contentWrapper = document.querySelector('.content-wrapper');

        if (window.innerWidth < 768) {
          if (sidebar) {
            sidebar.classList.remove('closed');
          }

          if (contentWrapper) {
            contentWrapper.style.marginLeft = '0';
          }
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

  <?php
  /*
   * Control global de sesión.
   * El JavaScript se inserta en línea para evitar errores 404, rutas relativas
   * incorrectas o caché del navegador. No depende de storage/sessions.
   */
  $datos_sesion_cliente = function_exists('cfgSesionDatosCliente')
      ? cfgSesionDatosCliente($session_config ?? [], $_SESSION)
      : null;

  $session_timeout_js = __DIR__ . '/../js/session-timeout.js';
  $session_timeout_code = is_file($session_timeout_js)
      ? (string) file_get_contents($session_timeout_js)
      : '';

  // Evita que una cadena accidental cierre anticipadamente la etiqueta script.
  $session_timeout_code = str_ireplace(
      '</script>',
      '<\/script>',
      $session_timeout_code
  );
  ?>

  <?php if (
      is_array($datos_sesion_cliente)
      && !empty($datos_sesion_cliente['authenticated'])
      && $session_timeout_code !== ''
  ): ?>
  <script>
    window.APP_SESSION_CONFIG = <?= json_encode(
        $datos_sesion_cliente,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_AMP
        | JSON_HEX_QUOT
    ) ?>;
  </script>
  <script><?= $session_timeout_code ?></script>
  <?php endif; ?>
</body>
</html>