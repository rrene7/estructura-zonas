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
})();
