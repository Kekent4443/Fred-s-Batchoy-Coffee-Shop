-- Set 80mm as the default for new receipts while retaining the 58mm option.
-- Existing Administrator-saved receipt widths remain unchanged.
ALTER TABLE receipt_settings
    MODIFY paper_width ENUM('58mm', '80mm') NOT NULL DEFAULT '80mm';

-- Upgrade only the untouched 58mm seed row. Do not overwrite a configured shop.
UPDATE receipt_settings
SET paper_width = '80mm'
WHERE id = 1
  AND paper_width = '58mm'
  AND updated_by IS NULL
  AND business_address = 'Business address not configured'
  AND contact_number = 'Contact number not configured';
