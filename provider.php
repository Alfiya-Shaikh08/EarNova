<?php
// Provider portal for posting gigs, managing applicants, verifying shifts, and reviewing outcomes.
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Provider') {
    header('Location: homepage.php');
    exit;
}

require __DIR__ . '/php/db.php';

$providerId = (int) $_SESSION['user_id'];
function submitProviderGigDispute(mysqli $conn, int $applicationId, int $submitterId, string $subject, string $description): bool
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
         WHERE a.application_id = ? AND a.provider_id = ? AND a.status = "Completed"
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
    $submitterRole = 'Provider';
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

function providerEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function providerFlash(string $type, string $message): void
{
    $_SESSION['provider_flash'] = ['type' => $type, 'message' => $message];
}

function providerRedirect(string $section): void
{
    header('Location: provider.php?section=' . rawurlencode($section));
    exit;
}

function providerTransactionError(mysqli $conn, string $section, string $message): void
{
    $conn->rollback();
    providerFlash('danger', $message);
    providerRedirect($section);
}

function providerStatusClass(string $status): string
{
    $status = strtolower($status);
    if ($status === 'completed') {
        return 'completed';
    }
    if ($status === 'rejected') {
        return 'rejected';
    }
    if (in_array($status, ['reserved', 'accepted', 'verified', 'in progress'], true)) {
        return 'reserved';
    }
    return 'pending';
}

