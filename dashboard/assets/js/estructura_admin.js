(() => {
    const path = window.location.pathname.toLowerCase();
    const configurationPages = [
        '/configuracion.php',
        '/estructura_admin.php',
        '/configuracion_estructura_reglas.php',
        '/configuracion_estructura_historial.php',
    ];

    if (configurationPages.some((page) => path.endsWith(page))) {
        document.body.classList.add('configuration-page');
    }

    document.querySelectorAll('a[href^="detalle_zona_personal.php"]').forEach((link) => {
        link.textContent = 'Administrar esta zona';
        link.setAttribute('title', 'Abrir la zona dentro de Configuración del sistema');
    });
})();
