<?php

declare(strict_types=1);

// These values are set by role-guarded route wrappers, never by request data.
if (!isset($productManagementRole, $productManagementBasePath)) {
    throw new LogicException('Product Management route context is missing.');
}
if (!in_array($productManagementRole, ['administrator', 'manager'], true)) {
    throw new LogicException('Unsupported Product Management role context.');
}

$productManagementCapabilities = match ($productManagementRole) {
    'administrator' => [
        'can_edit_product' => true,
        'can_update_availability' => true,
        'can_change_product_status' => true,
        'can_edit_product_status_field' => true,
        'can_delete_product' => true,
        'can_export_products' => true,
    ],
    'manager' => [
        'can_edit_product' => true,
        'can_update_availability' => true,
        'can_change_product_status' => true,
        'can_edit_product_status_field' => false,
        'can_delete_product' => true,
        'can_export_products' => false,
    ],
};

$productManagementViewer = current_user() ?? [];
$productManagementFormErrors = [];
$productManagementPageError = '';
$productManagementAssignedShop = null;
$productManagementShopId = null;
$productManagementSubmittedProduct = null;

if ($productManagementRole === 'manager') {
    try {
        assert_product_shop_schema();
        $productManagementAssignedShop = manager_product_shop($productManagementViewer);
        $productManagementShopId = (int) $productManagementAssignedShop['id'];
    } catch (RuntimeException $exception) {
        $productManagementPageError = $exception->getMessage();
    }
}

$productManagementAction = is_post() ? (string) post_scalar('action', '') : '';
$productManagementKnownActions = ['save_product', 'add_product', 'update_availability', 'toggle_product_status', 'delete_product'];
if (is_post() && !in_array($productManagementAction, $productManagementKnownActions, true)) {
    flash('products', 'Unsupported product action.', 'danger');
    redirect($productManagementBasePath);
}

