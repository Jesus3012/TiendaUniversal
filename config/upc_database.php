<?php
/**
 * Configuración privada de la API oficial de UPC Database.
 *
 * IMPORTANTE:
 * - Genera el OAuth token en: cuenta de UPC Database > API Keys.
 * - Pega SOLO el valor del token.
 * - No pegues "Bearer", "Authorization:", comillas ni
 *   "UPCDATABASE_API_TOKEN=". El integrador los limpia por seguridad,
 *   pero es preferible guardar únicamente el token.
 * - No compartas este archivo ni publiques el token en GitHub.
 */

return [
    'activo' => true,

    // Opción recomendada: variable de entorno.
    // En hosting compartido puedes reemplazar PEGA_SOLO_EL_TOKEN_AQUI.
    'api_token' => getenv('UPCDATABASE_API_TOKEN') ?: 'A51FEEAC695D1B31F4EE022259FEE25A',

    'endpoint_base' => 'https://api.upcdatabase.org',
    'timeout_segundos' => 7,
    'conexion_timeout_segundos' => 3,
    'verificar_ssl' => true,

    // Evita consumir varias consultas al repetir el mismo código.
    'cache_segundos' => 900,
];
