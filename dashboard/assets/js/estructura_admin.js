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

    if (!path.endsWith('/estructura_admin.php')) {
        return;
    }

    document.querySelectorAll('form').forEach((form) => {
        const action = form.querySelector('input[name="action"]')?.value || '';
        const codeInput = form.querySelector('input[name="code"]');

        if (!codeInput) {
            return;
        }

        const field = codeInput.closest('.field');
        const label = field?.querySelector('label');

        if (action === 'create' || action === 'create_root') {
            codeInput.value = '';
            codeInput.type = 'hidden';

            if (field) {
                field.classList.add('automatic-code-field');
                field.innerHTML = `
                    <label>Código del sistema</label>
                    <div class="automatic-code-note">
                        Se generará automáticamente al guardar. No podrá repetirse ni reutilizarse.
                    </div>
                    <input type="hidden" name="code" value="">
                `;
            }
            return;
        }

        if (action === 'update') {
            codeInput.readOnly = true;
            codeInput.setAttribute('aria-readonly', 'true');
            if (label) {
                label.textContent = 'Código del sistema';
            }

            const note = document.createElement('span');
            note.className = 'subtext automatic-code-help';
            note.textContent = 'Este código es permanente y no se puede cambiar ni reutilizar.';
            field?.appendChild(note);
        }
    });
})();
