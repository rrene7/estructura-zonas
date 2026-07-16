(() => {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.querySelector('[data-menu-toggle]');

    if (sidebar && toggle) {
        toggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
        });

        document.addEventListener('click', (event) => {
            if (window.innerWidth > 900 || !sidebar.classList.contains('open')) {
                return;
            }

            if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                sidebar.classList.remove('open');
            }
        });
    }

    document.querySelectorAll('[data-confirm]').forEach((element) => {
        element.addEventListener('click', (event) => {
            if (!window.confirm(element.getAttribute('data-confirm') || '¿Desea continuar?')) {
                event.preventDefault();
            }
        });
    });

    const loadDependencyTable = async () => {
        const currentPath = window.location.pathname.toLowerCase();
        if (!currentPath.endsWith('/unidad_detalle.php')) {
            return;
        }

        const parameters = new URLSearchParams(window.location.search);
        const unitId = parameters.get('id');
        const sourceId = parameters.get('source_id');
        const personnelSection = document.getElementById('personal');

        if (!unitId || !personnelSection || document.getElementById('dependencias-secciones')) {
            return;
        }

        const endpoint = new URL('dependencias_unidad.php', window.location.href);
        endpoint.searchParams.set('unit_id', unitId);
        if (sourceId) {
            endpoint.searchParams.set('source_id', sourceId);
        }

        try {
            const response = await fetch(endpoint, {
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                return;
            }

            const html = (await response.text()).trim();
            if (html !== '') {
                personnelSection.insertAdjacentHTML('beforebegin', html);
            }
        } catch (error) {
            console.warn('No fue posible cargar la tabla de dependencias.', error);
        }
    };

    loadDependencyTable();
})();
