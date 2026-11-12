<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require __DIR__ . '/php/Exception.php';
require __DIR__ . '/php/PHPMailer.php';
require __DIR__ . '/php/SMTP.php';

function respond(int $status, string $title, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');

    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo '<!DOCTYPE html><html lang="en"><meta charset="UTF-8"><title>' . $safeTitle . '</title>';
    echo '<body><h1>' . $safeTitle . '</h1><p>' . $safeMessage . '</p>';
    echo '<p><a href="index.html">Back to contact form</a></p></body></html>';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, 'Method not allowed', 'Please submit the contact form.');
    exit;
}

$fields = [];
foreach (['name', 'email', 'subject', 'message'] as $field) {
    $value = $_POST[$field] ?? null;
    if (!is_string($value)) {
        respond(400, 'Invalid submission', 'Please complete every field with valid text.');
        exit;
    }

    $fields[$field] = trim($value);
}

if (
    $fields['name'] === ''
    || $fields['email'] === ''
    || $fields['subject'] === ''
    || $fields['message'] === ''
    || filter_var($fields['email'], FILTER_VALIDATE_EMAIL) === false
) {
    respond(400, 'Invalid submission', 'Please enter a valid email address and complete every field.');
    exit;
}

$env = [];
$envPath = __DIR__ . DIRECTORY_SEPARATOR . '.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $matches)) {
            $value = $matches[2];
            if (
                strlen($value) >= 2
                && (($value[0] === '"' && substr($value, -1) === '"')
                    || ($value[0] === "'" && substr($value, -1) === "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            $env[$matches[1]] = $value;
        }
    }
}

$getConfig = static function (string $key) use ($env): string {
    $value = getenv($key);
    return trim($value === false ? ($env[$key] ?? '') : $value);
};

$host = $getConfig('SMTP_HOST');
$port = filter_var($getConfig('SMTP_PORT'), FILTER_VALIDATE_INT);
$username = $getConfig('SMTP_USER');
$password = $getConfig('SMTP_PASS');
$fromAddress = $getConfig('MAIL_FROM');
$toAddresses = array_filter(array_map('trim', explode(',', $getConfig('MAIL_TO'))));

if (
    $host === ''
    || $port === false
    || $port < 1
    || $port > 65535
    || $username === ''
    || $password === ''
    || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
    || $toAddresses === []
) {
    error_log('Contact form email configuration is incomplete or invalid.');
    respond(500, 'Unable to send message', 'The email service is not configured correctly. Please try again later.');
    exit;
}

foreach ($toAddresses as $address) {
    if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
        error_log('Contact form email configuration contains an invalid recipient address.');
        respond(500, 'Unable to send message', 'The email service is not configured correctly. Please try again later.');
        exit;
    }
}

$mailer = new PHPMailer(true);

try {
    $mailer->isSMTP();
    $mailer->Host = $host;
    $mailer->Port = $port;
    $mailer->SMTPAuth = true;
    $mailer->Username = $username;
    $mailer->Password = $password;

    $secure = strtolower($getConfig('SMTP_SECURE'));
    if ($secure === 'true' || $secure === 'ssl') {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($secure === 'tls' || $secure === 'starttls') {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom($fromAddress, $getConfig('MAIL_FROM_NAME') ?: 'Contact Form');
    foreach ($toAddresses as $address) {
        $mailer->addAddress($address);
    }
    $mailer->addReplyTo($fields['email'], $fields['name']);
    $mailer->Subject = $fields['subject'];
    $mailer->isHTML(true);
    $mailer->Body = '<p><strong>Name:</strong> '
        . htmlspecialchars($fields['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</p><p><strong>Email:</strong> '
        . htmlspecialchars($fields['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</p><p><strong>Message:</strong><br>'
        . nl2br(htmlspecialchars($fields['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
        . '</p>';
    $mailer->AltBody = "Name: {$fields['name']}\nEmail: {$fields['email']}\n\n{$fields['message']}";
    $mailer->send();
} catch (Exception $exception) {
    error_log('Contact form email failed: ' . $mailer->ErrorInfo);
    respond(500, 'Unable to send message', 'Your message could not be sent right now. Please try again later.');
    exit;
}

respond(200, 'Message sent', 'Thank you, ' . $fields['name'] . '! Your message has been sent successfully.');
