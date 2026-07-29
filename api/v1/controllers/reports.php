<?php
declare(strict_types=1);

function api_report_summary(mysqli $db, array $auth): void
{
    api_require_module($db, $auth, 'reportes');
    $sales = $db->query(
        'SELECT COUNT(*) sales_count,COALESCE(ROUND(SUM(total)*100),0) gross_cents
         FROM ventas_cabecera WHERE deleted_at IS NULL'
    )->fetch_assoc();
    $corrections = $db->query(
        "SELECT COALESCE(ROUND(SUM(amount)*100),0) correction_cents
         FROM sale_corrections WHERE status='completed'"
    )->fetch_assoc();
    $products = $db->query(
        'SELECT COUNT(*) products,
          COALESCE(SUM(CASE WHEN cantidad<=0 THEN 1 ELSE 0 END),0) low_stock
         FROM productos WHERE deleted_at IS NULL AND activo=1'
    )->fetch_assoc();
    $lastSync = $db->query('SELECT MAX(processed_at) last_sync_at FROM processed_operations')
        ->fetch_assoc()['last_sync_at'];
    $gross = (int) $sales['gross_cents'];
    $correction = (int) $corrections['correction_cents'];
    api_ok([
        'scope' => 'consolidated',
        'generatedAt' => gmdate('c'),
        'lastSyncAt' => $lastSync ? gmdate('c', strtotime($lastSync . ' UTC')) : null,
        'totals' => [
            'salesCount' => (int) $sales['sales_count'],
            'grossCents' => $gross,
            'correctionsCents' => $correction,
            'netCents' => $gross - $correction,
            'products' => (int) $products['products'],
            'lowStock' => (int) $products['low_stock'],
        ],
    ]);
}
