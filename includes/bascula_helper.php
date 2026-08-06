<?php
/**
 * includes/bascula_helper.php
 * Configuración central de la integración con la báscula local.
 */
declare(strict_types=1);

if (!function_exists('bascula_configuracion')) {
    function bascula_configuracion(mysqli $conn): array
    {
        $config = [
            'activo' => 1,
            'url_servicio_local' => 'http://127.0.0.1:8787',
            'token_local' => 'pescadores-hardware-local',
            'auto_captura' => 1,
            'intervalo_ms' => 350,
            'peso_minimo_kg' => 0.005,
            'variacion_estable_kg' => 0.003,
            'lecturas_estables' => 3,
        ];

        try {
            $resultado = $conn->query(
                "SELECT activo, url_servicio_local, token_local,
                        auto_captura, intervalo_ms, peso_minimo_kg,
                        variacion_estable_kg, lecturas_estables
                 FROM configuracion_bascula
                 WHERE id = 1
                 LIMIT 1"
            );

            if ($resultado && ($fila = $resultado->fetch_assoc())) {
                $config['activo'] = (int) $fila['activo'] === 1 ? 1 : 0;
                $config['url_servicio_local'] = rtrim(
                    trim((string) $fila['url_servicio_local']),
                    '/'
                );
                $config['token_local'] = trim((string) $fila['token_local']);
                $config['auto_captura'] = (int) $fila['auto_captura'] === 1 ? 1 : 0;
                $config['intervalo_ms'] = max(200, min(3000, (int) $fila['intervalo_ms']));
                $config['peso_minimo_kg'] = max(0.001, (float) $fila['peso_minimo_kg']);
                $config['variacion_estable_kg'] = max(0.000, (float) $fila['variacion_estable_kg']);
                $config['lecturas_estables'] = max(1, min(10, (int) $fila['lecturas_estables']));
            }
        } catch (Throwable $e) {
            error_log('Configuración de báscula no disponible: ' . $e->getMessage());
        }

        return $config;
    }
}

if (!function_exists('bascula_datos_cliente')) {
    function bascula_datos_cliente(array $config): array
    {
        return [
            'activo' => !empty($config['activo']),
            'url' => (string) ($config['url_servicio_local'] ?? 'http://127.0.0.1:8787'),
            'token' => (string) ($config['token_local'] ?? ''),
            'autoCaptura' => !empty($config['auto_captura']),
            'intervaloMs' => (int) ($config['intervalo_ms'] ?? 350),
            'pesoMinimoKg' => (float) ($config['peso_minimo_kg'] ?? 0.005),
            'variacionEstableKg' => (float) ($config['variacion_estable_kg'] ?? 0.003),
            'lecturasEstables' => (int) ($config['lecturas_estables'] ?? 3),
        ];
    }
}
