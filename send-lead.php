<?php
/**
 * Creatify Hub — contact form handler for PHP-only hosting.
 *
 * The static site's contact form POSTs JSON to this endpoint.
 * Configure $toAddress and $fromAddress below, then upload this file
 * alongside the exported site (it lives at the site root, e.g.
 * public_html/send-lead.php).
 *
 * Every submission is both emailed to $toAddress AND appended to a log
 * file (see $logFile below) as a backup — so a lead is never lost even if
 * an email gets missed, filtered to spam, or accidentally deleted.
 * View saved leads at /admin.php (protect that page — see its header).
 */

header('Content-Type: application/json');

// ---- Configure these before going live ----
$toAddress = 'sales@creatifyhub.com';
// Use an address on your own domain as the sender to avoid spam filtering.
$fromAddress = 'no-reply@creatifyhub.com';
// ---------------------------------------------

// Leads are logged one JSON object per line to this file, stored one
// directory above the web root so it can't be downloaded directly by URL.
// If your host's folder layout differs, adjust this path — after
// deploying, confirm it's NOT reachable by visiting its URL directly.
$logFile = __DIR__ . '/../creatify-hub-leads.jsonl';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    // Fall back to standard form-encoded submissions.
    $data = $_POST;
}

function clean($value) {
    $value = (string) $value;
    $value = str_replace(["\r", "\n"], ' ', $value); // prevent header injection
    return trim(strip_tags($value));
}

$name = clean($data['name'] ?? '');
$company = clean($data['company'] ?? '');
$email = clean($data['email'] ?? '');
$phone = clean($data['phone'] ?? '');
$industry = clean($data['industry'] ?? '');
$message = clean($data['message'] ?? '');

// "interests" arrives as an array (checkbox multi-select on the contact
// form); other forms that don't have this field simply omit it.
$interestsRaw = is_array($data['interests'] ?? null) ? $data['interests'] : [];
$interests = array_values(array_filter(array_map('clean', $interestsRaw), fn($v) => $v !== ''));
$interestsText = implode(', ', $interests);

$errors = [];
if (strlen($name) < 2) $errors[] = 'name';
if (strlen($company) < 2) $errors[] = 'company';
if (strlen($phone) < 7) $errors[] = 'phone';
// Email is optional site-wide, but if one IS provided it must be valid.
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Please fill in all required fields with valid values.']);
    exit;
}

// ---- Save to the log file first (best-effort — never blocks the response) ----
try {
    $entry = [
        'id' => bin2hex(random_bytes(6)),
        'timestamp' => date('c'),
        'name' => $name,
        'company' => $company,
        'email' => $email,
        'phone' => $phone,
        'industry' => $industry,
        'interests' => $interests,
        'message' => $message,
    ];
    @file_put_contents($logFile, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
} catch (\Throwable $e) {
    // Storage failing should never block the lead from being emailed.
}

$subject = "New Creatify Hub lead: {$name} ({$company})";

$body = "New lead from the Creatify Hub website:\n\n"
    . "Name: {$name}\n"
    . "Company: {$company}\n"
    . "Phone: {$phone}\n"
    . "Email: " . ($email !== '' ? $email : '(not provided)') . "\n"
    . "Industry: " . ($industry !== '' ? $industry : '(not provided)') . "\n"
    . "Looking for: " . ($interestsText !== '' ? $interestsText : '(not specified)') . "\n"
    . "Message: " . ($message !== '' ? $message : '(none)') . "\n";

$headers = "From: {$fromAddress}\r\n";
// Only set Reply-To when we actually have a valid email to reply to.
if ($email !== '') {
    $headers .= "Reply-To: {$name} <{$email}>\r\n";
}
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$sent = @mail($toAddress, $subject, $body, $headers);

if (!$sent) {
    // Email failed, but the lead is still safely saved in the log file above.
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong. Please try again or call us directly.']);
    exit;
}

echo json_encode(['ok' => true]);
