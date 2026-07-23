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

    const updateCodePreview = (form) => {
        const select = form.querySelector('[data-code-type]');
        const preview = form.querySelector('[data-code-preview]');
        if (!select || !preview) {
            return;
        }

        const option = select.options[select.selectedIndex];
        preview.value = option?.dataset.nextCode || 'Se asignará al guardar';
    };

    const updateZoneName = (form) => {
        const number = form.querySelector('[data-zone-number]');
        const description = form.querySelector('[data-zone-description]');
        const preview = form.querySelector('[data-name-preview]');
        if (!number || !description || !preview) {
            return;
        }

        const zoneNumber = number.value.trim() || '__';
        const zoneDescription = description.value.trim() || '__________________';
        preview.value = `${zoneNumber} Zona Policial - ${zoneDescription}`;
    };

    const buildPrincipalName = (group, value) => {
        const cleanValue = String(value || '').trim();
        const normalized = cleanValue.toLowerCase();

        if (group === 'direcciones') {
            if (normalized.startsWith('dirección nacional') || normalized.startsWith('direccion nacional')) {
                return cleanValue;
            }
            return `Dirección Nacional de ${cleanValue || '__________________'}`;
        }

        if (group === 'servicios') {
            if (normalized.startsWith('servicio')) {
                return cleanValue;
            }
            return `Servicio Policial - ${cleanValue || '__________________'}`;
        }

        return cleanValue;
    };

    const configurePrincipalForm = (form) => {
        const group = form.dataset.group || '';
        const typeSelect = form.querySelector('[data-code-type]');
        const expectedType = {
            zonas: 'zona_policial',
            direcciones: 'direccion_nacional',
            servicios: 'servicio_policial',
        }[group] || '';

        if (typeSelect && expectedType) {
            const matchingOption = Array.from(typeSelect.options).find(
                (option) => option.dataset.typeName === expectedType
            );

            if (matchingOption) {
                typeSelect.value = matchingOption.value;

                const originalField = typeSelect.closest('.field');
                if (originalField) {
                    originalField.hidden = true;

                    const visibleTypeField = document.createElement('div');
                    visibleTypeField.className = 'field';
                    visibleTypeField.dataset.automaticTypeField = 'true';
                    visibleTypeField.innerHTML = `
                        <label>Tipo</label>
                        <input class="system-readonly" value="${matchingOption.textContent.trim()}" readonly>
                    `;
                    originalField.insertAdjacentElement('afterend', visibleTypeField);
                }
            }
        }

        updateCodePreview(form);

        if (group === 'zonas') {
            updateZoneName(form);
            return;
        }

        const nameInput = form.querySelector('input[name="name"]');
        if (!nameInput) {
            return;
        }

        const nameField = nameInput.closest('.field');
        const label = nameField?.querySelector('label');
        if (label) {
            label.textContent = group === 'direcciones'
                ? 'Nombre o especialidad de la dirección'
                : 'Nombre o especialidad del servicio';
        }

        nameInput.placeholder = group === 'direcciones'
            ? 'Ejemplo: Telemática'
            : 'Ejemplo: Motorizado';

        const previewField = document.createElement('div');
        previewField.className = 'field admin-span-2';
        previewField.innerHTML = `
            <label>Nombre que se guardará</label>
            <input class="system-readonly" data-principal-name-preview readonly>
        `;
        nameField?.insertAdjacentElement('afterend', previewField);

        const previewInput = previewField.querySelector('[data-principal-name-preview]');
        const refreshName = () => {
            if (previewInput) {
                previewInput.value = buildPrincipalName(group, nameInput.value);
            }
        };

        nameInput.addEventListener('input', refreshName);
        refreshName();

        form.addEventListener('submit', () => {
            nameInput.value = buildPrincipalName(group, nameInput.value);
        });
    };

    const protectMissingParent = (form) => {
        const parentField = Array.from(form.querySelectorAll('.field')).find((field) =>
            field.querySelector('label')?.textContent?.trim() === 'Unidad superior'
        );
        const parentInput = parentField?.querySelector('input[readonly]');
        const submitButton = form.querySelector('button[type="submit"]');

        if (!parentInput || !submitButton) {
            return;
        }

        const parentName = parentInput.value.trim().toLowerCase();
        const missing = parentName === '' || parentName === 'nivel principal';
        if (!missing) {
            return;
        }

        submitButton.disabled = true;
        const warning = document.createElement('div');
        warning.className = 'notice warning admin-span-2';
        warning.textContent = 'No se encontró la unidad superior oficial. Debe instalarse o corregirse la configuración automática antes de guardar.';
        submitButton.closest('.admin-span-2')?.appendChild(warning);
    };

    const buildChildName = (typeName, label, description) => {
        const type = (typeName || '').toLowerCase();
        const cleanLabel = (label || '').trim().toUpperCase();
        const cleanDescription = (description || '').trim();

        if (type.includes('area')) {
            return `Área ${cleanLabel || '_'}${cleanDescription ? ` - ${cleanDescription}` : ''}`;
        }
        if (type.includes('seccion')) {
            return `Sección de ${cleanDescription || '__________________'}`;
        }
        if (type.includes('servicio')) {
            return `Servicio ${cleanDescription || '__________________'}`;
        }
        if (type.includes('departamento')) {
            return `Departamento de ${cleanDescription || '__________________'}`;
        }
        if (type.includes('oficina')) {
            return `Oficina de ${cleanDescription || '__________________'}`;
        }
        if (type.includes('subestacion')) {
            return `Subestación Policial de ${cleanDescription || '__________________'}`;
        }
        if (type.includes('estacion')) {
            return `Estación Policial de ${cleanDescription || '__________________'}`;
        }
        if (type.includes('sector')) {
            return `Sector ${cleanDescription || '__________________'}`;
        }
        if (type.includes('puesto')) {
            return `Puesto Policial de ${cleanDescription || '__________________'}`;
        }
        return cleanDescription || 'Escriba la descripción';
    };

    const updateChildName = (form) => {
        const select = form.querySelector('[data-guided-type]');
        const labelField = form.querySelector('[data-label-field]');
        const labelInput = form.querySelector('[data-unit-label]');
        const description = form.querySelector('[data-unit-description]');
        const preview = form.querySelector('[data-name-preview]');
        if (!select || !description || !preview) {
            return;
        }

        const option = select.options[select.selectedIndex];
        const typeName = option?.dataset.typeName || '';
        const isArea = typeName.toLowerCase().includes('area');

        if (labelField) {
            labelField.hidden = !isArea;
        }
        if (labelInput) {
            labelInput.required = isArea;
            if (!isArea) {
                labelInput.value = '';
            }
        }

        preview.value = buildChildName(typeName, labelInput?.value || '', description.value);
    };

    const escapeHtml = (value) => String(value || '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const showUnitIdentifiers = async () => {
        const unitId = Number(new URLSearchParams(window.location.search).get('id') || 0);
        if (!Number.isInteger(unitId) || unitId <= 0) {
            return;
        }

        try {
            const response = await fetch(`estructura_codigo.php?id=${encodeURIComponent(unitId)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            if (!payload?.ok || !payload.unit) {
                return;
            }

            const systemCode = payload.unit.system_code || 'Pendiente';
            const institutionalCode = payload.unit.institutional_code || 'Sin código anterior';
            const selectedPanel = document.querySelector('.simple-selected-unit .page-intro > div:first-child');

            if (selectedPanel && !selectedPanel.querySelector('[data-identifier-summary]')) {
                const summary = document.createElement('div');
                summary.className = 'identifier-summary';
                summary.dataset.identifierSummary = 'true';
                summary.innerHTML = `
                    <span><strong>Código del sistema:</strong> ${escapeHtml(systemCode)}</span>
                    <span><strong>Código institucional anterior:</strong> ${escapeHtml(institutionalCode)}</span>
                `;
                selectedPanel.appendChild(summary);
            }

            const updateForm = document.querySelector('form input[name="action"][value="update"]')?.closest('form');
            if (updateForm && !updateForm.querySelector('[data-institutional-code-field]')) {
                const systemCodeField = Array.from(updateForm.querySelectorAll('.field')).find((field) =>
                    field.querySelector('label')?.textContent?.trim() === 'Código del sistema'
                );

                if (systemCodeField) {
                    const historicalField = document.createElement('div');
                    historicalField.className = 'field';
                    historicalField.dataset.institutionalCodeField = 'true';
                    historicalField.innerHTML = `
                        <label>Código institucional anterior</label>
                        <input class="system-readonly" value="${escapeHtml(institutionalCode)}" readonly>
                    `;
                    systemCodeField.insertAdjacentElement('afterend', historicalField);
                }
            }
        } catch (error) {
            // La pantalla sigue funcionando aunque el resumen adicional no pueda cargarse.
        }
    };

    document.querySelectorAll('[data-guided-root-form]').forEach((form) => {
        const codeType = form.querySelector('[data-code-type]');
        const zoneNumber = form.querySelector('[data-zone-number]');
        const zoneDescription = form.querySelector('[data-zone-description]');

        codeType?.addEventListener('change', () => updateCodePreview(form));
        zoneNumber?.addEventListener('input', () => updateZoneName(form));
        zoneDescription?.addEventListener('input', () => updateZoneName(form));

        configurePrincipalForm(form);
        protectMissingParent(form);
    });

    document.querySelectorAll('[data-guided-child-form]').forEach((form) => {
        const typeSelect = form.querySelector('[data-guided-type]');
        const labelInput = form.querySelector('[data-unit-label]');
        const description = form.querySelector('[data-unit-description]');

        typeSelect?.addEventListener('change', () => {
            updateCodePreview(form);
            updateChildName(form);
        });
        labelInput?.addEventListener('input', () => updateChildName(form));
        description?.addEventListener('input', () => updateChildName(form));

        updateCodePreview(form);
        updateChildName(form);
    });

    showUnitIdentifiers();
})();
