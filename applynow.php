<?php
// Accepts student applications only after checking account approval and gig availability.
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/php/require_approved.php';
if (!studentHasApprovedId($pdo, (int) $_SESSION['user_id'])) {
    header('Location: student.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['gig_id'])) {
    // Check submitted values on the server because browser-side form rules can be bypassed.
    $gigId = (int) ($_POST['gig_id'] ?? 0);
    $studentId = (int) $_SESSION['user_id'];
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $skills = trim((string) ($_POST['skills'] ?? ''));
    $experience = trim((string) ($_POST['experience'] ?? ''));
    $availability = trim((string) ($_POST['availability'] ?? ''));

    $phoneDigits = preg_replace('/\D/', '', $phone);
    if (
        $gigId <= 0
        || $fullName === '' || strlen($fullName) > 150
        || !preg_match('/^[+()\d\s.-]{7,20}$/', $phone) || strlen((string) $phoneDigits) < 7 || strlen((string) $phoneDigits) > 15
        || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150
        || $skills === '' || strlen($skills) > 2000
        || strlen($experience) > 5000
        || $availability !== 'Available'
        || ($_POST['confirm_information'] ?? '') !== '1'
        || ($_POST['accept_terms'] ?? '') !== '1'
    ) {
        $_SESSION['application_message'] = ['type' => 'error', 'text' => 'Please complete all required information.'];
        header('Location: applynow.php?id=' . $gigId);
        exit;
    }

    $applicationConn = new mysqli('localhost', 'root', '', 'user_database');
    if ($applicationConn->connect_error) {
        $_SESSION['application_message'] = ['type' => 'error', 'text' => 'Database connection failed.'];
        header('Location: applynow.php?id=' . $gigId);
        exit;
    }

    if (!$applicationConn->begin_transaction()) {
        $applicationConn->close();
        $_SESSION['application_message'] = ['type' => 'error', 'text' => 'Unable to submit your application right now. Please try again.'];
        header('Location: applynow.php?id=' . $gigId);
        exit;
    }

    // Lock and recheck the student and gig inside the transaction before accepting the application.
    $verificationStmt = $applicationConn->prepare(
        "SELECT s.student_id
         FROM students s
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE s.user_id = ? AND u.role = 'Student' AND u.status = 'Active'
             AND s.verification_status = 'approved'
         LIMIT 1
         FOR UPDATE"
    );
    if (!$verificationStmt) {
        $applicationConn->rollback();
        $applicationConn->close();
        $_SESSION['student_portal_flash'] = ['type' => 'error', 'message' => 'Your college ID verification could not be checked. Please try again.'];
        header('Location: student.php');
        exit;
    }
    $verificationStmt->bind_param('i', $studentId);
    if (!$verificationStmt->execute()) {
        $verificationStmt->close();
        $applicationConn->rollback();
        $applicationConn->close();
        $_SESSION['student_portal_flash'] = ['type' => 'error', 'message' => 'Your college ID verification could not be checked. Please try again.'];
        header('Location: student.php');
        exit;
    }
    $verifiedStudent = $verificationStmt->get_result()->fetch_assoc();
    $verificationStmt->close();
    if (!$verifiedStudent) {
        $applicationConn->rollback();
        $applicationConn->close();
        $_SESSION['student_portal_flash'] = ['type' => 'error', 'message' => 'Your college ID must be approved before you can claim gigs.'];
        header('Location: student.php');
        exit;
    }

    $gigStmt = $applicationConn->prepare(
        "SELECT g.provider_id, g.status
         FROM gig_database.gigs g
         INNER JOIN users u ON u.user_id = g.provider_id
             AND u.role = 'Provider' AND u.status = 'Active'
         WHERE g.gig_id = ? LIMIT 1 FOR UPDATE"
    );
    if (!$gigStmt) {
        $applicationConn->rollback();
        $applicationConn->close();
        $_SESSION['application_message'] = ['type' => 'error', 'text' => 'Unable to check this gig right now. Please try again.'];
        header('Location: applynow.php?id=' . $gigId);
        exit;
    }
    $gigStmt->bind_param('i', $gigId);
    if (!$gigStmt->execute()) {
        $gigStmt->close();
        $applicationConn->rollback();
        $applicationConn->close();
        $_SESSION['application_message'] = ['type' => 'error', 'text' => 'Unable to check this gig right now. Please try again.'];
        header('Location: applynow.php?id=' . $gigId);
        exit;
    }
    $gig = $gigStmt->get_result()->fetch_assoc();
    $gigStmt->close();

    if (!$gig || strtolower((string) $gig['status']) !== 'active') {
        $applicationConn->rollback();
        $applicationConn->close();
        $_SESSION['application_message'] = ['type' => 'error', 'text' => 'This gig is no longer open for applications.'];
        header('Location: applynow.php?id=' . $gigId);
        exit;
    }

    $claimedStmt = $applicationConn->prepare(
        "SELECT application_id, status
         FROM gig_database.applications
         WHERE gig_id = ? AND status IN ('Pending', 'Accepted', 'Verified', 'Completed')
         LIMIT 1"
    );
    if (!$claimedStmt) {
        $applicationConn->rollback();
        $applicationConn->close();
        $_SESSION['application_message'] = ['type' => 'error', 'text' => 'Unable to check this gig right now. Please try again.'];
        header('Location: applynow.php?id=' . $gigId);
        exit;
    }
    $claimedStmt->bind_param('i', $gigId);
    if (!$claimedStmt->execute()) {
        $claimedStmt->close();
        $applicationConn->rollback();
        $applicationConn->close();
        $_SESSION['application_message'] = ['type' => 'error', 'text' => 'Unable to check this gig right now. Please try again.'];
        header('Location: applynow.php?id=' . $gigId);
        exit;
    }
    $existingApplication = $claimedStmt->get_result()->fetch_assoc();
    $claimedStmt->close();

    if ($existingApplication) {
        $applicationConn->rollback();
        $applicationConn->close();
        $message = strtolower((string) $existingApplication['status']) === 'completed'
            ? 'This one-time gig has been completed and is no longer available for applications.'
            : 'This gig is currently assigned to a student.';
        $_SESSION['application_message'] = ['type' => 'error', 'text' => $message];
        header('Location: applynow.php?id=' . $gigId);
        exit;
    }

    $insertStmt = $applicationConn->prepare(
        'INSERT INTO gig_database.applications
         (gig_id, student_id, provider_id, full_name, phone, email, skills, experience, availability)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$insertStmt) {
        $applicationConn->rollback();
        $applicationConn->close();
        $_SESSION['application_message'] = ['type' => 'error', 'text' => 'Unable to save your application. Please try again.'];
        header('Location: applynow.php?id=' . $gigId);
        exit;
    }
    $insertStmt->bind_param('iiissssss', $gigId, $studentId, $gig['provider_id'], $fullName, $phone, $email, $skills, $experience, $availability);
    if (!$insertStmt->execute()) {
        $message = $insertStmt->errno === 1062 ? 'You have already applied for this gig.' : 'Unable to save your application.';
        $insertStmt->close();
        $applicationConn->rollback();
        $applicationConn->close();
        $_SESSION['application_message'] = ['type' => 'error', 'text' => $message];
        header('Location: applynow.php?id=' . $gigId);
        exit;
    }
    $insertStmt->close();
    if (!$applicationConn->commit()) {
        $applicationConn->rollback();
        $applicationConn->close();
        $_SESSION['application_message'] = ['type' => 'error', 'text' => 'Unable to submit your application right now. Please try again.'];
        header('Location: applynow.php?id=' . $gigId);
        exit;
    }
    $applicationConn->close();
    $_SESSION['application_message'] = ['type' => 'success', 'text' => 'Application submitted successfully.'];
    header('Location: student.php?application=submitted');
    exit;
}

$applicationMessage = $_SESSION['application_message'] ?? null;
unset($_SESSION['application_message']);

$conn = new mysqli('localhost', 'root', '', 'user_database');
if ($conn->connect_error) {
    die('Database connection failed.');
}

$studentProfileStmt = $conn->prepare(
    'SELECT u.full_name, u.phone, u.email, s.skills
     FROM users u
     LEFT JOIN students s ON s.user_id = u.user_id
     WHERE u.user_id = ? AND u.role = "Student"
     LIMIT 1'
);
if (!$studentProfileStmt) {
    error_log('EarNova application student profile query could not be prepared: ' . $conn->error);
    http_response_code(500);
    exit('Your student information could not be loaded. Please try again later.');
}
$studentProfileId = (int) $_SESSION['user_id'];
$studentProfileStmt->bind_param('i', $studentProfileId);
if (!$studentProfileStmt->execute()) {
    error_log('EarNova application student profile query failed: ' . $studentProfileStmt->error);
    $studentProfileStmt->close();
    http_response_code(500);
    exit('Your student information could not be loaded. Please try again later.');
}
$studentProfile = $studentProfileStmt->get_result()->fetch_assoc();
$studentProfileStmt->close();
if (!$studentProfile) {
    http_response_code(403);
    exit('Student account information could not be found.');
}

$gigId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$gigRecord = null;
$gigStage = 'available';
if ($gigId > 0) {
    $gigStmt = $conn->prepare("SELECT g.gig_id, g.task_title, g.task_description, g.gig_image, g.category, g.location, g.shift_date, g.start_time, g.end_time, g.payment_amount, g.status FROM gig_database.gigs g INNER JOIN users u ON u.user_id = g.provider_id AND u.role = 'Provider' AND u.status = 'Active' INNER JOIN students verified_student ON verified_student.user_id = ? AND verified_student.verification_status = 'approved' WHERE g.gig_id = ? LIMIT 1");
    $studentUserId = (int) $_SESSION['user_id'];
    $gigStmt->bind_param('ii', $studentUserId, $gigId);
    $gigStmt->execute();
    $gigRecord = $gigStmt->get_result()->fetch_assoc();
    $gigStmt->close();

    if ($gigRecord && strtolower((string) ($gigRecord['status'] ?? 'Pending')) === 'active') {
        $stageStmt = $conn->prepare("SELECT status FROM gig_database.applications WHERE gig_id = ? AND status IN ('Pending', 'Accepted', 'Verified', 'Completed') ORDER BY CASE WHEN status = 'Completed' THEN 0 ELSE 1 END LIMIT 1");
        $stageStmt->bind_param('i', $gigId);
        $stageStmt->execute();
        $applicationStage = $stageStmt->get_result()->fetch_assoc();
        $stageStmt->close();

        if ($applicationStage) {
            $gigStage = strtolower($applicationStage['status']) === 'completed' ? 'completed' : 'in_progress';
        }
    }
}
$conn->close();

$isActiveGig = $gigRecord && strtolower((string) ($gigRecord['status'] ?? 'Pending')) === 'active' && $gigStage === 'available';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Apply Now - EarNova</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">




    <link rel="stylesheet" href="app.css?v=20261025-8">
    <link rel="stylesheet" href="site-nav.css">
    <link rel="stylesheet" href="applynow.css?v=20261029-2">
</head>

<body class="page-applynow">

<!-- ================= NAVBAR ================= -->

    <?php
    $activeSiteNavPage = 'browse';
    $siteNavLinks = [
        'home' => ['label' => 'HomePage', 'href' => 'homepage.php'],
        'categories' => ['label' => 'Categories', 'href' => 'categories.php'],
        'browse' => ['label' => 'Browse Gigs', 'href' => 'browse-gigs.php'],
        'about' => ['label' => 'About', 'href' => 'about.php'],
    ];
    $accountDestinations = ['Student' => 'student.php', 'Provider' => 'provider.php', 'Admin' => 'admin.php'];
    $accountRole = $_SESSION['role'] ?? '';
    ?>
    <nav class="navbar navbar-expand-lg sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="homepage.php"><span>Ear<span class="brand-highlight">Nova</span></span></a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="navbarContent">
                <ul class="navbar-nav mx-auto">
                    <?php foreach ($siteNavLinks as $page => $link) : ?>
                        <li class="nav-item"><a class="nav-link<?php echo $activeSiteNavPage === $page ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $activeSiteNavPage === $page ? 'aria-current="page"' : ''; ?>><?php echo htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <div class="nav-buttons">
                    <?php if (isset($_SESSION['user_id']) && isset($accountDestinations[$accountRole])) : ?>
                        <?php $accountLabel = $accountRole . ' account panel'; ?>
                        <a class="btn btn-outline-custom account-icon-link" href="<?php echo htmlspecialchars($accountDestinations[$accountRole], ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($accountLabel, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($accountLabel, ENT_QUOTES, 'UTF-8'); ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5Z"/></svg>
                        </a>
                    <?php else : ?>
                        <a class="btn btn-outline-custom" href="login.php">Log In</a>
                        <a class="btn btn-custom" href="register.php">Register</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- ================= LOGIN MODAL ================= -->

    <div class="modal fade" id="loginModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0" style="border-radius:20px;">
                <div class="modal-header border-0">
                    <h5 class="modal-title section-title"><span id="roleLabel">Student</span>Login</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small">Enter your registered details to continue.</p>
                    <input type="text" class="form-control mb-3" placeholder="Registered Email">
                    <input type="password" class="form-control mb-3" placeholder="Password">
                    <p class="text-muted small">Accounts are provisioned by an administrator.</p>
                    <button class="btn btn-primary-custom w-100" onclick="loginMessage()">
                        <i class="bi bi-box-arrow-in-right"></i>Login
                    </button>
                </div>
            </div>
        </div>
    </div>


<!-- MAIN -->
<div class="main-container">

<?php if (!$isActiveGig) : ?>
    <div class="gig-summary text-center p-5">
        <?php if ($gigStage === 'completed') : ?>
            <h2 class="mb-3">Completed</h2>
            <p class="text-muted mb-0">This one-time gig has been completed and is no longer available for applications.</p>
        <?php elseif ($gigStage === 'in_progress') : ?>
            <h2 class="mb-3">In Progress</h2>
            <p class="text-muted mb-0">This gig is currently assigned to a student.</p>
        <?php else : ?>
            <h2 class="mb-3">Application Not Available</h2>
            <p class="text-muted mb-0">This gig is not currently available for applications.</p>
        <?php endif; ?>
    </div>
<?php else : ?>

<a href="#" id="backToGig" class="back-link">
    <i class="fa-solid fa-arrow-left me-2"></i>
    Back to Gig
</a>

<div class="mt-4 mb-4">

<h1 class="page-title">
    Apply for Gig
</h1>

<p class="page-subtitle">
    Submit your details to apply for this opportunity.
</p>

</div>


<div class="row g-4">

<!-- SELECTED GIG -->
<div class="col-lg-5">

<div class="gig-summary">

<img id="gigImage"
     class="gig-image mb-3 d-none"
     src=""
     alt="Gig Image">

<span class="category-pill" id="gigCategory">
    Retail Store
</span>

<h2 class="gig-title mt-3 mb-2" id="gigTitle">
    Gig Details
</h2>

<div class="company-name mb-3">
    <i class="fa-solid fa-building me-2"></i>
    <span id="gigCompany">Company</span>
</div>

<div class="payment mb-3" id="gigPayment">
    ₹0
</div>


<div class="row g-2 mb-3">

<div class="col-6">
<div class="info-box">

<div class="info-label">
    Location
</div>

<div class="info-value" id="gigLocation">
    Not available
</div>

</div>
</div>


<div class="col-6">
<div class="info-box">

<div class="info-label">
    Date
</div>

<div class="info-value" id="gigDate">
    Not specified
</div>

</div>
</div>


<div class="col-6">
<div class="info-box">

<div class="info-label">
    Start Time
</div>

<div class="info-value" id="gigStartTime">
    Not specified
</div>

</div>
</div>


<div class="col-6">
<div class="info-box">

<div class="info-label">
    End Time
</div>

<div class="info-value" id="gigEndTime">
    Not specified
</div>

</div>
</div>

</div>


<h6 class="fw-bold mb-2">
    Gig Description
</h6>

<p class="description mb-0" id="gigDescription">
    No description available.
</p>

</div>

</div>


<!-- APPLICATION FORM -->
<div class="col-lg-7">

<div class="form-card">

<div id="applicationFormSection">

<h2 class="form-title">
    Student Information
</h2>

<form id="applicationForm" method="POST" action="applynow.php">
<input type="hidden" name="gig_id" value="<?php echo (int) $gigId; ?>">

<!-- FULL NAME -->
<div class="mb-3">

<label class="form-label">
    Full Name
</label>

<input type="text"
       class="form-control"
       id="fullName"
    name="full_name"
       maxlength="150"
       value="<?php echo htmlspecialchars((string) ($studentProfile['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
       required>

</div>


<!-- PHONE -->
<div class="mb-3">

<label class="form-label">
    Phone Number
</label>

<input type="tel"
       class="form-control"
       id="phone"
    name="phone"
       minlength="7"
       maxlength="20"
       pattern="[+()0-9 .-]{7,20}"
       title="Enter a phone number with 7 to 15 digits."
       value="<?php echo htmlspecialchars((string) ($studentProfile['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
       placeholder="Enter your phone number"
       required>

</div>


<!-- EMAIL -->
<div class="mb-3">

<label class="form-label">
    Email
</label>

<input type="email"
       class="form-control"
       id="email"
    name="email"
       maxlength="150"
       value="<?php echo htmlspecialchars((string) ($studentProfile['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
       required>

</div>


<!-- SKILLS -->
<div class="mb-3">

<label class="form-label">
    Relevant Skills
</label>

<input type="text"
       class="form-control"
       id="skills"
    name="skills"
       maxlength="2000"
       value="<?php echo htmlspecialchars((string) ($studentProfile['skills'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
       placeholder="Example: Communication, Excel, Customer Handling"
       required>

</div>


<!-- EXPERIENCE -->
<div class="mb-3">

<label class="form-label">
    Previous Experience
    <span class="text-muted">(Optional)</span>
</label>

<textarea class="form-control"
          id="experience"
          name="experience"
          rows="3"
          maxlength="5000"
          placeholder="Mention any relevant experience"></textarea>

</div>


<!-- AVAILABILITY -->
<div class="form-check mb-3">

<input class="form-check-input"
       type="checkbox"
       id="availability"
    name="availability"
    value="Available"
       required>

<label class="form-check-label small"
       for="availability">

    I confirm that I am available for the selected gig date and time.

</label>

</div>


<!-- CONFIRMATION -->
<div class="form-check mb-3">

<input class="form-check-input"
       type="checkbox"
       id="informationCheck"
       name="confirm_information"
       value="1"
       required>

<label class="form-check-label small"
       for="informationCheck">

    I confirm that the information provided by me is correct.

</label>

</div>


<!-- TERMS -->
<div class="form-check mb-4">

<input class="form-check-input"
       type="checkbox"
       id="termsCheck"
       name="accept_terms"
       value="1"
       required>

<label class="form-check-label small"
       for="termsCheck">

    I agree to the
    <a href="terms.html"
       class="terms-link"
       target="_blank">
       EarNova Terms & Platform Rules
    </a>.

</label>

</div>


<button type="submit"
        class="btn-apply">

    <i class="fa-solid fa-paper-plane me-2"></i>
    Submit Application

</button>

</form>

<?php if ($applicationMessage) : ?>
    <div class="alert alert-<?php echo $applicationMessage['type'] === 'success' ? 'success' : 'danger'; ?> mt-3">
        <?php echo htmlspecialchars($applicationMessage['text']); ?>
    </div>
<?php endif; ?>

</div>


<!-- SUCCESS MESSAGE -->
<div class="success-box" id="successBox">

<div class="success-icon">

    <i class="fa-solid fa-check"></i>

</div>

<h2 class="success-title">
    Application Submitted!
</h2>

<p class="success-text">
    Your application has been submitted successfully.
    The work provider will review your application.
</p>

<a href="student.php"
   class="btn-dashboard">

    Go to Student Dashboard

</a>

</div>

</div>

</div>

</div>

</div>
<?php endif; ?>


<!-- FOOTER -->
<footer>

<div class="container">
    <div class="row g-4">
        <div class="col-md-5">
            <h5>EarNova<span class="footer-brand-dot">.</span></h5>
            <p class="small mt-2">A hyper-local platform connecting students with short-term work opportunities and helping local businesses find reliable support.</p>
        </div>
        <div class="col-6 col-md-2">
            <h6>Platform</h6>
            <div class="d-flex flex-column gap-2 small mt-3">
                <a href="homepage.php#home">Home</a>
                <a href="browse-gigs.php">Browse live gigs</a>
                <a href="homepage.php#reviews">Community feedback</a>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <h6>Access</h6>
            <div class="d-flex flex-column gap-2 small mt-3">
                <a href="login.php">Log In</a>
                <a href="register.php">Register as a Student</a>
            </div>
        </div>
        <div class="col-md-3">
            <h6>Legal</h6>
            <div class="d-flex flex-column gap-2 small mt-3">
                <a href="#">Terms of Service</a>
                <a href="#">Privacy Policy</a>
            </div>
        </div>
    </div>

    <hr class="footer-line my-4">
    <div class="text-center small">© 2026 EarNova. All rights reserved.</div>
</div>

</footer>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

/* GET SELECTED GIG FROM URL */

const params = new URLSearchParams(window.location.search);

const gig = {

    id: params.get("id") || "",

    title: params.get("title") || "Gig Details",

    company: params.get("company") || "Company",

    category: params.get("category") || "Retail Store",

    location: params.get("location") || "Location not available",

    pay: params.get("pay") || "₹0",

    date: params.get("date") || "",

    startTime: params.get("startTime") || "",

    endTime: params.get("endTime") || "",

    description:
        params.get("description") ||
        "No description available.",

    image: params.get("image") || <?php echo json_encode((string) ($gigRecord['gig_image'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>

};

function setRole(role) {
    document.getElementById('roleLabel').textContent = role;
}

function loginMessage() {
    const role = document.getElementById('roleLabel').textContent.trim();
    window.location.href = role === 'Admin' ? 'homepage.php?role=admin' : (role === 'Work Provider' ? 'homepage.php?role=provider' : 'homepage.php?role=student');
}


/* HELPER */

function setText(id, value) {

    const element =
        document.getElementById(id);

    if (element) {
        element.textContent = value;
    }

}


/* FORMAT DATE */

function formatDate(dateValue) {

    if (!dateValue) {
        return "Not specified";
    }

    const date = new Date(dateValue);

    if (isNaN(date.getTime())) {
        return dateValue;
    }

    return date.toLocaleDateString("en-IN", {

        day: "numeric",
        month: "long",
        year: "numeric"

    });

}


/* DISPLAY SELECTED GIG */

setText("gigTitle", gig.title);

setText("gigCompany", gig.company);

setText("gigCategory", gig.category);

setText("gigPayment", gig.pay);

setText("gigLocation", gig.location);

setText("gigDate", formatDate(gig.date));

setText(
    "gigStartTime",
    gig.startTime || "Not specified"
);

setText(
    "gigEndTime",
    gig.endTime || "Not specified"
);

setText(
    "gigDescription",
    gig.description
);


/* IMAGE */

const gigImage =
    document.getElementById("gigImage");

if (gig.image) {
    gigImage.src = gig.image;
    gigImage.classList.remove("d-none");
} else {
    gigImage.removeAttribute("src");
    gigImage.classList.add("d-none");
}


/* BACK TO GIG */

const backToGig =
    document.getElementById("backToGig");

if (backToGig) {

    const query = new URLSearchParams({

        id: gig.id,
        title: gig.title,
        company: gig.company,
        category: gig.category,
        location: gig.location,
        pay: gig.pay,
        date: gig.date,
        startTime: gig.startTime,
        endTime: gig.endTime,
        description: gig.description,
        image: gig.image

    });

    backToGig.href =
        "Gigdetails.php?" + query.toString();

}


</script>

</body>
</html>