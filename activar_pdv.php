<?php
require_once 'includes/mercadopago_config.php';

$body = [
    'terminals' => [
        [
            'id' => MP_TERMINAL_ID,
            'operating_mode' => 'PDV'
        ]
    ]
];

$ch = curl_init('https://api.mercadopago.com/terminals/v1/setup');

curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'PATCH',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . MP_ACCESS_TOKEN,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($body)
]);

$response = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "<pre>";
echo "HTTP: $http\n";
echo "ERROR CURL: $error\n\n";
print_r(json_decode($response, true));
echo "\nRAW:\n$response";
echo "</pre>";