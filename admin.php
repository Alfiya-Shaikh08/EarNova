<?php
// Admin dashboard for reviewing accounts, managing categories, and handling platform records.
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header('Location: homepage.php');
    exit;
}

function connectDb() {
    $conn = new mysqli("localhost", "root", "", "user_database");
    if ($conn->connect_error) {
        die("Database connection failed");
    }
    return $conn;
}

$conn = connectDb();

$uploadFolder = __DIR__ . "/uploads/categories";
if (!is_dir($uploadFolder)) {
    mkdir($uploadFolder, 0777, true);
}

// Make gigs available once their provider is approved; pending gigs are otherwise hidden.
$conn->query("
    UPDATE gig_database.gigs g
    INNER JOIN users u ON u.user_id = g.provider_id
    SET g.status = 'Active', g.rejection_reason = NULL
    WHERE g.status = 'Pending' AND u.role = 'Provider' AND u.status = 'Active'
");

$successMessage = "";
$errorMessage = "";
$categoryNameValue = "";

$flashMessage = $_SESSION["admin_flash"] ?? null;
if ($flashMessage && isset($flashMessage["type"])) {
    unset($_SESSION["admin_flash"]);
    if ($flashMessage["type"] === "success") {
        $successMessage = $flashMessage["text"] ?? "";
    } else {
        $errorMessage = $flashMessage["text"] ?? "";
    }
}

if (empty($_SESSION['admin_student_verification_csrf'])) {
    // Keep verification form tokens in the session to reject forged review submissions.
    $_SESSION['admin_student_verification_csrf'] = bin2hex(random_bytes(32));
}
$studentVerificationCsrf = (string) $_SESSION['admin_student_verification_csrf'];
$studentVerificationStatuses = ['pending', 'approved', 'rejected'];
$studentVerificationStatus = (string) ($_GET['student_status'] ?? 'pending');
if (!in_array($studentVerificationStatus, $studentVerificationStatuses, true)) {
    $studentVerificationStatus = 'pending';
}
if (empty($_SESSION['admin_provider_verification_csrf'])) {
    $_SESSION['admin_provider_verification_csrf'] = bin2hex(random_bytes(32));
}
$providerVerificationCsrf = (string) $_SESSION['admin_provider_verification_csrf'];
$providerVerificationStatuses = ['pending', 'approved', 'rejected'];
$providerVerificationStatus = (string) ($_GET['provider_status'] ?? 'pending');
if (!in_array($providerVerificationStatus, $providerVerificationStatuses, true)) {
    $providerVerificationStatus = 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_student_id'])) {
    // Validate the decision and token before changing a student's verification state.
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $studentUserId = (int) ($_POST['student_user_id'] ?? 0);
    $decision = (string) ($_POST['decision'] ?? '');
    $reason = trim((string) ($_POST['rejection_reason'] ?? ''));
    $reviewError = '';

    if ($postedToken === '' || !hash_equals($studentVerificationCsrf, $postedToken)) {
        $reviewError = 'Your form session expired. Refresh and try again.';
    } elseif ($studentUserId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
        $reviewError = 'The verification request is invalid.';
    } elseif ($decision === 'reject' && ($reason === '' || strlen($reason) > 2000)) {
        $reviewError = 'Enter a rejection reason of 1 to 2,000 characters.';
    } else {
        $transactionStarted = false;
        try {
            if (!$conn->begin_transaction()) {
                throw new RuntimeException('Student verification transaction could not start: ' . $conn->error);
            }
            $transactionStarted = true;
            $pendingStmt = $conn->prepare(
                "SELECT s.student_id
                 FROM students s
                 INNER JOIN users u ON u.user_id = s.user_id
                 WHERE s.user_id = ? AND u.role = 'Student'
                     AND s.verification_status = 'pending'
                     AND s.id_card_path IS NOT NULL
                 LIMIT 1
                 FOR UPDATE"
            );
            if (!$pendingStmt) {
                throw new RuntimeException('Student verification lookup could not be prepared: ' . $conn->error);
            }
            $pendingStmt->bind_param('i', $studentUserId);
            if (!$pendingStmt->execute()) {
                throw new RuntimeException('Student verification lookup failed: ' . $pendingStmt->error);
            }
            $pendingStudent = $pendingStmt->get_result()->fetch_assoc();
            $pendingStmt->close();

            if (!$pendingStudent) {
                $conn->rollback();
                $transactionStarted = false;
                $reviewError = 'This student has no pending ID submission to review.';
            } else {
                if ($decision === 'approve') {
                    $reviewStmt = $conn->prepare(
                        "UPDATE students
                         SET verification_status = 'approved', approved_by = ?,
                             reviewed_at = NOW(), rejection_reason = NULL
                         WHERE user_id = ? AND verification_status = 'pending'"
                    );
                    if ($reviewStmt) {
                        $adminUserId = (int) $_SESSION['user_id'];
                        $reviewStmt->bind_param('ii', $adminUserId, $studentUserId);
                    }
                } else {
                    $reviewStmt = $conn->prepare(
                        "UPDATE students
                         SET verification_status = 'rejected', approved_by = ?,
                             reviewed_at = NOW(), rejection_reason = ?
                         WHERE user_id = ? AND verification_status = 'pending'"
                    );
                    if ($reviewStmt) {
                        $adminUserId = (int) $_SESSION['user_id'];
                        $reviewStmt->bind_param('isi', $adminUserId, $reason, $studentUserId);
                    }
                }

                if (!$reviewStmt) {
                    throw new RuntimeException('Student verification update could not be prepared: ' . $conn->error);
                }
                if (!$reviewStmt->execute()) {
                    $reviewErrorDetails = $reviewStmt->error;
                    $reviewStmt->close();
                    throw new RuntimeException('Student verification update failed: ' . $reviewErrorDetails);
                }
                $updated = $reviewStmt->affected_rows === 1;
                $reviewStmt->close();

                if (!$updated) {
                    $conn->rollback();
                    $transactionStarted = false;
                    $reviewError = 'The student verification could not be updated. Refresh and try again.';
                } elseif (!$conn->commit()) {
                    throw new RuntimeException('Student verification transaction could not commit: ' . $conn->error);
                } else {
                    $transactionStarted = false;
                    $_SESSION['admin_flash'] = [
                        'type' => 'success',
                        'text' => $decision === 'approve'
                            ? 'Student ID approved.'
                            : 'Student ID rejected. The student may upload a replacement.',
                    ];
                    header('Location: admin.php?section=student-verification&student_status=pending');
                    exit;
                }
            }
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                $conn->rollback();
            }
            error_log('EarNova student ID review failed: ' . $exception->getMessage());
            $reviewError = 'The verification review could not be saved. Check the database setup and try again.';
        }
    }

    if ($reviewError !== '') {
        $_SESSION['admin_flash'] = ['type' => 'error', 'text' => $reviewError];
        header('Location: admin.php?section=student-verification&student_status=' . rawurlencode($studentVerificationStatus));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_provider_id'])) {
    // Provider approval and its related gig activation are committed together.
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $providerUserId = (int) ($_POST['provider_user_id'] ?? 0);
    $decision = (string) ($_POST['decision'] ?? '');
    $reason = trim((string) ($_POST['rejection_reason'] ?? ''));
    $reviewError = '';

    if ($postedToken === '' || !hash_equals($providerVerificationCsrf, $postedToken)) {
        $reviewError = 'Your form session expired. Refresh and try again.';
    } elseif ($providerUserId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
        $reviewError = 'The provider verification request is invalid.';
    } elseif ($decision === 'reject' && ($reason === '' || strlen($reason) > 2000)) {
        $reviewError = 'Enter a rejection reason of 1 to 2,000 characters.';
    } else {
        $transactionStarted = false;
        try {
            if (!$conn->begin_transaction()) {
                throw new RuntimeException('Provider verification transaction could not start: ' . $conn->error);
            }
            $transactionStarted = true;
            $pendingStmt = $conn->prepare(
                "SELECT p.provider_id
                 FROM providers p
                 INNER JOIN users u ON u.user_id = p.user_id
                 WHERE p.user_id = ? AND u.role = 'Provider' AND u.status <> 'Blocked'
                     AND p.verification_status = 'pending'
                     AND TRIM(COALESCE(p.business_name, '')) <> ''
                     AND TRIM(COALESCE(p.business_address, '')) <> ''
                     AND TRIM(COALESCE(p.business_category, '')) <> ''
                 LIMIT 1
                 FOR UPDATE"
            );
            if (!$pendingStmt) {
                throw new RuntimeException('Provider verification lookup could not be prepared: ' . $conn->error);
            }
            $pendingStmt->bind_param('i', $providerUserId);
            if (!$pendingStmt->execute()) {
                throw new RuntimeException('Provider verification lookup failed: ' . $pendingStmt->error);
            }
            $pendingProvider = $pendingStmt->get_result()->fetch_assoc();
            $pendingStmt->close();

            if (!$pendingProvider) {
                $conn->rollback();
                $transactionStarted = false;
                $reviewError = 'This provider has no complete pending verification to review.';
            } else {
                if ($decision === 'approve') {
                    $reviewStmt = $conn->prepare(
                        "UPDATE providers
                         SET verification_status = 'approved', approved_by = ?,
                             reviewed_at = NOW(), rejection_reason = NULL
                         WHERE user_id = ? AND verification_status = 'pending'"
                    );
                    if ($reviewStmt) {
                        $adminUserId = (int) $_SESSION['user_id'];
                        $reviewStmt->bind_param('ii', $adminUserId, $providerUserId);
                    }
                } else {
                    $reviewStmt = $conn->prepare(
                        "UPDATE providers
                         SET verification_status = 'rejected', approved_by = ?,
                             reviewed_at = NOW(), rejection_reason = ?
                         WHERE user_id = ? AND verification_status = 'pending'"
                    );
                    if ($reviewStmt) {
                        $adminUserId = (int) $_SESSION['user_id'];
                        $reviewStmt->bind_param('isi', $adminUserId, $reason, $providerUserId);
                    }
                }

                if (!$reviewStmt) {
                    throw new RuntimeException('Provider verification update could not be prepared: ' . $conn->error);
                }
                if (!$reviewStmt->execute()) {
                    $reviewErrorDetails = $reviewStmt->error;
                    $reviewStmt->close();
                    throw new RuntimeException('Provider verification update failed: ' . $reviewErrorDetails);
                }
                $updated = $reviewStmt->affected_rows === 1;
                $reviewStmt->close();

                if (!$updated) {
                    $conn->rollback();
                    $transactionStarted = false;
                    $reviewError = 'The provider verification could not be updated. Refresh and try again.';
                } else {
                    if ($decision === 'approve') {
                        $activateStmt = $conn->prepare(
                            "UPDATE users SET status = 'Active'
                             WHERE user_id = ? AND role = 'Provider' AND status = 'Pending'"
                        );
                        if (!$activateStmt) {
                            throw new RuntimeException('Provider account activation could not be prepared: ' . $conn->error);
                        }
                        $activateStmt->bind_param('i', $providerUserId);
                        if (!$activateStmt->execute()) {
                            throw new RuntimeException('Provider account activation failed: ' . $activateStmt->error);
                        }
                        $activateStmt->close();

                        $activateGigsStmt = $conn->prepare(
                            "UPDATE gig_database.gigs
                             SET status = 'Active', rejection_reason = NULL
                             WHERE provider_id = ? AND status = 'Pending'"
                        );
                        if (!$activateGigsStmt) {
                            throw new RuntimeException('Provider gigs could not be prepared for activation: ' . $conn->error);
                        }
                        $activateGigsStmt->bind_param('i', $providerUserId);
                        if (!$activateGigsStmt->execute()) {
                            throw new RuntimeException('Provider gigs could not be activated: ' . $activateGigsStmt->error);
                        }
                        $activateGigsStmt->close();
                    }

                    if (!$conn->commit()) {
                        throw new RuntimeException('Provider verification transaction could not commit: ' . $conn->error);
                    }
                    $transactionStarted = false;
                    $_SESSION['admin_flash'] = [
                        'type' => 'success',
                        'text' => $decision === 'approve'
                            ? 'Provider approved. Their account and pending gigs are now active.'
                            : 'Provider rejected. The rejection reason has been recorded.',
                    ];
                    header('Location: admin.php?section=provider-verification&provider_status=pending');
                    exit;
                }
            }
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                $conn->rollback();
            }
            error_log('EarNova provider verification review failed: ' . $exception->getMessage());
            $reviewError = 'The provider verification review could not be saved. Check the database setup and try again.';
        }
    }

    if ($reviewError !== '') {
        $_SESSION['admin_flash'] = ['type' => 'error', 'text' => $reviewError];
        header('Location: admin.php?section=provider-verification&provider_status=' . rawurlencode($providerVerificationStatus));
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["add_category"])) {
    // Check both the category name and the uploaded image before storing either.
    $categoryNameValue = trim($_POST["category_name"] ?? "");
    $uploadedFile = $_FILES["category_image"] ?? null;

    if ($categoryNameValue === "" || strlen($categoryNameValue) > 100) {
        $errorMessage = "Enter a category name using no more than 100 characters.";
    } else {
        $duplicateStmt = $conn->prepare("SELECT category_id FROM gig_database.categories WHERE LOWER(category_name) = LOWER(?)");
        $duplicateStmt->bind_param("s", $categoryNameValue);
        $duplicateStmt->execute();
        $duplicateStmt->store_result();

        if ($duplicateStmt->num_rows > 0) {
            $errorMessage = "Duplicate category name. Please use a different name.";
        }
        $duplicateStmt->close();
    }

    if (!$uploadedFile || empty($uploadedFile["name"])) {
        if ($errorMessage === "") {
            $errorMessage = "Category Image is required.";
        }
    } else {
        $fileExt = strtolower(pathinfo($uploadedFile["name"], PATHINFO_EXTENSION));
        if ((int) ($uploadedFile["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($uploadedFile["size"] ?? 0) <= 0) {
            $errorMessage = "Choose a valid JPG, JPEG, or PNG image.";
        } elseif (!in_array($fileExt, ["jpg", "jpeg", "png"], true)) {
            $errorMessage = "Only JPG, JPEG and PNG files are allowed.";
        } elseif ($uploadedFile["size"] > 2 * 1024 * 1024) {
            $errorMessage = "Image size must be less than 2MB.";
        } elseif (!is_uploaded_file((string) ($uploadedFile["tmp_name"] ?? ""))) {
            $errorMessage = "The uploaded category image is invalid.";
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $uploadedFile["tmp_name"]);
            $allowedMimes = ["jpg" => "image/jpeg", "jpeg" => "image/jpeg", "png" => "image/png"];
            if (($allowedMimes[$fileExt] ?? null) !== $mime) {
                $errorMessage = "The category image content does not match its file type.";
            }
        }
    }

    if ($errorMessage === "") {
        $safeName = strtolower(str_replace(" ", "_", preg_replace('/[^A-Za-z0-9 _-]/', '', $categoryNameValue)));
        $fileExt = strtolower(pathinfo($uploadedFile["name"], PATHINFO_EXTENSION));
        $newFileName = time() . "_" . $safeName . "." . $fileExt;
        $targetFile = $uploadFolder . "/" . $newFileName;
        $dbImagePath = "uploads/categories/" . $newFileName;

        if (move_uploaded_file($uploadedFile["tmp_name"], $targetFile)) {
            $insertStmt = $conn->prepare("INSERT INTO gig_database.categories (category_name, category_image, status) VALUES (?, ?, 'Active')");
            $insertStmt->bind_param("ss", $categoryNameValue, $dbImagePath);

            if ($insertStmt->execute()) {
                $categoryNameValue = "";
                $_SESSION["admin_flash"] = ["type" => "success", "text" => "Category added successfully."];
                header("Location: " . $_SERVER["PHP_SELF"]);
                exit;
            } else {
                $errorMessage = "Category could not be saved. Please try again.";
            }
            $insertStmt->close();
        } else {
            $errorMessage = "Image upload failed. Please try again.";
        }
    }

    if ($errorMessage !== "") {
        $_SESSION["admin_flash"] = ["type" => "error", "text" => $errorMessage];
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["toggle_category"])) {
    $categoryId = isset($_POST["category_id"]) ? (int) $_POST["category_id"] : 0;
    if ($categoryId > 0) {
        $statusResult = $conn->query("SELECT status FROM gig_database.categories WHERE category_id = $categoryId LIMIT 1");
        $currentStatus = $statusResult && $statusResult->num_rows > 0 ? $statusResult->fetch_assoc()["status"] : "Active";
        $newStatus = strtolower($currentStatus) === "active" ? "Inactive" : "Active";
        $toggleStmt = $conn->prepare("UPDATE gig_database.categories SET status = ? WHERE category_id = ?");
        $toggleStmt->bind_param("si", $newStatus, $categoryId);
        $toggleStmt->execute();
        $toggleStmt->close();
        $_SESSION["admin_flash"] = ["type" => "success", "text" => "Category status updated successfully."];
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit;
    }
}

$categoriesResult = $conn->query("SELECT * FROM gig_database.categories ORDER BY category_id DESC");
$categories = $categoriesResult ? $categoriesResult->fetch_all(MYSQLI_ASSOC) : [];

$registeredProviders = $conn->query("
    SELECT u.user_id, u.full_name, u.email, u.phone, u.status, u.joining_date,
        pd.business_name, pd.business_address, pd.business_category, pd.business_image,
        pd.verification_status, pd.rejection_reason, pd.reviewed_at
    FROM users u
    LEFT JOIN providers pd ON pd.user_id = u.user_id
    WHERE u.role = 'Provider'
    ORDER BY u.joining_date DESC
");
$registeredProvidersList = $registeredProviders ? $registeredProviders->fetch_all(MYSQLI_ASSOC) : [];
$pendingProviderList = array_values(array_filter(
    $registeredProvidersList,
    static function ($userRow) {
        return strtolower((string) ($userRow['verification_status'] ?? 'pending')) === 'pending';
    }
));
$providerVerificationStmt = $conn->prepare(
    "SELECT u.user_id, u.full_name, u.email, u.phone, u.joining_date,
            p.business_name, p.business_address, p.business_category, p.business_image,
            p.rejection_reason, p.reviewed_at
     FROM providers p
     INNER JOIN users u ON u.user_id = p.user_id
     WHERE u.role = 'Provider' AND p.verification_status = ?
     ORDER BY COALESCE(p.reviewed_at, u.joining_date) DESC"
);
$providersForVerification = [];
$providerVerificationLoadError = '';
if (!$providerVerificationStmt) {
    error_log('EarNova provider verification list query could not be prepared: ' . $conn->error);
    $providerVerificationLoadError = 'Provider verification could not be loaded. Apply the provider verification SQL and try again.';
} else {
    $providerVerificationStmt->bind_param('s', $providerVerificationStatus);
    if (!$providerVerificationStmt->execute()) {
        error_log('EarNova provider verification list query failed: ' . $providerVerificationStmt->error);
        $providerVerificationLoadError = 'Provider verification could not be loaded. Check the database setup and try again.';
    } else {
        $providersForVerification = $providerVerificationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $providerVerificationStmt->close();
}
$studentVerificationStmt = $conn->prepare(
    "SELECT u.user_id, u.full_name, u.email, u.phone, s.id_uploaded_at,
            s.id_card_path, s.rejection_reason, s.reviewed_at
     FROM students s
     INNER JOIN users u ON u.user_id = s.user_id
     WHERE u.role = 'Student' AND s.verification_status = ?
     ORDER BY COALESCE(s.id_uploaded_at, u.joining_date) DESC"
);
$studentsForVerification = [];
$studentVerificationLoadError = '';
if (!$studentVerificationStmt) {
    error_log('EarNova verification list query could not be prepared: ' . $conn->error);
    $studentVerificationLoadError = 'Student verification could not be loaded. Check the database setup and try again.';
} else {
    $studentVerificationStmt->bind_param('s', $studentVerificationStatus);
    if (!$studentVerificationStmt->execute()) {
        error_log('EarNova verification list query failed: ' . $studentVerificationStmt->error);
        $studentVerificationLoadError = 'Student verification could not be loaded. Check the database setup and try again.';
    } else {
        $studentsForVerification = $studentVerificationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $studentVerificationStmt->close();
}
$activeAdminSection = (string) ($_GET['section'] ?? 'dashboard');
$adminSections = ['dashboard', 'provider-verification', 'student-verification', 'gigs', 'categories', 'applications', 'disputes', 'safety'];
if (!in_array($activeAdminSection, $adminSections, true)) {
    $activeAdminSection = 'dashboard';
}

$gigQuery = "
    SELECT g.gig_id,
           COALESCE(g.task_title, 'Untitled Gig') AS gig_title,
           u.full_name AS provider_name,
           COALESCE(c.category_name, g.category, 'General') AS category,
           COALESCE(g.location, '') AS location,
           COALESCE(g.shift_date, '') AS shift_date,
           COALESCE(g.start_time, '00:00:00') AS start_time,
           COALESCE(g.end_time, '00:00:00') AS end_time,
           COALESCE(g.payment_amount, 0) AS payment_amount,
           COALESCE(g.status, 'Pending') AS status,
           g.rejection_reason
    FROM gig_database.gigs g
    LEFT JOIN users u ON u.user_id = g.provider_id
    LEFT JOIN gig_database.categories c ON c.category_id = g.category_id
    ORDER BY g.gig_id DESC
";
$gigManagementResult = $conn->query($gigQuery);
$gigManagementList = $gigManagementResult ? $gigManagementResult->fetch_all(MYSQLI_ASSOC) : [];

$applicationManagementResult = $conn->query("SELECT a.application_id, a.full_name AS student_name, a.email, a.status, a.shift_code, a.applied_at, u.full_name AS provider_name FROM gig_database.applications a LEFT JOIN users u ON u.user_id = a.provider_id ORDER BY a.application_id DESC");
$applicationManagementList = $applicationManagementResult ? $applicationManagementResult->fetch_all(MYSQLI_ASSOC) : [];
$disputesResult = $conn->query("SELECT dispute_id, COALESCE(NULLIF(subject, ''), issue) AS subject, description, gig_title, student_name, provider_name, submitter_role, status, created_at FROM user_database.disputes ORDER BY created_at DESC");
$disputesList = $disputesResult ? $disputesResult->fetch_all(MYSQLI_ASSOC) : [];

$stats = [
    "users" => 0,
    "gigs" => 0,
    "categories" => 0,
    "disputes" => 0,
];
$statsQueries = [
    "users" => "SELECT COUNT(*) AS total FROM users WHERE role IN ('Student', 'Provider')",
    "gigs" => "SELECT COUNT(*) AS total FROM gig_database.gigs",
    "categories" => "SELECT COUNT(*) AS total FROM gig_database.categories WHERE status = 'Active'",
    "disputes" => "SELECT COUNT(*) AS total FROM disputes WHERE status = 'Open'",
];

foreach ($statsQueries as $key => $query) {
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        $stats[$key] = (int) $result->fetch_assoc()["total"];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Admin Dashboard | EarNova</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="app.css?v=20261025-8">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css?v=20261029-1">
</head>
<body class="page-admin">

<aside class="sidebar" id="sidebar">
<div class="brand"><div class="brand-icon"><i class="bi bi-lightning-charge-fill"></i></div>EarNova</div>
<nav>
<a class="nav-link active" data-section="dashboard"><i class="bi bi-grid-1x2-fill"></i>Dashboard</a>
<a class="nav-link" data-section="provider-verification"><i class="bi bi-person-check-fill"></i>Provider Verification</a>
<a class="nav-link" data-section="student-verification"><i class="bi bi-person-vcard-fill"></i>Student Verification</a>
<a class="nav-link" data-section="gigs"><i class="bi bi-briefcase-fill"></i>Gig Management</a>
<a class="nav-link" data-section="categories"><i class="bi bi-tags-fill"></i>Categories</a>
<a class="nav-link" data-section="applications"><i class="bi bi-file-earmark-person-fill"></i>Applications</a>
<a class="nav-link" data-section="disputes"><i class="bi bi-exclamation-triangle-fill"></i>Disputes</a>
</nav>
</aside>

<main class="main">
<div class="topbar">
<div class="d-flex align-items-center gap-3">
<button class="mobile-menu" id="menuBtn"><i class="bi bi-list fs-4"></i></button>
<div><h3 class="mb-0">Admin Dashboard</h3><small class="text-muted">Control and monitor the EarNova platform</small></div>
</div>
<div class="admin-header-actions">
<div class="profile"><div class="avatar">A</div><div class="profile-info"><div class="fw-semibold small">Admin</div><div class="text-muted" style="font-size:10px">System Controller</div></div></div>
<a class="btn btn-light border admin-header-logout" href="php/logout.php" aria-label="Logout"><i class="bi bi-box-arrow-right me-1"></i><span>Logout</span></a>
</div>
</div>

<?php if (!empty($successMessage)) : ?>
    <div class="alert alert-success small"><?php echo htmlspecialchars($successMessage); ?></div>
<?php endif; ?>
<?php if (!empty($errorMessage)) : ?>
    <div class="alert alert-warning small"><?php echo htmlspecialchars($errorMessage); ?></div>
<?php endif; ?>

<section id="dashboard" class="page-section active">
<div class="hero"><h1>Welcome, Admin 👋</h1><p>Manage users, categories, gigs and platform activities from one place.</p></div>
<div class="row g-3">
<div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="bi bi-people"></i></div><div class="stat-value"><?php echo htmlspecialchars((string) $stats["users"]); ?></div><div class="stat-label">Registered Users</div></div></div>
<div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="bi bi-briefcase"></i></div><div class="stat-value" id="gigCount"><?php echo htmlspecialchars((string) $stats["gigs"]); ?></div><div class="stat-label">Total Gigs</div></div></div>
<div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="bi bi-hourglass-split"></i></div><div class="stat-value"><?php echo htmlspecialchars((string) $stats["categories"]); ?></div><div class="stat-label">Gigs Category</div></div></div>
<div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="bi bi-flag"></i></div><div class="stat-value"><?php echo htmlspecialchars((string) $stats["disputes"]); ?></div><div class="stat-label">Open Disputes</div></div></div>
</div>

<div class="section-title"><h4>Provider Account Verification</h4><a href="#" onclick="openSection('provider-verification');return false;" style="color:#16879a;text-decoration:none;font-size:13px;font-weight:600">Review All Providers</a></div>
<div class="table-card">
<table class="table">
<thead><tr><th>Provider</th><th>Email</th><th>Phone</th><th>Business Details</th><th>Action</th></tr></thead>
<tbody>
<?php $recentPendingProviders = array_slice($pendingProviderList, 0, 5); ?>
<?php if (!empty($recentPendingProviders)) : ?>
    <?php foreach ($recentPendingProviders as $providerRow) : ?>
        <tr>
            <td><?php echo htmlspecialchars($providerRow['full_name'] ?? 'Provider'); ?></td>
            <td><?php echo htmlspecialchars($providerRow['email'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($providerRow['phone'] ?? ''); ?></td>
            <td>
                <?php if (!empty($providerRow['business_image'])) : ?>
                    <img src="<?php echo htmlspecialchars($providerRow['business_image']); ?>" alt="Business" style="width:44px;height:44px;object-fit:cover;border-radius:8px;margin-right:8px;">
                <?php endif; ?>
                <?php echo htmlspecialchars($providerRow['business_name'] ?? ''); ?> ·
                <?php echo htmlspecialchars($providerRow['business_category'] ?? ''); ?> ·
                <?php echo htmlspecialchars($providerRow['business_address'] ?? ''); ?>
            </td>
            <td>
                <a class="btn btn-sm btn-primary-custom" href="admin.php?section=provider-verification&amp;provider_status=pending">Review provider</a>
            </td>
        </tr>
    <?php endforeach; ?>
<?php else : ?>
    <tr><td colspan="5" class="text-muted text-center">No provider accounts are waiting for verification.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</section>

<section id="provider-verification" class="page-section">
<div class="section-title mt-0"><div><h4>Provider Verification</h4><small class="text-muted">Review registered provider accounts and their business details.</small></div></div>
<?php if ($providerVerificationLoadError !== '') : ?>
    <div class="alert alert-warning small"><?php echo htmlspecialchars($providerVerificationLoadError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
<?php endif; ?>
<ul class="nav nav-pills gap-2 my-3">
    <?php foreach ($providerVerificationStatuses as $status) : ?>
        <li class="nav-item"><a class="nav-link<?php echo $providerVerificationStatus === $status ? ' active' : ''; ?>" href="admin.php?section=provider-verification&amp;provider_status=<?php echo rawurlencode($status); ?>"><?php echo ucfirst($status); ?></a></li>
    <?php endforeach; ?>
</ul>
<div class="table-card"><div class="table-responsive">
<table class="table management-table">
<thead>
<tr>
<th>Provider</th>
<th>Email</th>
<th>Phone</th>
<th>Business Details</th>
<th>Registered</th>
<th>Review Status</th>
<th>Review</th>
</tr>
</thead>
<tbody>
<?php if (!empty($providersForVerification)) : ?>
    <?php foreach ($providersForVerification as $providerRow) : ?>
        <tr>
            <td><?php echo htmlspecialchars($providerRow['full_name'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($providerRow['email'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($providerRow['phone'] ?? ''); ?></td>
            <td>
                <?php if (!empty($providerRow['business_image'])) : ?>
                    <img src="<?php echo htmlspecialchars($providerRow['business_image']); ?>" alt="Business" style="width:36px;height:36px;object-fit:cover;border-radius:7px;margin-right:6px;">
                <?php endif; ?>
                <?php echo htmlspecialchars($providerRow['business_name'] ?? ''); ?> ·
                <?php echo htmlspecialchars($providerRow['business_category'] ?? ''); ?> ·
                <?php echo htmlspecialchars($providerRow['business_address'] ?? ''); ?>
            </td>
            <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($providerRow['joining_date'] ?? date('Y-m-d')))); ?></td>
            <td>
                <?php $providerStatusClass = $providerVerificationStatus === 'approved' ? 'active-status' : ($providerVerificationStatus === 'rejected' ? 'rejected-status' : 'pending-status'); ?>
                <span class="status <?php echo $providerStatusClass; ?>"><?php echo htmlspecialchars(ucfirst($providerVerificationStatus)); ?></span>
                <?php if ($providerVerificationStatus === 'rejected' && !empty($providerRow['rejection_reason'])) : ?>
                    <div class="small text-danger mt-1"><?php echo nl2br(htmlspecialchars($providerRow['rejection_reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?></div>
                <?php elseif ($providerVerificationStatus !== 'pending') : ?>
                    <div class="small text-muted mt-1"><?php echo htmlspecialchars($providerRow['reviewed_at'] ?? '—'); ?></div>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($providerVerificationStatus === 'pending') : ?>
                    <?php if (trim((string) ($providerRow['business_name'] ?? '')) !== '' && trim((string) ($providerRow['business_address'] ?? '')) !== '' && trim((string) ($providerRow['business_category'] ?? '')) !== '') : ?>
                    <form method="POST" class="d-flex flex-column gap-2">
                        <input type="hidden" name="review_provider_id" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($providerVerificationCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="provider_user_id" value="<?php echo (int) ($providerRow['user_id'] ?? 0); ?>">
                        <button class="btn btn-sm btn-primary-custom" name="decision" value="approve" type="submit">Approve</button>
                        <textarea class="form-control form-control-sm" name="rejection_reason" rows="2" maxlength="2000" placeholder="Reason required to reject"></textarea>
                        <button class="btn btn-sm btn-outline-danger" name="decision" value="reject" type="submit" onclick="return confirmReject(this.form)">Reject</button>
                    </form>
                    <?php else : ?>
                        <span class="text-muted small">Business details are incomplete.</span>
                    <?php endif; ?>
                <?php else : ?>
                    <span class="text-muted small">—</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
<?php else : ?>
    <tr><td colspan="7" class="text-muted text-center">No <?php echo htmlspecialchars($providerVerificationStatus, ENT_QUOTES, 'UTF-8'); ?> provider verifications.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div></div>
</section>

<section id="student-verification" class="page-section">
<div class="section-title mt-0"><div><h4>Student ID Verifications</h4><small class="text-muted">Review student ID submissions before students can access gigs.</small></div></div>
<?php if ($studentVerificationLoadError !== '') : ?>
    <div class="alert alert-warning small"><?php echo htmlspecialchars($studentVerificationLoadError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
<?php endif; ?>
<ul class="nav nav-pills gap-2 my-3">
    <?php foreach ($studentVerificationStatuses as $status) : ?>
        <li class="nav-item"><a class="nav-link<?php echo $studentVerificationStatus === $status ? ' active' : ''; ?>" href="admin.php?section=student-verification&amp;student_status=<?php echo rawurlencode($status); ?>"><?php echo ucfirst($status); ?></a></li>
    <?php endforeach; ?>
</ul>
<div class="table-card"><div class="table-responsive">
<table class="table management-table">
<thead><tr><th>Student</th><th>Email</th><th>Phone</th><th>Uploaded</th><th>College ID</th><th>Review</th></tr></thead>
<tbody>
<?php if (!empty($studentsForVerification)) : ?>
    <?php foreach ($studentsForVerification as $studentRow) : ?>
        <tr>
            <td><?php echo htmlspecialchars($studentRow['full_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($studentRow['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($studentRow['phone'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($studentRow['id_uploaded_at'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
            <td>
                <?php if (!empty($studentRow['id_card_path'])) : ?>
                    <a class="btn btn-sm btn-outline-primary" href="admin/view_id.php?id=<?php echo (int) $studentRow['user_id']; ?>" target="_blank" rel="noopener">View ID</a>
                <?php else : ?><span class="text-muted">No file</span><?php endif; ?>
            </td>
            <td>
                <?php if ($studentVerificationStatus === 'pending' && !empty($studentRow['id_card_path'])) : ?>
                    <form method="post" class="d-flex flex-column gap-2">
                        <input type="hidden" name="review_student_id" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($studentVerificationCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="student_user_id" value="<?php echo (int) $studentRow['user_id']; ?>">
                        <button class="btn btn-sm btn-primary-custom" name="decision" value="approve" type="submit">Approve</button>
                        <textarea class="form-control form-control-sm" name="rejection_reason" rows="2" maxlength="2000" placeholder="Reason required to reject"></textarea>
                        <button class="btn btn-sm btn-outline-danger" name="decision" value="reject" type="submit" onclick="return confirmReject(this.form)">Reject</button>
                    </form>
                <?php elseif ($studentVerificationStatus === 'rejected') : ?>
                    <span class="text-danger"><?php echo htmlspecialchars($studentRow['rejection_reason'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                <?php else : ?>
                    <span class="text-muted"><?php echo htmlspecialchars($studentRow['reviewed_at'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
<?php else : ?>
    <tr><td colspan="6" class="text-center text-secondary py-4">No <?php echo htmlspecialchars($studentVerificationStatus, ENT_QUOTES, 'UTF-8'); ?> student ID verifications.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div></div>
</section>

<section id="gigs" class="page-section">
<div class="section-title mt-0"><h4>Gig Management</h4></div>
<div class="table-card">
<table class="table management-table">
<thead><tr><th>Gig</th><th>Provider</th><th>Category</th><th>Location</th><th>Start Time</th><th>End Time</th><th>Payment</th><th>Status</th></tr></thead>
<tbody>
<?php if (!empty($gigManagementList)) : ?>
    <?php foreach ($gigManagementList as $gigRow) : ?>
        <?php
            $status = strtoupper((string) ($gigRow['status'] ?? 'Pending'));
            $statusClass = strtolower($status) === 'active' ? 'active-status' : (strtolower($status) === 'pending' ? 'pending-status' : (strtolower($status) === 'rejected' ? 'rejected-status' : 'blocked-status'));
        ?>
        <tr>
            <td><?php echo htmlspecialchars($gigRow['gig_title'] ?? 'Gig'); ?></td>
            <td><?php echo htmlspecialchars($gigRow['provider_name'] ?? 'Provider'); ?></td>
            <td><?php echo htmlspecialchars($gigRow['category'] ?? 'General'); ?></td>
            <td><?php echo htmlspecialchars($gigRow['location'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($gigRow['start_time'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($gigRow['end_time'] ?? ''); ?></td>
            <td>₹<?php echo htmlspecialchars(number_format((float) ($gigRow['payment_amount'] ?? 0), 2, '.', ',')); ?></td>
            <td><span class="status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($status); ?></span></td>
        </tr>
    <?php endforeach; ?>
<?php else : ?>
    <tr><td colspan="8" class="text-muted text-center">No gigs posted yet.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</section>

<section id="categories" class="page-section">
<div class="section-title mt-0"><div><h4>Category Management</h4><small class="text-muted">Admin creates and manages categories used by providers.</small></div><button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#categoryModal"><i class="bi bi-plus-lg me-1"></i>Add Category</button></div>
<div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>Providers cannot create categories. They can only select categories approved by Admin while posting a gig.</div>

<div class="row g-3" id="categoryList">
<?php if (!empty($categories)) : ?>
    <?php foreach ($categories as $category) : ?>
        <?php $isActive = strtolower($category["status"] ?? "Active") === "active"; ?>
        <div class="col-md-4 col-lg-3">
            <div class="category-card">
                <?php if (!empty($category["category_image"])) : ?>
                    <img src="<?php echo htmlspecialchars($category["category_image"]); ?>" alt="<?php echo htmlspecialchars($category["category_name"]); ?>" style="width:100%; height:110px; object-fit:cover; border-radius:10px; margin-top:12px;">
                <?php endif; ?>
                <div class="category-name"><?php echo htmlspecialchars($category["category_name"]); ?></div>
                <div class="category-count"><?php echo htmlspecialchars($category["status"]); ?> category</div>
                <form method="POST" class="mt-3">
                    <input type="hidden" name="toggle_category" value="1">
                    <input type="hidden" name="category_id" value="<?php echo (int) $category["category_id"]; ?>">
                    <button type="submit" class="btn btn-sm <?php echo $isActive ? 'btn-outline-danger' : 'btn-outline-success'; ?>" onclick="this.form.submit();">
                        <?php echo $isActive ? 'Deactivate' : 'Activate'; ?>
                    </button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php else : ?>
    <div class="col-12"><div class="alert alert-info">No category found.</div></div>
<?php endif; ?>
</div>
</section>


<section id="applications" class="page-section">
<div class="section-title mt-0"><h4>Applications</h4></div>
<div class="table-card"><div class="table-responsive">
<table class="table application-table">
<thead><tr><th>Student</th><th>Student Email</th><th>Provider</th><th>Status</th><th>Shift Code</th><th>Applied</th></tr></thead>
<tbody>
<?php if (!empty($applicationManagementList)) : ?>
    <?php foreach ($applicationManagementList as $application) : ?>
        <tr><td><?php echo htmlspecialchars($application['student_name']); ?></td><td class="student-email"><?php echo htmlspecialchars($application['email']); ?></td><td><?php echo htmlspecialchars($application['provider_name'] ?? 'Provider'); ?></td><td><span class="status <?php echo strtolower($application['status']) === 'completed' ? 'completed-status' : (strtolower($application['status']) === 'rejected' ? 'rejected-status' : (strtolower($application['status']) === 'pending' ? 'pending-status' : 'active-status')); ?>"><?php echo htmlspecialchars($application['status']); ?></span></td><td><?php echo htmlspecialchars($application['shift_code'] ?: '-'); ?></td><td><?php echo htmlspecialchars($application['applied_at']); ?></td></tr>
    <?php endforeach; ?>
<?php else : ?>
    <tr><td colspan="6" class="text-muted text-center">No applications submitted yet.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div></div>
</section>

<section id="disputes" class="page-section">
<div class="section-title mt-0"><h4>Dispute Resolution</h4></div>
<div class="table-card">
<table class="table management-table">
<thead><tr><th>Subject</th><th>Gig</th><th>Submitted By</th><th>Student</th><th>Provider</th><th>Description</th><th>Status</th><th>Date</th></tr></thead>
<tbody>
<?php if (!empty($disputesList)) : ?>
    <?php foreach ($disputesList as $dispute) : ?>
        <tr>
            <td><?php echo htmlspecialchars($dispute['subject']); ?></td>
            <td><?php echo htmlspecialchars($dispute['gig_title'] ?: '-'); ?></td>
            <td><?php echo htmlspecialchars($dispute['submitter_role'] ?: 'Legacy'); ?></td>
            <td><?php echo htmlspecialchars($dispute['student_name'] ?: '-'); ?></td>
            <td><?php echo htmlspecialchars($dispute['provider_name'] ?: '-'); ?></td>
            <td><?php echo nl2br(htmlspecialchars($dispute['description'] ?? '')); ?></td>
            <td><span class="status <?php echo strtolower($dispute['status']) === 'open' ? 'pending-status' : 'completed-status'; ?>"><?php echo htmlspecialchars($dispute['status']); ?></span></td>
            <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($dispute['created_at']))); ?></td>
        </tr>
    <?php endforeach; ?>
<?php else : ?>
    <tr><td colspan="8" class="text-muted text-center p-3">No disputes have been submitted.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</section>

<section id="safety" class="page-section">
<div class="section-title mt-0"><h4>Safety & Reliability Audit</h4></div>
<div class="row g-3">
<div class="col-md-4"><div class="analytics-card"><div class="muted">Average Student Reliability</div><div class="metric mt-2">4.6 / 5</div><p class="text-muted small">Based on completed shifts and ratings.</p></div></div>
<div class="col-md-4"><div class="analytics-card"><div class="muted">Flagged Accounts</div><div class="metric mt-2">4</div><p class="text-muted small">Accounts requiring admin review.</p></div></div>
<div class="col-md-4"><div class="analytics-card"><div class="muted">Verified Shifts</div><div class="metric mt-2">94%</div><p class="text-muted small">Shifts verified using student shift codes.</p></div></div>
</div>
<div class="table-card mt-3">
<table class="table">
<thead><tr><th>User</th><th>Role</th><th>Reliability</th><th>Flag</th><th>Action</th></tr></thead>
<tbody>
<tr><td>Student A</td><td>Student</td><td>4.8/5</td><td>None</td><td><button class="btn btn-sm btn-outline-success">Clear</button></td></tr>
<tr><td>Provider B</td><td>Provider</td><td>3.1/5</td><td>Payment complaint</td><td><button class="btn btn-sm btn-primary-custom">Review</button></td></tr>
</tbody>
</table>
</div>
</section>
</main>

<div class="modal fade" id="categoryModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<div class="modal-header"><div><h5 class="modal-title">Add New Category</h5><small class="text-muted">Create a category for providers to use.</small></div><button class="btn-close" data-bs-dismiss="modal"></button></div>
<form method="POST" enctype="multipart/form-data">
<div class="modal-body">
<label class="form-label fw-semibold">Category Name</label>
<input type="text" name="category_name" class="form-control" maxlength="100" required placeholder="e.g. Graphic Design" value="<?php echo htmlspecialchars($categoryNameValue); ?>">
<label class="form-label fw-semibold mt-3">Category Image</label>
<input type="file" name="category_image" id="categoryImageInput" class="form-control" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
<div class="mt-3">
    <img id="categoryImagePreview" src="#" alt="Image Preview" style="display:none; max-width:120px; max-height:120px; object-fit:cover; border-radius:10px; border:1px solid #e4eef0;">
</div>
</div>
<div class="modal-footer border-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" name="add_category" class="btn btn-primary-custom">Add Category</button></div>
</form>
</div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const links=document.querySelectorAll(".nav-link[data-section]"),sections=document.querySelectorAll(".page-section");
links.forEach(l=>l.onclick=()=>openSection(l.dataset.section));
function openSection(id){sections.forEach(s=>s.classList.remove("active"));document.getElementById(id).classList.add("active");links.forEach(l=>l.classList.toggle("active",l.dataset.section===id));sidebar.classList.remove("show")}
menuBtn.onclick=()=>sidebar.classList.toggle("show");
openSection(<?php echo json_encode($activeAdminSection); ?>);

function confirmReject(form) {
    const reason = form.querySelector('[name="rejection_reason"]').value.trim();
    if (!reason) {
        form.querySelector('[name="rejection_reason"]').focus();
        form.querySelector('[name="rejection_reason"]').setCustomValidity('Enter a rejection reason.');
        form.querySelector('[name="rejection_reason"]').reportValidity();
        return false;
    }
    return confirm('Reject this verification and save the reason?');
}
document.querySelectorAll('[name="rejection_reason"]').forEach(field => field.addEventListener('input', () => field.setCustomValidity('')));

const categoryImageInput = document.getElementById("categoryImageInput");
const categoryImagePreview = document.getElementById("categoryImagePreview");
if (categoryImageInput && categoryImagePreview) {
    categoryImageInput.addEventListener("change", function () {
        const file = this.files[0];
        if (!file) {
            categoryImagePreview.style.display = "none";
            categoryImagePreview.src = "#";
            return;
        }

        const reader = new FileReader();
        reader.onload = function (e) {
            categoryImagePreview.src = e.target.result;
            categoryImagePreview.style.display = "block";
        };
        reader.readAsDataURL(file);
    });
}

</script>
</body>
</html>