if (is_post() && in_array($productManagementAction, ['save_product', 'add_product'], true)) {
    $stagedImageUrl = '';
    $previousImageUrl = '';
    $productIdRaw = trim((string) post_scalar('product_id', ''));
    $productId = $productIdRaw === ''
        ? null
        : (filter_var($productIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null);
    $productManagementSubmittedProduct = [
        'id' => $productId,
        'name' => trim((string) post_scalar('name', '')),
        'category_id' => (string) post_scalar('category_id', ''),
        'price' => (string) post_scalar('price', ''),
        'stock_quantity' => (string) post_scalar('stock_quantity', ''),
        'is_available' => (string) post_scalar('is_available', ''),
        'status' => $productId === null ? 'active' : (string) post_scalar('status', ''),
        'description' => trim((string) post_scalar('description', '')),
    ];

    try {
        if (!$productManagementCapabilities['can_edit_product']) {
            throw new RuntimeException('You are not authorized to save products.');
        }
        if ($productManagementRole === 'manager' && $productManagementShopId === null) {
            throw new RuntimeException($productManagementPageError !== '' ? $productManagementPageError : 'No assigned shop is available.');
        }
        if ($productIdRaw !== '' && $productId === null) {
            throw new InvalidArgumentException('Please select a valid product.');
        }

        $existingProduct = $productId !== null
            ? fetch_product_by_id($productId, $productManagementShopId)
            : null;
        if ($productId !== null && !$existingProduct) {
            throw new InvalidArgumentException(
                $productManagementRole === 'manager'
                    ? 'Product does not belong to your assigned shop.'
                    : 'Product does not exist.'
            );
        }
        $previousImageUrl = (string) ($existingProduct['image_url'] ?? '');
        $productManagementSubmittedProduct['image_url'] = $previousImageUrl;
        if ($productManagementRole === 'manager' && $existingProduct) {
            $productManagementSubmittedProduct['status'] = (string) $existingProduct['status'];
        }

        $finalImageUrl = save_uploaded_product_image($_FILES['image_upload'] ?? null, $previousImageUrl);
        $stagedImageUrl = $finalImageUrl !== $previousImageUrl ? $finalImageUrl : '';
        if ($finalImageUrl === '') {
            throw new InvalidArgumentException('Please upload a product image.');
        }

        $payload = [
            'category_id' => post_scalar('category_id', ''),
            'name' => post_scalar('name', ''),
            'description' => post_scalar('description', ''),
            'price' => post_scalar('price', ''),
            'is_available' => post_scalar('is_available', ''),
            'stock_quantity' => post_scalar('stock_quantity', ''),
            'image_url' => $finalImageUrl,
            'status' => $productId === null ? 'active' : post_scalar('status', ''),
        ];

        if ($productManagementRole === 'manager') {
            save_manager_product($productManagementViewer, $payload, $productId, $stagedImageUrl !== '');
        } else {
            save_product($payload, $productId, null, $stagedImageUrl !== '');
        }

        flash('products', $productId === null ? 'Product added successfully.' : 'Product information updated successfully.', 'success');
        redirect($productManagementBasePath);
    } catch (InvalidArgumentException | RuntimeException $exception) {
        if ($stagedImageUrl !== '') {
            delete_local_product_image($stagedImageUrl);
        }
        $productManagementFormErrors[validation_error_field($exception->getMessage())] = $exception->getMessage();
        if (!empty($_FILES['image_upload']['name'])) {
            $productManagementFormErrors['image_upload'] = 'For security, select the product image again before resubmitting.';
        }
        flash('products', $exception->getMessage(), 'danger');
    } catch (mysqli_sql_exception $exception) {
        if ($stagedImageUrl !== '') {
            delete_local_product_image($stagedImageUrl);
        }
        $productManagementFormErrors['form'] = 'Unable to save the product. Please check the details and try again.';
        flash('products', $productManagementFormErrors['form'], 'danger');
    }
}

if (is_post() && $productManagementAction === 'update_availability') {
    try {
        if (!$productManagementCapabilities['can_update_availability']) {
            throw new RuntimeException('You are not authorized to update product availability.');
        }
        if ($productManagementRole === 'manager' && $productManagementShopId === null) {
            throw new RuntimeException($productManagementPageError !== '' ? $productManagementPageError : 'No assigned shop is available.');
        }
        $productId = filter_var(post_scalar('product_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $availabilityRaw = post_scalar('is_available');
        if (!in_array((string) $availabilityRaw, ['0', '1'], true)) {
            throw new InvalidArgumentException('Please select a valid product availability.');
        }
        update_product_availability($productManagementViewer, $productId, (int) $availabilityRaw);
        flash('products', 'Product availability updated.', 'success');
    } catch (InvalidArgumentException | RuntimeException $exception) {
        flash('products', $exception->getMessage(), 'danger');
    } catch (mysqli_sql_exception $exception) {
        flash('products', 'Unable to update product availability. Please try again.', 'danger');
    }
    redirect($productManagementBasePath . '#products-list');
}

if (is_post() && $productManagementAction === 'toggle_product_status') {
    try {
        if (!$productManagementCapabilities['can_change_product_status']) {
            throw new RuntimeException('You are not authorized to change product status.');
        }
        $productId = filter_var(post_scalar('product_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($productManagementRole === 'manager') {
            update_manager_product_status($productManagementViewer, $productId, (string) post_scalar('status', ''));
        } else {
            update_product_status_as_admin($productManagementViewer, $productId, (string) post_scalar('status', ''));
        }
        flash('products', 'Product status updated.', 'success');
    } catch (InvalidArgumentException | RuntimeException $exception) {
        flash('products', $exception->getMessage(), 'danger');
    } catch (mysqli_sql_exception $exception) {
        flash('products', 'Unable to update product status. Please try again.', 'danger');
    }
    redirect($productManagementBasePath . '#products-list');
}

if (is_post() && $productManagementAction === 'delete_product') {
    try {
        if (!$productManagementCapabilities['can_delete_product']) {
            throw new RuntimeException('You are not authorized to delete products.');
        }
        $productId = filter_var(post_scalar('product_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($productId < 1) {
            throw new InvalidArgumentException('Please select a valid product.');
        }
        if ($productManagementRole === 'manager') {
            delete_manager_product($productManagementViewer, $productId);
        } else {
            delete_product_as_admin($productManagementViewer, $productId);
        }
        flash('products', 'Product deleted successfully.', 'success');
    } catch (InvalidArgumentException | RuntimeException $exception) {
        flash('products', $exception->getMessage(), 'danger');
    } catch (mysqli_sql_exception $exception) {
        flash('products', 'Unable to delete this product because it is linked to system records.', 'danger');
    }
    redirect($productManagementBasePath . '#products-list');
}

$productManagementSearch = table_search_value();
$productManagementCategories = fetch_categories();
$productManagementCategoryFilter = filter_var(query_scalar('category_id', 0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$productManagementStatusFilter = (string) query_scalar('status_filter', '');
$productManagementStatusFilter = in_array($productManagementStatusFilter, ['active', 'inactive'], true) ? $productManagementStatusFilter : '';
$productManagementProducts = [];

if ($productManagementRole !== 'manager' || $productManagementShopId !== null) {
    try {
        $allProducts = fetch_products($productManagementSearch, false, $productManagementShopId);
        $productManagementProducts = array_values(array_filter(
            $allProducts,
            static function (array $product) use ($productManagementCategoryFilter, $productManagementStatusFilter): bool {
                if ($productManagementCategoryFilter > 0 && (int) ($product['category_id'] ?? 0) !== $productManagementCategoryFilter) {
                    return false;
                }
                return $productManagementStatusFilter === '' || ($product['status'] ?? '') === $productManagementStatusFilter;
            }
        ));
    } catch (mysqli_sql_exception $exception) {
        $productManagementPageError = 'Products could not be loaded. Confirm that all required database migrations were applied.';
    }
}

$productManagementEditProduct = null;
$productManagementEditParam = trim((string) query_scalar('edit', ''));
if ($productManagementSubmittedProduct !== null) {
    $productManagementEditProduct = $productManagementSubmittedProduct;
} elseif ($productManagementEditParam !== '' && $productManagementEditParam !== 'new') {
    $editId = filter_var($productManagementEditParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $canLoadEditProduct = $editId > 0
        && ($productManagementRole !== 'manager' || $productManagementShopId !== null);
    if ($canLoadEditProduct) {
        try {
            $productManagementEditProduct = fetch_product_by_id($editId, $productManagementShopId);
        } catch (mysqli_sql_exception $exception) {
            $productManagementPageError = 'The selected product could not be loaded. Please try again.';
        }
    }
    if (!$productManagementEditProduct && $productManagementPageError === '') {
        $productManagementPageError = $productManagementRole === 'manager'
            ? 'The selected product is not available in your assigned shop.'
            : 'The selected product does not exist.';
    }
}

$productManagementActiveCategories = array_values(array_filter(
    $productManagementCategories,
    static fn (array $category): bool => ($category['status'] ?? '') === 'active'
));
$productManagementFormCategories = !empty($productManagementEditProduct['id'])
    ? $productManagementCategories
    : $productManagementActiveCategories;
$productManagementInStock = count(array_filter(
    $productManagementProducts,
    static fn (array $product): bool => (int) $product['stock_quantity'] > inventory_low_stock_threshold()
));
$productManagementLowStock = count(array_filter(
    $productManagementProducts,
    static fn (array $product): bool => (int) $product['stock_quantity'] > 0
        && (int) $product['stock_quantity'] <= inventory_low_stock_threshold()
));
$productManagementOutOfStock = count(array_filter(
    $productManagementProducts,
    static fn (array $product): bool => (int) $product['stock_quantity'] <= 0
));
$productManagementFormDisabled = !$productManagementCapabilities['can_edit_product']
    || $productManagementFormCategories === []
    || ($productManagementRole === 'manager' && $productManagementShopId === null);
$productManagementCanAddProduct = $productManagementCapabilities['can_edit_product']
    && $productManagementActiveCategories !== []
    && ($productManagementRole !== 'manager' || $productManagementShopId !== null);

$pageTitle = $productManagementPageTitle;