if (empty($_SESSION['provider_csrf_token'])) {
    // Reuse a session token across portal forms to block cross-site form submissions.
    $_SESSION['provider_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['provider_csrf_token'];

$providerStmt = $conn->prepare(
    'SELECT u.user_id, u.full_name, u.email, p.business_name, p.business_address
     FROM users u
     LEFT JOIN providers p ON p.user_id = u.user_id
     WHERE u.user_id = ? AND u.role = "Provider" AND u.status = "Active"
     LIMIT 1'
);
$providerStmt->bind_param('i', $providerId);
$providerStmt->execute();
$providerData = $providerStmt->get_result()->fetch_assoc();
$providerStmt->close();

if (!$providerData) {
    http_response_code(403);
    exit('Provider account not found.');
}

$businessName = trim((string) ($providerData['business_name'] ?? ''));
if ($businessName === '') {
    $businessName = (string) $providerData['full_name'];
}
$businessAddress = trim((string) ($providerData['business_address'] ?? ''));

$areaOptions = [];
if ($businessAddress !== '') {
    $areaOptions[] = $businessAddress;
}
$areaStmt = $conn->prepare(
    "SELECT DISTINCT location
     FROM gig_database.gigs
     WHERE provider_id = ? AND TRIM(location) <> ''
     ORDER BY location"
);
$areaStmt->bind_param('i', $providerId);
$areaStmt->execute();
foreach ($areaStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $areaRow) {
    $storedArea = trim((string) $areaRow['location']);
    if ($storedArea !== '' && !in_array($storedArea, $areaOptions, true)) {
        $areaOptions[] = $storedArea;
    }
}
$areaStmt->close();

$categoryStmt = $conn->prepare(
    "SELECT category_id, category_name
     FROM gig_database.categories
     WHERE status = 'Active'
     ORDER BY category_name"
);
$categoryStmt->execute();
$categoryOptions = $categoryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$categoryStmt->close();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // One action field routes each provider form to its matching server-side workflow.
    $section = 'active_gigs';
    $action = (string) ($_POST['action'] ?? '');
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        providerFlash('danger', 'Your form session expired. Please try again.');
        providerRedirect($action === 'create_gig' ? 'quick_post' : $section);
    }

    if ($action === 'create_gig') {
        $section = 'quick_post';
        // A one-use nonce prevents accidentally posting the same gig form twice.
        $postedNonce = (string) ($_POST['post_nonce'] ?? '');
        $sessionNonce = (string) ($_SESSION['provider_post_nonce'] ?? '');
        unset($_SESSION['provider_post_nonce']);

        if ($postedNonce === '' || $sessionNonce === '' || !hash_equals($sessionNonce, $postedNonce)) {
            providerFlash('danger', 'This gig form was already submitted or has expired. Please try again.');
            providerRedirect($section);
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $selectedArea = trim((string) ($_POST['area'] ?? ''));
        $customArea = trim((string) ($_POST['custom_area'] ?? ''));
        $specificLocation = trim((string) ($_POST['specific_location'] ?? ''));
        $area = $selectedArea === '__custom__' ? $customArea : $selectedArea;
        $location = $area . ($specificLocation !== '' ? ' • ' . $specificLocation : '');
        $shiftDate = trim((string) ($_POST['shift_date'] ?? ''));
        $startTime = trim((string) ($_POST['start_time'] ?? ''));
        $endTime = trim((string) ($_POST['end_time'] ?? ''));
        $paymentAmountInput = trim((string) ($_POST['payment_amount'] ?? ''));

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $shiftDate);
        $start = DateTimeImmutable::createFromFormat('!H:i', $startTime);
        $end = DateTimeImmutable::createFromFormat('!H:i', $endTime);
        $today = new DateTimeImmutable('today');

        $areaIsValid = $selectedArea === '__custom__'
            ? ($customArea !== '' && strlen($customArea) <= 150)
            : in_array($selectedArea, $areaOptions, true);
        if (
            $title === '' || strlen($title) > 150
            || $description === '' || strlen($description) > 5000
            || $category === '' || !$areaIsValid
            || strlen($specificLocation) > 100
            || strlen($location) > 150
        ) {
            providerFlash('danger', 'Enter a title, description, category, and location within the allowed lengths.');
            providerRedirect($section);
        }
        if (!$date || $date->format('Y-m-d') !== $shiftDate || $date < $today) {
            providerFlash('danger', 'Choose a valid shift date that is today or later.');
            providerRedirect($section);
        }
        if (!$start || !$end || $start->format('H:i') !== $startTime || $end->format('H:i') !== $endTime || $end <= $start) {
            providerFlash('danger', 'Choose a valid end time later than the start time.');
            providerRedirect($section);
        }
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $paymentAmountInput) || (float) $paymentAmountInput <= 0) {
            providerFlash('danger', 'Enter a payout amount greater than ₹0 with up to two decimal places.');
            providerRedirect($section);
        }

        $validCategory = $conn->prepare(
            "SELECT category_id, category_name
             FROM gig_database.categories
             WHERE category_name = ? AND status = 'Active'
             LIMIT 1"
        );
        $validCategory->bind_param('s', $category);
        $validCategory->execute();
        $categoryRow = $validCategory->get_result()->fetch_assoc();
        $validCategory->close();

        if (!$categoryRow) {
            providerFlash('danger', 'Select a currently active category.');
            providerRedirect($section);
        }

        $categoryId = (int) $categoryRow['category_id'];
        $categoryName = (string) $categoryRow['category_name'];
        $paymentAmount = (float) $paymentAmountInput;

        $insertGig = $conn->prepare(
            'INSERT INTO gig_database.gigs
             (provider_id, category_id, task_title, task_description, category, location, shift_date, start_time, end_time, payment_amount, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Active")'
        );
        if (!$insertGig) {
            error_log('EarNova provider gig insert could not be prepared: ' . $conn->error);
            providerFlash('danger', 'The gig could not be saved. Please try again.');
            providerRedirect($section);
        }
        $insertGig->bind_param(
            'iisssssssd',
            $providerId,
            $categoryId,
            $title,
            $description,
            $categoryName,
            $location,
            $shiftDate,
            $startTime,
            $endTime,
            $paymentAmount
        );

        if (!$insertGig->execute()) {
            $insertError = $insertGig->error;
            $insertGig->close();
            error_log('EarNova provider gig insert failed: ' . $insertError);
            providerFlash('danger', 'The gig could not be saved. Please try again.');
            providerRedirect($section);
        }
        $insertGig->close();
        providerFlash('success', 'Your gig is live and available for applications.');
        providerRedirect($section);
    }

    if ($action === 'hire_applicant') {
        // Lock the gig and application while assigning a student to prevent competing hires.
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        if ($applicationId <= 0 || !$conn->begin_transaction()) {
            providerFlash('danger', 'The application could not be assigned. Please try again.');
            providerRedirect($section);
        }

        $gigStmt = $conn->prepare(
            'SELECT g.gig_id, g.status, g.shift_date, g.start_time, g.end_time, g.payment_amount
             FROM gig_database.applications a
             JOIN gig_database.gigs g ON g.gig_id = a.gig_id
             WHERE a.application_id = ? AND a.provider_id = ? AND g.provider_id = ?
             LIMIT 1 FOR UPDATE'
        );
        if (!$gigStmt) {
            providerTransactionError($conn, $section, 'Gig data could not be loaded.');
        }
        $gigStmt->bind_param('iii', $applicationId, $providerId, $providerId);
        if (!$gigStmt->execute()) {
            $gigStmt->close();
            providerTransactionError($conn, $section, 'Gig data could not be loaded.');
        }
        $gig = $gigStmt->get_result()->fetch_assoc();
        $gigStmt->close();
        if (!$gig || $gig['status'] !== 'Active') {
            providerTransactionError($conn, $section, 'This gig is no longer open for hiring.');
        }

        $assignedStmt = $conn->prepare(
            "SELECT application_id
             FROM gig_database.applications
             WHERE gig_id = ? AND provider_id = ? AND status IN ('Accepted', 'Verified', 'Completed')
             LIMIT 1 FOR UPDATE"
        );
        $gigId = (int) $gig['gig_id'];
        $assignedStmt->bind_param('ii', $gigId, $providerId);
        $assignedStmt->execute();
        $existingAssignment = $assignedStmt->get_result()->fetch_assoc();
        $assignedStmt->close();
        if ($existingAssignment) {
            providerTransactionError($conn, $section, 'A student has already been assigned to this gig.');
        }

        $assignStmt = $conn->prepare(
            "UPDATE gig_database.applications
             SET status = 'Accepted', shift_code = ?, approved_at = NOW()
             WHERE application_id = ? AND gig_id = ? AND provider_id = ? AND status = 'Pending'"
        );
        $shiftCode = (string) random_int(100000, 999999);
        $assignStmt->bind_param('siii', $shiftCode, $applicationId, $gigId, $providerId);
        if (!$assignStmt->execute() || $assignStmt->affected_rows !== 1) {
            $assignStmt->close();
            providerTransactionError($conn, $section, 'This application is no longer available to hire.');
        }
        $assignStmt->close();

        $rejectOtherApplicants = $conn->prepare(
            "UPDATE gig_database.applications
             SET status = 'Rejected', shift_code = NULL
             WHERE gig_id = ? AND provider_id = ? AND application_id <> ? AND status = 'Pending'"
        );
        $rejectOtherApplicants->bind_param('iii', $gigId, $providerId, $applicationId);
        if (!$rejectOtherApplicants->execute()) {
            $rejectOtherApplicants->close();
            providerTransactionError($conn, $section, 'Other applications could not be closed.');
        }
        $rejectOtherApplicants->close();

        $reserveGig = $conn->prepare(
            "UPDATE gig_database.gigs
             SET status = 'Reserved'
             WHERE gig_id = ? AND provider_id = ? AND status = 'Active'"
        );
        $reserveGig->bind_param('ii', $gigId, $providerId);
        if (!$reserveGig->execute() || $reserveGig->affected_rows !== 1) {
            $reserveGig->close();
            providerTransactionError($conn, $section, 'This gig could not be reserved.');
        }
        $reserveGig->close();

        $shiftInsert = $conn->prepare(
            "INSERT INTO shift_database.shifts
             (gig_id, application_id, student_id, provider_id, shift_code, scheduled_date, start_time, end_time, status)
             SELECT g.gig_id, a.application_id, a.student_id, a.provider_id, ?, g.shift_date, g.start_time, g.end_time, 'Scheduled'
             FROM gig_database.applications a
             JOIN gig_database.gigs g ON g.gig_id = a.gig_id
             WHERE a.application_id = ? AND a.provider_id = ? AND a.status = 'Accepted'"
        );
        $shiftInsert->bind_param('sii', $shiftCode, $applicationId, $providerId);
        if (!$shiftInsert->execute() || $shiftInsert->affected_rows !== 1) {
            $shiftInsert->close();
            providerTransactionError($conn, $section, 'The scheduled shift could not be created.');
        }
        $shiftId = (int) $shiftInsert->insert_id;
        $shiftInsert->close();

        $earningInsert = $conn->prepare(
            "INSERT INTO earning_database.earnings
             (shift_id, gig_id, student_id, provider_id, amount, payment_status)
             SELECT ?, g.gig_id, a.student_id, a.provider_id, g.payment_amount, 'Pending'
             FROM gig_database.applications a
             JOIN gig_database.gigs g ON g.gig_id = a.gig_id
             WHERE a.application_id = ? AND a.provider_id = ?"
        );
        $earningInsert->bind_param('iii', $shiftId, $applicationId, $providerId);
        if (!$earningInsert->execute() || $earningInsert->affected_rows !== 1) {
            $earningInsert->close();
            providerTransactionError($conn, $section, 'The shift payout record could not be created.');
        }
        $earningInsert->close();

        if (!$conn->commit()) {
            providerTransactionError($conn, $section, 'The student could not be assigned. Please try again.');
        }
        providerFlash('success', 'The student was hired. Their six-digit shift code is available in their student account.');
        providerRedirect($section);
    }

    if ($action === 'verify_shift_code') {
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $submittedCode = trim((string) ($_POST['shift_code'] ?? ''));
        if ($applicationId <= 0 || !preg_match('/^[0-9]{6}$/', $submittedCode)) {
            providerFlash('danger', 'Enter the student’s valid six-digit shift code.');
            providerRedirect($section);
        }
        if (!$conn->begin_transaction()) {
            providerFlash('danger', 'Shift verification is temporarily unavailable.');
            providerRedirect($section);
        }

        $verifyStmt = $conn->prepare(
            'SELECT a.application_id, a.gig_id, a.student_id, a.provider_id, a.status AS application_status,
                    a.shift_code AS application_code, s.shift_id, s.shift_code AS shift_code,
                    s.status AS shift_status, e.payment_status
             FROM gig_database.applications a
             JOIN gig_database.gigs g ON g.gig_id = a.gig_id AND g.provider_id = a.provider_id
             JOIN shift_database.shifts s ON s.application_id = a.application_id
                 AND s.gig_id = a.gig_id AND s.student_id = a.student_id AND s.provider_id = a.provider_id
             JOIN earning_database.earnings e ON e.shift_id = s.shift_id
                 AND e.gig_id = a.gig_id AND e.student_id = a.student_id AND e.provider_id = a.provider_id
             WHERE a.application_id = ? AND a.provider_id = ? AND g.provider_id = ?
                   AND s.provider_id = ? AND e.provider_id = ?
             LIMIT 1 FOR UPDATE'
        );
        if (!$verifyStmt) {
            providerTransactionError($conn, $section, 'Shift verification data could not be loaded.');
        }
        $verifyStmt->bind_param('iiiii', $applicationId, $providerId, $providerId, $providerId, $providerId);
        if (!$verifyStmt->execute()) {
            $verifyStmt->close();
            providerTransactionError($conn, $section, 'Shift verification data could not be loaded.');
        }
        $verification = $verifyStmt->get_result()->fetch_assoc();
        $verifyStmt->close();

        if (
            !$verification
            || $verification['application_status'] !== 'Accepted'
            || $verification['shift_status'] !== 'Scheduled'
            || $verification['payment_status'] !== 'Pending'
            || !preg_match('/^[0-9]{6}$/', (string) $verification['application_code'])
            || !hash_equals((string) $verification['application_code'], $submittedCode)
            || !hash_equals((string) $verification['shift_code'], $submittedCode)
        ) {
            providerTransactionError($conn, $section, 'That code is invalid, expired, already used, or belongs to a different assigned shift.');
        }

        $shiftId = (int) $verification['shift_id'];
        $gigId = (int) $verification['gig_id'];
        $completeShift = $conn->prepare(
            "UPDATE shift_database.shifts
             SET status = 'Completed', verified_at = NOW(), completed_at = NOW()
             WHERE shift_id = ? AND provider_id = ? AND status = 'Scheduled' AND shift_code = ?"
        );
        $completeShift->bind_param('iis', $shiftId, $providerId, $submittedCode);
        if (!$completeShift->execute() || $completeShift->affected_rows !== 1) {
            $completeShift->close();
            providerTransactionError($conn, $section, 'The shift code has already been used or the shift is no longer scheduled.');
        }
        $completeShift->close();

        $completeApplication = $conn->prepare(
            "UPDATE gig_database.applications
             SET status = 'Completed'
             WHERE application_id = ? AND provider_id = ? AND status = 'Accepted' AND shift_code = ?"
        );
        $completeApplication->bind_param('iis', $applicationId, $providerId, $submittedCode);
        if (!$completeApplication->execute() || $completeApplication->affected_rows !== 1) {
            $completeApplication->close();
            providerTransactionError($conn, $section, 'The assigned application could not be completed.');
        }
        $completeApplication->close();

        $completeGig = $conn->prepare(
            "UPDATE gig_database.gigs SET status = 'Completed'
             WHERE gig_id = ? AND provider_id = ? AND status = 'Reserved'"
        );
        $completeGig->bind_param('ii', $gigId, $providerId);
        if (!$completeGig->execute() || $completeGig->affected_rows !== 1) {
            $completeGig->close();
            providerTransactionError($conn, $section, 'The gig could not be marked completed.');
        }
        $completeGig->close();

        $payEarning = $conn->prepare(
            "UPDATE earning_database.earnings
             SET payment_status = 'Paid', earned_at = NOW(), paid_at = NOW()
             WHERE shift_id = ? AND provider_id = ? AND payment_status = 'Pending'"
        );
        $payEarning->bind_param('ii', $shiftId, $providerId);
        if (!$payEarning->execute() || $payEarning->affected_rows !== 1) {
            $payEarning->close();
            providerTransactionError($conn, $section, 'The payout could not be marked paid.');
        }
        $payEarning->close();

        if (!$conn->commit()) {
            providerTransactionError($conn, $section, 'Shift verification could not be completed.');
        }
        providerFlash('success', 'Shift verified and marked completed. The payout has been marked paid.');
        providerRedirect($section);
    }

    if ($action === 'reject_applicant') {
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $rejectStmt = $conn->prepare(
            "UPDATE gig_database.applications
             SET status = 'Rejected', shift_code = NULL
             WHERE application_id = ? AND provider_id = ? AND status = 'Pending'
               AND EXISTS (
                   SELECT 1 FROM gig_database.gigs g
                   WHERE g.gig_id = gig_database.applications.gig_id AND g.provider_id = ?
               )"
        );
        $rejectStmt->bind_param('iii', $applicationId, $providerId, $providerId);
        $rejected = $rejectStmt->execute() && $rejectStmt->affected_rows === 1;
        $rejectStmt->close();
        providerFlash($rejected ? 'success' : 'danger', $rejected ? 'The application was declined.' : 'This application is no longer available.');
        providerRedirect($section);
    }

    if ($action === 'submit_rating') {
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $rating = (int) ($_POST['rating'] ?? 0);
        $reviewText = trim((string) ($_POST['review_text'] ?? ''));
        $ratingStmt = $conn->prepare(
            "SELECT a.student_id, a.gig_id, s.shift_id
             FROM gig_database.applications a
             JOIN gig_database.gigs g ON g.gig_id = a.gig_id
             JOIN shift_database.shifts s ON s.application_id = a.application_id
             WHERE a.application_id = ? AND a.provider_id = ? AND g.provider_id = ?
                   AND a.status = 'Completed' AND s.status = 'Completed'
             LIMIT 1"
        );
        $ratingStmt->bind_param('iii', $applicationId, $providerId, $providerId);
        $ratingStmt->execute();
        $ratingTarget = $ratingStmt->get_result()->fetch_assoc();
        $ratingStmt->close();

        if (!$ratingTarget || $rating < 1 || $rating > 5 || strlen($reviewText) > 2000) {
            providerFlash('danger', 'A valid rating can only be submitted for a completed shift.');
            providerRedirect('history');
        }

        $shiftId = (int) $ratingTarget['shift_id'];
        $studentId = (int) $ratingTarget['student_id'];
        $gigId = (int) $ratingTarget['gig_id'];
        $saveRating = $conn->prepare(
            'INSERT INTO rating_database.ratings (shift_id, gig_id, reviewer_id, reviewed_user_id, rating, review_text)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $saveRating->bind_param('iiiiis', $shiftId, $gigId, $providerId, $studentId, $rating, $reviewText);
        $saved = $saveRating->execute();
        $saveRating->close();
        providerFlash($saved ? 'success' : 'danger', $saved ? 'Your student feedback was saved.' : 'Feedback could not be saved; this shift may already have a rating.');
        providerRedirect($saved ? 'feedback' : 'history');
    }

    if ($action === 'submit_dispute') {
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $saved = submitProviderGigDispute(
            $conn,
            $applicationId,
            $providerId,
            (string) ($_POST['subject'] ?? ''),
            (string) ($_POST['description'] ?? '')
        );
        providerFlash($saved ? 'success' : 'danger', $saved ? 'Your dispute was submitted for admin review.' : 'The dispute could not be submitted. Check the subject and description.');
        providerRedirect('disputes');
    }

    providerFlash('danger', 'That action is not available.');
    providerRedirect('dashboard');
}

