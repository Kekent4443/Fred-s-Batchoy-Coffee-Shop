<?php

declare(strict_types=1);

/**
 * Render the shared page-level hero used by the authenticated application.
 *
 * The optional actions callback is trusted application markup supplied by the
 * calling page. Titles, descriptions, kickers, and class names are escaped.
 */
function render_page_header(
    string $title,
    string $description,
    string $icon = 'dashboard',
    string $kicker = '',
    ?callable $actions = null,
    string $className = ''
): void {
    $classes = trim('section__heading page-header ' . $className);
    ?>
    <div class="<?= e($classes) ?>">
        <div class="page-header__main">
            <span class="page-header__icon" aria-hidden="true"><?= sidebar_icon_svg($icon) ?></span>
            <div class="page-header__copy">
                <?php if ($kicker !== ''): ?>
                    <span class="page-header__kicker"><?= e($kicker) ?></span>
                <?php endif; ?>
                <h1><?= e($title) ?></h1>
                <?php if ($description !== ''): ?>
                    <p><?= e($description) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($actions !== null): ?>
            <div class="page-header__actions"><?php $actions(); ?></div>
        <?php endif; ?>
    </div>
    <?php
}
