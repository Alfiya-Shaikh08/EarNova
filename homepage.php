<?php
// Public landing page with navigation, featured gigs, and role-aware entry points.
session_start();

if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'Admin') {
    header('Location: admin.php');
    exit;
}

$message = '';
$canViewStudentGigs = false;
if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'Student') {
    // Only show student-only gig listings when the required ID review has been approved.
    require_once __DIR__ . '/php/require_approved.php';
    $canViewStudentGigs = studentHasApprovedId($pdo, (int) $_SESSION['user_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $conn = new mysqli('localhost', 'root', '', 'user_database');
    if ($conn->connect_error) {
        $message = 'Database connection failed.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = trim($_POST['role'] ?? 'Student');

        $stmt = $conn->prepare('SELECT user_id, full_name, password, role, status FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ($user['status'] === 'Blocked') {
                $message = 'Your account is blocked. Please contact the admin.';
            } elseif ($user['role'] !== $role) {
                $message = 'Incorrect email or password.';
            } elseif (password_verify($password, $user['password']) || $password === $user['password']) {
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
            } else {
                $message = 'Incorrect email or password.';
            }
        } else {
            $message = 'Account not found. Please register first.';
        }

        if ($stmt) {
            $stmt->close();
        }
        $conn->close();
    }
}

if (isset($_GET['registered']) && $_GET['registered'] == '1') {
    $message = 'Registration successful. Please log in with your new account.';
} elseif (isset($_GET['registered']) && $_GET['registered'] === 'provider') {
    $message = 'Provider registration received. Your account is awaiting admin verification. You can log in once it is verified.';
} elseif (isset($_GET['registered']) && $_GET['registered'] === 'student') {
    $message = 'Student registration successful. Please log in with your new account.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EarNova | Find the Right Gig</title>
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap"
        rel="stylesheet">
    
    
    <link rel="stylesheet" href="app.css?v=20261025-8">
    <link rel="stylesheet" href="site-nav.css">
    <link rel="stylesheet" href="homepage.css?v=20261029-3">
</head>
<body class="page-homepage">
    <?php
    $activeSiteNavPage = 'home';
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

    <main>
        <section class="hero" id="home">
            <div class="hero-container">
                <div class="hero-content">
                    <div class="hero-kicker"><i class="bi bi-geo-alt-fill"></i> Local opportunities. Flexible shifts.</div>
                    <h1>Find the Right Gig.<br><span>Right When You Need It.</span></h1>
                    <p class="hero-description">
                        Connect with local businesses, discover flexible work opportunities,
                        and earn while you learn. Your next opportunity could be just around the corner.
                    </p>

                    <div class="hero-buttons">
                        <a href="browse-gigs.php" class="hero-btn hero-btn-primary">
                            Explore Gigs <i class="bi bi-arrow-right"></i>
                        </a>
                        <a href="register.php" class="hero-btn hero-btn-outline">
                            Register as a Student <i class="bi bi-person-plus"></i>
                        </a>
                    </div>

                    <div class="hero-benefits">
                        <div class="benefit"><i class="bi bi-check-circle"></i>Flexible Opportunities</div>
                        <div class="benefit"><i class="bi bi-check-circle"></i>Local Businesses</div>
                        <div class="benefit"><i class="bi bi-check-circle"></i>Learn and Earn</div>
                    </div>
                    <a class="hero-scroll-link" href="#how-it-works">See how EarNova works <i class="bi bi-arrow-down"></i></a>
                </div>
            </div>
        </section>

        <section class="how-it-works" id="how-it-works">
            <div class="container">
                <div class="section-heading text-center">
                    <div class="eyebrow">A simple way to get started</div>
                    <h2 class="section-title">Your next shift, in three steps</h2>
                    <p class="section-intro">Find work that fits your schedule and stay in control from application to shift.</p>
                </div>
                <div class="row g-3 g-lg-4">
                    <div class="col-md-4">
                        <a class="step-card" href="categories.php">
                            <span class="step-number">01</span>
                            <span class="step-icon"><i class="bi bi-search"></i></span>
                            <h3>Explore local gigs</h3>
                            <p>Browse opportunities and find a task that works for you.</p>
                            <span class="step-link">Browse categories <i class="bi bi-arrow-up-right"></i></span>
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a class="step-card" href="register.php">
                            <span class="step-number">02</span>
                            <span class="step-icon"><i class="bi bi-send"></i></span>
                            <h3>Apply with confidence</h3>
                            <p>Create your student profile and apply for the shifts you choose.</p>
                            <span class="step-link">Join as a student <i class="bi bi-arrow-up-right"></i></span>
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a class="step-card" href="login.php">
                            <span class="step-number">03</span>
                            <span class="step-icon"><i class="bi bi-check2-circle"></i></span>
                            <h3>Track your progress</h3>
                            <p>Keep up with shift updates, completed work, and earnings.</p>
                            <span class="step-link">Log in to your account <i class="bi bi-arrow-up-right"></i></span>
                        </a>
                    </div>
                </div>
            </div>
        </section>

    <!-- ================= LIVE GIGS ================= -->

    <section class="section" id="gigs">
        <div class="container">
            <div class="section-heading gigs-heading">
                <div>
                    <div class="eyebrow">Find your next opportunity</div>
                    <h2 class="section-title">Live Gigs</h2>
                </div>
                <a class="section-action" href="browse-gigs.php">Browse all gigs <i class="bi bi-arrow-right"></i></a>
            </div>
            <div id="gigCarousel" class="carousel slide" data-bs-ride="carousel">
                <div class="carousel-inner">
                    <?php
                    $homeGigConn = new mysqli('localhost', 'root', '', 'user_database');
                    $homeGigRows = [];

                    if (!$homeGigConn->connect_error && $canViewStudentGigs) {
                        $homeGigQuery = "
                            SELECT g.gig_id, g.task_title, g.category, g.location, g.payment_amount,
                                   g.shift_date, g.start_time, g.end_time, g.task_description,
                                   COALESCE(u.full_name, 'Business') AS provider_name,
                                   COALESCE(NULLIF(TRIM(p.business_name), ''), '') AS business_name,
                                   COALESCE(g.gig_image, '') AS gig_image
                            FROM gig_database.gigs g
                            INNER JOIN users u ON u.user_id = g.provider_id AND u.role = 'Provider' AND u.status = 'Active'
                            INNER JOIN students verified_student ON verified_student.user_id = ? AND verified_student.verification_status = 'approved'
                            LEFT JOIN providers p ON p.user_id = g.provider_id
                            WHERE LOWER(COALESCE(g.status, 'Pending')) = 'active'
                            ORDER BY g.gig_id DESC
                            LIMIT 6
                        ";

                        $homeGigStmt = $homeGigConn->prepare($homeGigQuery);
                        if ($homeGigStmt) {
                            $homeGigUserId = (int) $_SESSION['user_id'];
                            $homeGigStmt->bind_param('i', $homeGigUserId);
                            if ($homeGigStmt->execute()) {
                                $homeGigRows = $homeGigStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                            }
                            $homeGigStmt->close();
                        }
                        $homeGigConn->close();
                    }

                    $homeGigPages = array_chunk($homeGigRows, 3);

                    if (empty($homeGigPages)) {
                        $emptyGigMessage = $canViewStudentGigs
                            ? 'No provider gigs available yet.'
                            : 'Log in with an approved student account to browse local gigs.';
                        echo '<div class="carousel-item active"><div class="row g-4"><div class="col-12"><div class="gig-card"><div class="gig-body text-center"><h5 class="gig-title mb-0">' . htmlspecialchars($emptyGigMessage, ENT_QUOTES, 'UTF-8') . '</h5></div></div></div></div></div>';
                    } else {
                        foreach ($homeGigPages as $pageIndex => $pageRows) {
                            echo '<div class="carousel-item ' . ($pageIndex === 0 ? 'active' : '') . '"><div class="row g-2">';

                            foreach ($pageRows as $gig) {
                                $gigImage = !empty($gig['gig_image']) ? htmlspecialchars($gig['gig_image']) : '';
                                $gigTitle = htmlspecialchars($gig['task_title'] ?? 'Provider Gig');
                                $gigCompany = htmlspecialchars($gig['provider_name'] ?? 'Business');
                                $gigBusinessName = htmlspecialchars($gig['business_name'] ?: ($gig['provider_name'] ?? 'Business'));
                                $gigLocation = htmlspecialchars($gig['location'] ?? 'Location not specified');
                                $gigPrice = '₹' . number_format((float) ($gig['payment_amount'] ?? 0), 0);
                                $gigDetailUrl = 'Gigdetails.php?id=' . (int) ($gig['gig_id'] ?? 0)
                                    . '&title=' . urlencode($gig['task_title'] ?? '')
                                    . '&company=' . urlencode($gig['provider_name'] ?? '')
                                    . '&category=' . urlencode($gig['category'] ?? '')
                                    . '&location=' . urlencode($gig['location'] ?? '')
                                    . '&pay=' . urlencode($gigPrice)
                                    . '&rating=0'
                                    . '&shift=' . urlencode('Anytime')
                                    . '&date=' . urlencode($gig['shift_date'] ?? '')
                                    . '&startTime=' . urlencode($gig['start_time'] ?? '')
                                    . '&endTime=' . urlencode($gig['end_time'] ?? '')
                                    . '&postedAt=' . urlencode('Recently posted')
                                    . '&description=' . urlencode($gig['task_description'] ?? '')
                                    . '&image=' . urlencode($gig['gig_image'] ?? '');

                                echo '<div class="col-md-4">';
                                echo '  <a href="' . $gigDetailUrl . '" class="d-block gig-card h-100 text-decoration-none" style="color: inherit;">';
                                if ($gigImage !== '') {
                                    echo '      <img class="gig-image" src="' . $gigImage . '" alt="' . $gigTitle . '">';
                                }
                                echo '      <div class="gig-body">';
                                echo '          <div class="d-flex justify-content-between"><span class="gig-price">' . $gigPrice . '</span></div>';
                                echo '          <h5 class="gig-title">' . $gigTitle . '</h5>';
                                echo '          <div class="gig-meta"><i class="bi bi-shop"></i>' . $gigBusinessName . '</div>';
                                echo '          <div class="gig-meta mt-1"><i class="bi bi-person"></i>' . $gigCompany . '</div>';
                                echo '          <div class="gig-meta mt-1"><i class="bi bi-geo-alt"></i>' . $gigLocation . '</div>';
                                echo '      </div>';
                                echo '  </a>';
                                echo '</div>';
                            }

                            echo '</div></div>';
                        }
                    }
                    ?>
                </div>

                <?php if (!empty($homeGigPages) && count($homeGigPages) > 1) : ?>
                    <button class="carousel-control-prev" type="button" data-bs-target="#gigCarousel" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon"></span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#gigCarousel" data-bs-slide="next">
                        <span class="carousel-control-next-icon"></span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ================= REVIEWS ================= -->
    <section class="section review-section" id="reviews">
        <div class="container">
            <div class="text-center">
                <div class="eyebrow">Community feedback</div>
                <h2 class="section-title">What People Say</h2>
            </div>

            <div id="reviewCarousel" class="carousel slide mt-5" data-bs-ride="carousel" data-bs-interval="6500">

                <div class="carousel-inner">

                    <div class="carousel-item active">
                        <div class="review-card">
                            <div class="stars">★★★★★</div>
                            <p class="review-text mt-3">"I found a short shift between my classes. The process was
                                simple and the opportunity was close to my location."</p>
                            <div class="reviewer">— Student user</div>
                        </div>
                    </div>

                    <div class="carousel-item">
                        <div class="review-card">
                            <div class="stars">★★★★★</div>
                            <p class="review-text mt-3">"Posting a short task is much easier when you know you can reach
                                available people nearby."</p>
                            <div class="reviewer">— Work provider</div>
                        </div>
                    </div>

                    <div class="carousel-item">
                        <div class="review-card">
                            <div class="stars">★★★★☆</div>
                            <p class="review-text mt-3">"The simple verification process makes the whole shift easier to
                                manage."</p>
                            <div class="reviewer">— EarNova user</div>
                        </div>
                    </div>
                </div>
                    <div class="review-controls">
                        <button class="review-control" type="button" data-bs-target="#reviewCarousel" data-bs-slide="prev" aria-label="Previous review">
                            <i class="bi bi-arrow-left" aria-hidden="true"></i>
                        </button>
                        <div class="carousel-indicators review-indicators">
                            <button type="button" data-bs-target="#reviewCarousel" data-bs-slide-to="0" class="active" aria-current="true" aria-label="Review 1"></button>
                            <button type="button" data-bs-target="#reviewCarousel" data-bs-slide-to="1" aria-label="Review 2"></button>
                            <button type="button" data-bs-target="#reviewCarousel" data-bs-slide-to="2" aria-label="Review 3"></button>
                        </div>
                        <button class="review-control" type="button" data-bs-target="#reviewCarousel" data-bs-slide="next" aria-label="Next review">
                            <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </button>
                    </div>
            </div>
        </div>
    </section>
    </main>

    <!-- ================= FOOTER ================= -->
    <footer>
        <div class="container">
            <div class="row g-4">
                <div class="col-md-5">
                    <h5>EarNova<span style="color:#2FA37A">.</span></h5>
                    <p class="small mt-2">A hyper-local platform connecting students with short-term work opportunities
                        and helping local businesses find reliable support.</p>
                </div>
                <div class="col-6 col-md-2">
                    <h6>Platform</h6>
                    <div class="d-flex flex-column gap-2 small mt-3">
                        <a href="#home">Home</a>
                        <a href="#gigs">Browse live gigs</a>
                        <a href="#reviews">Community feedback</a>
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
            <div class="text-center small">
                © 2026 EarNova. All rights reserved.
            </div>
        </div>
    </footer>
    <!-- ================= BOOTSTRAP JS ================= -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if ($message !== '') : ?>
        window.addEventListener('DOMContentLoaded', function () {
            const messageBox = document.getElementById('messageBox');
            messageBox.classList.add('show');
            window.setTimeout(function () {
                messageBox.classList.remove('show');
            }, 3500);
        });
        <?php endif; ?>
    </script>
    <div class="message-box<?php echo $message !== '' ? ' show' : ''; ?>" id="messageBox"
         role="status" aria-live="polite">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span id="messageText"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
</body>
</html>