$providerGigsStmt = $conn->prepare(
    'SELECT gig_id, task_title, task_description, gig_image, category, location, shift_date, start_time, end_time,
            payment_amount, status, posted_date
     FROM gig_database.gigs
     WHERE provider_id = ?
     ORDER BY gig_id DESC'
);
$providerGigsStmt->bind_param('i', $providerId);
$providerGigsStmt->execute();
$providerGigs = $providerGigsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$providerGigsStmt->close();

$applicationsStmt = $conn->prepare(
    'SELECT a.application_id, a.gig_id, a.student_id, a.full_name, a.status, a.applied_at,
            g.task_title, g.status AS gig_status,
            (SELECT ROUND(AVG(r.rating), 1)
             FROM rating_database.ratings r
             WHERE r.reviewed_user_id = a.student_id) AS reliability_rating
     FROM gig_database.applications a
     JOIN gig_database.gigs g ON g.gig_id = a.gig_id
     WHERE a.provider_id = ? AND g.provider_id = ?
     ORDER BY a.application_id DESC'
);
$applicationsStmt->bind_param('ii', $providerId, $providerId);
$applicationsStmt->execute();
$providerApplications = $applicationsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$applicationsStmt->close();

$applicationsByGig = [];
$pendingApplicantCount = 0;
foreach ($providerApplications as $application) {
    $applicationsByGig[(int) $application['gig_id']][] = $application;
    if ($application['status'] === 'Pending' && $application['gig_status'] === 'Active') {
        $pendingApplicantCount++;
    }
}

