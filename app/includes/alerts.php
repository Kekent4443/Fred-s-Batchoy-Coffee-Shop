<?php $flashMessages = get_flash(); ?>
<?php if ($flashMessages): ?>
    <div class="flash-stack" aria-label="System notifications">
        <?php foreach ($flashMessages as $item): ?>
            <?= system_toast_markup((string) ($item['message'] ?? ''), (string) ($item['type'] ?? 'info')) ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
