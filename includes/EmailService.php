<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

require_once __DIR__ . '/../PHPMailer/Exception.php';
require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';

class EmailService {
    private $config = [];
    private $lastError = '';

    public function __construct() {
        $this->config = [
            'enabled'       => defined('MAIL_ENABLED') && MAIL_ENABLED,
            'from'          => defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@localhost',
            'from_name'     => defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'FLIXO Escrow',
            'smtp_host'     => defined('MAIL_SMTP_HOST') ? MAIL_SMTP_HOST : '',
            'smtp_port'     => defined('MAIL_SMTP_PORT') ? MAIL_SMTP_PORT : 587,
            'smtp_user'     => defined('MAIL_SMTP_USERNAME') ? MAIL_SMTP_USERNAME : '',
            'smtp_pass'     => defined('MAIL_SMTP_PASSWORD') ? MAIL_SMTP_PASSWORD : '',
            'smtp_secure'   => defined('MAIL_SMTP_SECURE') ? MAIL_SMTP_SECURE : 'tls',
            'use_php_mail'  => defined('MAIL_USE_PHP_MAIL') && MAIL_USE_PHP_MAIL,
        ];
    }

    public function send($toEmail, $subject, $body, $isHtml = true) {
        if (!$this->config['enabled']) {
            $this->lastError = 'Email notifications are disabled (MAIL_ENABLED=0)';
            return false;
        }

        if (empty($toEmail)) {
            $this->lastError = 'Invalid recipient email address';
            return false;
        }

        if (!empty($this->config['smtp_host']) && !empty($this->config['smtp_user'])) {
            return $this->sendViaSMTP($toEmail, $subject, $body, $isHtml);
        }

        if ($this->config['use_php_mail']) {
            return $this->sendViaPhpMail($toEmail, $subject, $body, $isHtml);
        }

        $this->lastError = 'Email transport not configured (set MAIL_SMTP_* in .env)';
        error_log('Email skipped: ' . $this->lastError . ' -> ' . $toEmail);
        return false;
    }

    private function sendViaSMTP($toEmail, $subject, $body, $isHtml) {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $this->config['smtp_host'];
            $mail->Port = (int) $this->config['smtp_port'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['smtp_user'];
            $mail->Password = $this->config['smtp_pass'];
            $secure = strtolower((string) $this->config['smtp_secure']);
            if ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPAutoTLS = false;
            }
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($this->config['from'], $this->config['from_name']);
            $mail->addAddress($toEmail);
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $body;
            if (!$isHtml) {
                $mail->AltBody = strip_tags($body);
            }
            $mail->send();
            return true;
        } catch (MailerException $e) {
            $this->lastError = $mail->ErrorInfo ?: $e->getMessage();
            error_log('Email SMTP failed: ' . $this->lastError);
            return false;
        }
    }

    private function sendViaPhpMail($toEmail, $subject, $body, $isHtml) {
        $headers = [];
        $headers[] = 'From: ' . $this->config['from_name'] . ' <' . $this->config['from'] . '>';
        $headers[] = 'Reply-To: ' . $this->config['from'];
        $headers[] = 'Content-Type: ' . ($isHtml ? 'text/html' : 'text/plain') . '; charset=UTF-8';
        $headers[] = 'X-Mailer: FLIXO Escrow System';
        $headersStr = implode("\r\n", $headers);

        $sent = @mail($toEmail, $subject, $body, $headersStr);
        if ($sent) {
            return true;
        }

        $this->lastError = 'PHP mail() function failed';
        error_log('Email mail() failed -> ' . $toEmail);
        return false;
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function isConfigured() {
        if (!$this->config['enabled']) {
            return false;
        }
        return (!empty($this->config['smtp_host']) && !empty($this->config['smtp_user']))
            || $this->config['use_php_mail'];
    }
}
