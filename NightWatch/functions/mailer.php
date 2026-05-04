<?php
// ============================================================================
// MAILER.PHP - BUILD AND SEND APP EMAILS
// ============================================================================
// This file builds the verification and reset emails. The actual send step uses
// PHP's built-in mail() function, not a full SMTP client library.

declare(strict_types=1);

// ============================================================================
// This helper should only be loaded from another PHP file in the app.
// ============================================================================
if (!defined('NIGHTWATCH_INTERNAL')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access Denied');
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
// send_mail() - Shared helper that prepares headers and calls mail()
// ============================================================================
// It supports plain text plus HTML by building a multipart email body.
function send_mail(string $to, string $subject, string $textMessage, string $htmlMessage = ''): bool
{
    // Load the values used for the sender name and local mail setup.
    require_once(__DIR__ . DIRECTORY_SEPARATOR . 'mail_config.php');
    
    // ========================================================================
    // Email headers are metadata such as sender and content type.
    // ========================================================================
    
    $headers = [];
    
    // Set "From" header with sender's email and name
    $headers[] = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>';
    
    // Tell email client to reply to this address
    $headers[] = 'Reply-To: ' . MAIL_FROM_ADDRESS;
    
    // Set charset to UTF-8 for international characters
    $headers[] = 'Content-Type: multipart/alternative; charset=UTF-8';
    
    // Add other headers
    $headers[] = 'X-Mailer: NightWatch/1.0';
    
    // ========================================================================
    // Multipart emails let the receiving client choose the best version.
    // ========================================================================
    
    // Create boundary string for separating email parts
    $boundary = 'boundary_' . bin2hex(random_bytes(16));
    
    // Build complete headers string with boundary
    $headerString = implode("\r\n", $headers) . "\r\n";
    $headerString .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    
    // Build email body with both versions
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $textMessage . "\r\n\r\n";
    
    // Add HTML version if provided
    if (!empty($htmlMessage)) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlMessage . "\r\n\r\n";
    }
    
    // End boundary
    $body .= "--{$boundary}--\r\n";
    
    // ========================================================================
    // Some PHP setups read these values when mail() runs.
    // ========================================================================
    
    $originalSmtpHost = ini_get('SMTP');
    $originalSmtpPort = ini_get('smtp_port');
    $originalSendmailFrom = ini_get('sendmail_from');
    
    // Set SMTP parameters for this email
    ini_set('SMTP', SMTP_HOST);
    ini_set('smtp_port', (string)SMTP_PORT);
    ini_set('sendmail_from', MAIL_FROM_ADDRESS);
    
    // ========================================================================
    // mail() still depends on the server being configured to send email.
    // ========================================================================
    
    $success = mail(
        $to,                    // recipient email
        $subject,               // email subject
        $body,                  // email body with plain text + HTML
        $headerString           // email headers
    );
    
    // ========================================================================
    // Put the old settings back so this request does not affect later work.
    // ========================================================================
    
    if ($originalSmtpHost !== false) {
        ini_set('SMTP', $originalSmtpHost);
    }
    if ($originalSmtpPort !== false) {
        ini_set('smtp_port', $originalSmtpPort);
    }
    if ($originalSendmailFrom !== false) {
        ini_set('sendmail_from', $originalSendmailFrom);
    }
    
    // ========================================================================
    // Keep a simple log so delivery problems are easier to investigate.
    // ========================================================================
    
    // Create log entry for audit trail
    $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nightwatch_mail_log.txt';
    @file_put_contents($logFile, 
        date('Y-m-d H:i:s') . " | To: {$to} | Subject: {$subject} | Success: " . ($success ? 'YES' : 'NO') . "\n",
        FILE_APPEND
    );
    
    return $success;
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
