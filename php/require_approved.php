<?php
// Centralizes PDO access and student-ID approval checks shared by student gig flows.
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=user_database;charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    error_log('EarNova PDO connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Database connection failed. Please start MySQL in XAMPP and try again.');
}

function getStudentVerification(PDO $pdo, int $userId): array
{
    // A missing student detail row is treated as pending so incomplete accounts stay restricted.
    try {
        $stmt = $pdo->prepare(
            "SELECT s.verification_status AS status, s.id_card_path, s.rejection_reason,
                    s.id_uploaded_at, s.reviewed_at
             FROM users u
             LEFT JOIN students s ON s.user_id = u.user_id
             WHERE u.user_id = ? AND u.role = 'Student' AND u.status = 'Active'
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        $verification = $stmt->fetch();
    } catch (PDOException $exception) {
        error_log('EarNova student verification lookup failed: ' . $exception->getMessage());
        return ['status' => 'error', 'message' => 'Student verification is not set up. Ask an administrator to apply the verification SQL.'];
    }

    if (!$verification) {
        return ['status' => 'unavailable', 'message' => 'Your active student account could not be found.'];
    }
    if (empty($verification['status'])) {
        return ['status' => 'pending', 'id_card_path' => null, 'rejection_reason' => null, 'id_uploaded_at' => null, 'reviewed_at' => null];
    }

    return $verification;
}

function studentHasApprovedId(PDO $pdo, int $userId): bool
{
    $verification = getStudentVerification($pdo, $userId);
    return ($verification['status'] ?? '') === 'approved';
}

function requireApprovedStudent(PDO $pdo, int $userId): bool
{
    // Enforce role and approval at the data boundary before exposing student-only gig actions.
    if (($_SESSION['role'] ?? '') !== 'Student' || $userId <= 0) {
        http_response_code(403);
        exit('An authenticated student account is required.');
    }

    $verification = getStudentVerification($pdo, $userId);
    if (($verification['status'] ?? '') === 'error') {
        http_response_code(500);
        exit(htmlspecialchars($verification['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
    if (($verification['status'] ?? '') !== 'approved') {
        http_response_code(403);
        exit('Your college ID must be approved before you can view or claim gigs.');
    }

    return true;
}
