<?php
include 'includes/session.php';

$nombre = $_SESSION['nombre'] ?? 'Usuario';
$rol = $_SESSION['rol'] ?? 'Sin rol';
?>

<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tienda Pescadores - Navbar Responsive</title>

  <!-- AdminLTE CSS (CDN) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    :root{
      --sidebar-width: 250px;
      --sidebar-collapsed: 70px;
      --glass-bg: rgba(30, 40, 70, 0.6);
      --glass-border: rgba(80, 110, 180, 0.45);
      --accent: #0c0c0cff;
      --blur: 8px;
      --transition-fast: 220ms;
    }

    /* Menu lateral en blanco */
    .sidebar-custom,
    .sidebar-custom * {
        color: #ffffff !important;
    }

    /* Enlaces del menú */
    .sidebar-custom .nav-links a {
        color: #ffffff !important;
    }

    /* Íconos principales y secundarios */
    .sidebar-custom .nav-links a i,
    .sidebar-custom .submenu-toggle i,
    .sidebar-custom .submenu-items a i,
    .sidebar-custom .logout i {
        color: #ffffff !important;
    }

    /* Flechas del submenú */
    .sidebar-custom .icon-arrow {
        color: #ffffff !important;
    }

    /* Títulos o encabezados dentro del sidebar */
    .sidebar-custom .menu-header,
    .sidebar-custom h3,
    .sidebar-custom h4 {
        color: #ffffff !important;
    }

    /* Reset for the example page */
    body{ margin:0; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial; color:var(--text); }

    /* SIDEBAR - desktop */
    .sidebar-custom{
      position:fixed; left:0; top:0; height:100vh; width:var(--sidebar-width); padding:18px; box-sizing:border-box;
      background: linear-gradient(180deg, #e76f51, #f4a261);
      display:flex; flex-direction:column; gap:18px; z-index:30;
      backdrop-filter: blur(6px);
      transition: width var(--transition-fast) ease, transform var(--transition-fast) ease;
    }

    .sidebar-custom.closed{ width:var(--sidebar-collapsed); }
    .sidebar-custom.closed .nav-links span{ display:none; }
    .sidebar-custom.closed .submenu-toggle span{ display:none; }
    .sidebar-custom.closed h1{ display:none; }
    .sidebar-custom.closed .user-info{ display:none; }
    .sidebar-custom.closed .nav-links span{ display:none; }
    .sidebar-custom.closed .submenu-toggle span{ display:none; }
    .sidebar-custom.closed .logout span{ display:none; }
     
    .sidebar-custom h1{ font-size:18px; letter-spacing:0.6px; margin:0; color:var(--accent); }
    .sidebar-custom .user-info{ font-size:13px; color:var(--muted); }

    /* Nav links */
    .nav-links{ display:flex; flex-direction:column; gap:6px; margin-top:8px; }
    .nav-links a{ display:flex; align-items:center; gap:12px; padding:10px 12px; border-radius:12px; text-decoration:none; color:var(--text); transition: transform var(--transition-fast), background var(--transition-fast), box-shadow var(--transition-fast);
      background: transparent; }
    .nav-links a:hover{ transform: translateY(-3px); box-shadow: 0 6px 18px rgba(12,22,30,0.45); background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); }
    .nav-links a i{ width:24px; text-align:center; font-size:16px; }

    /* Submenu styles */
    .submenu{ margin-top:6px; border-radius:12px; overflow:hidden; }
    .submenu-toggle{ display:flex; align-items:center; justify-content:space-between; padding:10px 12px; cursor:pointer; gap:12px; }
    .submenu-toggle > span{ flex:1; text-align:left; }
    .submenu-items{ display:none; flex-direction:column; gap:6px; padding:8px 6px 12px 6px; }
    .submenu.open .submenu-items{ display:flex; }

    /* Animated icon - subtle float */
    .fa-anim{ animation: floaty 3s ease-in-out infinite; transform-origin:center bottom; }
    @keyframes floaty{ 0%{ transform: translateY(0) } 50%{ transform: translateY(-6px) } 100%{ transform: translateY(0) } }

    /* Topbar (mobile) */
    .topbar-mobile{
      display:none; position:fixed; top:0; left:0; right:0; height:64px; padding:10px 14px; box-sizing:border-box; z-index:60;
      align-items:center; gap:12px; backdrop-filter: blur(var(--blur));
      background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02)); border-bottom:1px solid var(--glass-border);
    }
    .topbar-mobile .brand{ font-weight:700; color:var(--accent); }
    .topbar-mobile .hamburger{ background:transparent; border:0; font-size:20px; color:var(--text); }

    /* Mobile overlay menu (glass + blur transition) */
    .mobile-overlay{ position:fixed; inset:0; display:none; z-index:70; align-items:flex-start; justify-content:center; }
    .mobile-overlay.active{ display:flex; }
    .mobile-overlay .backdrop{ position:absolute; inset:0; background: rgba(6,10,14,0.45); backdrop-filter: blur(8px); transition: opacity 240ms ease; }
    .mobile-overlay .panel{ position:relative; margin-top:72px; width:92%; max-width:420px; border-radius:14px; padding:14px; box-sizing:border-box;
      background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02)); border:1px solid rgba(255,255,255,0.08); backdrop-filter: blur(8px); transform: translateY(-8px); opacity:0; transition: all 260ms ease; }
    .mobile-overlay.active .panel{ transform: translateY(0); opacity:1; }

    /* Content push on desktop */
    .content-wrapper{ margin-left:var(--sidebar-width); padding:24px; transition: margin-left var(--transition-fast) ease; min-height:100vh; }

    /* small screens: hide sidebar, show topbar */
    @media (max-width: 767px){
      .sidebar-custom{ transform: translateX(-120%); transition: transform 280ms ease; }
      .topbar-mobile{ display:flex; }
      .content-wrapper{ margin-left:0; padding-top:84px; }
    }

    /* small visual tweaks */
    .logout{ margin-top:auto; text-decoration:none; padding:10px 12px; display:flex; align-items:center; gap:10px; border-radius:10px; }
    .logout:hover{ background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02)); border:1px solid rgba(255,255,255,0.08); backdrop-filter: blur(8px); transform: translateY(-8px); }
    /* tiny helper for small icon badges */
    .badge-dot{ display:inline-block; width:9px; height:9px; border-radius:50%; background:var(--accent); box-shadow:0 0 10px rgba(79,209,197,0.18); }

    /* ============================ */
