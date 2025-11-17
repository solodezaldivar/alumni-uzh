<?php
declare(strict_types=1);

// --- CONFIG ---
const TO_EMAIL   = 'info@alumni.ch'; // where to receive messages
const FROM_EMAIL = 'webform@your-domain.tld'; //TODO: setzen
const SUBJECT_PREFIX = '[UZH.AI Kontakt]';

// --- CORS/Headers (optional basic) ---
header('Content-Type: application/json; charset=utf-8');

// --- HONEYPOT ---
if (!empty($_POST['company'])) {
    http_response_code(200);
    echo json_encode(['ok' => true]); // silently accept bots
    exit;
}

// --- CSRF (very light) ---
$csrf = $_POST['csrf'] ?? '';
session_start();
$sessionToken = $_SESSION['csrf_form'] ?? null;
// Allow token from sessionStorage (sent by the form) OR same-page session token:
if (!$sessionToken) {
    $_SESSION['csrf_form'] = $csrf; // first hit stores token
    $sessionToken = $csrf;
}
if (!$csrf || !hash_equals((string)$sessionToken, (string)$csrf)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'CSRF ungültig']);
    exit;
}

// --- INPUTS ---
$first   = trim((string)($_POST['first'] ?? ''));
$last    = trim((string)($_POST['last'] ?? ''));
$email   = trim((string)($_POST['email'] ?? ''));
$subject = trim((string)($_POST['subject'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

if ($first === '' || $last === '' || $subject === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Bitte alle Pflichtfelder korrekt ausfüllen.']);
    exit;
}

// --- Compose email ---
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
$body = "Neue Kontaktanfrage:\n\n".
    "Name: $first $last\n".
    "E-Mail: $email\n".
    "Betreff: $subject\n\n".
    "Nachricht:\n$message\n\n".
    "---\nIP: $ip\nUA: $ua\n";

$headers = [];
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-type: text/plain; charset=utf-8';
$headers[] = 'From: '.FROM_EMAIL;
$headers[] = 'Reply-To: '.$email;
$headers[] = 'X-Mailer: PHP/'.phpversion();

$ok = @mail(TO_EMAIL, SUBJECT_PREFIX.' '.$subject, $body, implode("\r\n", $headers));

if ($ok) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Senden fehlgeschlagen.']);
}
