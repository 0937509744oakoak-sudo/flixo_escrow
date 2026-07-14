Run DB migrations for FLIXO (local XAMPP)

1. Open terminal and import SQL into your database (adjust user/password/database name):

```bash
mysql -u root -p flixo_db < migrations/001_create_logging_tables.sql
```

2. Verify tables created:

```sql
USE flixo_db;
SHOW TABLES LIKE '%logs%';
SHOW TABLES LIKE 'slip_upload_attempts';
```

3. If using phpMyAdmin, you can also import the SQL file via the Import tab.

Notes:
- Migration creates `order_status_logs`, `transaction_logs`, and `slip_upload_attempts`.
- A second migration also creates `email_event_logs` for SMTP / email delivery history.
- Migration `004_create_product_images.sql` adds multi-image gallery support for products.
- The application code writes to `order_status_logs` and `email_event_logs` automatically.
- If you are using a different DB user, ensure it has CREATE and INSERT privileges.

Email notification test:
```bash
php scripts/test_email_notification.php Buyer
```

Product images migration:
```bash
mysql -u root -p flixo_db < migrations/004_create_product_images.sql
```

Or:

```bash
php scripts/run_migration.php 004_create_product_images.sql
php scripts/run_migration.php 010_create_reviews_table.sql
```

Reviews table (buyer ratings after completed orders):

```bash
php scripts/run_migration.php 010_create_reviews_table.sql
php scripts/run_migration.php 011_alter_reviews_table.sql
```

If `reviews` already existed from an older schema, run `011` to add indexes and column alignment.

Product image fraud detection (AI Vision):

```bash
php scripts/run_migration.php 016_product_image_fraud.sql
```

Configure in `.env`:
```ini
IMAGE_FRAUD_PROVIDER=auto
GOOGLE_CLOUD_VISION_API_KEY=your-key
IMAGE_FRAUD_BLOCK=1
```

Tier 1 checks EXIF metadata (camera model, GPS) for free. Tier 2 sends only suspicious images to Google Cloud Vision API.

Chat moderation (AI monitoring):

```bash
php scripts/run_migration.php 017_chat_moderation.sql
```

Configure in `.env`:
```ini
CHAT_MODERATION_ENABLED=1
CHAT_MODERATION_BLOCK=1
GEMINI_API_KEY=your-google-ai-studio-key
CHAT_MODERATION_AI_ENABLED=1
CHAT_MODERATION_BLOCK_THRESHOLD=70
CHAT_MODERATION_FLAG_THRESHOLD=45
```

Tier 1 uses regex to detect phone numbers, bank accounts, external links, and off-platform transfer keywords. Tier 2 calls Gemini (Google AI Studio) only for suspicious messages. Tier 2b uses Cloud Vision OCR on chat images.

AI Dispute Assistant:

```bash
php scripts/run_migration.php 018_dispute_ai_assistant.sql
```

Configure in `.env`:
```ini
DISPUTE_AI_ENABLED=1
GEMINI_API_KEY=your-google-ai-studio-key
DISPUTE_AI_GEMINI_MODEL=gemini-2.0-flash
```

Test with an open dispute:
```bash
php scripts/test_dispute_assistant.php
php scripts/test_dispute_assistant.php 5
```

Phase 0 production checks:

```bash
php scripts/validate_production_config.php
php scripts/test_e2e_flow.php
```

If mail fails, update `.env` with SMTP credentials:
```ini
MAIL_ENABLED=1
MAIL_SMTP_HOST=smtp.yourprovider.com
MAIL_SMTP_PORT=587
MAIL_SMTP_USERNAME=your_smtp_username
MAIL_SMTP_PASSWORD=your_smtp_password
MAIL_SMTP_SECURE=tls
```
