<?php
require_once __DIR__ . "/config/seguridad_administrador.php";
?>
<section class="menu-admin-panel" aria-label="Acciones de administrador">
    <div class="menu-admin-encabezado">
        <span>CONFIGURACIÓN</span>
        <h2>Administración</h2>
    </div>
    <a class="menu-admin-enlace menu-admin-biologicos" href="biologicos/index.php">
        <span class="menu-admin-enlace-icono" aria-hidden="true">
            <svg viewBox="0 0 24 24">
                <path d="m9 3 6 0M10 3v5l-4.5 9.2A2.6 2.6 0 0 0 7.8 21h8.4a2.6 2.6 0 0 0 2.3-3.8L14 8V3"></path>
                <path d="M7.5 15h9"></path>
            </svg>
        </span>
        <span>
            <strong>Biológicos</strong>
            <small>Administrar biológicos y presentaciones</small>
        </span>
        <span class="menu-admin-enlace-flecha" aria-hidden="true">›</span>
    </a>
    <a class="menu-admin-enlace menu-admin-usuarios" href="usuarios/index.php">
        <span class="menu-admin-enlace-icono" aria-hidden="true">
            <svg viewBox="0 0 24 24">
                <circle cx="9" cy="8" r="3"></circle>
                <path d="M3.5 20v-1.5a5.5 5.5 0 0 1 11 0V20zM16 5.5a3 3 0 0 1 0 5.8M17 14a4.5 4.5 0 0 1 3.5 4.4V20h-4"></path>
            </svg>
        </span>
        <span>
            <strong>Usuarios</strong>
            <small>Administrar cuentas y asignaciones</small>
        </span>
        <span class="menu-admin-enlace-flecha" aria-hidden="true">›</span>
    </a>
    <a class="menu-admin-enlace menu-admin-esquemas" href="censo/administrar.php">
        <span class="menu-admin-enlace-icono" aria-hidden="true">
            <svg viewBox="0 0 24 24">
                <path d="M4 5.5h16M4 12h16M4 18.5h16"></path>
                <circle cx="8" cy="5.5" r="1.5"></circle>
                <circle cx="15" cy="12" r="1.5"></circle>
                <circle cx="10" cy="18.5" r="1.5"></circle>
            </svg>
        </span>
        <span>
            <strong>Esquemas y censos</strong>
            <small>Crear esquemas y configurar censos</small>
        </span>
        <span class="menu-admin-enlace-flecha" aria-hidden="true">›</span>
    </a>
</section>