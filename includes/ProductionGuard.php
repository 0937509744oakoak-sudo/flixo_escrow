<?php
// includes/ProductionGuard.php — validate required secrets before serving traffic

class ProductionGuard {
    private const DEMO_WEBHOOK_SECRET = 'FLIXO_SECRET_DEMO_2026';

    /**
     * @param bool $force run the checks even outside production (used by
     *                    scripts/validate_production_config.php --strict)
     * @return array<int, string> list of validation errors
     */
    public static function validate(bool $force = false): array {
        if (!$force && (!defined('APP_ENV') || APP_ENV !== 'production')) {
            return [];
        }

        $errors = [];

        if (!defined('FORCE_HTTPS') || !FORCE_HTTPS) {
            $errors[] = 'FORCE_HTTPS must be 1 in production.';
        }

        $encryptionKey = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : '';
        if (strlen($encryptionKey) < 32) {
            $errors[] = 'ENCRYPTION_KEY must be at least 32 characters in production.';
        }

        if (!defined('DB_PASS') || DB_PASS === '') {
            $errors[] = 'DB_PASS must not be empty in production.';
        }

        $webhookSecret = defined('WEBHOOK_SECRET') ? WEBHOOK_SECRET : '';
        if ($webhookSecret === '' || $webhookSecret === self::DEMO_WEBHOOK_SECRET) {
            $errors[] = 'WEBHOOK_SECRET must be set to a unique value (not the demo default).';
        }

        if (!defined('SLIP_API_KEY') || SLIP_API_KEY === '') {
            $errors[] = 'SLIP_API_KEY is required in production for slip verification.';
        }

        $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        if ($baseUrl === '' || str_starts_with($baseUrl, 'http://localhost')) {
            $errors[] = 'BASE_URL must be your public HTTPS domain in production.';
        }

        // Mock mode makes eKYC, chat moderation and image-fraud checks return
        // fabricated results, so serving real users with it on is worse than
        // having no verification at all — the UI still claims users are verified.
        if (defined('MOCK_API_MODE') && MOCK_API_MODE) {
            $errors[] = 'MOCK_API_MODE must be off in production (eKYC and AI checks would be simulated).';
        }

        // Without this secret api/webhook_ekyc.php cannot authenticate Sumsub,
        // and anyone could POST themselves a passing KYC result.
        if (defined('EKYC_PROVIDER') && EKYC_PROVIDER !== 'manual'
            && (!defined('SUMSUB_WEBHOOK_SECRET') || SUMSUB_WEBHOOK_SECRET === '')) {
            $errors[] = 'SUMSUB_WEBHOOK_SECRET is required to authenticate eKYC webhooks.';
        }

        return $errors;
    }

    public static function assertWebSafe(): void {
        if (php_sapi_name() === 'cli') {
            return;
        }

        $errors = self::validate();
        if ($errors === []) {
            return;
        }

        if (class_exists('ErrorLogger')) {
            ErrorLogger::write('critical', 'Production configuration invalid', ['errors' => $errors]);
        } else {
            error_log('Production configuration invalid: ' . implode(' | ', $errors));
        }

        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ระบบไม่พร้อมใช้งาน</title></head>';
        echo '<body style="font-family:sans-serif;text-align:center;padding:48px;background:#f4f6f8;">';
        echo '<div style="background:#fff;padding:32px;border-radius:12px;display:inline-block;max-width:480px;box-shadow:0 4px 16px rgba(0,0,0,.08);">';
        echo '<h1 style="color:#c0392b;margin:0 0 12px;">ระบบยังไม่พร้อมให้บริการ</h1>';
        echo '<p style="color:#555;margin:0;">การตั้งค่า production ยังไม่ครบ กรุณาติดต่อผู้ดูแลระบบ</p>';
        echo '</div></body></html>';
        exit(1);
    }
}