$activeGigStatuses = ['Active', 'Reserved', 'In Progress'];
$completedGigIds = [];
foreach ($providerApplications as $application) {
    if ($application['status'] === 'Completed') {
        $completedGigIds[(int) $application['gig_id']] = true;
    }
}
$activeGigs = array_values(array_filter($providerGigs, static function ($gig) use ($activeGigStatuses, $completedGigIds) {
    return in_array($gig['status'], $activeGigStatuses, true) && !isset($completedGigIds[(int) $gig['gig_id']]);
}));
$historyStmt = $conn->prepare(
    "SELECT a.application_id, a.gig_id, a.full_name, a.student_id,
            g.task_title, g.task_description, g.category, g.location, g.payment_amount,
            s.completed_at, s.shift_id, e.payment_status
     FROM gig_database.applications a
     JOIN gig_database.gigs g ON g.gig_id = a.gig_id
     LEFT JOIN shift_database.shifts s ON s.application_id = a.application_id
     LEFT JOIN earning_database.earnings e ON e.shift_id = s.shift_id
     WHERE a.provider_id = ? AND g.provider_id = ? AND a.status = 'Completed'
     ORDER BY COALESCE(s.completed_at, a.approved_at, a.applied_at) DESC"
);
$historyStmt->bind_param('ii', $providerId, $providerId);
$historyStmt->execute();
$completedGigs = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$historyStmt->close();

$ratedApplications = [];
$ratedStmt = $conn->prepare(
    'SELECT s.application_id
     FROM rating_database.ratings r
     JOIN shift_database.shifts s ON s.shift_id = r.shift_id
     WHERE r.reviewer_id = ?'
);
$ratedStmt->bind_param('i', $providerId);
$ratedStmt->execute();
foreach ($ratedStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $ratedRow) {
    $ratedApplications[(int) $ratedRow['application_id']] = true;
}
$ratedStmt->close();

$feedbackStmt = $conn->prepare(
    'SELECT r.rating, r.review_text, r.created_at,
            u.full_name AS student_name, g.task_title
     FROM rating_database.ratings r
     JOIN shift_database.shifts s ON s.shift_id = r.shift_id
     JOIN gig_database.applications a ON a.application_id = s.application_id
     JOIN gig_database.gigs g ON g.gig_id = a.gig_id
     LEFT JOIN user_database.users u ON u.user_id = r.reviewer_id
     WHERE r.reviewed_user_id = ? AND r.reviewer_id <> ?
           AND a.provider_id = ? AND g.provider_id = ?
     ORDER BY r.created_at DESC'
);
$feedbackStmt->bind_param('iiii', $providerId, $providerId, $providerId, $providerId);
$feedbackStmt->execute();
$providerFeedback = $feedbackStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$feedbackStmt->close();

$ratingAverageStmt = $conn->prepare(
    'SELECT ROUND(AVG(rating), 1) AS average_rating
     FROM rating_database.ratings
     WHERE reviewed_user_id = ? AND reviewer_id <> ?'
);
$ratingAverageStmt->bind_param('ii', $providerId, $providerId);
$ratingAverageStmt->execute();
$ratingAverageRow = $ratingAverageStmt->get_result()->fetch_assoc();
$ratingAverageStmt->close();

$disputesStmt = $conn->prepare(
    "SELECT subject, description, gig_title, status, created_at
     FROM user_database.disputes
     WHERE submitter_role = 'Provider' AND submitter_id = ?
     ORDER BY created_at DESC"
);
$disputesStmt->bind_param('i', $providerId);
$disputesStmt->execute();
$providerDisputes = $disputesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$disputesStmt->close();

