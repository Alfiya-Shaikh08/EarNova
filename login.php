<?php
// Authenticates users, verifies their selected role, and sends them to the right portal.
session_start();

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
	// Signed-in users should return directly to the portal for their account role.
	$dashboard = [
		'Student' => 'student.php',
		'Provider' => 'homepage.php',
		'Admin' => 'admin.php',
	];
	header('Location: ' . ($dashboard[$_SESSION['role']] ?? 'homepage.php'));
	exit;
}

$message = '';
$selectedRole = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
	// Validate credentials and the requested role before looking up an account.
	$email = trim($_POST['email'] ?? '');
	$password = $_POST['password'] ?? '';
	$selectedRole = strtolower(trim($_POST['role'] ?? ''));
	$role = ucfirst($selectedRole);

	if (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
		$message = 'Please enter a valid email address.';
	} elseif ($password === '') {
		$message = 'Please enter your password.';
	} elseif (!in_array($role, ['Student', 'Provider', 'Admin'], true)) {
		$message = 'Please select a valid account type.';
	} else {
		require __DIR__ . '/php/db.php';
		$stmt = $conn->prepare(
			"SELECT u.user_id, u.full_name, u.password, u.role, u.status,
					p.verification_status, p.rejection_reason
			 FROM users u
			 LEFT JOIN providers p ON p.user_id = u.user_id
			 WHERE u.email = ?
			 LIMIT 1"
		);
		$stmt->bind_param('s', $email);
		$stmt->execute();
		$result = $stmt->get_result();
		$user = $result ? $result->fetch_assoc() : null;

		if (!$user) {
			$message = 'Incorrect email, password, or role.';
		} elseif ($user['status'] === 'Blocked') {
			$message = 'Your account is blocked. Please contact the admin.';
		} elseif ($user['role'] !== $role) {
			$message = 'Incorrect email, password, or role.';
		} elseif (!(password_verify($password, $user['password']) || $password === $user['password'])) {
			$message = 'Incorrect email, password, or role.';
		} elseif ($user['role'] === 'Provider' && ($user['verification_status'] ?? 'pending') === 'pending') {
			$message = 'Your provider account is awaiting admin verification. You can log in once it is approved.';
		} elseif ($user['role'] === 'Provider' && ($user['verification_status'] ?? '') === 'rejected') {
			$reason = trim((string) ($user['rejection_reason'] ?? ''));
			$message = 'Your provider verification was rejected.' . ($reason !== '' ? ' Reason: ' . $reason : ' Contact the administrator for details.');
		} else {
			session_regenerate_id(true);
			$_SESSION['user_id'] = (int) $user['user_id'];
			$_SESSION['role'] = $user['role'];
			$_SESSION['full_name'] = $user['full_name'];

			$dashboard = [
				'Student' => 'student.php',
				'Provider' => 'homepage.php',
				'Admin' => 'admin.php',
			];
			header('Location: ' . $dashboard[$user['role']]);
			exit;
		}

		$stmt->close();
		$conn->close();
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>EarNova | Login</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="app.css?v=20261025-8">
    <link rel="stylesheet" href="login.css?v=20261029-1">
</head>
<body class="page-login">
<div class="login-box">
<div class="logo">Gig<span>Sync</span></div>
<h2 class="login-title">Welcome Back</h2>
<?php if ($message !== ''): ?><div class="alert alert-danger py-2 small"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
<form id="loginForm" method="POST" action="login.php" style="padding-top:0;padding-bottom:0">
<input type="hidden" name="login_submit" value="1">
<label class="form-label">Login As <span class="text-danger" aria-hidden="true">*</span></label>
<div class="input-group">
<div class="role-select-wrap w-100">
<select class="form-select" id="role" name="role" required>
<option value="" disabled <?php echo $selectedRole === '' ? 'selected' : ''; ?>>Select your role</option>
<option value="student" <?php echo $selectedRole === 'student' ? 'selected' : ''; ?>>Student</option>
<option value="provider" <?php echo $selectedRole === 'provider' ? 'selected' : ''; ?>>Work Provider</option>
<option value="admin" <?php echo $selectedRole === 'admin' ? 'selected' : ''; ?>>Admin</option>
</select>
<i class="bi bi-chevron-down role-arrow" aria-hidden="true"></i>
</div>
</div>
<div class="hint" id="hint"><i class="bi bi-info-circle" aria-hidden="true"></i> <span id="hintText"></span></div>
<label class="form-label">Registered Email <span class="text-danger" aria-hidden="true">*</span></label>
<div class="input-group">
<span class="input-group-text"><i class="bi bi-envelope"></i></span>
<input type="email" class="form-control" id="email" name="email" placeholder="Enter your registered email" maxlength="150" autocomplete="username" required>
</div>
<label class="form-label">Password <span class="text-danger" aria-hidden="true">*</span></label>
<div class="input-group">
<span class="input-group-text"><i class="bi bi-lock"></i></span>
<input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
<button type="button" class="btn password-btn" id="toggle"><i class="bi bi-eye" id="eye"></i></button>
</div>
<div class="remember-row">
<label><input type="checkbox" id="rememberMe" name="remember_me"> <span>Remember me</span></label>
</div>
<button class="login-btn" type="submit"><i class="bi bi-box-arrow-in-right me-2"></i>Login</button>
</form>
<div class="register">New to EarNova? <a href="register.php">Create Account</a></div>
</div>
<script>
const role=document.getElementById("role"),
hint=document.getElementById("hint"),
hintText=document.getElementById("hintText"),
pass=document.getElementById("password"),
eye=document.getElementById("eye"),
rememberMe=document.getElementById("rememberMe"),
email=document.getElementById("email");
const savedEmail=localStorage.getItem("gigsyncRememberedEmail");
if(savedEmail){email.value=savedEmail;rememberMe.checked=true}
rememberMe.onchange=()=>{if(!rememberMe.checked)localStorage.removeItem("gigsyncRememberedEmail")};
role.onchange=()=>{hint.style.display="block";
hintText.textContent=role.value==="student"?"Student: Access gigs and applications."
:role.value==="provider"?"Provider: Post and manage gigs."
:"Admin: Manage users and gigs."};
document.getElementById("toggle").onclick=()=>{pass.type=pass.type==="password"?"text":"password";
eye.classList.toggle("bi-eye");eye.classList.toggle("bi-eye-slash")};
document.getElementById("loginForm").onsubmit=e=>{
email.value=email.value.trim();
if(rememberMe.checked){
localStorage.setItem("gigsyncRememberedEmail",email.value);
}
};
</script>
</body>
</html>