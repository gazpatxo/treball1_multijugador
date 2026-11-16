<?php
echo "PHP: " . PHP_VERSION . "<br>";
echo "Archivo php.ini: " . (php_ini_loaded_file() ?: "No identificado") . "<br>";
echo "cURL: " . (function_exists('curl_init') ? "ACTIVADO" : "DESACTIVADO");
?>