$activeSection = (string) ($_GET['section'] ?? 'dashboard');
$allowedSections = ['dashboard', 'quick_post', 'active_gigs', 'history', 'feedback', 'disputes'];
if (!in_array($activeSection, $allowedSections, true)) {
    $activeSection = 'dashboard';
}
$flash = $_SESSION['provider_flash'] ?? null;
unset($_SESSION['provider_flash']);
$postNonce = bin2hex(random_bytes(24));
$_SESSION['provider_post_nonce'] = $postNonce;
$conn->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Provider Dashboard | EarNova</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="provider.css">
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <div class="brand-icon"><i class="bi bi-lightning-charge-fill"></i></div>
        <div>EarNova<span class="brand-subtitle">PROVIDER HUB</span></div>
    </div>
    <nav aria-label="Provider sections">
        <a class="nav-link" href="#dashboard" data-section="dashboard"><i class="bi bi-grid-1x2-fill"></i>Dashboard</a>
        <a class="nav-link" href="#quick_post" data-section="quick_post"><i class="bi bi-plus-circle"></i>Post Gig</a>
        <a class="nav-link" href="#active_gigs" data-section="active_gigs"><i class="bi bi-briefcase-fill"></i>Active Gigs</a>
        <a class="nav-link" href="#history" data-section="history"><i class="bi bi-clock-history"></i>History</a>
        <a class="nav-link" href="#feedback" data-section="feedback"><i class="bi bi-star-fill"></i>Feedback</a>
        <a class="nav-link" href="#disputes" data-section="disputes"><i class="bi bi-exclamation-triangle"></i>Disputes</a>
        <a class="nav-link" href="homepage.php"><i class="bi bi-house-fill"></i>Homepage</a>
    </nav>
</aside>

