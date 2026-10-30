<?php
// Student portal for profile, applications, scheduled shifts, earnings, ratings, and disputes.
session_start();
mysqli_report(MYSQLI_REPORT_OFF);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: homepage.php');
    exit;
}

$studentId = (int) $_SESSION['user_id'];
$studentCsrfToken = (string) ($_SESSION['student_csrf_token'] ?? '');
if ($studentCsrfToken === '') {
    $studentCsrfToken = bin2hex(random_bytes(32));
    $_SESSION['student_csrf_token'] = $studentCsrfToken;
}
$studentFormError = (string) ($_SESSION['student_form_error'] ?? '');
unset($_SESSION['student_form_error']);

$conn = new mysqli('localhost', 'root', '', 'user_database');
if ($conn->connect_error) {
    die('Database connection failed. Import database.sql in phpMyAdmin and start MySQL in XAMPP.');
}
require_once __DIR__ . '/php/require_approved.php';
$studentVerification = getStudentVerification($pdo, $studentId);
$studentIsApproved = ($studentVerification['status'] ?? '') === 'approved';
if (($studentVerification['status'] ?? '') === 'error' || ($studentVerification['status'] ?? '') === 'unavailable') {
    http_response_code(500);
    exit(htmlspecialchars($studentVerification['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

function submitStudentGigDispute(mysqli $conn, int $applicationId, int $submitterId, string $subject, string $description): bool
{
    $subject = trim($subject);
    $description = trim($description);
    if ($applicationId <= 0 || $submitterId <= 0 || $subject === '' || strlen($subject) > 255 || $description === '' || strlen($description) > 5000) {
        return false;
    }

    $applicationStmt = $conn->prepare(
        'SELECT a.application_id, a.student_id, a.provider_id,
                LEFT(a.full_name, 100) AS student_name,
                LEFT(COALESCE(p.full_name, "Provider"), 100) AS provider_name,
                g.task_title
         FROM gig_database.applications a
         JOIN gig_database.gigs g ON g.gig_id = a.gig_id
         LEFT JOIN user_database.users p ON p.user_id = a.provider_id
         WHERE a.application_id = ? AND a.student_id = ? AND a.status = "Completed"
         LIMIT 1'
    );
    if (!$applicationStmt) {
        return false;
    }
    $applicationStmt->bind_param('ii', $applicationId, $submitterId);
    $applicationStmt->execute();
    $application = $applicationStmt->get_result()->fetch_assoc();
    $applicationStmt->close();
    if (!$application) {
        return false;
    }

    $studentName = (string) $application['student_name'];
    $providerName = (string) $application['provider_name'];
    $studentId = (int) $application['student_id'];
    $providerId = (int) $application['provider_id'];
    $gigTitle = (string) $application['task_title'];
    $submitterRole = 'Student';
    $insertStmt = $conn->prepare(
        'INSERT INTO user_database.disputes
         (issue, student_name, provider_name, application_id, submitter_id, submitter_role,
          student_id, provider_id, subject, description, gig_title)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$insertStmt) {
        return false;
    }
    $insertStmt->bind_param('sssiisiiiss', $subject, $studentName, $providerName, $applicationId, $submitterId, $submitterRole, $studentId, $providerId, $subject, $description, $gigTitle);
    $saved = $insertStmt->execute();
    $insertStmt->close();
    return $saved;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'show_shift_code') {
    // Return a code only for this student's scheduled shift after checking the session token.
    header('Content-Type: application/json; charset=utf-8');
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $applicationId = (int) ($_POST['application_id'] ?? 0);
    if ($postedToken === '' || !hash_equals($studentCsrfToken, $postedToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Your session expired. Refresh the page and try again.']);
        exit;
    }
    if ($applicationId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'This shift could not be found.']);
        exit;
    }

    $codeStmt = $conn->prepare(
        "SELECT s.shift_code
         FROM shift_database.shifts s
         JOIN gig_database.applications a ON a.application_id = s.application_id
             AND a.gig_id = s.gig_id AND a.student_id = s.student_id
         WHERE s.application_id = ? AND s.student_id = ?
             AND a.status = 'Accepted' AND s.status = 'Scheduled'
         LIMIT 1"
    );
    if (!$codeStmt) {
        http_response_code(500);
        echo json_encode(['error' => 'The shift code is temporarily unavailable.']);
        exit;
    }
    $codeStmt->bind_param('ii', $applicationId, $studentId);
    if (!$codeStmt->execute()) {
        $codeStmt->close();
        http_response_code(500);
        echo json_encode(['error' => 'The shift code is temporarily unavailable.']);
        exit;
    }
    $codeRow = $codeStmt->get_result()->fetch_assoc();
    $codeStmt->close();
    if (!$codeRow) {
        http_response_code(404);
        echo json_encode(['error' => 'This shift code is not available yet.']);
        exit;
    }
    echo json_encode(['code' => (string) $codeRow['shift_code']]);
    exit;
}

function studentApplicationStatusClass($status) {
    $status = strtolower((string) $status);
    return $status === 'rejected' ? 'rejected' : ($status === 'pending' ? 'pending' : ($status === 'completed' ? 'completed' : 'reserved'));
}

function studentShiftDateTime($date, $time): string {
    if (!$date) {
        return 'Date not specified';
    }
    $timestamp = strtotime(trim((string) $date . ' ' . (string) ($time ?? '')));
    return $timestamp === false ? 'Date not specified' : date('M j, Y, g:i A', $timestamp);
}

function studentMoney($amount): string {
    $amount = (float) $amount;
    $decimals = round($amount, 2) === round($amount) ? 0 : 2;
    return number_format($amount, $decimals, '.', ',');
}

function studentPortalDatabaseError($details): void {
    error_log('EarNova student portal database operation failed: ' . $details);
    http_response_code(500);
    exit('Student portal data could not be loaded. Please try again later.');
}

$uploadDir = __DIR__ . '/uploads/students';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_student_rating'])) {
    // Ratings are accepted only for completed applications owned by the signed-in student.
    $applicationId = (int) ($_POST['application_id'] ?? 0);
    $rating = (int) ($_POST['rating'] ?? 0);
    $reviewText = trim((string) ($_POST['review_text'] ?? ''));
    $ratingSaved = false;

    if ($applicationId > 0 && $rating >= 1 && $rating <= 5 && strlen($reviewText) <= 2000) {
        $stmt = $conn->prepare('SELECT gig_id, student_id, provider_id, shift_code FROM gig_database.applications WHERE application_id = ? AND student_id = ? AND status = "Completed" LIMIT 1');
        $stmt->bind_param('ii', $applicationId, $_SESSION['user_id']);
        $stmt->execute();
        $applicationRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($applicationRow) {
            $providerId = (int) ($applicationRow['provider_id'] ?? 0);
            $gigId = (int) ($applicationRow['gig_id'] ?? 0);
            $shiftCode = (string) ($applicationRow['shift_code'] ?? '');
            $shiftId = ensureShiftRecord($conn, $applicationId, (int) $_SESSION['user_id'], $providerId, $gigId, $shiftCode);
            if ($shiftId > 0) {
                $existingRating = $conn->prepare('SELECT rating_id FROM rating_database.ratings WHERE shift_id = ? AND reviewer_id = ? LIMIT 1');
                $existingRating->bind_param('ii', $shiftId, $_SESSION['user_id']);
                $existingRating->execute();
                $existingRatingResult = $existingRating->get_result()->fetch_assoc();
                $existingRating->close();
                if (!$existingRatingResult) {
                    $insert = $conn->prepare('INSERT INTO rating_database.ratings (shift_id, gig_id, reviewer_id, reviewed_user_id, rating, review_text) VALUES (?, ?, ?, ?, ?, ?)');
                    $insert->bind_param('iiiiis', $shiftId, $gigId, $_SESSION['user_id'], $providerId, $rating, $reviewText);
                    $ratingSaved = $insert->execute();
                    $insert->close();
                }
            }
        }
    }

    header('Location: student.php?section=history&rating=' . ($ratingSaved ? 'success' : 'failed'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_dispute'])) {
    $saved = submitStudentGigDispute($conn, (int) ($_POST['application_id'] ?? 0), (int) $_SESSION['user_id'], (string) ($_POST['subject'] ?? ''), (string) ($_POST['description'] ?? ''));
    header('Location: student.php?section=disputes&dispute=' . ($saved ? 'success' : 'failed'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student_profile'])) {
    // Validate all profile fields before saving so invalid input cannot partially update the account.
    $newName = trim($_POST['full_name'] ?? '');
    $newEmail = trim($_POST['email'] ?? '');
    $newPhone = trim($_POST['phone'] ?? '');
    $newAddress = trim($_POST['address'] ?? '');
    $newEducation = trim($_POST['education'] ?? '');
    $newCollege = trim($_POST['college'] ?? '');
    $newCity = trim($_POST['city'] ?? '');
    $newSkills = trim($_POST['skills'] ?? '');
    $newBio = trim($_POST['bio'] ?? '');

    $phoneDigits = preg_replace('/\D/', '', $newPhone);
    $profileError = '';
    if ($newName === '' || strlen($newName) > 150) {
        $profileError = 'Enter a name using no more than 150 characters.';
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > 150) {
        $profileError = 'Enter a valid email address using no more than 150 characters.';
    } elseif (
        strlen($newPhone) > 20
        || ($newPhone !== '' && (!preg_match('/^[+()\d\s.-]+$/', $newPhone) || strlen((string) $phoneDigits) < 7 || strlen((string) $phoneDigits) > 15))
    ) {
        $profileError = 'Enter a valid phone number with 7 to 15 digits.';
    } elseif (
        strlen($newAddress) > 255 || strlen($newEducation) > 150
        || strlen($newCollege) > 150 || strlen($newCity) > 100
        || strlen($newSkills) > 255 || strlen($newBio) > 5000
    ) {
        $profileError = 'One or more profile fields exceed their allowed length.';
    }

    $profileImageUpload = $_FILES['profile_image'] ?? null;
    if (
        $profileError === ''
        && $profileImageUpload
        && (int) ($profileImageUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
    ) {
        if ((int) $profileImageUpload['error'] !== UPLOAD_ERR_OK || (int) $profileImageUpload['size'] <= 0 || (int) $profileImageUpload['size'] > 2 * 1024 * 1024) {
            $profileError = 'Choose a JPG or PNG profile image no larger than 2 MB.';
        } else {
            $extension = strtolower(pathinfo((string) $profileImageUpload['name'], PATHINFO_EXTENSION));
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $profileImageUpload['tmp_name']);
            $allowedImageTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
            if (
                !is_uploaded_file((string) $profileImageUpload['tmp_name'])
                || !isset($allowedImageTypes[$extension])
                || $mime !== $allowedImageTypes[$extension]
            ) {
                $profileError = 'Upload a valid JPG or PNG profile image.';
            }
        }
    }

    if ($profileError !== '') {
        $_SESSION['student_form_error'] = $profileError;
        header('Location: student.php?section=profile');
        exit;
    }

    $userUpdate = $conn->prepare('UPDATE users SET full_name = ?, phone = ?, email = ? WHERE user_id = ?');
    $userUpdate->bind_param('sssi', $newName, $newPhone, $newEmail, $studentId);
    $userUpdate->execute();
    $userUpdate->close();

    $profileImagePath = null;
    if ($profileImageUpload && (int) ($profileImageUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo((string) $profileImageUpload['name'], PATHINFO_EXTENSION));
        $fileName = 'student_' . $_SESSION['user_id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $target = $uploadDir . '/' . $fileName;
        if (move_uploaded_file((string) $profileImageUpload['tmp_name'], $target)) {
            $profileImagePath = 'uploads/students/' . $fileName;
        } else {
            $_SESSION['student_form_error'] = 'The profile image could not be saved. Please try again.';
            header('Location: student.php?section=profile');
            exit;
        }
    }

    $studentDetail = $conn->query('SELECT address, education, college, city, skills, bio, profile_image FROM students WHERE user_id = ' . (int) $_SESSION['user_id'] . ' LIMIT 1')->fetch_assoc();
    if (!$profileImagePath && $studentDetail && !empty($studentDetail['profile_image'])) {
        $profileImagePath = $studentDetail['profile_image'];
    }

    if ($studentDetail) {
        $sql = 'UPDATE students SET address = ?, education = ?, college = ?, city = ?, skills = ?, bio = ?';
        $params = [$newAddress, $newEducation, $newCollege, $newCity, $newSkills, $newBio];
        if ($profileImagePath) {
            $sql .= ', profile_image = ?';
            $params[] = $profileImagePath;
        }
        $sql .= ' WHERE user_id = ?';
        $params[] = (int) $_SESSION['user_id'];
        $stmt = $conn->prepare($sql);
        $types = 'ssssss';
        if ($profileImagePath) {
            $types .= 's';
        }
        $types .= 'i';
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    } else {
        $sql = 'INSERT INTO students (user_id, address, education, college, city, skills, bio, profile_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $conn->prepare($sql);
        $profileImagePath = $profileImagePath ?? null;
        $stmt->bind_param('isssssss', $_SESSION['user_id'], $newAddress, $newEducation, $newCollege, $newCity, $newSkills, $newBio, $profileImagePath);
        $stmt->execute();
        $stmt->close();
    }

    $_SESSION['full_name'] = $newName;
    header('Location: student.php?profile=updated');
    exit;
}

function ensureShiftRecord($conn, $applicationId, $studentId, $providerId, $gigId, $shiftCode) {
    // Create a shift row on demand for older completed applications that lack one.
    $check = $conn->prepare('SELECT shift_id FROM shift_database.shifts WHERE application_id = ? LIMIT 1');
    $check->bind_param('i', $applicationId);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();
    if ($existing) {
        return (int) $existing['shift_id'];
    }

    $gigStmt = $conn->prepare('SELECT shift_date, start_time, end_time FROM gig_database.gigs WHERE gig_id = ? LIMIT 1');
    $gigStmt->bind_param('i', $gigId);
    $gigStmt->execute();
    $gigRow = $gigStmt->get_result()->fetch_assoc();
    $gigStmt->close();

    $scheduledDate = $gigRow['shift_date'] ?? date('Y-m-d');
    $startTime = $gigRow['start_time'] ?? '09:00:00';
    $endTime = $gigRow['end_time'] ?? '10:00:00';
    $finalShiftCode = $shiftCode !== '' ? $shiftCode : strtoupper(substr(md5((string) $applicationId), 0, 8));

    $insert = $conn->prepare('INSERT INTO shift_database.shifts (gig_id, application_id, student_id, provider_id, shift_code, scheduled_date, start_time, end_time, status, completed_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "Completed", NOW(), NOW())');
    $insert->bind_param('iiiissss', $gigId, $applicationId, $studentId, $providerId, $finalShiftCode, $scheduledDate, $startTime, $endTime);
    $insert->execute();
    $newShiftId = $insert->insert_id;
    $insert->close();

    return (int) $newShiftId;
}

$user = $conn->query('SELECT full_name, email, phone FROM users WHERE user_id = ' . (int) $_SESSION['user_id'] . ' LIMIT 1')->fetch_assoc();
$studentDetail = $conn->query('SELECT address, education, college, city, skills, bio, profile_image FROM students WHERE user_id = ' . (int) $_SESSION['user_id'] . ' LIMIT 1')->fetch_assoc();

$studentApplications = [];
$studentApplicationsStmt = $conn->prepare(
    'SELECT a.application_id, a.gig_id, a.status, g.task_title, g.location, g.shift_date,
            g.start_time, g.payment_amount,
            COALESCE(NULLIF(p.business_name, \'\'), u.full_name, \'Provider\') AS provider_name,
            s.shift_id, s.status AS shift_status, s.scheduled_date, s.start_time AS shift_start_time,
            e.earning_id, e.amount AS earning_amount, e.payment_status, e.paid_at
     FROM gig_database.applications a
     JOIN gig_database.gigs g ON g.gig_id = a.gig_id
     LEFT JOIN (
         SELECT user_id, MAX(business_name) AS business_name
         FROM providers
         GROUP BY user_id
     ) p ON p.user_id = a.provider_id
     LEFT JOIN users u ON u.user_id = a.provider_id
     LEFT JOIN shift_database.shifts s ON s.application_id = a.application_id
         AND s.gig_id = a.gig_id AND s.student_id = a.student_id
     LEFT JOIN earning_database.earnings e ON e.shift_id = s.shift_id
         AND e.gig_id = a.gig_id AND e.student_id = a.student_id
     WHERE a.student_id = ?
     ORDER BY a.application_id DESC'
);
if (!$studentApplicationsStmt) {
    studentPortalDatabaseError($conn->error);
}
$studentApplicationsStmt->bind_param('i', $studentId);
if (!$studentApplicationsStmt->execute()) {
    $error = $studentApplicationsStmt->error;
    $studentApplicationsStmt->close();
    studentPortalDatabaseError($error);
}
$studentApplications = $studentApplicationsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$studentApplicationsStmt->close();
$studentRatedApplications = [];
$studentRatingStmt = $conn->prepare('SELECT s.application_id FROM rating_database.ratings r JOIN shift_database.shifts s ON s.shift_id = r.shift_id WHERE r.reviewer_id = ?');
if (!$studentRatingStmt) {
    studentPortalDatabaseError($conn->error);
}
$studentRatingStmt->bind_param('i', $studentId);
if (!$studentRatingStmt->execute()) {
    $error = $studentRatingStmt->error;
    $studentRatingStmt->close();
    studentPortalDatabaseError($error);
}
foreach ($studentRatingStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $ratedApplication) {
    $studentRatedApplications[(int) $ratedApplication['application_id']] = true;
}
$studentRatingStmt->close();
$completedEarningsStmt = $conn->prepare(
    "SELECT COUNT(DISTINCT s.shift_id) AS completed_count,
            COALESCE(SUM(e.amount), 0) AS total_earned,
            COALESCE(SUM(CASE WHEN e.payment_status = 'Paid' THEN e.amount ELSE 0 END), 0) AS paid_total
     FROM shift_database.shifts s
     JOIN gig_database.applications a ON a.application_id = s.application_id
         AND a.gig_id = s.gig_id AND a.student_id = s.student_id
     LEFT JOIN earning_database.earnings e ON e.shift_id = s.shift_id
         AND e.gig_id = s.gig_id AND e.student_id = s.student_id
     WHERE s.student_id = ? AND a.status = 'Completed' AND s.status = 'Completed'"
);
$completedStudentCount = 0;
$completedStudentEarnings = 0.0;
$paidStudentEarnings = 0.0;
if (!$completedEarningsStmt) {
    studentPortalDatabaseError($conn->error);
}
$completedEarningsStmt->bind_param('i', $studentId);
if (!$completedEarningsStmt->execute()) {
    $error = $completedEarningsStmt->error;
    $completedEarningsStmt->close();
    studentPortalDatabaseError($error);
}
$completedEarnings = $completedEarningsStmt->get_result()->fetch_assoc();
$completedStudentCount = (int) ($completedEarnings['completed_count'] ?? 0);
$completedStudentEarnings = (float) ($completedEarnings['total_earned'] ?? 0);
$paidStudentEarnings = (float) ($completedEarnings['paid_total'] ?? 0);
$completedEarningsStmt->close();
$perShiftEarnings = $completedStudentCount > 0 ? $completedStudentEarnings / $completedStudentCount : 0;
$feedbackStmt = $conn->prepare('SELECT r.rating, r.review_text, r.created_at, g.task_title, u.full_name AS provider_name FROM rating_database.ratings r JOIN shift_database.shifts s ON s.shift_id = r.shift_id JOIN gig_database.applications a ON a.application_id = s.application_id JOIN gig_database.gigs g ON g.gig_id = a.gig_id LEFT JOIN users u ON u.user_id = r.reviewer_id WHERE a.student_id = ? AND r.reviewer_id = a.provider_id ORDER BY r.created_at DESC');
$feedbackStmt->bind_param('i', $_SESSION['user_id']);
$feedbackStmt->execute();
$providerFeedback = $feedbackStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$feedbackStmt->close();
$studentDisputesStmt = $conn->prepare("SELECT subject, description, gig_title, status, created_at FROM user_database.disputes WHERE submitter_role = 'Student' AND submitter_id = ? ORDER BY created_at DESC");
$studentDisputesStmt->bind_param('i', $_SESSION['user_id']);
$studentDisputesStmt->execute();
$studentDisputes = $studentDisputesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$studentDisputesStmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Student Dashboard | EarNova</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="app.css?v=20261029-3">
    <link rel="stylesheet" href="student.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="page-student">

<aside class="sidebar" id="sidebar">
<div class="brand"><div class="brand-icon"><i class="bi bi-lightning-charge-fill"></i></div>EarNova</div>
<nav>
<a class="nav-link active" data-section="dashboard"><i class="bi bi-grid-1x2-fill"></i>Dashboard</a>
<a class="nav-link" data-section="shift"><i class="bi bi-clock-history"></i>My Shifts</a>
<a class="nav-link" data-section="earnings"><i class="bi bi-wallet2"></i>My Earnings</a>
<a class="nav-link" data-section="history"><i class="bi bi-journal-text"></i>Gig History</a>
<a class="nav-link" data-section="feedback"><i class="bi bi-chat-square-text"></i>Student Feedback</a>
<a class="nav-link" data-section="disputes"><i class="bi bi-exclamation-triangle"></i>Disputes</a>
<a class="nav-link" data-section="profile"><i class="bi bi-person-circle"></i>My Profile</a>
</nav>
</aside>

<main class="main">

<div class="topbar">
<div class="d-flex align-items-center gap-3">
<button class="mobile-menu" id="menuBtn"><i class="bi bi-list fs-4"></i></button>
<div>
<h3 class="mb-0">Student Dashboard</h3>
<small class="text-muted">Manage your gigs and earnings</small>
</div>
</div>
<div class="top-actions">
<div class="profile">
<div class="avatar"><?php echo htmlspecialchars(strtoupper(substr((string) ($user['full_name'] ?? 'S'), 0, 1))); ?></div>
<div class="profile-info">
<div class="fw-semibold small" id="topName"><?php echo htmlspecialchars($user['full_name'] ?? 'Student'); ?></div>
<small class="text-muted">Student</small>
</div>
</div>
<a class="btn btn-light border student-header-logout" href="php/logout.php" aria-label="Logout"><i class="bi bi-box-arrow-right me-1"></i><span>Logout</span></a>
</div>
</div>

<!-- DASHBOARD -->
<section id="dashboard" class="page-section active">

<div class="hero">
<div class="row align-items-center">
<div class="col-12">
<h1>Hi, <?php echo htmlspecialchars($user['full_name'] ?? 'Student'); ?>! 👋</h1>
<p>Welcome to your EarNova student dashboard. Manage your active shifts, earnings and profile.</p>
<div class="location-pill"><i class="bi bi-geo-alt-fill"></i> Location: Solapur</div>
<div class="d-flex gap-2 flex-wrap mt-3">
<button class="btn btn-light fw-semibold" onclick="openSection('shift')"><i class="bi bi-clock me-1"></i>View Active Shift</button>
<button class="btn btn-outline-light fw-semibold" onclick="window.location.href='categories.php'"><i class="bi bi-grid me-1"></i>Browse Categories</button>
</div>
</div>
</div>
</div>

<div class="row g-3">
<div class="col-6 col-xl-3">
<div class="stat-card">
<div class="stat-icon"><i class="bi bi-briefcase"></i></div>
<div class="stat-value"><?php echo $completedStudentCount; ?></div>
<div class="stat-label">Completed Gigs</div>
</div>
</div>
<div class="col-6 col-xl-3">
<div class="stat-card">
<div class="stat-icon"><i class="bi bi-clock"></i></div>
<div class="stat-value">0</div>
<div class="stat-label">Active Shift</div>
</div>
</div>
<div class="col-6 col-xl-3">
<div class="stat-card">
<div class="stat-icon"><i class="bi bi-wallet2"></i></div>
<div class="stat-value">₹<?php echo number_format($completedStudentEarnings, 2, '.', ','); ?></div>
<div class="stat-label">Total Earnings</div>
</div>
</div>
<div class="col-6 col-xl-3">
<div class="stat-card">
<div class="stat-icon"><i class="bi bi-star"></i></div>
<div class="stat-value">4.8</div>
<div class="stat-label">Reliability Rating</div>
</div>
</div>
</div>

<?php
$studentUploadMessage = (string) ($_SESSION['student_id_upload_message'] ?? '');
unset($_SESSION['student_id_upload_message']);
?>
<?php if ($studentUploadMessage !== '') : ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($studentUploadMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
<?php endif; ?>
<?php if ($studentIsApproved) : ?>
    <div class="alert alert-success border-success-subtle" role="status"><strong>College ID approved.</strong> You can browse and claim gigs.</div>
<?php elseif (($studentVerification['status'] ?? '') === 'rejected') : ?>
    <div class="alert alert-danger" role="status">
        <strong>College ID rejected.</strong>
        <?php if (!empty($studentVerification['rejection_reason'])) : ?>
            <?php echo htmlspecialchars((string) $studentVerification['rejection_reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
        <?php else : ?>Please upload a new college ID for review.<?php endif; ?>
        <a class="alert-link ms-1" href="student/upload_id.php">Upload a replacement</a>
    </div>
<?php elseif (!empty($studentVerification['id_card_path'])) : ?>
    <div class="alert alert-warning" role="status"><strong>College ID pending review.</strong> Gig browsing and claiming will be available after an admin approves your ID.</div>
<?php else : ?>
    <div class="alert alert-warning" role="status">
        <strong>College ID required.</strong> Upload your college ID to request access to gigs.
        <a class="alert-link ms-1" href="student/upload_id.php">Upload college ID</a>
    </div>
<?php endif; ?>

<?php if (!empty($studentApplications)) : ?>
<div class="section-title"><h4>My Applications</h4></div>
<div class="panel p-3">
    <?php foreach ($studentApplications as $application) : ?>
        <div class="history-item">
            <div><strong><?php echo htmlspecialchars($application['task_title']); ?></strong><div class="small text-muted"><?php echo htmlspecialchars($application['provider_name'] ?? 'Provider'); ?> • <?php echo htmlspecialchars($application['location']); ?></div></div>
            <div class="text-end">
                <span class="status <?php echo studentApplicationStatusClass($application['status']); ?>"><?php echo htmlspecialchars($application['status']); ?></span>
                <?php if ($application['status'] === 'Accepted' && ($application['shift_status'] ?? '') === 'Scheduled' && (int) ($application['shift_id'] ?? 0) > 0) : ?>
                    <div class="mt-2">
                        <button type="button" class="btn portal-code-button" data-show-shift-code data-application-id="<?php echo (int) $application['application_id']; ?>" data-csrf-token="<?php echo htmlspecialchars($studentCsrfToken); ?>">Show shift code</button>
                        <div class="portal-code-result" hidden aria-live="polite"></div>
                        <button type="button" class="portal-copy-button" data-copy-shift-code hidden><i class="bi bi-copy me-1"></i>Copy code</button>
                        <div class="small text-success mt-2" data-shift-code-copy-message aria-live="polite"></div>
                        <div class="small text-danger mt-2" data-shift-code-error role="alert"></div>
                    </div>
                <?php elseif ($application['status'] === 'Accepted') : ?>
                    <div class="small text-warning mt-1">Your shift is being scheduled. Refresh this page shortly.</div>
                <?php elseif ($application['status'] === 'Verified') : ?>
                    <div class="small text-success mt-1">Shift verified</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</section>

<!-- SHIFT MANAGER -->
<section id="shift" class="page-section portal-section">
<div class="section-title"><div><h4>My Shifts</h4><small class="text-muted">Track upcoming work and provider approvals</small></div></div>
<?php
$upcomingShifts = [];
$awaitingShifts = [];
$cancelledShifts = [];
foreach ($studentApplications as $application) {
    $applicationStatus = (string) ($application['status'] ?? '');
    $shiftStatus = (string) ($application['shift_status'] ?? '');
    if ($applicationStatus === 'Completed' || $shiftStatus === 'Completed') {
        continue;
    } elseif ($applicationStatus === 'Pending') {
        $awaitingShifts[] = $application;
    } elseif (in_array($applicationStatus, ['Accepted', 'Verified'], true)
        && in_array($shiftStatus, ['Scheduled', 'Verified'], true)) {
        $upcomingShifts[] = $application;
    } elseif (in_array($applicationStatus, ['Cancelled', 'Rejected'], true) || $shiftStatus === 'Cancelled') {
        $cancelledShifts[] = $application;
    }
}
?>
<?php foreach ([
    'upcoming' => ['Upcoming — show this code to the shopkeeper', $upcomingShifts],
    'awaiting' => ['Awaiting approval', $awaitingShifts],
    'cancelled' => ['Cancelled / closed shifts', $cancelledShifts],
] as $groupKey => [$groupTitle, $groupApplications]) : ?>
    <?php if ($groupApplications || $groupKey !== 'cancelled') : ?>
        <h5 class="portal-subheading"><i class="bi <?php echo $groupKey === 'upcoming' ? 'bi-check-circle' : ($groupKey === 'awaiting' ? 'bi-hourglass-split' : 'bi-x-circle'); ?>"></i><?php echo htmlspecialchars($groupTitle); ?></h5>
        <?php if ($groupApplications) : ?>
            <?php foreach ($groupApplications as $application) : ?>
                <?php
                $applicationId = (int) $application['application_id'];
                $applicationStatus = (string) ($application['status'] ?? 'Pending');
                $shiftStatus = (string) ($application['shift_status'] ?? '');
                $displayStatus = $shiftStatus === 'Cancelled' ? 'Cancelled' : ($applicationStatus === 'Rejected' ? 'Not selected' : $applicationStatus);
                $canRevealCode = $groupKey === 'upcoming' && $applicationStatus === 'Accepted'
                    && $shiftStatus === 'Scheduled' && (int) ($application['shift_id'] ?? 0) > 0;
                ?>
                <article class="portal-card">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                        <div>
                            <h6 class="portal-shift-title"><?php echo htmlspecialchars($application['task_title']); ?></h6>
                            <div class="portal-shift-meta"><?php echo htmlspecialchars($application['provider_name'] ?? 'Business'); ?> · <?php echo htmlspecialchars($application['location']); ?></div>
                            <div class="portal-shift-meta"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars(studentShiftDateTime($application['scheduled_date'] ?? $application['shift_date'], $application['shift_start_time'] ?? $application['start_time'])); ?></div>
                        </div>
                        <span class="portal-status <?php echo $groupKey === 'completed' ? 'completed' : ($groupKey === 'cancelled' ? 'cancelled' : ''); ?>"><?php echo htmlspecialchars($displayStatus); ?></span>
                    </div>
                    <?php if ($canRevealCode) : ?>
                        <button type="button" class="btn portal-code-button" data-show-shift-code data-application-id="<?php echo $applicationId; ?>" data-csrf-token="<?php echo htmlspecialchars($studentCsrfToken); ?>">
                            <i class="bi bi-hash me-2"></i>Show shift code
                        </button>
                        <div class="portal-code-result" hidden aria-live="polite"></div>
                        <button type="button" class="portal-copy-button" data-copy-shift-code hidden><i class="bi bi-copy me-1"></i>Copy code</button>
                        <div class="small text-success mt-2" data-shift-code-copy-message aria-live="polite"></div>
                        <div class="small text-danger mt-2" data-shift-code-error role="alert"></div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="portal-card portal-empty"><?php echo $groupKey === 'upcoming' ? 'No upcoming shifts.' : 'No shifts awaiting approval.'; ?></div>
        <?php endif; ?>
    <?php endif; ?>
<?php endforeach; ?>
</section>

<!-- EARNINGS -->
<section id="earnings" class="page-section portal-section">
<div class="section-title"><div><h4>My Earnings</h4><small class="text-muted">Your completed work and payment history</small></div></div>
<div class="portal-earned-card mb-4">
<div class="portal-earned-label">TOTAL EARNED</div>
<div class="portal-earned-value">₹<?php echo studentMoney($completedStudentEarnings); ?></div>
<div class="portal-earned-caption"><?php echo $completedStudentCount; ?> completed <?php echo $completedStudentCount === 1 ? 'shift' : 'shifts'; ?></div>
<i class="bi bi-wallet2 portal-earned-icon" aria-hidden="true"></i>
</div>
<div class="row g-3 mb-4">
<div class="col-6 col-lg-3"><div class="portal-earnings-stat"><div class="portal-earnings-label">TOTAL SHIFTS</div><div class="portal-earnings-value"><?php echo $completedStudentCount; ?></div></div></div>
<div class="col-6 col-lg-3"><div class="portal-earnings-stat"><div class="portal-earnings-label">PAID</div><div class="portal-earnings-value">₹<?php echo studentMoney($paidStudentEarnings); ?></div></div></div>
<div class="col-6 col-lg-3"><div class="portal-earnings-stat"><div class="portal-earnings-label">PER SHIFT</div><div class="portal-earnings-value">₹<?php echo studentMoney($perShiftEarnings); ?></div></div></div>
</div>
<div class="portal-card">
<h5 class="portal-shift-title mb-3">History</h5>
<?php
$earningHistory = array_values(array_filter($studentApplications, static function ($application) {
    return ($application['status'] ?? '') === 'Completed'
        && ($application['shift_status'] ?? '') === 'Completed';
}));
?>
<?php if ($earningHistory) : ?>
    <?php foreach ($earningHistory as $earning) : ?>
        <div class="portal-history-row">
            <div><strong><?php echo htmlspecialchars($earning['task_title']); ?></strong><div class="small text-muted"><?php echo htmlspecialchars($earning['provider_name'] ?? 'Business'); ?> · <?php echo htmlspecialchars($earning['location']); ?></div></div>
            <div class="text-muted"><?php echo htmlspecialchars(studentShiftDateTime($earning['scheduled_date'] ?? $earning['shift_date'], $earning['shift_start_time'] ?? $earning['start_time'])); ?></div>
            <div><span class="portal-status <?php echo ($earning['payment_status'] ?? '') === 'Paid' ? 'completed' : ''; ?>"><?php echo htmlspecialchars($earning['payment_status'] ?? 'Not recorded'); ?></span></div>
            <div class="text-end"><strong><?php echo !empty($earning['earning_id']) ? '₹' . studentMoney($earning['earning_amount']) : '—'; ?></strong><div class="small text-muted"><?php echo !empty($earning['earning_id']) ? 'Transaction #' . (int) $earning['earning_id'] : 'Transaction details unavailable'; ?><?php if (!empty($earning['paid_at'])) : ?> · <?php echo htmlspecialchars(date('M j, Y', strtotime($earning['paid_at']))); ?><?php endif; ?></div></div>
        </div>
    <?php endforeach; ?>
<?php else : ?>
    <div class="portal-empty">No completed shift earnings yet.</div>
<?php endif; ?>
</div>
</section>

<!-- HISTORY -->
<section id="history" class="page-section">

<div class="section-title mt-0">
<h4>Gig History</h4>
</div>

<div class="panel p-2">
<div class="table-responsive">
<table class="table student-history-table">
<thead><tr><th>Gig</th><th>Provider</th><th>Location</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php if (!empty($studentApplications)) : ?>
    <?php foreach ($studentApplications as $application) : ?>
        <?php $applicationStatus = $application['status'] ?? 'Pending'; ?>
        <tr>
            <td class="gig-name"><?php echo htmlspecialchars($application['task_title']); ?></td>
            <td><?php echo htmlspecialchars($application['provider_name'] ?? 'Provider'); ?></td>
            <td><?php echo htmlspecialchars($application['location']); ?></td>
            <td>₹<?php echo number_format((float) $application['payment_amount'], 2, '.', ','); ?></td>
            <td><span class="status <?php echo studentApplicationStatusClass($applicationStatus); ?>"><?php echo htmlspecialchars($applicationStatus); ?></span></td>
            <td>
                <?php if ($applicationStatus === 'Completed') : ?>
                    <?php if (empty($studentRatedApplications[(int) $application['application_id']])) : ?>
                        <button type="button" class="btn btn-sm btn-primary-custom mb-2" data-bs-toggle="modal" data-bs-target="#studentRateModal<?php echo (int) $application['application_id']; ?>">Add Feedback / Rate</button>
                    <?php else : ?>
                        <span class="text-success small d-block mb-2">Rated</span>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#studentDisputeModal<?php echo (int) $application['application_id']; ?>">Dispute</button>
                <?php else : ?>
                    <span class="text-muted">-</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
<?php else : ?>
    <tr><td colspan="6" class="p-3 text-muted text-center">No gig applications yet.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</section>

<!-- FEEDBACK -->
<section id="feedback" class="page-section">
<div class="section-title mt-0">
<h4>Provider Feedback</h4>
</div>

<div class="panel p-2">
<div class="table-responsive">
<table class="table student-history-table">
<thead><tr><th>Gig</th><th>Provider</th><th>Rating</th><th>Feedback</th><th>Date</th></tr></thead>
<tbody>
<?php if (!empty($providerFeedback)) : ?>
    <?php foreach ($providerFeedback as $feedback) : ?>
        <tr>
            <td class="gig-name"><?php echo htmlspecialchars($feedback['task_title']); ?></td>
            <td><?php echo htmlspecialchars($feedback['provider_name'] ?? 'Provider'); ?></td>
            <td><?php echo (int) $feedback['rating']; ?> / 5</td>
            <td><?php echo htmlspecialchars(trim((string) ($feedback['review_text'] ?? '')) ?: 'No written feedback.'); ?></td>
            <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($feedback['created_at']))); ?></td>
        </tr>
    <?php endforeach; ?>
<?php else : ?>
    <tr><td colspan="5" class="p-3 text-muted text-center">No provider feedback yet.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</section>

<!-- DISPUTES -->
<section id="disputes" class="page-section">
<div class="section-title mt-0"><h4>My Disputes</h4></div>
<?php if (($_GET['dispute'] ?? '') === 'success') : ?><div class="alert alert-success py-2">Your dispute was submitted for admin review.</div><?php elseif (($_GET['dispute'] ?? '') === 'failed') : ?><div class="alert alert-danger py-2">Unable to submit the dispute. Check the subject and description, then try again.</div><?php endif; ?>
<div class="panel p-2"><div class="table-responsive">
<table class="table student-history-table">
<thead><tr><th>Gig</th><th>Subject</th><th>Description</th><th>Status</th><th>Submitted</th></tr></thead>
<tbody>
<?php if (!empty($studentDisputes)) : ?>
    <?php foreach ($studentDisputes as $dispute) : ?>
        <tr><td class="gig-name"><?php echo htmlspecialchars($dispute['gig_title']); ?></td><td><?php echo htmlspecialchars($dispute['subject']); ?></td><td><?php echo nl2br(htmlspecialchars($dispute['description'])); ?></td><td><span class="status completed"><?php echo htmlspecialchars($dispute['status']); ?></span></td><td><?php echo htmlspecialchars(date('d-m-Y', strtotime($dispute['created_at']))); ?></td></tr>
    <?php endforeach; ?>
<?php else : ?>
    <tr><td colspan="5" class="p-3 text-muted text-center">You have not submitted any disputes.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div></div>
</section>

<!-- PROFILE -->
<section id="profile" class="page-section">

<div class="section-title mt-0">
<div>
<h4>My Profile</h4>
<small class="text-muted">Manage your student information</small>
</div>
<button class="btn btn-primary-custom" onclick="editProfile()" id="editBtn">
<i class="bi bi-pencil me-1"></i>Edit Profile
</button>
</div>

<?php if ($studentFormError !== '') : ?>
    <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($studentFormError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="panel p-4">

<div class="student-profile">
    <?php
        $studentProfileImage = !empty($studentDetail['profile_image']) ? $studentDetail['profile_image'] : '';
        $studentProfileFileExists = $studentProfileImage !== '' && file_exists(__DIR__ . '/' . $studentProfileImage);
    ?>
    <?php if ($studentProfileFileExists) : ?>
        <img id="profileAvatar" src="<?php echo htmlspecialchars($studentProfileImage); ?>" alt="Student Profile" style="width:95px;height:95px;border-radius:25px;object-fit:cover;border:3px solid #fff;box-shadow:0 5px 18px rgba(0,0,0,.12);margin:0 auto 14px;display:block;">
    <?php else : ?>
        <div class="student-avatar" id="profileAvatar"><?php echo strtoupper(substr(htmlspecialchars($user['full_name'] ?? 'S'), 0, 1)); ?></div>
    <?php endif; ?>
    <h4 id="profileName"><?php echo htmlspecialchars($user['full_name'] ?? 'Student'); ?></h4>
    <span class="badge rounded-pill text-bg-light"><?php echo htmlspecialchars($studentDetail['education'] ?? 'Student'); ?></span>
</div>

<div id="profileView" class="row g-3">

<div class="col-md-6">
<div class="info-box">
<div class="info-label">FULL NAME</div>
<div class="info-value" id="viewName"><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></div>
</div>
</div>

<div class="col-md-6">
<div class="info-box">
<div class="info-label">EMAIL</div>
<div class="info-value" id="viewEmail"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
</div>
</div>

<div class="col-md-6">
<div class="info-box">
<div class="info-label">PHONE</div>
<div class="info-value" id="viewPhone"><?php echo htmlspecialchars($user['phone'] ?? ''); ?></div>
</div>
</div>

<div class="col-md-6">
<div class="info-box">
<div class="info-label">CITY</div>
<div class="info-value"><?php echo htmlspecialchars($studentDetail['city'] ?? 'Not added'); ?></div>
</div>
</div>

<div class="col-md-6">
<div class="info-box">
<div class="info-label">EDUCATION</div>
<div class="info-value"><?php echo htmlspecialchars($studentDetail['education'] ?? 'Not added'); ?></div>
</div>
</div>

<div class="col-md-6">
<div class="info-box">
<div class="info-label">COLLEGE / SCHOOL</div>
<div class="info-value"><?php echo htmlspecialchars($studentDetail['college'] ?? 'Not added'); ?></div>
</div>
</div>

<div class="col-md-6">
<div class="info-box">
<div class="info-label">SKILLS</div>
<div class="info-value"><?php echo htmlspecialchars($studentDetail['skills'] ?? 'Not added'); ?></div>
</div>
</div>

<div class="col-md-6">
<div class="info-box">
<div class="info-label">ADDRESS</div>
<div class="info-value"><?php echo htmlspecialchars($studentDetail['address'] ?? 'Not added'); ?></div>
</div>
</div>

<div class="col-12">
<div class="info-box">
<div class="info-label">BIO</div>
<div class="info-value"><?php echo !empty($studentDetail['bio']) ? nl2br(htmlspecialchars($studentDetail['bio'])) : 'Not added'; ?></div>
</div>
</div>

</div>

<div id="profileEdit" style="display:none" class="row g-3">
<form method="POST" enctype="multipart/form-data" class="w-100">
    <input type="hidden" name="save_student_profile" value="1">
    <div class="col-md-6">
        <label class="form-label fw-semibold">Full Name</label>
        <input name="full_name" id="editName" class="form-control" maxlength="150" required value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-semibold">Email</label>
        <input name="email" id="editEmail" type="email" class="form-control" maxlength="150" required value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-semibold">Phone</label>
        <input name="phone" id="editPhone" type="tel" class="form-control" maxlength="20" pattern="[+()0-9 .-]{7,20}" title="Enter a phone number with 7 to 15 digits." value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-semibold">City</label>
        <input name="city" class="form-control" maxlength="100" value="<?php echo htmlspecialchars($studentDetail['city'] ?? ''); ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-semibold">Education</label>
        <input name="education" class="form-control" maxlength="150" value="<?php echo htmlspecialchars($studentDetail['education'] ?? ''); ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-semibold">College / School</label>
        <input name="college" class="form-control" maxlength="150" value="<?php echo htmlspecialchars($studentDetail['college'] ?? ''); ?>">
    </div>

    <div class="col-12">
        <label class="form-label fw-semibold">Address</label>
        <input name="address" class="form-control" maxlength="255" value="<?php echo htmlspecialchars($studentDetail['address'] ?? ''); ?>">
    </div>

    <div class="col-12">
        <label class="form-label fw-semibold">Skills</label>
        <input name="skills" class="form-control" maxlength="255" value="<?php echo htmlspecialchars($studentDetail['skills'] ?? ''); ?>">
    </div>

    <div class="col-12">
        <label class="form-label fw-semibold">Short Bio</label>
        <textarea name="bio" class="form-control" rows="3" maxlength="5000"><?php echo htmlspecialchars($studentDetail['bio'] ?? ''); ?></textarea>
    </div>

    <div class="col-12">
        <label class="form-label fw-semibold">Profile Image</label>
        <input type="file" name="profile_image" class="form-control" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
    </div>

    <div class="col-12 d-flex justify-content-end gap-2">
        <button type="button" class="btn btn-light" onclick="cancelEdit()">Cancel</button>
        <button type="submit" class="btn btn-primary-custom">Save Profile</button>
    </div>
</form>
</div>
</div>
</section>

</main>

<?php foreach ($studentApplications as $application) : ?>
    <?php if (($application['status'] ?? '') !== 'Completed') continue; ?>
    <?php $applicationId = (int) $application['application_id']; ?>
    <?php if (empty($studentRatedApplications[$applicationId])) : ?>
        <div class="modal fade" id="studentRateModal<?php echo $applicationId; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                <div class="modal-header"><div><h5 class="modal-title">Rate provider</h5><small class="text-muted"><?php echo htmlspecialchars($application['provider_name'] ?? 'Provider'); ?> · <?php echo htmlspecialchars($application['task_title']); ?></small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <form method="POST">
                    <input type="hidden" name="application_id" value="<?php echo $applicationId; ?>">
                    <div class="modal-body">
                        <div class="star-rating-control">
                            <fieldset class="star-rating" aria-label="Rate provider">
                                <legend class="visually-hidden">Choose a rating from one to five stars</legend>
                                <?php foreach ([5 => 'Excellent', 4 => 'Good', 3 => 'Fair', 2 => 'Poor', 1 => 'Very Poor'] as $ratingValue => $ratingLabel) : ?>
                                    <input type="radio" id="providerRating<?php echo $applicationId; ?>-<?php echo $ratingValue; ?>" name="rating" value="<?php echo $ratingValue; ?>" data-rating-label="<?php echo $ratingLabel; ?>" <?php echo $ratingValue === 5 ? 'required' : ''; ?>>
                                    <label for="providerRating<?php echo $applicationId; ?>-<?php echo $ratingValue; ?>" data-rating-label="<?php echo $ratingLabel; ?>" title="<?php echo $ratingLabel; ?>" aria-label="<?php echo $ratingValue; ?> stars, <?php echo $ratingLabel; ?>"><i class="bi bi-star-fill" aria-hidden="true"></i></label>
                                <?php endforeach; ?>
                            </fieldset>
                            <span class="star-rating-hint" aria-live="polite">Choose a rating</span>
                        </div>
                        <label class="form-label fw-semibold mt-3" for="studentReview<?php echo $applicationId; ?>">Feedback</label>
                        <textarea id="studentReview<?php echo $applicationId; ?>" name="review_text" class="form-control" rows="4" maxlength="2000" placeholder="Share feedback about this provider"></textarea>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" name="submit_student_rating" class="btn btn-primary-custom">Save Feedback</button></div>
                </form>
            </div></div>
        </div>
    <?php endif; ?>
    <div class="modal fade" id="studentDisputeModal<?php echo $applicationId; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><div><h5 class="modal-title">Submit a dispute</h5><small class="text-muted"><?php echo htmlspecialchars($application['task_title']); ?></small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <form method="POST">
                <input type="hidden" name="application_id" value="<?php echo $applicationId; ?>">
                <div class="modal-body">
                    <label class="form-label fw-semibold" for="studentDisputeSubject<?php echo $applicationId; ?>">Subject</label>
                    <input id="studentDisputeSubject<?php echo $applicationId; ?>" name="subject" class="form-control mb-3" maxlength="255" required>
                    <label class="form-label fw-semibold" for="studentDisputeDescription<?php echo $applicationId; ?>">Description</label>
                    <textarea id="studentDisputeDescription<?php echo $applicationId; ?>" name="description" class="form-control" rows="5" maxlength="5000" required></textarea>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" name="submit_dispute" value="1" class="btn btn-primary-custom">Submit Dispute</button></div>
            </form>
        </div></div>
    </div>
<?php endforeach; ?>

<script>
const links=document.querySelectorAll(".nav-link[data-section]");
const sections=document.querySelectorAll(".page-section");

links.forEach(link=>{
link.addEventListener("click",(event)=>{
event.preventDefault();
openSection(link.dataset.section);
document.getElementById("sidebar").classList.remove("show");
});
});

function openSection(id){
const activeSection=document.getElementById(id);
if(!activeSection||!activeSection.classList.contains("page-section")) return;
sections.forEach(section=>section.classList.remove("active"));
activeSection.classList.add("active");
links.forEach(link=>link.classList.toggle("active",link.dataset.section===id));
document.body.classList.toggle("portal-mode",["shift","earnings"].includes(id));
window.scrollTo({top:0,behavior:"smooth"});
}

const requestedSection = new URLSearchParams(window.location.search).get('section');
if (requestedSection && document.getElementById(requestedSection)?.classList.contains('page-section')) {
    openSection(requestedSection);
}

document.getElementById("menuBtn").onclick=()=>{
document.getElementById("sidebar").classList.toggle("show");
};
document.querySelectorAll("[data-show-shift-code]").forEach(function(button){
    button.addEventListener("click",async function(){
        const card=button.closest(".portal-card");
        const result=card.querySelector(".portal-code-result");
        const error=card.querySelector("[data-shift-code-error]");
        button.disabled=true;
        error.textContent="";
        try{
            const formData=new FormData();
            formData.append("action","show_shift_code");
            formData.append("application_id",button.dataset.applicationId);
            formData.append("csrf_token",button.dataset.csrfToken);
            const response=await fetch("student.php",{method:"POST",body:formData,credentials:"same-origin"});
            const payload=await response.json();
            if(!response.ok) throw new Error(payload.error||"The shift code is temporarily unavailable.");
            result.textContent=payload.code;
            result.hidden=false;
            button.hidden=true;
            card.querySelector("[data-copy-shift-code]").hidden=false;
        }catch(requestError){
            error.textContent=requestError.message||"The shift code is temporarily unavailable.";
            button.disabled=false;
        }
    });
});

document.querySelectorAll("[data-copy-shift-code]").forEach(function(button){
    button.addEventListener("click",async function(){
        const card=button.closest(".portal-card");
        const code=card.querySelector(".portal-code-result").textContent;
        const message=card.querySelector("[data-shift-code-copy-message]");
        try{
            if(!navigator.clipboard) throw new Error("Copy is not available in this browser.");
            await navigator.clipboard.writeText(code);
            message.textContent="Shift code copied.";
        }catch(copyError){
            message.textContent=copyError.message||"Unable to copy the shift code.";
        }
    });
});

function editProfile(){
document.getElementById("profileView").style.display="none";
document.getElementById("profileEdit").style.display="flex";
document.getElementById("editBtn").style.display="none";
}

function cancelEdit(){
document.getElementById("profileView").style.display="flex";
document.getElementById("profileEdit").style.display="none";
document.getElementById("editBtn").style.display="block";
}

document.querySelectorAll('.star-rating-control').forEach(function (control) {
    const hint = control.querySelector('.star-rating-hint');
    const radios = control.querySelectorAll('input[name="rating"]');
    const labels = control.querySelectorAll('label[data-rating-label]');
    const updateHint = function () {
        const selected = control.querySelector('input[name="rating"]:checked');
        hint.textContent = selected ? selected.value + ' / 5 - ' + selected.dataset.ratingLabel : 'Choose a rating';
    };

    radios.forEach(function (radio) {
        radio.addEventListener('change', updateHint);
        radio.addEventListener('focus', function () {
            hint.textContent = radio.value + ' / 5 - ' + radio.dataset.ratingLabel;
        });
    });
    labels.forEach(function (label) {
        label.addEventListener('mouseenter', function () {
            hint.textContent = label.dataset.ratingLabel;
        });
    });
    control.addEventListener('mouseleave', updateHint);
    control.addEventListener('focusout', updateHint);
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>