<?php

declare(strict_types=1);

/** @var string $discountManagementTab */
/** @var string $discountManagementBaseFolder */
render_page_header(
    'Discounts & Promotions',
    'Manage automatic quantity discounts and customer-entered promo codes.',
    'percent',
    'Management'
);
$discountManagementTabs = [
    'bulk' => ['Bulk Discounts', $discountManagementBaseFolder . '/discount-rules.php'],
    'promo' => ['Promo Codes', $discountManagementBaseFolder . '/promo-codes.php'],
];
?>
<nav class="notification-filter-tabs discount-management__tabs" aria-label="Discounts and promotions sections">
    <?php foreach ($discountManagementTabs as $tab => [$label, $path]): ?>
        <a class="notification-filter-tabs__item<?= $discountManagementTab === $tab ? ' is-active' : '' ?>"
           href="<?= e(url($path)) ?>"<?= $discountManagementTab === $tab ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<p class="discount-management__note">One bulk rule applies automatically by total item quantity. If ranges overlap, the matching rule with the highest minimum quantity wins, then the highest rate; rules never add together. Promo minimum spend uses the original item subtotal. A valid promo applies once to the remaining item subtotal; combined item discounts never exceed that subtotal.</p>
