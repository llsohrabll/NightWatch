<?php
// ============================================================================
// MAIL_CONFIG.PHP - VALUES USED BY THE EMAIL HELPER
// ============================================================================
// These values describe how email should be sent and what sender details to show.
// Today, mailer.php mainly uses SMTP_HOST, SMTP_PORT, MAIL_FROM_ADDRESS, and MAIL_FROM_NAME.

// ============================================================================
// SMTP SERVER CONFIGURATION
// ============================================================================

// SMTP Server hostname (e.g., smtp.gmail.com, smtp.mailtrap.io, etc)
define('SMTP_HOST', 'smtp.gmail.com');

// SMTP Server port (587 = TLS, 465 = SSL, 25 = plain)
// Using 587 is recommended for most providers with TLS encryption
define('SMTP_PORT', 587);

// SMTP username (usually your email address)
// Example: 'your-email@gmail.com'
define('SMTP_USERNAME', 'your-email@gmail.com');

// SMTP password or app-specific password
// For Gmail: Use "App Password" from https://myaccount.google.com/apppasswords
// For other providers: Use your email password or provided app password
define('SMTP_PASSWORD', 'your-app-password-here');

// Enable TLS encryption (true for security, false for plain)
// TLS is more secure than SSL - strongly recommended
define('SMTP_ENCRYPTION', 'tls');

// Set to false to disable certificate verification (use only for development)
// ALWAYS set to true in production for security
define('SMTP_VERIFY_CERT', true);

// ============================================================================
// EMAIL SETTINGS
// ============================================================================

// From address for outgoing emails
// This appears as the sender in user's email client
// Usually matches SMTP_USERNAME but can be different
define('MAIL_FROM_ADDRESS', 'noreply@nightwatch.local');

// From name displayed in email client
define('MAIL_FROM_NAME', 'NightWatch System');

// ============================================================================
// EMAIL CONTENT SETTINGS
// ============================================================================

// Password reset code expiration time in seconds (3600 = 1 hour)
define('PASSWORD_RESET_TIMEOUT', 3600);

// Email verification code expiration time in seconds (900 = 15 minutes)
define('EMAIL_VERIFICATION_TIMEOUT', 900);

// ============================================================================
// SETUP INSTRUCTIONS
// ============================================================================
// 
// For Gmail:
// 1. Enable 2-Step Verification: https://myaccount.google.com/security
// 2. Generate App Password: https://myaccount.google.com/apppasswords
// 3. Copy the 16-character password and paste it into SMTP_PASSWORD above
// 4. Use SMTP_HOST = 'smtp.gmail.com' and SMTP_PORT = 587
//
// For Other Providers:
// - Contact your email provider for SMTP settings
// - Most use port 587 with TLS encryption
// - Enter the port number and encryption type provided
//