<main class="main">
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-menu" id="menuBtn" type="button" aria-label="Open navigation"><i class="bi bi-list fs-4"></i></button>
            <div>
                <h3 class="mb-0">Provider Dashboard</h3>
                <small class="text-muted">Manage your local gigs &amp; shifts</small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="profile">
                <div class="avatar"><?php echo providerEscape(strtoupper(substr((string) $providerData['full_name'], 0, 1))); ?></div>
                <div class="profile-info">
                    <div class="fw-semibold small"><?php echo providerEscape($providerData['full_name']); ?></div>
                    <div class="text-muted" style="font-size:10px"><?php echo providerEscape($businessName); ?></div>
                </div>
            </div>
            <a class="btn btn-light border header-logout" href="php/logout.php"><i class="bi bi-box-arrow-right me-1"></i><span class="logout-label">Logout</span></a>
        </div>
    </div>

    <?php if ($flash) : ?>
        <div class="alert alert-<?php echo providerEscape($flash['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo providerEscape($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <section id="dashboard" class="page-section">
        <div class="hero">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1>Welcome, <?php echo providerEscape($providerData['full_name']); ?>! 👋</h1>
                    <p>Post a gig, find the right student and manage your shifts easily.</p>
                    <div class="provider-summary">
                        <i class="bi bi-shop me-1"></i><?php echo providerEscape($businessName); ?>
                        <?php if ($businessAddress !== '') : ?><span class="mx-1">·</span><i class="bi bi-geo-alt me-1"></i><?php echo providerEscape($businessAddress); ?><?php endif; ?>
                    </div>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <button class="quick-btn" type="button" data-open-section="quick_post"><i class="bi bi-plus-lg me-1"></i>Post New Gig</button>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="bi bi-briefcase"></i></div><div class="stat-value"><?php echo count($activeGigs); ?></div><div class="stat-label">Active Gigs</div></div></div>
            <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="bi bi-people"></i></div><div class="stat-value"><?php echo $pendingApplicantCount; ?></div><div class="stat-label">Applicants</div></div></div>
            <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="bi bi-check-circle"></i></div><div class="stat-value"><?php echo count($completedGigIds); ?></div><div class="stat-label">Completed Shifts</div></div></div>
            <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="bi bi-star"></i></div><div class="stat-value"><?php echo $ratingAverageRow['average_rating'] !== null ? providerEscape($ratingAverageRow['average_rating']) . ' / 5' : '—'; ?></div><div class="stat-label">Provider Rating</div></div></div>
        </div>

        <div class="section-title">
            <h4>Recent Gigs</h4>
            <a href="#active_gigs" class="section-jump" data-open-section="active_gigs" style="color:#198396;text-decoration:none;font-size:13px;font-weight:600">View All</a>
        </div>
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="panel p-3">
                    <?php if ($providerGigs) : ?>
                        <?php foreach (array_slice($providerGigs, 0, 2) as $gig) : ?>
                            <?php
                            $gigStatus = (string) $gig['status'];
                            $gigApps = $applicationsByGig[(int) $gig['gig_id']] ?? [];
                            $hiredApp = null;
                            foreach ($gigApps as $gigApp) {
                                if (in_array($gigApp['status'], ['Accepted', 'Verified'], true)) {
                                    $hiredApp = $gigApp;
                                    break;
                                }
                            }
                            $dashboardStatus = $hiredApp ? ($hiredApp['status'] === 'Verified' ? 'In Progress' : 'Reserved') : $gigStatus;
                            ?>
                            <div class="gig-card">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <div class="gig-title"><?php echo providerEscape($gig['task_title']); ?></div>
                                        <div class="gig-meta">
                                            <span><i class="bi bi-tag"></i> <?php echo providerEscape($gig['category']); ?></span>
                                            <span><i class="bi bi-geo-alt"></i> <?php echo providerEscape($gig['location']); ?></span>
                                            <span><i class="bi bi-calendar3"></i> <?php echo providerEscape($gig['shift_date'] ?? 'Date not set'); ?></span>
                                            <span><i class="bi bi-clock"></i> <?php echo providerEscape(substr((string) $gig['start_time'], 0, 5)); ?>–<?php echo providerEscape(substr((string) $gig['end_time'], 0, 5)); ?></span>
                                            <span><i class="bi bi-cash"></i> ₹<?php echo number_format((float) $gig['payment_amount'], 2, '.', ','); ?></span>
                                        </div>
                                    </div>
                                    <span class="status <?php echo providerStatusClass($dashboardStatus); ?>"><?php echo providerEscape($dashboardStatus); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="p-3 text-muted">No gigs posted yet.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="panel p-3">
                    <h5 class="mb-3">Recent Applicants</h5>
                    <?php if ($providerApplications) : ?>
                        <?php foreach (array_slice($providerApplications, 0, 3) as $application) : ?>
                            <div class="applicant">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="small-avatar"><?php echo providerEscape(strtoupper(substr((string) $application['full_name'], 0, 1))); ?></div>
                                    <div><div class="applicant-name"><?php echo providerEscape($application['full_name']); ?></div><div class="applicant-role"><?php echo providerEscape($application['task_title']); ?></div></div>
                                </div>
                                <span class="status <?php echo providerStatusClass((string) $application['status']); ?>"><?php echo providerEscape($application['status']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="text-muted small">No applications yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section id="quick_post" class="page-section">
        <div class="section-title mt-0"><div><h4>Post Gig</h4><small class="text-muted">Create a short period shift for students to apply to.</small></div></div>
        <div class="panel quick-post-panel">
            <form method="POST" id="gigForm" class="quick-post-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo providerEscape($csrfToken); ?>">
                <input type="hidden" name="post_nonce" value="<?php echo providerEscape($postNonce); ?>">
                <input type="hidden" name="action" value="create_gig">
                <div class="row g-2 mb-2">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="gigTitle">Gig title <span class="text-danger">*</span></label>
                        <input class="form-control" id="gigTitle" name="title" maxlength="150" required placeholder="e.g. Help with evening inventory">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="gigDescription">Task description <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="gigDescription" name="description" rows="2" maxlength="5000" required placeholder="Describe the work and any useful details"></textarea>
                    </div>
                </div>
                <div class="row g-1">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="gigCategory">Category <span class="text-danger">*</span></label>
                        <select class="form-select" id="gigCategory" name="category" required>
                            <option value="" selected disabled>Select a category</option>
                            <?php foreach ($categoryOptions as $categoryOption) : ?>
                                <option value="<?php echo providerEscape($categoryOption['category_name']); ?>"><?php echo providerEscape($categoryOption['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="gigArea">Area <span class="text-danger">*</span></label>
                        <select class="form-select" id="gigArea" name="area" required>
                            <option value="" selected disabled>Select an area</option>
                            <?php foreach ($areaOptions as $areaOption) : ?>
                                <option value="<?php echo providerEscape($areaOption); ?>"><?php echo providerEscape($areaOption); ?></option>
                            <?php endforeach; ?>
                            <option value="__custom__">Other area…</option>
                        </select>
                    </div>
                    <div class="col-md-6 d-none" id="customAreaWrap">
                        <label class="form-label fw-semibold" for="customArea">New area <span class="text-danger">*</span></label>
                        <input class="form-control" id="customArea" name="custom_area" maxlength="150" placeholder="Enter the area">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="specificLocation">Specific location <span class="text-muted">(optional)</span></label>
                        <input class="form-control" id="specificLocation" name="specific_location" maxlength="100" placeholder="Shop, street, landmark, etc.">
                    </div>
                </div>
                <div class="row g-1 mt-1">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="shiftDate">Shift date <span class="text-danger">*</span></label>
                        <input class="form-control" type="date" id="shiftDate" name="shift_date" min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="startTime">Shift start time <span class="text-danger">*</span></label>
                        <select class="form-select" id="startTime" name="start_time" required>
                            <option value="" selected disabled>Select a start time</option>
                            <?php for ($minutes = 0; $minutes <= (21 * 60 + 30); $minutes += 30) : ?>
                                <?php $timeOption = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60); ?>
                                <option value="<?php echo $timeOption; ?>"><?php echo providerEscape(date('g:i A', strtotime($timeOption))); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="endTime">Shift end time <span class="text-danger">*</span></label>
                        <select class="form-select" id="endTime" name="end_time" required>
                            <option value="" selected disabled>Select an end time</option>
                            <?php for ($minutes = 0; $minutes <= (23 * 60 + 30); $minutes += 30) : ?>
                                <?php $timeOption = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60); ?>
                                <option value="<?php echo $timeOption; ?>"><?php echo providerEscape(date('g:i A', strtotime($timeOption))); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="payout-box mt-2">
                    <div class="payout-field">
                        <label class="form-label fw-semibold" for="paymentAmount">Payout amount (₹) <span class="text-danger">*</span></label>
                        <input class="form-control" type="number" id="paymentAmount" name="payment_amount" min="0.01" max="99999999.99" step="0.01" required placeholder="Enter amount">
                    </div>
                    <button class="btn btn-primary-custom px-4 py-2" type="submit"><i class="bi bi-send me-1"></i>Post Gig</button>
                </div>
            </form>
        </div>
    </section>

    <section id="active_gigs" class="page-section">
        <div class="section-title mt-0"><div><h4>Active Gigs</h4><small class="text-muted">Manage applications and assigned shifts.</small></div><button class="btn btn-primary-custom" type="button" data-open-section="quick_post"><i class="bi bi-plus-lg me-1"></i>Post Gig</button></div>
        <?php if ($activeGigs) : ?>
            <?php foreach ($activeGigs as $gig) : ?>
                <?php
                $gigId = (int) $gig['gig_id'];
                $gigApps = $applicationsByGig[$gigId] ?? [];
                $claimants = array_values(array_filter($gigApps, static function ($application) {
                    return in_array($application['status'], ['Pending', 'Accepted', 'Verified'], true);
                }));
                $hiredApp = null;
                foreach ($gigApps as $gigApp) {
                    if (in_array($gigApp['status'], ['Accepted', 'Verified'], true)) {
                        $hiredApp = $gigApp;
                        break;
                    }
                }
                if ($hiredApp) {
                    $cardStatus = $hiredApp['status'] === 'Verified' ? 'In Progress' : 'Reserved';
                } elseif ($gig['status'] === 'Active') {
                    $cardStatus = 'Pending';
                } else {
                    $cardStatus = (string) $gig['status'];
                }
                ?>
                <article class="gig-management-card">
                    <div class="gig-card-top">
                        <span class="status <?php echo providerStatusClass($cardStatus); ?>"><?php echo providerEscape($cardStatus); ?></span>
                        <span class="history-detail"><i class="bi bi-clock me-1"></i><?php echo providerEscape($gig['shift_date'] ?? 'Date not set'); ?> · <?php echo providerEscape(substr((string) $gig['start_time'], 0, 5)); ?></span>
                    </div>
                    <?php if (!empty($gig['gig_image'])) : ?>
                        <img class="provider-gig-image" src="<?php echo providerEscape($gig['gig_image']); ?>" alt="<?php echo providerEscape($gig['task_title']); ?>">
                    <?php endif; ?>
                    <h5 class="gig-title"><?php echo providerEscape($gig['task_title']); ?></h5>
                    <p class="mb-3"><?php echo nl2br(providerEscape($gig['task_description'] ?? '')); ?></p>
                    <div class="gig-meta">
                        <span><i class="bi bi-tag me-1"></i><?php echo providerEscape($gig['category']); ?></span>
                        <span><i class="bi bi-geo-alt me-1"></i><?php echo providerEscape($gig['location']); ?></span>
                        <span><i class="bi bi-clock me-1"></i><?php echo providerEscape(substr((string) $gig['start_time'], 0, 5)); ?>–<?php echo providerEscape(substr((string) $gig['end_time'], 0, 5)); ?></span>
                        <span><i class="bi bi-cash me-1"></i>₹<?php echo number_format((float) $gig['payment_amount'], 2, '.', ','); ?></span>
                    </div>
                    <div class="claimant-list">
                        <div class="fw-semibold text-uppercase small text-secondary"><i class="bi bi-people me-1"></i><?php echo count($claimants); ?> <?php echo count($claimants) === 1 ? 'Claimant' : 'Claimants'; ?></div>
                        <?php if ($hiredApp) : ?>
                            <div class="assigned-student">
                                <div class="assigned-student-label">Hired student</div>
                                <div class="fw-semibold mt-1"><?php echo providerEscape($hiredApp['full_name']); ?></div>
                                <div class="small mt-1">
                                    <?php if ($hiredApp['reliability_rating'] !== null) : ?>
                                        <i class="bi bi-star-fill rating-stars me-1"></i><?php echo providerEscape($hiredApp['reliability_rating']); ?> reliability rating
                                    <?php else : ?>
                                        No reliability rating yet
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($hiredApp['status'] === 'Accepted') : ?>
                                <button type="button" class="btn btn-primary-custom w-100 mt-3 verify-shift-button" data-application-id="<?php echo (int) $hiredApp['application_id']; ?>" data-student="<?php echo providerEscape($hiredApp['full_name']); ?>" data-bs-toggle="modal" data-bs-target="#verifyShiftModal"><i class="bi bi-shield-check me-2"></i>Verify Shift Code</button>
                            <?php else : ?>
                                <div class="text-success small mt-3"><i class="bi bi-check-circle me-1"></i>Shift verified.</div>
                            <?php endif; ?>
                        <?php else : ?>
                            <?php
                            $pendingApplicants = array_values(array_filter($gigApps, static function ($application) {
                                return $application['status'] === 'Pending';
                            }));
                            ?>
                            <?php if ($pendingApplicants) : ?>
                                <?php foreach ($pendingApplicants as $applicant) : ?>
                                    <div class="claimant-row">
                                        <div>
                                            <div class="fw-semibold"><?php echo providerEscape($applicant['full_name']); ?></div>
                                            <div class="small text-muted">
                                                <?php if ($applicant['reliability_rating'] !== null) : ?>
                                                    <i class="bi bi-star-fill rating-stars me-1"></i><?php echo providerEscape($applicant['reliability_rating']); ?> reliability rating
                                                <?php else : ?>
                                                    No reliability rating yet
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($gig['status'] === 'Active') : ?>
                                            <div class="d-flex gap-2 flex-wrap">
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?php echo providerEscape($csrfToken); ?>">
                                                    <input type="hidden" name="application_id" value="<?php echo (int) $applicant['application_id']; ?>">
                                                    <input type="hidden" name="action" value="hire_applicant">
                                                    <button class="btn btn-primary-custom" type="submit"><i class="bi bi-person-check me-1"></i>Hire</button>
                                                </form>
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?php echo providerEscape($csrfToken); ?>">
                                                    <input type="hidden" name="application_id" value="<?php echo (int) $applicant['application_id']; ?>">
                                                    <input type="hidden" name="action" value="reject_applicant">
                                                    <button class="btn btn-outline-secondary" type="submit">Decline</button>
                                                </form>
                                            </div>
                                        <?php else : ?>
                                            <span class="small text-muted">This gig is not currently active.</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <div class="text-muted fst-italic mt-3">Waiting for students...</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="panel empty-state">No active gigs yet. Post your first gig to get started.</div>
        <?php endif; ?>
    </section>

    <section id="history" class="page-section">
        <div class="section-title mt-0"><div><h4>Gig History</h4><small class="text-muted">Completed shifts and payouts.</small></div></div>
        <?php if ($completedGigs) : ?>
            <?php foreach ($completedGigs as $completedGig) : ?>
                <article class="gig-management-card">
                    <div class="gig-card-top"><span class="status completed">Completed</span><span class="history-detail"><?php echo $completedGig['completed_at'] ? providerEscape(date('d M Y, g:i A', strtotime($completedGig['completed_at']))) : 'Completion date unavailable'; ?></span></div>
                    <h5 class="gig-title"><?php echo providerEscape($completedGig['task_title']); ?></h5>
                    <p><?php echo nl2br(providerEscape($completedGig['task_description'] ?? '')); ?></p>
                    <div class="gig-meta">
                        <span><i class="bi bi-geo-alt me-1"></i><?php echo providerEscape($completedGig['location']); ?></span>
                        <span><i class="bi bi-person me-1"></i><?php echo providerEscape($completedGig['full_name']); ?></span>
                        <span><i class="bi bi-cash me-1"></i>₹<?php echo number_format((float) $completedGig['payment_amount'], 2, '.', ','); ?></span>
                        <span><i class="bi bi-wallet2 me-1"></i>Payment: <?php echo providerEscape($completedGig['payment_status'] ?? 'Not recorded'); ?></span>
                    </div>
                    <div class="d-flex gap-2 flex-wrap mt-3">
                        <?php if (!isset($ratedApplications[(int) $completedGig['application_id']])) : ?>
                            <button type="button" class="btn btn-outline-custom" data-bs-toggle="modal" data-bs-target="#rateModal<?php echo (int) $completedGig['application_id']; ?>"><i class="bi bi-star me-1"></i>Leave Feedback</button>
                        <?php else : ?>
                            <span class="small text-success align-self-center">Feedback submitted</span>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#disputeModal<?php echo (int) $completedGig['application_id']; ?>"><i class="bi bi-exclamation-circle me-1"></i>Dispute</button>
                    </div>
                </article>
                <div class="modal fade" id="rateModal<?php echo (int) $completedGig['application_id']; ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                        <div class="modal-header"><div><h5 class="modal-title">Rate student</h5><small class="text-muted"><?php echo providerEscape($completedGig['full_name']); ?> · <?php echo providerEscape($completedGig['task_title']); ?></small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo providerEscape($csrfToken); ?>">
                            <input type="hidden" name="application_id" value="<?php echo (int) $completedGig['application_id']; ?>">
                            <input type="hidden" name="action" value="submit_rating">
                            <div class="modal-body">
                                <label class="form-label fw-semibold" for="rating<?php echo (int) $completedGig['application_id']; ?>">Rating</label>
                                <select class="form-select mb-3" id="rating<?php echo (int) $completedGig['application_id']; ?>" name="rating" required>
                                    <option value="" selected disabled>Select 1 to 5 stars</option>
                                    <option value="5">5 - Excellent</option><option value="4">4 - Good</option><option value="3">3 - Fair</option><option value="2">2 - Poor</option><option value="1">1 - Very poor</option>
                                </select>
                                <label class="form-label fw-semibold" for="review<?php echo (int) $completedGig['application_id']; ?>">Feedback</label>
                                <textarea class="form-control" id="review<?php echo (int) $completedGig['application_id']; ?>" name="review_text" rows="4" maxlength="2000" placeholder="Share feedback about this student"></textarea>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary-custom">Save Feedback</button></div>
                        </form>
                    </div></div>
                </div>
                <div class="modal fade" id="disputeModal<?php echo (int) $completedGig['application_id']; ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                        <div class="modal-header"><div><h5 class="modal-title">Submit a dispute</h5><small class="text-muted"><?php echo providerEscape($completedGig['task_title']); ?></small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo providerEscape($csrfToken); ?>">
                            <input type="hidden" name="application_id" value="<?php echo (int) $completedGig['application_id']; ?>">
                            <input type="hidden" name="action" value="submit_dispute">
                            <div class="modal-body">
                                <label class="form-label fw-semibold" for="subject<?php echo (int) $completedGig['application_id']; ?>">Subject</label>
                                <input class="form-control mb-3" id="subject<?php echo (int) $completedGig['application_id']; ?>" name="subject" maxlength="255" required>
                                <label class="form-label fw-semibold" for="description<?php echo (int) $completedGig['application_id']; ?>">Description</label>
                                <textarea class="form-control" id="description<?php echo (int) $completedGig['application_id']; ?>" name="description" rows="5" maxlength="5000" required></textarea>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary-custom">Submit Dispute</button></div>
                        </form>
                    </div></div>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="panel empty-state">No completed gigs yet.</div>
        <?php endif; ?>
    </section>

    <section id="feedback" class="page-section">
        <div class="section-title mt-0"><div><h4>Student Feedback</h4><small class="text-muted">Ratings and reviews students have left for your business.</small></div></div>
        <?php if ($providerFeedback) : ?>
            <?php foreach ($providerFeedback as $feedback) : ?>
                <article class="feedback-card">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div><div class="fw-semibold"><?php echo providerEscape($feedback['student_name'] ?? 'Student'); ?></div><div class="small text-muted"><?php echo providerEscape($feedback['task_title']); ?> · <?php echo providerEscape(date('d M Y', strtotime($feedback['created_at']))); ?></div></div>
                        <span class="rating-stars" aria-label="<?php echo (int) $feedback['rating']; ?> out of 5 stars"><?php echo str_repeat('★', (int) $feedback['rating']); ?><?php echo str_repeat('☆', 5 - (int) $feedback['rating']); ?></span>
                    </div>
                    <div class="mt-3 text-wrap-safe"><?php echo trim((string) ($feedback['review_text'] ?? '')) !== '' ? nl2br(providerEscape($feedback['review_text'])) : '<span class="text-muted">No written feedback.</span>'; ?></div>
                </article>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="panel empty-state">No student feedback yet.</div>
        <?php endif; ?>
    </section>

    <section id="disputes" class="page-section">
        <div class="section-title mt-0"><div><h4>My Disputes</h4><small class="text-muted">Track disputes submitted for completed shifts.</small></div></div>
        <?php if ($providerDisputes) : ?>
            <div class="panel p-2"><div class="table-responsive">
                <table class="table provider-gigs-table">
                    <thead><tr><th>Gig</th><th>Subject</th><th>Description</th><th>Status</th><th>Submitted</th></tr></thead>
                    <tbody>
                    <?php foreach ($providerDisputes as $dispute) : ?>
                        <tr>
                            <td class="gig-name"><?php echo providerEscape($dispute['gig_title']); ?></td>
                            <td><?php echo providerEscape($dispute['subject']); ?></td>
                            <td class="text-wrap-safe"><?php echo nl2br(providerEscape($dispute['description'])); ?></td>
                            <td><span class="status pending"><?php echo providerEscape($dispute['status']); ?></span></td>
                            <td><?php echo providerEscape(date('d-m-Y', strtotime($dispute['created_at']))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        <?php else : ?>
            <div class="panel empty-state">You have not submitted any disputes.</div>
        <?php endif; ?>
    </section>
</main>

<div class="modal fade" id="verifyShiftModal" tabindex="-1" aria-labelledby="verifyShiftTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header">
            <div><h5 class="modal-title" id="verifyShiftTitle">Verify Shift</h5><small class="text-muted" id="verifyStudentName"></small></div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" id="verifyShiftForm">
            <input type="hidden" name="csrf_token" value="<?php echo providerEscape($csrfToken); ?>">
            <input type="hidden" name="application_id" id="verifyApplicationId" value="">
            <input type="hidden" name="action" value="verify_shift_code">
            <div class="modal-body">
                <p class="text-muted">Enter the student’s six-digit shift code to confirm and complete this shift.</p>
                <label class="form-label fw-semibold" for="verifyShiftCode">Six-digit shift code</label>
                <input class="form-control text-center fs-4" id="verifyShiftCode" name="shift_code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary-custom"><i class="bi bi-check2 me-1"></i>Complete Shift</button>
            </div>
        </form>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sections = document.querySelectorAll('.page-section');
const sidebarLinks = document.querySelectorAll('.sidebar .nav-link[data-section]');
const menuButton = document.getElementById('menuBtn');
const sidebar = document.getElementById('sidebar');

function openSection(sectionId) {
    sections.forEach(function (section) {
        section.classList.toggle('active', section.id === sectionId);
    });
    sidebarLinks.forEach(function (link) {
        link.classList.toggle('active', link.dataset.section === sectionId);
    });
    document.querySelectorAll('.tab-strip [data-open-section]').forEach(function (link) {
        link.classList.toggle('active', link.dataset.openSection === sectionId);
    });
    sidebar.classList.remove('show');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.querySelectorAll('[data-section]').forEach(function (link) {
    link.addEventListener('click', function (event) {
        event.preventDefault();
        openSection(link.dataset.section);
    });
});
document.querySelectorAll('[data-open-section]').forEach(function (button) {
    button.addEventListener('click', function (event) {
        event.preventDefault();
        openSection(button.dataset.openSection);
    });
});
document.getElementById(<?php echo json_encode($activeSection); ?>) && openSection(<?php echo json_encode($activeSection); ?>);

if (menuButton) {
    menuButton.addEventListener('click', function () {
        sidebar.classList.toggle('show');
    });
}

document.querySelectorAll('.verify-shift-button').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById('verifyApplicationId').value = button.dataset.applicationId;
        document.getElementById('verifyStudentName').textContent = 'Student: ' + button.dataset.student;
        document.getElementById('verifyShiftCode').value = '';
    });
});

