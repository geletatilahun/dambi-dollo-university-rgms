<?php
/**
 * Email delivery using PHPMailer.
 * Loads SMTP config from the settings table.
 */

// Use PHPMailer via Composer autoload if available
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    // Manual include fallback (if installed without Composer)
    $phpMailerDir = __DIR__ . '/../vendor/phpmailer/';
    if (is_dir($phpMailerDir)) {
        require_once $phpMailerDir . 'src/Exception.php';
        require_once $phpMailerDir . 'src/PHPMailer.php';
        require_once $phpMailerDir . 'src/SMTP.php';
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!function_exists('send_email')) {
    /**
     * Send an email via SMTP.
     * Returns true on success, false on failure (errors logged).
     */
    function send_email($pdo, $to_email, $to_name, $subject, $body_html) {
        // Load settings
        $settings = [];
        foreach ($pdo->query("SELECT key_name, value FROM settings") as $row) {
            $settings[$row['key_name']] = $row['value'];
        }

        $host = $settings['smtp_host'] ?? '';
        $port = (int)($settings['smtp_port'] ?? 587);
        $user = $settings['smtp_user'] ?? '';
        $pass = $settings['smtp_pass'] ?? '';
        $from = $settings['smtp_from'] ?? 'noreply@ddu.edu.et';

        // No SMTP configured → skip silently
        if ($host === '' || $user === '') {
            @error_log("Email skipped (SMTP not configured): $subject to $to_email");
            return false;
        }

        // PHPMailer not installed?
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            @error_log("PHPMailer class not found — did you run composer require phpmailer/phpmailer?");
            return false;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $user;
            $mail->Password   = $pass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $port;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($from, defined('SITE_NAME') ? SITE_NAME : 'DDU-RGMS');
            $mail->addAddress($to_email, $to_name);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = wrap_email_template($subject, $body_html);
            $mail->AltBody = strip_tags($body_html);

            $mail->send();
            return true;
        } catch (Exception $e) {
            @error_log("Email send failed: " . $mail->ErrorInfo);
            return false;
        }
    }
}

if (!function_exists('wrap_email_template')) {
    /**
     * Wrap a simple message in a branded HTML email template.
     */
    function wrap_email_template($heading, $body_html) {
        $site = defined('SITE_NAME') ? SITE_NAME : 'DDU-RGMS';
        $year = date('Y');
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;padding:24px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0"
           style="background:#fff;border-radius:8px;overflow:hidden;
                  box-shadow:0 2px 8px rgba(10,30,61,0.08);">
      <tr>
        <td style="background:linear-gradient(135deg,#0a1e3d 0%,#12315a 100%);
                   color:#fff;padding:24px 32px;">
          <div style="font-size:20px;font-weight:800;">DDU–RGMS</div>
          <div style="font-size:12px;text-transform:uppercase;
                      letter-spacing:2px;color:rgba(255,255,255,0.6);margin-top:4px;">
            Research Grant Management System
          </div>
        </td>
      </tr>
      <tr>
        <td style="padding:28px 32px;color:#0f172a;font-size:15px;line-height:1.6;">
          <h2 style="margin:0 0 16px;font-size:18px;color:#0a1e3d;">$heading</h2>
          $body_html
        </td>
      </tr>
      <tr>
        <td style="background:#f8fafc;padding:16px 32px;
                   color:#94a3b8;font-size:12px;text-align:center;
                   border-top:1px solid #e2e8f0;">
          © $year Dambi Dollo University · Research &amp; Innovation
          <br>This is an automated message — please do not reply.
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }
}