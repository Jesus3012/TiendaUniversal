<?php
// includes/utils.php

function logPedido($conn, $idPedido, $accion, $detalle, $usuario){
    $stmt = $conn->prepare("
        INSERT INTO pedidos_log (id_pedido, accion, detalle, usuario, fecha)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("isss", $idPedido, $accion, $detalle, $usuario);
    return $stmt->execute();
}
?>