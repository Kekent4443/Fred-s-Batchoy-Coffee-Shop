SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

START TRANSACTION;

INSERT INTO shop_locations
    (shop_name, complete_address, google_maps_url, google_maps_embed_url, contact_number,
     email_address, operating_hours, status, created_at, updated_at)
SELECT
    'Fred''s Batchoy', 'Business address not configured', 'https://maps.google.com/', NULL,
    '09171234567', 'support@example.com',
    'Open daily; configure exact hours in Shop Location.', 'active', NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM shop_locations WHERE shop_name = 'Fred''s Batchoy'
);

SET @seed_shop_id := (
    SELECT id FROM shop_locations WHERE shop_name = 'Fred''s Batchoy' ORDER BY id LIMIT 1
);

-- Administrator credentials are never seeded from source control.
-- First run scripts/set-primary-admin.php with your local ADMIN_* values.

SET @seed_admin_id := (
    SELECT id FROM users WHERE role = 'administrator' ORDER BY id LIMIT 1
);

INSERT INTO receipt_settings
    (business_name, business_address, contact_number, contact_email, social_media,
     tax_rate, service_fee, delivery_fee, footer_message, paper_width, updated_by)
SELECT
    'Fred''s Batchoy', 'Business address not configured', '09171234567',
    'support@example.com', NULL, 12.00, 0.00, 0.00,
    'Thank you for your order!', '58mm', @seed_admin_id
WHERE @seed_admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM receipt_settings);

INSERT INTO gcash_payment_accounts
    (account_name, account_number, qr_code_path, status, updated_by, created_at, updated_at)
SELECT
    'Configure GCash Account', '09171234567', NULL, 'inactive',
    @seed_admin_id, NOW(), NOW()
WHERE @seed_admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM gcash_payment_accounts);

INSERT INTO bulk_discount_rules
    (label, min_quantity, max_quantity, discount_rate, status, created_at, updated_at)
SELECT '5 to 9 items', 5, 9, 5.00, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM bulk_discount_rules WHERE label = '5 to 9 items');

INSERT INTO bulk_discount_rules
    (label, min_quantity, max_quantity, discount_rate, status, created_at, updated_at)
SELECT '10 or more items', 10, 4294967295, 10.00, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM bulk_discount_rules WHERE label = '10 or more items');

INSERT INTO support_faqs
    (category, question, keywords, answer, priority, status, created_at, updated_at)
SELECT
    'product_availability', 'Product availability',
    'available,availability,stock,price,product',
    'Current product availability and price are shown in the product catalog.',
    20, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM support_faqs WHERE question = 'Product availability');

INSERT INTO support_faqs
    (category, question, keywords, answer, priority, status, created_at, updated_at)
SELECT
    'order_status', 'Order status',
    'order,status,preparing,confirmed,delivery',
    'Link an order to the conversation so support can review its recorded status.',
    30, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM support_faqs WHERE question = 'Order status');

INSERT INTO support_faqs
    (category, question, keywords, answer, priority, status, created_at, updated_at)
SELECT
    'payment_concern', 'Payment status',
    'payment,gcash,cash,proof,pending,paid',
    'GCash proof is reviewed by staff; cash-on-delivery becomes paid when the rider confirms delivery and cash receipt.',
    30, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM support_faqs WHERE question = 'Payment status');

COMMIT;
