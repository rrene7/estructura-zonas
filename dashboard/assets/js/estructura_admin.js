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

    if (!path.endsWith('/estructura_admin.php')) {
        return;
    }

    document.body.classList.add('admin-friendly-page');

    const breadcrumbs = document.querySelector('.breadcrumbs');
    if (breadcrumbs) {
        const tabs = document.createElement('nav');
        tabs.className = 'configuration-tabs';
        tabs.setAttribute('aria-label', 'Configuración de estructura');
        tabs.innerHTML = `
            <a href="configuracion.php">Resumen</a>
            <a href="estructura_admin.php" class="active">Estructura organizacional</a>
            <a href="configuracion_estructura_reglas.php">Reglas de jerarquía</a>
            <a href="configuracion_estructura_historial.php">Historial</a>
        `;
        breadcrumbs.insertAdjacentElement('afterend', tabs);
    }

    const findPanel = (title) => Array.from(document.querySelectorAll('section.panel')).find((panel) => {
        const heading = panel.querySelector('.panel-header h2');
        return heading && heading.textContent.trim().toLowerCase() === title.toLowerCase();
    });

    const banner = document.querySelector('.admin-mode-banner');
    if (banner) {
        banner.innerHTML = '<strong>Configuración de estructura</strong><span> Seleccione una unidad y elija la acción que desea realizar. Las reglas y el historial se controlan desde MySQL.</span>';
    }

    const searchPanel = findPanel('Buscar dentro de la estructura');
    if (searchPanel) {
        const heading = searchPanel.querySelector('.panel-header h2');
        const description = searchPanel.querySelector('.panel-header p');
        const input = searchPanel.querySelector('input[type="search"]');

        if (heading) {
            heading.textContent = '¿Qué unidad deseas configurar?';
        }
        if (description) {
            description.textContent = 'Escribe el nombre de una dirección, zona, área, estación, puesto o dependencia.';
        }
        if (input) {
            input.placeholder = 'Ejemplo: Chiriquí, Área A, Puerto Armuelles o Telemática';
        }
    }

    document.querySelectorAll('a.button.soft').forEach((button) => {
        if (button.textContent.trim() === 'Administrar') {
            button.textContent = 'Abrir';
        }
    });

    const pageIntro = document.querySelector('.page-intro');
    const editPanel = findPanel('Editar unidad');
    if (!pageIntro || !editPanel) {
        return;
    }

    const statePanel = findPanel('Estado y procedencia');
    const addPanel = findPanel('Agregar dependencia');
    const movePanel = findPanel('Mover unidad');
    const deactivatePanel = findPanel('Desactivar unidad') || findPanel('Reactivar unidad');
    const historyPanel = findPanel('Historial de cambios');

    const renamePanel = (panel, title, description) => {
        if (!panel) {
            return;
        }
        const heading = panel.querySelector('.panel-header h2');
        const paragraph = panel.querySelector('.panel-header p');
        if (heading) {
            heading.textContent = title;
        }
        if (paragraph && description) {
            paragraph.textContent = description;
        }
    };

    renamePanel(
        editPanel,
        'Editar datos de la unidad',
        'Corrige el nombre, el nombre corto, el tipo o los códigos. El identificador interno no cambia.'
    );
    renamePanel(
        statePanel,
        'Información técnica',
        'Datos de control sobre el estado, la vigencia y el origen del registro.'
    );
    renamePanel(
        addPanel,
        'Agregar una dependencia',
        'Crea un nuevo nivel directamente debajo de la unidad seleccionada.'
    );
    renamePanel(
        movePanel,
        'Mover dentro de la estructura',
        'Selecciona una nueva unidad superior. El historial y las referencias se mantienen.'
    );
    renamePanel(
        deactivatePanel,
        deactivatePanel?.querySelector('.panel-header h2')?.textContent.includes('Reactivar')
            ? 'Reactivar esta unidad'
            : 'Desactivar esta unidad',
        'La unidad no se elimina; solamente deja de aparecer como destino vigente.'
    );
    renamePanel(
        historyPanel,
        'Historial de la unidad',
        'Consulta quién modificó la unidad, cuándo lo hizo y qué cambió.'
    );

    const groups = {
        edit: [editPanel, statePanel].filter(Boolean),
        add: [addPanel].filter(Boolean),
        move: [movePanel].filter(Boolean),
        status: [deactivatePanel].filter(Boolean),
        history: [historyPanel].filter(Boolean),
    };

    const allActionPanels = Object.values(groups).flat();

    const updateContainers = () => {
        document.querySelectorAll('.admin-columns').forEach((container) => {
            const panels = Array.from(container.children).filter((child) => child.matches('section.panel'));
            const visiblePanels = panels.filter((panel) => !panel.classList.contains('admin-panel-hidden'));
            container.classList.toggle('admin-container-hidden', visiblePanels.length === 0);
            container.classList.toggle('admin-one-column', visiblePanels.length === 1);
        });
    };

    const hideActions = () => {
        allActionPanels.forEach((panel) => panel.classList.add('admin-panel-hidden'));
        updateContainers();
    };

    const actionMenu = document.createElement('section');
    actionMenu.className = 'panel admin-action-menu';
    actionMenu.innerHTML = `
        <div class="admin-action-menu-copy">
            <span class="admin-step">Acciones disponibles</span>
            <h2>¿Qué deseas configurar?</h2>
            <p>La pantalla muestra únicamente el formulario de la acción seleccionada.</p>
        </div>
        <div class="admin-action-buttons" role="group" aria-label="Acciones de configuración">
            <button type="button" class="admin-action-button" data-admin-action="edit"><span>✎</span><strong>Editar datos</strong><small>Nombre, tipo y códigos</small></button>
            <button type="button" class="admin-action-button" data-admin-action="add"><span>＋</span><strong>Agregar</strong><small>Nueva dependencia</small></button>
            <button type="button" class="admin-action-button" data-admin-action="move"><span>↳</span><strong>Mover</strong><small>Cambiar unidad superior</small></button>
            <button type="button" class="admin-action-button" data-admin-action="status"><span>◉</span><strong>Estado</strong><small>Desactivar o reactivar</small></button>
            <button type="button" class="admin-action-button" data-admin-action="history"><span>↺</span><strong>Historial</strong><small>Ver cambios de la unidad</small></button>
        </div>
    `;

    pageIntro.insertAdjacentElement('afterend', actionMenu);
    hideActions();

    const showAction = (action) => {
        hideActions();
        document.querySelectorAll('[data-admin-action]').forEach((button) => {
            button.classList.toggle('active', button.dataset.adminAction === action);
        });

        const selectedPanels = groups[action] || [];
        selectedPanels.forEach((panel) => panel.classList.remove('admin-panel-hidden'));
        updateContainers();

        const firstPanel = selectedPanels[0];
        if (firstPanel) {
            firstPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            const firstInput = firstPanel.querySelector('input:not([type="hidden"]), select, textarea');
            window.setTimeout(() => firstInput?.focus({ preventScroll: true }), 350);
        }
    };

    actionMenu.querySelectorAll('[data-admin-action]').forEach((button) => {
        const action = button.dataset.adminAction;
        if (!groups[action] || groups[action].length === 0) {
            button.hidden = true;
            return;
        }
        button.addEventListener('click', () => showAction(action));
    });

    const subordinatePanel = findPanel('Unidades subordinadas');
    if (subordinatePanel) {
        const heading = subordinatePanel.querySelector('.panel-header h2');
        const description = subordinatePanel.querySelector('.panel-header p');
        if (heading) {
            heading.textContent = 'Dependencias de esta unidad';
        }
        if (description) {
            description.textContent = 'Abre una dependencia para continuar navegando o configurarla.';
        }
    }
})();
