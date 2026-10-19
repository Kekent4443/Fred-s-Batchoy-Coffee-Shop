-- Restore 58mm as the database default without deleting 80mm support or
-- changing any receipt that an administrator explicitly configured.
ALTER TABLE receipt_settings
    MODIFY paper_width ENUM('58mm', '80mm') NOT NULL DEFAULT '58mm';

-- Older installations can still hold an untouched 80mm seed row. Only the
-- two known seed shapes are normalized; an audited settings edit is kept.
UPDATE receipt_settings AS settings
SET settings.paper_width = '58mm'
WHERE settings.id = 1
  AND settings.paper_width = '80mm'
  AND settings.business_address = 'Business address not configured'
  AND (
      (settings.updated_by IS NULL AND settings.contact_number = 'Contact number not configured')
      OR (settings.contact_number = '09171234567' AND settings.contact_email = 'support@example.com')
  )
  AND NOT EXISTS (
      SELECT 1 FROM audit_logs AS audit
      WHERE audit.action = 'receipt_settings_updated'
  );
