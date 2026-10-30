<?php
// Creates student or provider accounts after validating details and required supporting documents.
session_start();

if (isset($_SESSION['user_id'])) {
    $dashboard = [
        'Student' => 'student.php',
        'Provider' => 'provider.php',
        'Admin' => 'admin.php',
    ];
    header('Location: ' . ($dashboard[$_SESSION['role'] ?? ''] ?? 'homepage.php'));
    exit;
}

if (empty($_SESSION['register_csrf_token'])) {
    // Bind the registration form to this session to prevent forged account-creation requests.
    $_SESSION['register_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['register_csrf_token'];
$message = '';
$fullName = '';
$email = '';
$phone = '';
$address = '';
$accountType = 'Student';
$businessName = '';
$businessAddress = '';
$businessCategory = '';
$studentIdUpload = null;
$studentIdExtension = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate shared fields first, then require only the details for the selected account type.
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $accountType = is_string($_POST['account_type'] ?? null) ? $_POST['account_type'] : '';
    $fullName = trim(is_string($_POST['full_name'] ?? null) ? $_POST['full_name'] : '');
    $email = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
    $phone = trim(is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '');
    $address = trim(is_string($_POST['address'] ?? null) ? $_POST['address'] : '');
    $businessName = trim(is_string($_POST['business_name'] ?? null) ? $_POST['business_name'] : '');
    $businessAddress = trim(is_string($_POST['business_address'] ?? null) ? $_POST['business_address'] : '');
    $businessCategory = trim(is_string($_POST['business_category'] ?? null) ? $_POST['business_category'] : '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    $termsAccepted = ($_POST['accept_terms'] ?? '') === '1';
    $studentIdUpload = $_FILES['college_id'] ?? null;
    $phoneDigits = preg_replace('/\D/', '', $phone);

    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $message = 'Your session expired. Refresh the page and try again.';
    } elseif (!in_array($accountType, ['Student', 'Provider'], true)) {
        $message = 'Please select a valid account type.';
    } elseif ($fullName === '' || strlen($fullName) > 150) {
        $message = 'Enter your name using no more than 150 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $message = 'Enter a valid email address using no more than 150 characters.';
    } elseif (!preg_match('/^[+()\d\s.-]{7,20}$/', $phone) || strlen((string) $phoneDigits) < 7 || strlen((string) $phoneDigits) > 15) {
        $message = 'Enter a valid phone number with 7 to 15 digits.';
    } elseif (!$termsAccepted) {
        $message = 'Accept the Terms & Platform Rules to create your account.';
    } elseif ($accountType === 'Student' && $address === '') {
        $message = 'Enter your address.';
    } elseif ($accountType === 'Student' && strlen($address) > 255) {
        $message = 'Address must be no more than 255 characters.';
    } elseif ($accountType === 'Student' && (!$studentIdUpload || !isset($studentIdUpload['error']) || $studentIdUpload['error'] !== UPLOAD_ERR_OK)) {
        $message = 'Upload your college ID as a JPG, PNG, or PDF file under 2 MB.';
    } elseif ($accountType === 'Student' && ((int) ($studentIdUpload['size'] ?? 0) <= 0 || (int) $studentIdUpload['size'] > 2 * 1024 * 1024)) {
        $message = 'Your college ID file must be larger than 0 bytes and no bigger than 2 MB.';
    } elseif ($accountType === 'Provider' && ($businessName === '' || strlen($businessName) > 150)) {
        $message = 'Enter your business name using no more than 150 characters.';
    } elseif ($accountType === 'Provider' && ($businessAddress === '' || strlen($businessAddress) > 255)) {
        $message = 'Enter your business address using no more than 255 characters.';
    } elseif ($accountType === 'Provider' && ($businessCategory === '' || strlen($businessCategory) > 150)) {
        $message = 'Enter your business category using no more than 150 characters.';
    } elseif (strlen($password) < 8 || strlen($password) > 72) {
        $message = 'Choose a password between 8 and 72 characters.';
    } elseif ($password !== $confirmPassword) {
        $message = 'The passwords do not match.';
    } else {
        if ($accountType === 'Student') {
            $extension = strtolower(pathinfo((string) ($studentIdUpload['name'] ?? ''), PATHINFO_EXTENSION));
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) ($studentIdUpload['tmp_name'] ?? ''));
            $allowedIdTypes = [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'pdf' => ['application/pdf'],
            ];
            if (!isset($allowedIdTypes[$extension]) || !in_array($mime, $allowedIdTypes[$extension], true)) {
                $message = 'The uploaded college ID must be a valid JPG, PNG, or PDF document.';
            } else {
                $studentIdExtension = $extension;
            }
        }

        if ($message === '') {
            // Save student identity documents outside the public web root.
            require_once __DIR__ . '/php/db.php';

            $storedIdName = null;
            if ($accountType === 'Student') {
                $privateUploadDirectory = 'C:\\xampp\\private_uploads';
                if (!is_dir($privateUploadDirectory) &&
                    !mkdir($privateUploadDirectory, 0700, true) &&
                    !is_dir($privateUploadDirectory)) {
                    $message = 'Private ID upload storage could not be prepared. Please contact the administrator.';
                } else {
                    $storedIdName = bin2hex(random_bytes(16)) . '.' . $studentIdExtension;
                    $targetPath = $privateUploadDirectory . DIRECTORY_SEPARATOR . $storedIdName;
                    if (!is_uploaded_file((string) ($studentIdUpload['tmp_name'] ?? '')) ||
                        !move_uploaded_file((string) $studentIdUpload['tmp_name'], $targetPath)) {
                        $storedIdName = null;
                        $message = 'Your college ID could not be saved. Please try again.';
                    }
                }
            }

            if ($message === '' && !$conn->begin_transaction()) {
                // Account and role-specific records must either both save or both roll back.
                error_log('EarNova registration transaction could not start: ' . $conn->error);
                $message = 'Registration could not be completed right now. Please try again.';
            } elseif ($message === '') {
                $role = $accountType;
                $status = $accountType === 'Provider' ? 'Pending' : 'Active';
                $userStmt = $conn->prepare(
                    "INSERT INTO users (full_name, email, phone, password, role, status)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                if (!$userStmt) {
                    $conn->rollback();
                    error_log('EarNova registration user query failed: ' . $conn->error);
                    $message = 'Registration could not be completed right now. Please try again.';
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $userStmt->bind_param('ssssss', $fullName, $email, $phone, $hashedPassword, $role, $status);
                    if (!$userStmt->execute()) {
                        $duplicateEmail = $userStmt->errno === 1062;
                        $errorDetails = $userStmt->error;
                        $userStmt->close();
                        $conn->rollback();
                        if ($duplicateEmail) {
                            $message = 'An account already exists for this email address.';
                        } else {
                            error_log('EarNova registration user insert failed: ' . $errorDetails);
                            $message = 'Registration could not be completed right now. Please try again.';
                        }
                    } else {
                        $userId = $conn->insert_id;
                        $userStmt->close();
                        if ($accountType === 'Student') {
                            $profileStmt = $conn->prepare(
                                "INSERT INTO students (user_id, address, id_card_path, verification_status, id_uploaded_at)
                                 VALUES (?, ?, ?, 'pending', NOW())"
                            );
                        } else {
                            $profileStmt = $conn->prepare(
                                'INSERT INTO providers (user_id, business_name, business_address, business_category)
                                 VALUES (?, ?, ?, ?)'
                            );
                        }
                        if (!$profileStmt) {
                            $conn->rollback();
                            error_log('EarNova registration profile query failed: ' . $conn->error);
                            $message = 'Registration could not be completed right now. Please try again.';
                        } else {
                            if ($accountType === 'Student') {
                                $profileStmt->bind_param('iss', $userId, $address, $storedIdName);
                            } else {
                                $profileStmt->bind_param('isss', $userId, $businessName, $businessAddress, $businessCategory);
                            }
                            if (!$profileStmt->execute()) {
                                $errorDetails = $profileStmt->error;
                                $profileStmt->close();
                                $conn->rollback();
                                error_log('EarNova registration profile insert failed: ' . $errorDetails);
                                $message = 'Registration could not be completed right now. Please try again.';
                            } else {
                                $profileStmt->close();
                                if (!$conn->commit()) {
                                    error_log('EarNova registration commit failed: ' . $conn->error);
                                    $conn->rollback();
                                    $message = 'Registration could not be completed right now. Please try again.';
                                } else {
                                    unset($_SESSION['register_csrf_token']);
                                    $conn->close();
                                    header('Location: homepage.php?registered=' . strtolower($accountType));
                                    exit;
                                }
                            }
                        }
                    }
                }
            }

            if ($storedIdName !== null && $message !== '') {
                $stagedIdPath = 'C:\\xampp\\private_uploads' . DIRECTORY_SEPARATOR . $storedIdName;
                if (is_file($stagedIdPath) && !unlink($stagedIdPath)) {
                    error_log('EarNova could not remove an unregistered college ID file: ' . $stagedIdPath);
                }
            }
            $conn->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>EarNova | Create Account</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="app.css?v=20261025-8">
    <link rel="stylesheet" href="register.css?v=20261029-1">
</head>
<body class="page-register">
<main class="register-shell">
    <section class="welcome-panel" aria-label="About EarNova">
        <div class="logo"><span style="color:#fff">Ear<span>Nova</span></span></div>
        <div class="welcome-copy">
            <p class="eyebrow">JOIN EARNOVA</p>
            <h1>Start your<br>gig journey.</h1>
            <p>Create your account and connect students with local opportunities and businesses with reliable support.</p>
            <div class="benefits">
                <div class="benefit"><i class="bi bi-geo-alt" aria-hidden="true"></i><span>Find hyper-local opportunities</span></div>
                <div class="benefit"><i class="bi bi-people" aria-hidden="true"></i><span>Connect students and businesses</span></div>
                <div class="benefit"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Verified and reliable platform</span></div>
            </div>
        </div>
    </section>
    <section class="form-panel">
      <div class="form-content">
        <h2 class="form-heading">CREATE ACCOUNT</h2>
    <?php if ($message !== ''): ?>
        <div class="alert alert-danger py-2 small" role="alert"><?php echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form id="registrationForm" method="POST" action="register.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <label class="form-label" for="account_type">Account Type<span class="required-mark" aria-hidden="true">*</span></label>
        <div class="account-select-wrap">
            <select class="form-select" id="account_type" name="account_type" aria-describedby="account_hint" required>
                <option value="Student" <?php echo $accountType === 'Student' ? 'selected' : ''; ?>>Student</option>
                <option value="Provider" <?php echo $accountType === 'Provider' ? 'selected' : ''; ?>>Provider</option>
            </select>
            <i class="bi bi-chevron-down select-arrow" aria-hidden="true"></i>
        </div>
        <p class="account-hint" id="account_hint"><i class="bi bi-info-circle" aria-hidden="true"></i> <span id="account_hint_text"><?php echo $accountType === 'Provider' ? 'Providers can register their business and post opportunities after admin verification.' : 'Submit your college ID during registration. Admin approval is required before you can apply for gigs.'; ?></span></p>
        <div class="field-grid">
            <div>
                <label class="form-label" for="full_name">Full Name<span class="required-mark" aria-hidden="true">*</span></label>
                <div class="input-wrap"><i class="bi bi-person" aria-hidden="true"></i><input class="field-control" type="text" id="full_name" name="full_name" maxlength="150" autocomplete="name" value="<?php echo htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required></div>
            </div>
            <div>
                <label class="form-label" for="email">Email<span class="required-mark" aria-hidden="true">*</span></label>
                <div class="input-wrap"><i class="bi bi-envelope" aria-hidden="true"></i><input class="field-control" type="email" id="email" name="email" maxlength="150" autocomplete="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required></div>
            </div>
            <div>
                <label class="form-label" for="password">Password<span class="required-mark" aria-hidden="true">*</span></label>
                <div class="input-wrap"><i class="bi bi-lock" aria-hidden="true"></i><input class="field-control has-toggle" type="password" id="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required><button class="password-toggle" type="button" data-toggle-password="password" aria-label="Show password"><i class="bi bi-eye-slash" aria-hidden="true"></i></button></div>
            </div>
            <div>
                <label class="form-label" for="confirm_password">Confirm Password<span class="required-mark" aria-hidden="true">*</span></label>
                <div class="input-wrap"><i class="bi bi-shield-lock" aria-hidden="true"></i><input class="field-control has-toggle" type="password" id="confirm_password" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required><button class="password-toggle" type="button" data-toggle-password="confirm_password" aria-label="Show confirm password"><i class="bi bi-eye-slash" aria-hidden="true"></i></button></div>
                <p class="field-error" id="password_error" aria-live="polite"></p>
            </div>
            <div>
                <label class="form-label" for="phone">Phone Number<span class="required-mark" aria-hidden="true">*</span></label>
                <div class="input-wrap"><i class="bi bi-telephone" aria-hidden="true"></i><input class="field-control" type="tel" id="phone" name="phone" minlength="7" maxlength="20" pattern="[+()0-9 .-]{7,20}" title="Enter a phone number with 7 to 15 digits." autocomplete="tel" value="<?php echo htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required></div>
            </div>
            <div data-account-field="student" <?php echo $accountType === 'Student' ? '' : 'hidden'; ?>>
                <label class="form-label" for="address">Address<span class="required-mark" aria-hidden="true">*</span></label>
                <div class="input-wrap"><i class="bi bi-geo-alt" aria-hidden="true"></i><input class="field-control" type="text" id="address" name="address" maxlength="255" autocomplete="street-address" value="<?php echo htmlspecialchars($address, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required></div>
            </div>
             <div class="student-id-field" data-account-field="student" <?php echo $accountType === 'Student' ? '' : 'hidden'; ?>>
            <label class="form-label" for="college_id">College ID<span class="required-mark" aria-hidden="true">*</span></label>
            <input class="field-control" type="file" id="college_id" name="college_id" form="registrationForm" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required>
            <small class="text-secondary student-id-help">Upload student ID (JPG, PNG, PDF; max 2 MB). Admin approval required.</small>
        </div>
            <div data-account-field="provider" <?php echo $accountType === 'Provider' ? '' : 'hidden'; ?>>
                <label class="form-label" for="business_name">Business Name<span class="required-mark" aria-hidden="true">*</span></label>
                <div class="input-wrap"><i class="bi bi-shop" aria-hidden="true"></i><input class="field-control" type="text" id="business_name" name="business_name" maxlength="150" autocomplete="organization" value="<?php echo htmlspecialchars($businessName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required></div>
            </div>
            <div data-account-field="provider" <?php echo $accountType === 'Provider' ? '' : 'hidden'; ?>>
                <label class="form-label" for="business_address">Business Address<span class="required-mark" aria-hidden="true">*</span></label>
                <div class="input-wrap"><i class="bi bi-geo-alt" aria-hidden="true"></i><input class="field-control" type="text" id="business_address" name="business_address" maxlength="255" autocomplete="street-address" value="<?php echo htmlspecialchars($businessAddress, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required></div>
            </div>
            <div data-account-field="provider" <?php echo $accountType === 'Provider' ? '' : 'hidden'; ?>>
                <label class="form-label" for="business_category">Business Category<span class="required-mark" aria-hidden="true">*</span></label>
                <div class="input-wrap"><i class="bi bi-tag" aria-hidden="true"></i><input class="field-control" type="text" id="business_category" name="business_category" maxlength="150" placeholder="e.g. Retail, Food, Design" value="<?php echo htmlspecialchars($businessCategory, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required></div>
            </div>
        </div>
        <p class="field-error" id="provider_note" <?php echo $accountType === 'Provider' ? '' : 'hidden'; ?>>Provider accounts require admin verification before login.</p>
        <label class="terms-row"><input type="checkbox" name="accept_terms" value="1" required><span>I agree to the <a href="about.php">Terms &amp; Platform Rules</a><span class="required-mark" aria-hidden="true">*</span></span></label>
        <button class="register-btn" type="submit"><i class="bi bi-person-plus me-2" aria-hidden="true"></i>Create Account</button>
    </form>
    <p class="login-link">Already have an account? <a href="login.php">Back to Login</a></p>
      </div>
    </section>
</main>
<script>
document.querySelectorAll('[data-toggle-password]').forEach((button) => {
    button.addEventListener('click', () => {
        const field = document.getElementById(button.dataset.togglePassword);
        const icon = button.querySelector('i');
        const showPassword = field.type === 'password';
        field.type = showPassword ? 'text' : 'password';
        button.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
        icon.classList.toggle('bi-eye', showPassword);
        icon.classList.toggle('bi-eye-slash', !showPassword);
    });
});
const registrationForm = document.querySelector('form');
const accountType = document.getElementById('account_type');
const accountHint = document.getElementById('account_hint_text');
const providerNote = document.getElementById('provider_note');
const updateAccountFields = () => {
    const providerSelected = accountType.value === 'Provider';
    document.querySelectorAll('[data-account-field]').forEach((fieldGroup) => {
        const isActive = fieldGroup.dataset.accountField === (providerSelected ? 'provider' : 'student');
        fieldGroup.hidden = !isActive;
        fieldGroup.querySelectorAll('input').forEach((input) => {
            input.disabled = !isActive;
        });
    });
    accountHint.textContent = providerSelected
        ? 'Providers can register their business and post opportunities after admin verification.'
        : 'Submit your college ID during registration. Admin approval is required before you can apply for gigs.';
    providerNote.hidden = !providerSelected;
};
accountType.addEventListener('change', updateAccountFields);
updateAccountFields();
registrationForm.addEventListener('submit', (event) => {
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const error = document.getElementById('password_error');
    error.textContent = password.value === confirmPassword.value ? '' : 'Passwords do not match.';
    if (error.textContent !== '') {
        event.preventDefault();
        confirmPassword.focus();
    }
});
</script>
</body>
</html>
