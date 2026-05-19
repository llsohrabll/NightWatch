<?php
// ============================================================================
// MAILER.PHP - BUILD AND SEND APP EMAILS
// ============================================================================
// This file builds verification/reset emails and sends them through PHPMailer
// when installed, otherwise through the native SMTP stream fallback below.

declare(strict_types=1);

// ============================================================================
// This helper should only be loaded from another PHP file in the app.
// ============================================================================
if (!defined('NIGHTWATCH_INTERNAL')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access Denied');
}

// Load mail settings once so timeout constants are available to callers too.
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'mail_config.php');

function mail_header_value(string $value): string
{
    return trim(str_replace(["\r", "\n"], ' ', $value));
}

function mail_address_value(string $email): string
{
    $clean = trim(str_replace(["\r", "\n"], '', $email));
    return filter_var($clean, FILTER_VALIDATE_EMAIL) !== false ? $clean : '';
}

// ============================================================================
// send_verification_email() - Send email verification code during registration
// ============================================================================
// New users receive this code after registration and must type it on the verify page.
function send_verification_email(string $email, string $verificationCode): bool
{
    // Email subject
    $subject = 'NightWatch - Email Verification';
    
    // Plain text is useful for simple mail clients.
    $textMessage = "Welcome to NightWatch!\n\n";
    $textMessage .= "Your email verification code is: {$verificationCode}\n\n";
    $textMessage .= "This code will expire in 15 minutes.\n";
    $textMessage .= "Do not share this code with anyone.\n\n";
    $textMessage .= "If you did not register for NightWatch, ignore this email.\n";
    
    // HTML lets the email look friendlier in modern mail clients.
    $htmlMessage = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #1a1a2e; color: #6bff9e; padding: 20px; text-align: center; }
            .content { background: #f9f9f9; padding: 20px; margin: 20px 0; }
            .code { 
                font-size: 24px; 
                font-weight: bold; 
                letter-spacing: 2px; 
                background: #e8f5e9; 
                padding: 15px; 
                text-align: center; 
                border-radius: 5px;
                color: #2e7d32;
            }
            .warning { color: #d32f2f; font-weight: bold; }
            .footer { text-align: center; color: #666; font-size: 12px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>NightWatch - Email Verification</h1>
            </div>
            <div class='content'>
                <p>Welcome to NightWatch!</p>
                <p>Your email verification code is:</p>
                <div class='code'>{$verificationCode}</div>
                <p class='warning'>⚠️ This code will expire in 15 minutes.</p>
                <p class='warning'>⚠️ Do not share this code with anyone.</p>
                <p>If you did not register for NightWatch, ignore this email.</p>
            </div>
            <div class='footer'>
                <p>NightWatch Team</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Send the email
    return send_mail($email, $subject, $textMessage, $htmlMessage);
}

// ============================================================================
// send_password_reset_email() - Send password reset code via email
// ============================================================================
// This email is sent after the user asks to reset a password.
function send_password_reset_email(string $email, string $resetCode): bool
{
    // Email subject
    $subject = 'NightWatch - Password Reset Request';
    
    // Plain text version
    $textMessage = "Password Reset Request\n\n";
    $textMessage .= "We received a request to reset your password.\n\n";
    $textMessage .= "Your password reset code is: {$resetCode}\n\n";
    $textMessage .= "This code will expire in 1 hour.\n";
    $textMessage .= "Do not share this code with anyone.\n\n";
    $textMessage .= "If you did not request this reset, ignore this email.\n";
    
    // HTML version
    $htmlMessage = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #1a1a2e; color: #6bff9e; padding: 20px; text-align: center; }
            .content { background: #f9f9f9; padding: 20px; margin: 20px 0; }
            .code { 
                font-size: 24px; 
                font-weight: bold; 
                letter-spacing: 2px; 
                background: #fff3e0; 
                padding: 15px; 
                text-align: center; 
                border-radius: 5px;
                color: #e65100;
            }
            .warning { color: #d32f2f; font-weight: bold; }
            .footer { text-align: center; color: #666; font-size: 12px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>NightWatch - Password Reset</h1>
            </div>
            <div class='content'>
                <p>We received a request to reset your password.</p>
                <p>Your password reset code is:</p>
                <div class='code'>{$resetCode}</div>
                <p class='warning'>⚠️ This code will expire in 1 hour.</p>
                <p class='warning'>⚠️ Do not share this code with anyone.</p>
                <p>If you did not request this reset, ignore this email.</p>
            </div>
            <div class='footer'>
                <p>NightWatch Team</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return send_mail($email, $subject, $textMessage, $htmlMessage);
}


// ============================================================================
// send_email_change_verification_email() - Verify a requested email change
// ============================================================================
function send_email_change_verification_email(string $email, string $verificationCode): bool
{
    $subject = 'NightWatch - Confirm Email Change';

    $textMessage = "Email Change Confirmation\n\n";
    $textMessage .= "Use this code to confirm your new NightWatch email address: {$verificationCode}\n\n";
    $textMessage .= "This code will expire in 15 minutes. Do not share it.\n\n";
    $textMessage .= "If you did not request this change, keep your current email and reset your password.\n";

    $htmlMessage = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, sans-serif; color: #222;'>
      <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
        <div style='background: #1a1a2e; color: #6bff9e; padding: 20px; text-align: center;'>
          <h1>Confirm Email Change</h1>
        </div>
        <div style='background: #f9f9f9; padding: 20px; margin: 20px 0;'>
          <p>Use this code to confirm your new NightWatch email address:</p>
          <div style='font-size: 24px; font-weight: bold; letter-spacing: 2px; background: #e8f5e9; padding: 15px; text-align: center; border-radius: 5px; color: #2e7d32;'>{$verificationCode}</div>
          <p style='color: #d32f2f; font-weight: bold;'>This code expires in 15 minutes. Do not share it.</p>
          <p>If you did not request this change, keep your current email and reset your password.</p>
        </div>
      </div>
    </body>
    </html>";

    return send_mail($email, $subject, $textMessage, $htmlMessage);
}

// ============================================================================
// send_mail() - Shared helper that sends email via SMTP or mail()
// ============================================================================
// Attempts to use PHPMailer if available, otherwise uses native SMTP via PHP streams.
function send_mail(string $to, string $subject, string $textMessage, string $htmlMessage = ''): bool
{
    require_once(__DIR__ . DIRECTORY_SEPARATOR . 'mail_config.php');
    require_once(__DIR__ . DIRECTORY_SEPARATOR . 'app_logging.php');

    $recipient = mail_address_value($to);
    $fromAddress = mail_address_value((string) MAIL_FROM_ADDRESS);
    $fromName = mail_header_value((string) MAIL_FROM_NAME);
    $safeSubject = mail_header_value($subject);

    if ($recipient === '' || $fromAddress === '' || $safeSubject === '') {
        log_mail_event($recipient, $safeSubject, false, 'Invalid email parameters');
        return false;
    }

    if (SMTP_REQUIRE_AUTH && ((string) SMTP_USERNAME === '' || (string) SMTP_PASSWORD === '')) {
        log_mail_event($recipient, $safeSubject, false, 'SMTP authentication is required but SMTP_USERNAME or SMTP_PASSWORD is not configured');
        return false;
    }

    if (SMTP_REQUIRE_AUTH && SMTP_ENCRYPTION === 'none' && !SMTP_ALLOW_INSECURE_AUTH) {
        log_mail_event($recipient, $safeSubject, false, 'Refusing SMTP authentication without TLS/SSL');
        return false;
    }
    
    // ========================================================================
    // Try PHPMailer first if available
    // ========================================================================
    $phpmailerPath = __DIR__ . '/../../../vendor/autoload.php';
    if (file_exists($phpmailerPath)) {
        return send_mail_via_phpmailer($recipient, $fromAddress, $fromName, $safeSubject, $textMessage, $htmlMessage);
    }
    
    // ========================================================================
    // Use native SMTP via PHP streams
    // ========================================================================
    return send_mail_via_mail($recipient, $fromAddress, $fromName, $safeSubject, $textMessage, $htmlMessage);
}

// ============================================================================
// send_mail_via_phpmailer() - Send email using PHPMailer library
// ============================================================================
// NOTE: PHPMailer is an optional dependency. This function only executes 
// if the vendor/autoload.php file exists.
function send_mail_via_phpmailer(string $recipient, string $fromAddress, string $fromName, string $subject, string $textMessage, string $htmlMessage = ''): bool
{
    try {
        require_once(__DIR__ . '/../../../vendor/autoload.php');
        
        // Using string class name to avoid type resolution errors when PHPMailer is not installed
        $phpMailerClass = '\PHPMailer\PHPMailer\PHPMailer';
        if (!class_exists($phpMailerClass)) {
            log_mail_event($recipient, $subject, false, 'PHPMailer not installed');
            return false;
        }
        
        /** @var object $mail */
        $mail = new $phpMailerClass(true);
        
        // SMTP configuration
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = SMTP_REQUIRE_AUTH;
        if (SMTP_REQUIRE_AUTH) {
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
        }
        $mail->Port = (int) SMTP_PORT;
        
        // Set encryption
        $encryptionClass = '\PHPMailer\PHPMailer\PHPMailer';
        if (SMTP_ENCRYPTION === 'tls' && defined("$encryptionClass::ENCRYPTION_STARTTLS")) {
            $mail->SMTPSecure = constant("$encryptionClass::ENCRYPTION_STARTTLS");
        } elseif (SMTP_ENCRYPTION === 'ssl' && defined("$encryptionClass::ENCRYPTION_SMTPS")) {
            $mail->SMTPSecure = constant("$encryptionClass::ENCRYPTION_SMTPS");
        }
        
        // Certificate verification
        if (!SMTP_VERIFY_CERT) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ]
            ];
        }
        
        // Email details
        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($recipient);
        $mail->addReplyTo($fromAddress, $fromName);
        $mail->Subject = $subject;
        
        // Content
        if (!empty($htmlMessage)) {
            $mail->Body = $htmlMessage;
            $mail->AltBody = $textMessage;
            $mail->isHTML(true);
        } else {
            $mail->Body = $textMessage;
            $mail->isHTML(false);
        }
        
        // Send the email
        $success = $mail->send();
        log_mail_event($recipient, $subject, $success, $success ? 'PHPMailer delivery' : 'PHPMailer failed: ' . $mail->ErrorInfo);
        
        return $success;
    } catch (\Exception $e) {
        log_mail_event($recipient, $subject, false, 'PHPMailer exception: ' . $e->getMessage());
        return false;
    }
}

/**
 * Dot-stuff SMTP DATA content so a body line starting with a dot cannot
 * accidentally terminate or corrupt the message.
 */
function smtp_dot_stuff(string $message): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $message);
    $lines = explode("\n", $normalized);
    foreach ($lines as &$line) {
        if (isset($line[0]) && $line[0] === '.') {
            $line = '.' . $line;
        }
    }
    unset($line);
    return implode("\r\n", $lines);
}


/**
 * Read one SMTP response, including multiline replies such as 250-... / 250 ... .
 */
function smtp_read_response($smtp): string
{
    $lines = [];
    while (($line = stream_get_line($smtp, 2048, "\r\n")) !== false) {
        $lines[] = $line;
        if (preg_match('/^\d{3} /', $line) === 1) {
            break;
        }
        if (preg_match('/^\d{3}-/', $line) !== 1) {
            break;
        }
    }

    return implode("\n", $lines);
}

function smtp_response_ok(string $response, string $code): bool
{
    return $response !== '' && preg_match('/^' . preg_quote($code, '/') . '(?:[ -]|$)/', $response) === 1;
}

// ============================================================================
// send_mail_via_mail() - Send email using native SMTP via PHP streams
// ============================================================================
function send_mail_via_mail(string $recipient, string $fromAddress, string $fromName, string $subject, string $textMessage, string $htmlMessage = ''): bool
{
    try {
        // ====================================================================
        // Connect to SMTP server
        // ====================================================================
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => SMTP_VERIFY_CERT,
                'verify_peer_name' => SMTP_VERIFY_CERT,
                'allow_self_signed' => !SMTP_VERIFY_CERT,
            ]
        ]);
        
        $protocol = (SMTP_ENCRYPTION === 'ssl') ? 'ssl' : 'tcp';
        $smtpUri = "{$protocol}://" . SMTP_HOST . ':' . SMTP_PORT;
        
        $smtp = @stream_socket_client($smtpUri, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        
        if (!$smtp) {
            log_mail_event($recipient, $subject, false, "SMTP connection failed: {$errstr}");
            return false;
        }

        stream_set_timeout($smtp, 10);
        
        // Read greeting
        $response = smtp_read_response($smtp);
        if (!smtp_response_ok($response, '220')) {
            @fclose($smtp);
            log_mail_event($recipient, $subject, false, "SMTP greeting failed: {$response}");
            return false;
        }
        
        // Send EHLO
        fwrite($smtp, "EHLO " . gethostname() . "\r\n");
        $response = smtp_read_response($smtp);
        if (!smtp_response_ok($response, '250')) {
            @fclose($smtp);
            log_mail_event($recipient, $subject, false, "SMTP EHLO failed");
            return false;
        }
        
        // Start TLS if needed
        if (SMTP_ENCRYPTION === 'tls') {
            fwrite($smtp, "STARTTLS\r\n");
            $response = smtp_read_response($smtp);
            if (!smtp_response_ok($response, '220')) {
                @fclose($smtp);
                log_mail_event($recipient, $subject, false, "SMTP STARTTLS failed");
                return false;
            }
            
            if (!stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                @fclose($smtp);
                log_mail_event($recipient, $subject, false, "SMTP TLS enable failed");
                return false;
            }
            
            // Send EHLO again after TLS
            fwrite($smtp, "EHLO " . gethostname() . "\r\n");
            $response = smtp_read_response($smtp);
            if (!smtp_response_ok($response, '250')) {
                @fclose($smtp);
                log_mail_event($recipient, $subject, false, "SMTP EHLO after STARTTLS failed");
                return false;
            }
        }
        
        if (SMTP_REQUIRE_AUTH) {
            // Authenticate
            fwrite($smtp, "AUTH LOGIN\r\n");
            $response = smtp_read_response($smtp);
            if (!smtp_response_ok($response, '334')) {
                @fclose($smtp);
                log_mail_event($recipient, $subject, false, "SMTP AUTH LOGIN failed");
                return false;
            }
            
            // Send username
            fwrite($smtp, base64_encode(SMTP_USERNAME) . "\r\n");
            $response = smtp_read_response($smtp);
            if (!smtp_response_ok($response, '334')) {
                @fclose($smtp);
                log_mail_event($recipient, $subject, false, "SMTP username auth failed");
                return false;
            }
            
            // Send password
            fwrite($smtp, base64_encode(SMTP_PASSWORD) . "\r\n");
            $response = smtp_read_response($smtp);
            if (!smtp_response_ok($response, '235')) {
                @fclose($smtp);
                log_mail_event($recipient, $subject, false, "SMTP password auth failed");
                return false;
            }
        }
        
        // ====================================================================
        // Build email message
        // ====================================================================
        $boundary = 'boundary_' . bin2hex(random_bytes(16));
        
        $headers = "From: \"" . addcslashes($fromName, '"\\') . "\" <{$fromAddress}>\r\n";
        $headers .= "To: <{$recipient}>\r\n";
        $headers .= "Reply-To: {$fromAddress}\r\n";
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "X-Mailer: NightWatch/1.0\r\n";
        
        if (!empty($htmlMessage)) {
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"; charset=UTF-8\r\n";
            
            $body = "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $textMessage . "\r\n\r\n";
            
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $htmlMessage . "\r\n\r\n";
            
            $body .= "--{$boundary}--\r\n";
        } else {
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body = $textMessage;
        }
        
        $fullMessage = $headers . "\r\n" . $body;
        
        // ====================================================================
        // Send message
        // ====================================================================
        fwrite($smtp, "MAIL FROM: <{$fromAddress}>\r\n");
        $response = smtp_read_response($smtp);
        if (!smtp_response_ok($response, '250')) {
            @fclose($smtp);
            log_mail_event($recipient, $subject, false, "SMTP MAIL FROM failed");
            return false;
        }
        
        fwrite($smtp, "RCPT TO: <{$recipient}>\r\n");
        $response = smtp_read_response($smtp);
        if (!smtp_response_ok($response, '250')) {
            @fclose($smtp);
            log_mail_event($recipient, $subject, false, "SMTP RCPT TO failed");
            return false;
        }
        
        fwrite($smtp, "DATA\r\n");
        $response = smtp_read_response($smtp);
        if (!smtp_response_ok($response, '354')) {
            @fclose($smtp);
            log_mail_event($recipient, $subject, false, "SMTP DATA failed");
            return false;
        }
        
        fwrite($smtp, smtp_dot_stuff($fullMessage) . "\r\n.\r\n");
        $response = smtp_read_response($smtp);
        if (!smtp_response_ok($response, '250')) {
            @fclose($smtp);
            log_mail_event($recipient, $subject, false, "SMTP message send failed: {$response}");
            return false;
        }
        
        // ====================================================================
        // Close connection
        // ====================================================================
        fwrite($smtp, "QUIT\r\n");
        @fclose($smtp);
        
        log_mail_event($recipient, $subject, true, "Native SMTP delivery");
        return true;
        
    } catch (\Exception $e) {
        log_mail_event($recipient, $subject, false, "Exception: " . $e->getMessage());
        return false;
    } catch (\Throwable $e) {
        log_mail_event($recipient, $subject, false, "Error: " . $e->getMessage());
        return false;
    }
}


// ============================================================================
// generate_verification_code() - Create random 6-digit code
// ============================================================================
// The leading-zero padding matters because users expect a fixed-width code.
function generate_verification_code(): string
{
    // random_int() is suitable for security-sensitive codes.
    $code = random_int(0, 999999);
    
    // Convert to string and pad with leading zeros to make 6 digits
    // Example: 1234 becomes "001234"
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}
