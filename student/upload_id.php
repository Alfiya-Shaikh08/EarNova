<?php
// Accepts a student verification document and stores it outside the public web directory.
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../php/require_approved.php';

$studentId = (int) $_SESSION['user_id'];
$errorMessage = '';
if (empty($_SESSION['student_id_csrf'])) {
    $_SESSION['student_id_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['student_id_csrf'];
$privateUploadDirectory = 'C:\\xampp\\private_uploads';

if (!is_dir($privateUploadDirectory) && !mkdir($privateUploadDirectory, 0700, true) && !is_dir($privateUploadDirectory)) {
    http_response_code(500);
    exit('Private upload directory could not be created. Check the XAMPP directory permissions.');
}

// Pending submissions cannot be replaced until reviewed; approved accounts need no new upload.
$verification = getStudentVerification($pdo, $studentId);
if (($verification['status'] ?? '') === 'error' || ($verification['status'] ?? '') === 'unavailable') {
    http_response_code(500);
    exit(htmlspecialchars($verification['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $upload = $_FILES['college_id'] ?? null;

    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $errorMessage = 'Your form session expired. Refresh the page and try again.';
    } elseif (($verification['status'] ?? '') === 'approved') {
        $errorMessage = 'Your college ID is already approved. Contact an administrator if your account needs review.';
    } elseif (($verification['status'] ?? '') === 'pending' && !empty($verification['id_card_path'])) {
        $errorMessage = 'Your college ID is awaiting review. You can upload a replacement after a rejection.';
    } elseif (!$upload || !isset($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = 'Choose a JPG, PNG, or PDF file under 2 MB and try again.';
    } elseif ((int) $upload['size'] <= 0 || (int) $upload['size'] > 2 * 1024 * 1024) {
        $errorMessage = 'The file must be larger than 0 bytes and no bigger than 2 MB.';
    } else {
        $extension = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $upload['tmp_name']);
        $allowed = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'pdf' => ['application/pdf'],
        ];

        if (!isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) {
            $errorMessage = 'The uploaded file must be a valid JPG, PNG, or PDF document.';
        } else {
            $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
            $targetPath = $privateUploadDirectory . DIRECTORY_SEPARATOR . $storedName;
            $oldPath = (string) ($verification['id_card_path'] ?? '');

            if (!is_uploaded_file((string) $upload['tmp_name']) || !move_uploaded_file((string) $upload['tmp_name'], $targetPath)) {
                $errorMessage = 'The college ID could not be saved. Please try again.';
            } else {
                try {
                    $saveStmt = $pdo->prepare(
                        "UPDATE students
                         SET id_card_path = ?, verification_status = 'pending',
                             rejection_reason = NULL, approved_by = NULL,
                             reviewed_at = NULL, id_uploaded_at = NOW()
                         WHERE user_id = ?"
                    );
                    $saveStmt->execute([$storedName, $studentId]);
                    $saved = $saveStmt->rowCount() === 1;
                } catch (PDOException $exception) {
                    @unlink($targetPath);
                    error_log('EarNova ID upload update failed: ' . $exception->getMessage());
                    $errorMessage = 'The college ID could not be saved. Please try again later.';
                    $saved = false;
                }

                if (!$saved && $errorMessage === '') {
                    @unlink($targetPath);
                    $errorMessage = 'The college ID could not be saved. Confirm your student profile exists and try again.';
                } elseif ($saved) {
                    if ($oldPath !== '' && preg_match('/\A[a-f0-9]{32}\.(?:jpg|jpeg|png|pdf)\z/i', $oldPath)) {
                        $oldAbsolutePath = $privateUploadDirectory . DIRECTORY_SEPARATOR . $oldPath;
                        if (is_file($oldAbsolutePath)) {
                            if (!unlink($oldAbsolutePath)) {
                                error_log('EarNova could not remove a replaced college ID file: ' . $oldAbsolutePath);
                            }
                        }
                    }
                    $_SESSION['student_id_upload_message'] = 'College ID uploaded. Your account is now awaiting admin review.';
                    header('Location: ../student.php');
                    exit;
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upload College ID | EarNova</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <link rel="stylesheet" href="../app.css?v=20261025-8">
</head>
<body class="page-upload-id">
<main class="container py-5" style="max-width:760px">
    <a href="../student.php" class="text-decoration-none" style="color:#198396">&larr; Back to student dashboard</a>
    <div class="card shadow-sm mt-3">
        <div class="card-body p-4 p-md-5">
            <h1 class="h3 mb-2">College ID verification</h1>
            <p class="text-secondary">Upload a clear image or PDF of your college ID. Maximum file size: 2 MB.</p>
            <?php if ($errorMessage !== '') : ?>
                <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if (!empty($verification['rejection_reason'])) : ?>
                <div class="alert alert-danger"><strong>Previous review:</strong> <?php echo htmlspecialchars((string) $verification['rejection_reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" id="idUploadForm">
                <?php if (($verification['status'] ?? '') === 'pending' && !empty($verification['id_card_path'])) : ?>
                    <div class="alert alert-warning">Your uploaded college ID is awaiting admin review. You can upload a replacement if it is rejected.</div>
                <?php elseif (($verification['status'] ?? '') === 'approved') : ?>
                    <div class="alert alert-success">Your college ID is approved.</div>
                <?php else : ?>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="mb-3">
                    <label for="collegeId" class="form-label fw-semibold">College ID file</label>
                    <input class="form-control" type="file" id="collegeId" name="college_id" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required>
                    <div class="form-text">Accepted formats: JPG, PNG, PDF.</div>
                </div>
                <div id="clientError" class="alert alert-danger d-none" role="alert"></div>
                <img id="idPreview" class="mb-3" alt="Selected college ID preview">
                <div id="pdfPreview" class="small text-secondary mb-3" hidden></div>
                <button class="btn btn-primary" type="submit">Upload for verification</button>
                <?php endif; ?>
            </form>
        </div>
    </div>
</main>
<script>
const idInput = document.getElementById('collegeId');
const preview = document.getElementById('idPreview');
const pdfPreview = document.getElementById('pdfPreview');
const clientError = document.getElementById('clientError');
const maxSize = 2 * 1024 * 1024;
const allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];

idInput.addEventListener('change', () => {
    const file = idInput.files[0];
    clientError.classList.add('d-none');
    preview.style.display = 'none';
    pdfPreview.hidden = true;
    if (!file) return;
    if (!allowedTypes.includes(file.type) || file.size > maxSize || file.size === 0) {
        clientError.textContent = 'Choose a valid JPG, PNG, or PDF file no larger than 2 MB.';
        clientError.classList.remove('d-none');
        idInput.value = '';
        return;
    }
    if (file.type === 'application/pdf') {
        pdfPreview.textContent = 'Selected PDF: ' + file.name;
        pdfPreview.hidden = false;
        return;
    }
    preview.src = URL.createObjectURL(file);
    preview.style.display = 'block';
});

document.getElementById('idUploadForm').addEventListener('submit', event => {
    const file = idInput.files[0];
    if (!file || !allowedTypes.includes(file.type) || file.size === 0 || file.size > maxSize) {
        event.preventDefault();
        clientError.textContent = 'Choose a valid JPG, PNG, or PDF file no larger than 2 MB.';
        clientError.classList.remove('d-none');
    }
});
</script>
</body>
</html>
