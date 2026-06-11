<?php
error_reporting(E_ALL & ~E_DEPRECATED);
session_start();

require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['rol'] ?? '', ['administrador', 'vendedor'], true)) {
    header('Location: login.php');
    exit;
}

include 'includes/header.php';
include 'includes/navbar.php';
?>

<link rel="stylesheet" href="css/dashboard_reportes_ventas.css?v=<?= time() ?>">

<div class="content-wrapper reportes-dashboard-page">
    <section class="content">
        <div class="container-fluid">

            <div class="breadcrumb-card">
                <a href="dashboard_vendedor.php"><i class="fas fa-home"></i> Inicio</a>
                <span>/</span>
                <strong><i class="fas fa-chart-line"></i> Reportes</strong>
            </div>

            <div class="page-title-block">
                <div class="title-icon"><i class="fas fa-chart-pie"></i></div>
                <div>
                    <h1>Reportes de ventas</h1>
                    <span></span>
                    <p>Selecciona el tipo de reporte que deseas consultar.</p>
                </div>
            </div>

            <div class="report-options-grid">

                <a href="reportes_vendedor.php" class="report-option-card">
                    <div class="option-icon"><i class="fas fa-store"></i></div>
                    <div class="option-content">
                        <h2>Reporte general</h2>
                        <p>Consulta todos los productos vendidos, ventas totales, stock, ganancia y deuda general.</p>
                    </div>
                    <div class="option-footer">
                        <span><i class="fas fa-layer-group"></i> Todos</span>
                        <strong>Abrir <i class="fas fa-arrow-right"></i></strong>
                    </div>
                </a>

                <a href="reporte_vendedor_productos.php" class="report-option-card">
                    <div class="option-icon"><i class="fas fa-user-check"></i></div>
                    <div class="option-content">
                        <h2>Productos asignados</h2>
                        <p>Consulta ventas, deuda y stock únicamente de productos asignados a vendedores autorizados.</p>
                    </div>
                    <div class="option-footer">
                        <span><i class="fas fa-boxes"></i> Asignados</span>
                        <strong>Abrir <i class="fas fa-arrow-right"></i></strong>
                    </div>
                </a>

            </div>

        </div>
    </section>
</div>