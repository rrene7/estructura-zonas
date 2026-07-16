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

    const currentUnitParameters = () => {
        const currentPath = window.location.pathname.toLowerCase();
        if (!currentPath.endsWith('/unidad_detalle.php')) {
            return null;
        }

        const parameters = new URLSearchParams(window.location.search);
        const unitId = parameters.get('id');
        const sourceId = parameters.get('source_id');

        if (!unitId) {
            return null;
        }

        return { unitId, sourceId };
    };

    const loadDependencyTable = async () => {
        const context = currentUnitParameters();
        const personnelSection = document.getElementById('personal');

        if (!context || !personnelSection || document.getElementById('dependencias-secciones')) {
            return;
        }

        const endpoint = new URL('dependencias_unidad.php', window.location.href);
        endpoint.searchParams.set('unit_id', context.unitId);
        if (context.sourceId) {
            endpoint.searchParams.set('source_id', context.sourceId);
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

    const loadHierarchySummary = async () => {
        const context = currentUnitParameters();
        if (!context) {
            return;
        }

        const endpoint = new URL('resumen_unidad.php', window.location.href);
        endpoint.searchParams.set('unit_id', context.unitId);
        if (context.sourceId) {
            endpoint.searchParams.set('source_id', context.sourceId);
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

            const summary = await response.json();
            if (!summary.ok || !summary.aggregate || Number(summary.subordinate_total) <= 0) {
                return;
            }

            const numberFormatter = new Intl.NumberFormat('es-PA');
            const cards = document.querySelectorAll('.kpi-grid .kpi-card');
            if (cards.length < 4) {
                return;
            }

            const updateCard = (card, label, value, note) => {
                const labelElement = card.querySelector('.kpi-label');
                const valueElement = card.querySelector('.kpi-value');
                const noteElement = card.querySelector('.kpi-note');

                if (labelElement) {
                    labelElement.textContent = label;
                }
                if (valueElement) {
                    valueElement.textContent = numberFormatter.format(Number(value) || 0);
                }
                if (noteElement) {
                    noteElement.textContent = note;
                }
            };

            updateCard(
                cards[0],
                'Personal total',
                summary.total,
                'Incluye el personal directo y el asignado a todas sus unidades subordinadas.'
            );
            updateCard(
                cards[1],
                'Personal directo',
                summary.direct_total,
                'Funcionarios asignados directamente a esta unidad principal.'
            );
            updateCard(
                cards[2],
                'En unidades subordinadas',
                summary.subordinate_total,
                'Personal distribuido entre las dependencias y unidades que aparecen debajo.'
            );
            updateCard(
                cards[3],
                'Registros validados',
                summary.validated_total,
                'Asignaciones aprobadas en toda la estructura de esta unidad.'
            );

            const personnelSection = document.getElementById('personal');
            const personnelTitle = personnelSection?.querySelector('.panel-header h2');
            const personnelDescription = personnelSection?.querySelector('.panel-header p');

            if (personnelTitle) {
                personnelTitle.textContent = 'Personal asignado directamente a la unidad';
            }
            if (personnelDescription && !personnelDescription.textContent.includes('El total institucional')) {
                personnelDescription.textContent += ` El total institucional de ${numberFormatter.format(Number(summary.total) || 0)} incluye también las unidades subordinadas mostradas arriba.`;
            }

            const searchButton = document.querySelector('.page-intro .button.primary');
            if (searchButton && searchButton.textContent.trim() === 'Buscar en todo el personal') {
                searchButton.textContent = 'Buscar personal directo';
            }
        } catch (error) {
            console.warn('No fue posible calcular el total jerárquico de la unidad.', error);
        }
    };

    loadDependencyTable();
    loadHierarchySummary();
})();
