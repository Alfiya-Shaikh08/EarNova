<?php
// Streams a stored student ID only to an authenticated administrator.
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    exit('Admin access is required.');
}

require_once __DIR__ . '/../php/require_approved.php';

$studentUserId = (int) ($_GET['id'] ?? 0);
if ($studentUserId <= 0) {
    http_response_code(400);
    exit('A valid student ID is required.');
}

try {
    $stmt = $pdo->prepare(
        "SELECT s.id_card_path
         FROM students s
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE s.user_id = ? AND u.role = 'Student'
         LIMIT 1"
    );
    $stmt->execute([$studentUserId]);
    $storedName = $stmt->fetchColumn();
} catch (PDOException $exception) {
    error_log('EarNova college ID lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Student verification is not set up. Apply the college ID verification SQL in phpMyAdmin.');
}

if (!is_string($storedName) || !preg_match('/\A[a-f0-9]{32}\.(?:jpg|jpeg|png|pdf)\z/i', $storedName)) {
    http_response_code(404);
    exit('College ID file not found.');
}

$privateUploadDirectory = 'C:\\xampp\\private_uploads';
// The stored filename is random and validated before it is used to locate a private document.
$filePath = $privateUploadDirectory . DIRECTORY_SEPARATOR . $storedName;
if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('College ID file is unavailable.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
$allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(415);
    exit('The stored file type is not supported.');
}

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="college-id.' . strtolower(pathinfo($storedName, PATHINFO_EXTENSION)) . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
