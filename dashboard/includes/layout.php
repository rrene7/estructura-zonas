<?php
declare(strict_types=1);

function render_header(string $title, string $active = 'inicio', string $subtitle = ''): void
{
    $items = [
        'inicio' => ['Inicio', 'index.php', '⌂'],
        'personal' => ['Personal', 'pie_fuerza.php', '👥'],
        'direcciones' => ['Direcciones', 'unidades.php?grupo=direcciones', '▦'],
        'zonas' => ['Zonas policiales', 'unidades.php?grupo=zonas', '◎'],
        'servicios' => ['Servicios policiales', 'unidades.php?grupo=servicios', '◆'],
        'estructura' => ['Estructura', 'unidades.php?grupo=todas', '⌘'],
        'reportes' => ['Reportes', 'reportes.php', '⇩'],
    ];
    ?>
    <!doctype html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light">
        <title><?= h($title) ?> | Pie de Fuerza</title>
        <link rel="stylesheet" href="assets/css/dashboard.css?v=20260717-1">
    </head>
    <body>
    <div class="app-shell">
        <aside class="sidebar" id="sidebar" aria-label="Navegación principal">
            <div class="brand">
                <div class="brand-mark" aria-hidden="true">PF</div>
                <div>
                    <strong>Pie de Fuerza</strong>
                    <span>Consulta institucional</span>
                </div>
            </div>

            <nav class="main-nav">
                <?php foreach ($items as $key => [$label, $href, $icon]): ?>
                    <a href="<?= h($href) ?>" class="<?= $active === $key ? 'active' : '' ?>">
                        <span class="nav-icon" aria-hidden="true"><?= h($icon) ?></span>
                        <span><?= h($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="sidebar-help">
                <strong>¿Qué muestra este módulo?</strong>
                <p>La unidad funcional, la zona donde presta servicio y la dependencia interna de cada funcionario.</p>
            </div>

            <details class="technical-menu" <?= $active === 'estructura_admin' ? 'open' : '' ?>>
                <summary>Herramientas técnicas</summary>
                <a href="estructura_admin.php" class="<?= $active === 'estructura_admin' ? 'active' : '' ?>">Administrar estructura</a>
                <a href="revision.php">Revisión de estructura</a>
                <a href="trabajo_zonas.php">Trabajo por zona</a>
                <a href="asignar_unidades_direccion.php">Unidades por dirección</a>
            </details>
        </aside>

        <div class="app-main">
            <header class="topbar">
                <button class="menu-button" type="button" data-menu-toggle aria-label="Abrir menú">☰</button>
                <div>
                    <h1><?= h($title) ?></h1>
                    <?php if ($subtitle !== ''): ?>
                        <p><?= h($subtitle) ?></p>
                    <?php endif; ?>
                </div>
                <div class="topbar-note">
                    <span class="status-dot" aria-hidden="true"></span>
                    Acceso local sin inicio de sesión
                </div>
            </header>
            <main class="page-content">
    <?php
}

function render_footer(): void
{
    ?>
            </main>
            <footer class="footer">
                <span>Módulo de consulta del pie de fuerza.</span>
                <span>La autenticación será administrada por el sistema principal al momento de la integración.</span>
            </footer>
        </div>
    </div>
    <script src="assets/js/dashboard.js?v=20260716-4"></script>
    </body>
    </html>
    <?php
}

function render_breadcrumbs(array $items): void
{
    ?>
    <nav class="breadcrumbs" aria-label="Ruta de navegación">
        <?php foreach ($items as $index => $item): ?>
            <?php if ($index > 0): ?><span aria-hidden="true">›</span><?php endif; ?>
            <?php if (!empty($item['href'])): ?>
                <a href="<?= h($item['href']) ?>"><?= h($item['label']) ?></a>
            <?php else: ?>
                <strong><?= h($item['label']) ?></strong>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php
}

function render_empty_state(string $title, string $message, string $link = '', string $linkLabel = ''): void
{
    ?>
    <section class="empty-state">
        <div class="empty-icon" aria-hidden="true">i</div>
        <h2><?= h($title) ?></h2>
        <p><?= h($message) ?></p>
        <?php if ($link !== ''): ?>
            <a class="button primary" href="<?= h($link) ?>"><?= h($linkLabel ?: 'Continuar') ?></a>
        <?php endif; ?>
    </section>
    <?php
}
