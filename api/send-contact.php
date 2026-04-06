<?php
/**
 * ChessPairings — Contact Form Handler
 * Receives form POST, validates, sends via SES SMTP PHPMailer
 */

require_once __DIR__ . '/../vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/SMTP.php';
require_once __DIR__ . '/../vendor/phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Load config
if (!file_exists(__DIR__ . '/../config.php')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server configuration missing']);
    exit;
}
require_once __DIR__ . '/../config.php';

// ─── Rate Limiting ───
function get_client_ip(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function check_rate_limit(): bool {
    $ip = get_client_ip();
    $dir = __DIR__ . '/../.rate_limit';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    
    $file = $dir . '/' . md5($ip);
    $now = time();
    $window = 3600; // 1 hour
    
    $submissions = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: [];
        $submissions = array_filter($data, fn($t) => ($now - $t) < $window);
    }
    
    if (count($submissions) >= CONTACT_RATE_LIMIT) {
        return false;
    }
    
    $submissions[] = $now;
    file_put_contents($file, json_encode($submissions));
    return true;
}

if (!check_rate_limit()) {
    echo json_encode(['success' => false, 'error' => 'Too many submissions. Please try again later.']);
    exit;
}

// ─── Input Validation ───
$name     = trim($_POST['name'] ?? '');
$email    = trim($_POST['email'] ?? '');
$tournament_url = trim($_POST['tournament_url'] ?? '');
$message  = trim($_POST['message'] ?? '');

$errors = [];

if (strlen($name) < 1 || strlen($name) > 100) {
    $errors[] = 'Please provide your name.';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please provide a valid email address.';
}

if ($tournament_url && !filter_var($tournament_url, FILTER_VALIDATE_URL)) {
    $errors[] = 'Tournament URL is not valid.';
}

if (strlen($message) < 5 || strlen($message) > 5000) {
    $errors[] = 'Please provide a description (5-5000 characters).';
}

// ─── hCaptcha Verification ───
if (defined('HCAPTCHA_ENABLED') && HCAPTCHA_ENABLED && defined('HCAPTCHA_SECRET_KEY')) {
    $response = $_POST['h-captcha-response'] ?? '';
    if (!$response) {
        $errors[] = 'Please complete the captcha.';
    } else {
        $verify = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query([
                    'secret' => HCAPTCHA_SECRET_KEY,
                    'response' => $response,
                ]),
            ],
        ]);
        $result = @file_get_contents('https://hcaptcha.com/siteverify', false, $verify);
        $data = json_decode($result, true);
        if (!$data['success']) {
            $errors[] = 'Captcha verification failed.';
        }
    }
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// ─── Sanitize Input ───
$name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$email = filter_var($email, FILTER_SANITIZE_EMAIL);
$tournament_url = filter_var($tournament_url, FILTER_SANITIZE_URL);
$message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

// Build email body
$tournament_section = $tournament_url ? "\nTournament URL: {$tournament_url}\n" : "\nTournament URL: Not provided\n";

$email_body = <<<EMAIL
ChessPairings Contact Form
==========================

Name: {$name}
Email: {$email}
{$tournament_section}
Description:
{$message}
EMAIL;

// ─── Send via PHPMailer + SES SMTP ───
try {
    $mail = new PHPMailer(true);

    // Server settings
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USERNAME;
    $mail->Password = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = SMTP_PORT;

    // Recipients
    $mail->setFrom(MAIL_FROM, SITE_NAME);
    $mail->addAddress(MAIL_TO, 'ChessPairings Admin');
    $mail->addReplyTo($email, $name);

    // Content
    $mail->isHTML(false);
    $mail->Subject = "ChessPairings Contact: " . substr($message, 0, 60);
    $mail->Body = $email_body;

    $mail->send();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Unable to send message. Please try again later.'
    ]);
}