/*   🎨 NAVBAR MÓVIL MEJORADO   */
/* ============================ */

/* Links dentro del panel móvil */
.nav-links-mobile a{
  display:flex;
  align-items:center;
  gap:10px;
  padding:12px 14px;
  border-radius:12px;
  text-decoration:none;
  font-size:15px;

  /* Nuevo color: blanco suave, NO azul */
  color:#fff;

  /* Efecto glass suave */
  background: rgba(255, 255, 255, 0.05);
  backdrop-filter: blur(6px);
  border:1px solid rgba(255,255,255,0.08);

  transition: transform 0.2s ease, background 0.2s ease, border 0.2s ease;
}

/* Hover – elegante y naranja */
.nav-links-mobile a:hover{
  transform: translateX(6px);
  background: rgba(255, 175, 90, 0.25);
  border-color: rgba(255, 175, 90, 0.45);
}

/* Íconos del menú móvil */
.nav-links-mobile a i{
  width:24px;
  text-align:center;
  font-size:18px;
  color:#fff; /* ICONOS YA NO AZULES */
}

/* Submenú <details> */
.nav-links-mobile details{
  background: rgba(255,255,255,0.05);
  border:1px solid rgba(255,255,255,0.08);
  border-radius:12px;
  padding:6px 10px;
  color:white;
}

.nav-links-mobile summary{
  list-style:none;
  cursor:pointer;
  padding:10px;
  border-radius:10px;
  font-size:15px;
  display:flex;
  align-items:center;
  gap:10px;
  color:#fff;
}

/* Items dentro del submenu */
.nav-links-mobile details div a{
  padding-left:35px;
  color:#eee;
  background: rgba(40,40,40,0.3);
  border:1px solid rgba(255,255,255,0.05);
}
  </style>
