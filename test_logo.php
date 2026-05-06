<?php
$archivo = "img/panel_principal.jpg";
echo "Buscando: " . $archivo . "<br>";
echo "Existe: " . (file_exists($archivo) ? "SI" : "NO") . "<br>";
echo "Ruta real: " . realpath($archivo) . "<br>";
echo "Directorio actual: " . __DIR__;
?>