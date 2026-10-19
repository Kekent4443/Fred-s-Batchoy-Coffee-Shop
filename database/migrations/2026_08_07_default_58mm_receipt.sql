-- Make 58mm the fresh-install default while preserving every saved selection.
ALTER TABLE receipt_settings
    MODIFY paper_width ENUM('58mm', '80mm') NOT NULL DEFAULT '58mm';