</head>
<body>

  <!-- TOPBAR for mobile -->
  <div class="topbar-mobile" role="navigation" aria-label="Barra superior móvil">
    <button id="mobileToggle" class="hamburger" aria-expanded="false" aria-controls="mobileMenu"><i class="fas fa-bars"></i></button>
    <div class="brand">Tienda Pescadores</div>
    <div style="margin-left:auto; display:flex; gap:12px; align-items:center;">
      <div style="text-align:right; font-size:13px; color:var(--muted);">
        <div><?php echo htmlspecialchars($nombre); ?></div>
        <div style="font-size:12px; color:var(--muted);"><?php echo ucfirst(htmlspecialchars($rol)); ?></div>
      </div>
      <div class="badge-dot" title="Conectado"></div>
    </div>
  </div>

  <!-- MOBILE OVERLAY MENU -->
  <div id="mobileMenu" class="mobile-overlay" aria-hidden="true">
    <div class="backdrop" data-dismiss="true"></div>
    <nav class="panel" role="menu" aria-label="Menú principal móvil">
      <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
        <h3 style="margin:0; font-size:18px; color:#fff; ">Tienda Pescadores</h3>
        <button id="mobileClose" aria-label="Cerrar menú" style="margin-left:auto; background:transparent; border:0; font-size:18px; color:var(--text)"><i class="fas fa-xmark"></i></button>
      </div>

      <div class="nav-links-mobile" style="display:flex; flex-direction:column; gap:8px;">
        <?php if ($rol === 'administrador'): ?>
          <a href="dashboard_admin.php"><i class="fas fa-tachometer-alt fa-anim"></i> Panel Admin</a>
          <a href="registrar_usuario.php"><i class="fas fa-user-plus fa-anim"></i> Registrar Usuario</a>
          <details style="border-radius:10px; padding:8px;">
            <summary style="cursor:pointer;"> <i class="fas fa-store fa-anim"></i> Inventario / Ventas</summary>
            <div style="display:flex; flex-direction:column; gap:6px; padding-top:8px;">
              <a href="productos.php">Gestión Productos</a>
              <a href="pedidos.php"><i class="fas fa-shipping-fast fa-anim"></i><span>Pedidos</span></a>
              <a href="venta_admin.php">Registrar Ventas</a>
              <a href="ventas_proveedor.php">Registro Ventas por Proveeedor</a>
              <a href="historial_ventas.php">Historial / Cancelación</a>
            </div>
          </details>
          <a href="inventario_admin.php"><i class="fas fa-boxes fa-anim"></i><span>Inventario</span></a>
          <a href="ver_ventas.php"><i class="fas fa-chart-line fa-anim"></i> Ver Ventas</a>
          <a href="cambiar_password.php"><i class="nav-icon fas fa-key fa-anim"></i><span>Cambiar contraseña</span></a>
        <?php elseif ($rol === 'vendedor'): ?>
          <a href="dashboard_vendedor.php"><i class="fas fa-home fa-anim"></i> Panel Vendedor</a>
          <a href="ventas.php"><i class="fas fa-cash-register fa-anim"></i> Registrar Venta</a>
          <a href="historial_ventas.php"><i class="fas fa-receipt fa-anim"></i> Historial / Cancelación</a>
          <a href="inventario.php"><i class="fas fa-boxes fa-anim"></i> Inventario</a>
          <a href="cambiar_password.php"><i class="nav-icon fas fa-key fa-anim"></i><span>Cambiar contraseña</span></a>
        <?php endif; ?>

        <a href="logout.php" style="margin-top:8px;"><i class="fas fa-sign-out-alt fa-anim"></i> Cerrar sesión</a>
      </div>
    </nav>
  </div>

  <!-- SIDEBAR - desktop/tablet -->
  <aside class="sidebar-custom" id="sidebar">
    <button id="toggleBtn" class="toggle-btn" title="Colapsar menú" style="background:transparent; border:0; color:var(--muted); text-align:left; padding:0;">
      <i class="fas fa-bars"></i>
    </button>

    <h1>Tienda Pescadores</h1>

    <div class="user-info">
      <strong><?php echo htmlspecialchars($nombre); ?></strong><br>
      <span><?php echo ucfirst(htmlspecialchars($rol)); ?></span>
    </div>

    <div class="nav-links">
      <?php if ($rol === 'administrador'): ?>
          <a href="dashboard_admin.php"><i class="fas fa-tachometer-alt fa-anim"></i><span>Panel Admin</span></a>
          <a href="registrar_usuario.php"><i class="fas fa-user-plus fa-anim"></i><span>Registrar Usuario</span></a>

          <div class="submenu">
            <div class="submenu-toggle" role="button" tabindex="0" aria-expanded="false">
              <div style="display:flex; align-items:center; gap:12px;"><i class="fas fa-store fa-anim"></i><span>Inventario/Ventas</span></div>
              <i class="fa-solid fa-circle-chevron-right icon-arrow"></i>
            </div>

            <div class="submenu-items">
              <a href="productos.php"><i class="fas fa-box fa-anim"></i><span>Gestión Productos</span></a>
              <a href="ventas_proveedor.php"><i class="fas fa-handshake fa-anim"></i><span>Registro Inventario por Proveeedor</span></a>
              <a href="pedidos.php"><i class="fas fa-shipping-fast fa-anim"></i><span>Pedidos</span></a>
              <a href="historial_ventas.php"><i class="fas fa-receipt fa-anim"></i><span>Historial / Cancelación</span></a>
              <a href="venta_admin.php"><i class="fas fa-cash-register fa-anim"></i><span>Registrar Ventas</span></a>
            </div>
          </div>
          <a href="inventario_admin.php"><i class="fas fa-boxes fa-anim"></i><span>Inventario</span></a>
          <a href="ver_ventas.php"><i class="fas fa-chart-line fa-anim"></i><span>Reporte de Ventas</span></a>
          <a href="cambiar_password.php"><i class="nav-icon fas fa-key fa-anim"></i><span>Cambiar contraseña</span></a>
      <?php elseif ($rol === 'vendedor'): ?>
          <a href="dashboard_vendedor.php"><i class="fas fa-home fa-anim"></i><span>Panel Vendedor</span></a>
          <a href="ventas.php"><i class="fas fa-cash-register fa-anim"></i><span>Registrar Venta</span></a>
          <a href="historial_ventas.php"><i class="fas fa-receipt fa-anim"></i><span>Historial / Cancelación</span></a>
          <a href="inventario.php"><i class="fas fa-boxes fa-anim"></i><span>Inventario</span></a>
          <a href="cambiar_password.php"><i class="nav-icon fas fa-key fa-anim"></i><span>Cambiar contraseña</span></a>
      <?php endif; ?>
    </div>

    <a class="logout" href="logout.php">
      <i class="fas fa-sign-out-alt fa-anim" style="color: #000000;"></i> <span style="color: #000000;">Cerrar Sesión</span>
    </a>
  </aside>

  <!-- AdminLTE JS + dependencies (Popper is included) -->
  <script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/plugins/jquery/jquery.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>

  <script>
    // Desktop toggle
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggleBtn');
    const content = document.getElementById('content');

    toggleBtn.addEventListener('click', () => {
      sidebar.classList.toggle('closed');
      if(sidebar.classList.contains('closed')){
        content.style.marginLeft = 'var(--sidebar-collapsed)';
      } else {
        content.style.marginLeft = 'var(--sidebar-width)';
      }
    });

    // Submenu toggles (keyboard accessible)
    document.querySelectorAll('.submenu-toggle').forEach(toggle => {
      toggle.addEventListener('click', function(){
        const parent = this.parentElement;
        parent.classList.toggle('open');
        const icon = this.querySelector('.icon-arrow');
        if(parent.classList.contains('open')){
          icon.classList.remove('fa-circle-chevron-right'); icon.classList.add('fa-circle-chevron-down');
          this.setAttribute('aria-expanded','true');
        } else {
          icon.classList.remove('fa-circle-chevron-down'); icon.classList.add('fa-circle-chevron-right');
          this.setAttribute('aria-expanded','false');
        }
      });
      // allow Enter/Space to toggle
      toggle.addEventListener('keydown', function(e){ if(e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.click(); } });
    });

    // Mobile overlay logic
    const mobileToggle = document.getElementById('mobileToggle');
    const mobileMenu = document.getElementById('mobileMenu');
    const mobileClose = document.getElementById('mobileClose');
    const mobileBackdrop = document.querySelector('.mobile-overlay .backdrop');

    function openMobile(){
      mobileMenu.classList.add('active');
      mobileMenu.setAttribute('aria-hidden','false');
      mobileToggle.setAttribute('aria-expanded','true');
      document.body.style.overflow = 'hidden'; // prevent scroll
    }
    function closeMobile(){
      mobileMenu.classList.remove('active');
      mobileMenu.setAttribute('aria-hidden','true');
      mobileToggle.setAttribute('aria-expanded','false');
      document.body.style.overflow = '';
    }

    mobileToggle.addEventListener('click', () => {
      if(mobileMenu.classList.contains('active')) closeMobile(); else openMobile();
    });
    mobileClose.addEventListener('click', closeMobile);
    mobileBackdrop.addEventListener('click', closeMobile);

    // Close mobile on ESC
    document.addEventListener('keydown', (e) => { if(e.key === 'Escape') closeMobile(); });

    // Responsive behavior on resize
    function adaptLayout(){
      if(window.innerWidth < 768){
        sidebar.style.transform = 'translateX(-120%)';
        content.style.marginLeft = '0';
      } else {
        sidebar.style.transform = '';
        sidebar.classList.remove('closed');
        content.style.marginLeft = 'var(--sidebar-width)';
        closeMobile();
      }
    }

    window.addEventListener('resize', adaptLayout);
    adaptLayout();

  </script>
</body>
</html>
