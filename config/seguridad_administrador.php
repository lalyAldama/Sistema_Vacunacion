<?php
require_once __DIR__ . "/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    $archivoActual = basename($_SERVER["SCRIPT_NAME"] ?? "");
    $rutaLogin = $archivoActual === "menu_admin.php" ? "login.php" : "../login.php";
    header("Location: " . $rutaLogin);
    exit();
}

if (($_SESSION["nombre_rol"] ?? "") !== "Administrador") {
    http_response_code(403);
    exit("Esta función está disponible únicamente para el administrador.");
}
