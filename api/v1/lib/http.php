<?php
declare(strict_types=1);

function api_request_id(): string
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    $candidate = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
    $id = preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $candidate)
        ? $candidate
        : bin2hex(random_bytes(16));
    return $id;
}

function api_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        api_fail(400, 'INVALID_JSON', 'El cuerpo JSON no es válido.');
    }
    return $data;
}

function api_ok(array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'ok' => true,
        'data' => $data,
        'request_id' => api_request_id(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_fail(int $status, string $code, string $message, array $details = []): void
{
    http_response_code($status);
    echo json_encode([
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
            'details' => (object) $details,
        ],
        'request_id' => api_request_id(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_require_fields(array $data, array $fields): void
{
    $missing = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
            $missing[] = $field;
        }
    }
    if ($missing) {
        api_fail(422, 'VALIDATION_ERROR', 'Faltan campos obligatorios.', ['fields' => $missing]);
    }
}

function api_assert_uuid($value, string $field): string
{
    $value = (string) $value;
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
        api_fail(422, 'VALIDATION_ERROR', 'UUID inválido.', ['field' => $field]);
    }
    return strtolower($value);
}

function api_query_int(string $name, int $default, int $min, int $max): int
{
    $raw = $_GET[$name] ?? $default;
    if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
        api_fail(422, 'VALIDATION_ERROR', 'Parámetro entero inválido.', ['field' => $name]);
    }
    return max($min, min($max, (int) $raw));
}