const gigForm = document.getElementById('gigForm');
const startTime = document.getElementById('startTime');
const endTime = document.getElementById('endTime');
const areaSelect = document.getElementById('gigArea');
const customAreaWrap = document.getElementById('customAreaWrap');
const customAreaInput = document.getElementById('customArea');
if (areaSelect && customAreaWrap && customAreaInput) {
    areaSelect.addEventListener('change', function () {
        const useCustomArea = areaSelect.value === '__custom__';
        customAreaWrap.classList.toggle('d-none', !useCustomArea);
        customAreaInput.required = useCustomArea;
        if (!useCustomArea) {
            customAreaInput.value = '';
        }
    });
}
if (gigForm && startTime && endTime) {
    const endTimeOptions = Array.from(endTime.options);
    const updateEndTimeOptions = function () {
        endTimeOptions.forEach(function (option) {
            option.disabled = option.value !== '' && option.value <= startTime.value;
        });
        if (endTime.value && endTime.value <= startTime.value) {
            endTime.value = '';
        }
        endTime.setCustomValidity('');
    };
    startTime.addEventListener('change', function () {
        updateEndTimeOptions();
    });
    endTime.addEventListener('change', function () {
        endTime.setCustomValidity('');
    });
    gigForm.addEventListener('submit', function (event) {
        if (!startTime.value || !endTime.value) {
            return;
        }
        if (endTime.value <= startTime.value) {
            event.preventDefault();
            endTime.setCustomValidity('Choose an end time later than the start time.');
            endTime.reportValidity();
        } else {
            endTime.setCustomValidity('');
        }
    });
}
</script>
</body>
</html>
