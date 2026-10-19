<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('administrator');

$categoryFormErrors = [];
$submittedCategory = null;

if (is_post() && request('action') === 'save_category') {
    $submittedCategory = [
        'id' => request('category_id') ? (int) request('category_id') : null,
        'name' => trim((string) request('name')),
        'description' => trim((string) request('description')),
        'status' => request('category_id') ? (string) request('status') : 'active',
    ];
    try {
        if (request('category_id')) {
            update_category((int) request('category_id'), trim((string) request('name')), trim((string) request('description')), (string) request('status'));
            flash('categories', 'Category updated successfully.', 'success');
        } else {
            create_category(trim((string) request('name')), trim((string) request('description')));
            flash('categories', 'Category added successfully.', 'success');
        }
        redirect('admin/categories.php');
    } catch (InvalidArgumentException $exception) {
        $categoryFormErrors[validation_error_field($exception->getMessage())] = $exception->getMessage();
        flash('categories', $exception->getMessage(), 'danger');
    } catch (mysqli_sql_exception $exception) {
        $field = duplicate_error_field($exception, 'name');
        $message = (int) $exception->getCode() === 1062 ? 'This category name already exists.' : 'Unable to save this category. Please check the details and try again.';
        $categoryFormErrors[$field] = $message;
        flash('categories', $message, 'danger');
    }
}

if (is_post() && request('action') === 'delete_category') {
    try {
        delete_category((int) request('category_id'), (int) current_user()['id']);
        flash('categories', 'Category deleted successfully.', 'success');
    } catch (InvalidArgumentException $exception) {
        flash('categories', $exception->getMessage(), 'danger');
    } catch (mysqli_sql_exception $exception) {
        flash('categories', 'This category cannot be deleted because it is currently in use.', 'danger');
    }

    redirect('admin/categories.php');
}

$search = table_search_value();
$statusFilter = (string) request('status', '');
$statusFilter = in_array($statusFilter, ['active', 'inactive'], true) ? $statusFilter : '';
$allCategories = fetch_categories($search);
$categories = array_values(array_filter(
    $allCategories,
    static fn (array $category): bool => $statusFilter === '' || ($category['status'] ?? '') === $statusFilter
));
$editParam = (string) request('edit', '');
$editCategory = ($editParam !== '' && $editParam !== 'new') ? db_find('SELECT * FROM categories WHERE id = ? LIMIT 1', 'i', [(int) $editParam]) : null;
if ($submittedCategory !== null) {
    $editCategory = $submittedCategory;
}
$isEditingCategory = !empty($editCategory['id']);
$products = fetch_products();
$activeCategories = count(array_filter($categories, static fn ($category) => $category['status'] === 'active'));
$avgPerCategory = count($categories) > 0 ? round(count($products) / count($categories)) : 0;

$pageTitle = 'Category Management';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Category Management', 'Organize your products into categories.', 'categories', 'Inventory', static function (): void { ?><a class="btn btn-primary" href="<?= e(url('admin/categories.php?edit=new#category-form')) ?>">Add Category</a><?php }); ?>

            <div class="metric-grid">
                <article class="metric-box"><div class="metric-box__icon blue">◫</div><strong><?= e((string) count($categories)) ?></strong><span>Total Categories</span></article>
                <article class="metric-box"><div class="metric-box__icon green">✓</div><strong><?= e((string) $activeCategories) ?></strong><span>Active Categories</span></article>
                <article class="metric-box"><div class="metric-box__icon purple">◻</div><strong><?= e((string) count($products)) ?></strong><span>Total Products</span></article>
                <article class="metric-box"><div class="metric-box__icon orange">↗</div><strong><?= e((string) $avgPerCategory) ?></strong><span>Avg Products/Category</span></article>
            </div>

            <div class="page-panel">
                <form method="get" class="toolbar">
                    <input type="search" name="search" placeholder="Search categories by name, description..." value="<?= e($search) ?>">
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <button class="btn btn-primary" type="submit">Filter Categories</button>
                    <button class="btn btn-secondary" type="button" data-export-table="categories">Export</button>
                    <a class="btn btn-ghost" href="<?= e(url('admin/categories.php')) ?>">Reset</a>
                </form>
                <div class="table-responsive">
                    <table class="data-table" data-export-name="categories">
                        <thead><tr><th>Name</th><th>Description</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($categories as $category): ?>
                                <tr<?= priority_item_attrs($category) ?>>
                                    <td><?= e($category['name']) ?></td>
                                    <td><?= e($category['description']) ?></td>
                                    <td><?= status_badge($category['status']) ?></td>
                                    <td>
                                        <div class="table-actions category-table-actions">
                                            <a class="btn btn-icon btn-edit-icon" href="<?= e(url('admin/categories.php?edit=' . $category['id'] . '#category-form')) ?>" aria-label="Edit Category" title="Edit Category">
                                                <?= edit_icon_svg() ?>
                                                <span class="sr-only">Edit Category</span>
                                            </a>
                                            <form method="post" class="inline-form inline-form--icon" data-confirm-message="Delete this category? Categories assigned to products cannot be deleted." data-confirm-title="Delete Category" data-confirm-label="Delete Category" data-confirm-variant="danger" data-confirm-sensitive="true">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_category">
                                                <input type="hidden" name="category_id" value="<?= e((string) $category['id']) ?>">
                                                <button class="btn btn-icon btn-delete-icon" type="submit" aria-label="Delete Category" title="Delete Category">
                                                    <?= trash_icon_svg() ?>
                                                    <span class="sr-only">Delete Category</span>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="page-panel" id="category-form" style="margin-top: 1rem;">
                <h2><?= $isEditingCategory ? 'Update Category' : 'Add Category' ?></h2>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_category">
                    <input type="hidden" name="category_id" value="<?= e((string) ($editCategory['id'] ?? '')) ?>">
                    <label>Category Name<input type="text" name="name" required value="<?= e($editCategory['name'] ?? '') ?>"<?= form_error_attributes($categoryFormErrors, 'name', 'category') ?>><?= form_field_error($categoryFormErrors, 'name', 'category') ?></label>
                    <label>Description<textarea name="description" rows="3"><?= e($editCategory['description'] ?? '') ?></textarea></label>
                    <?php if ($isEditingCategory): ?>
                        <label>Status
                            <select name="status">
                                <option value="active" <?= ($editCategory['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($editCategory['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </label>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="submit">Save Category</button>
                </form>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
