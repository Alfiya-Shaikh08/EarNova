<?php
// Loads an active gig from the database, then renders its details and application link.
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

$conn = new mysqli('localhost', 'root', '', 'user_database');
if ($conn->connect_error) {
    die('Database connection failed.');
}

$gigId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$gigRecord = null;
$gigStage = 'available';
if ($gigId > 0) {
    // Limit details to active-provider gigs and require the signed-in student's ID approval.
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

$isActiveGig = $gigRecord && strtolower((string) ($gigRecord['status'] ?? 'Pending')) === 'active';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gig Details - EarNova</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap"
        rel="stylesheet">

    
    
    

    
    <link rel="stylesheet" href="app.css?v=20261025-8">
    <link rel="stylesheet" href="site-nav.css">
    <link rel="stylesheet" href="gigdetails.css?v=20261029-1">
</head>

<body class="page-gigdetails">
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

        <?php if (!$gigRecord || strtolower((string) ($gigRecord['status'] ?? 'Pending')) !== 'active') : ?>
            <div class="gig-card p-5 text-center">
                <h2 class="mb-3">Gig Not Available</h2>
                <p class="text-muted mb-0">This gig is either waiting for admin approval or is no longer active.</p>
            </div>
        <?php else : ?>

        <a href="browse-gigs.php" class="back-link">
            <i class="fa-solid fa-arrow-left me-2"></i>Back to Gigs
        </a>

        <div class="row g-4">

            <!-- LEFT SIDE -->
            <div class="col-lg-8">

                <div class="gig-card p-4">

                    <img id="gigImage" class="gig-image mb-4 d-none" src="" alt="Gig Image">

                    <span class="category-pill" id="categoryPill">
                        Retail Store
                    </span>

                    <h1 class="gig-title mt-3 mb-2" id="gigTitle">
                        Gig Details
                    </h1>

                    <div class="company-name mb-3">
                        <i class="fa-solid fa-building me-2"></i>
                        <span id="companyName">Company</span>
                    </div>

                    <div class="payment mb-4" id="gigPay">
                        ₹0
                    </div>


                    <!-- DESCRIPTION -->
                    <div class="mb-4">

                        <h3 class="section-title">
                            Detailed Description
                        </h3>

                        <p id="gigDescription" class="text-muted mb-0">
                            No description available.
                        </p>

                    </div>


                    <!-- GIG INFORMATION -->
                    <div class="mb-4">

                        <h3 class="section-title">
                            Gig Information
                        </h3>

                        <div class="row g-3">

                            <div class="col-md-6">
                                <div class="info-box">

                                    <div class="info-icon mb-2">
                                        <i class="fa-solid fa-location-dot"></i>
                                    </div>

                                    <div class="info-label">
                                        Location
                                    </div>

                                    <div class="info-value" id="gigLocation">
                                        Location not available
                                    </div>

                                </div>
                            </div>


                            <div class="col-md-6">
                                <div class="info-box">

                                    <div class="info-icon mb-2">
                                        <i class="fa-solid fa-calendar"></i>
                                    </div>

                                    <div class="info-label">
                                        Date
                                    </div>

                                    <div class="info-value" id="gigDate">
                                        Not specified
                                    </div>

                                </div>
                            </div>


                            <div class="col-md-6">
                                <div class="info-box">

                                    <div class="info-icon mb-2">
                                        <i class="fa-solid fa-clock"></i>
                                    </div>

                                    <div class="info-label">
                                        Starting Time
                                    </div>

                                    <div class="info-value" id="gigStartTime">
                                        Not specified
                                    </div>

                                </div>
                            </div>


                            <div class="col-md-6">
                                <div class="info-box">

                                    <div class="info-icon mb-2">
                                        <i class="fa-solid fa-clock"></i>
                                    </div>

                                    <div class="info-label">
                                        Ending Time
                                    </div>

                                    <div class="info-value" id="gigEndTime">
                                        Not specified
                                    </div>

                                </div>
                            </div>


                            <div class="col-md-6">
                                <div class="info-box">

                                    <div class="info-icon mb-2">
                                        <i class="fa-solid fa-indian-rupee-sign"></i>
                                    </div>

                                    <div class="info-label">
                                        Payment
                                    </div>

                                    <div class="info-value" id="gigPaymentInfo">
                                        ₹0
                                    </div>

                                </div>
                            </div>


                            <div class="col-md-6">
                                <div class="info-box">

                                    <div class="info-icon mb-2">
                                        <i class="fa-solid fa-star"></i>
                                    </div>

                                    <div class="info-label">
                                        Employer Rating
                                    </div>

                                    <div class="info-value" id="gigRating">
                                        0
                                    </div>

                                </div>
                            </div>

                        </div>
                    </div>


                    <!-- POSTED INFO -->
                    <div>

                        <h3 class="section-title">
                            Posted Information
                        </h3>

                        <div class="info-box">

                            <i class="fa-solid fa-circle-info info-icon me-2"></i>

                            <span id="postedInfo">
                                Posted information not available.
                            </span>

                        </div>

                    </div>

                </div>
            </div>


            <!-- RIGHT SIDE -->
            <div class="col-lg-4">

                <div class="apply-card">

                    <div class="total-label">
                        Total Payment
                    </div>

                    <div class="total-payment mb-4" id="totalPayment">
                        ₹0
                    </div>

                    <?php if ($gigStage === 'available') : ?>
                        <span class="gig-status available mb-3">Available</span>
                    <?php elseif ($gigStage === 'completed') : ?>
                        <span class="gig-status completed mb-3">Completed</span>
                        <p class="small text-muted mt-2">This one-time gig has been completed and is no longer available for applications.</p>
                    <?php else : ?>
                        <span class="gig-status in-progress mb-3">In Progress</span>
                        <p class="small text-muted mt-2">This gig is currently assigned to a student.</p>
                    <?php endif; ?>


                    <!-- APPLY NOW -->
                    <?php if ($gigStage === 'available') : ?>
                    <a href="applynow.php" id="applyBtn" class="btn btn-primary-custom mb-3">

                        <i class="bi bi-send-check me-1"></i>
                        Apply / Claim Gig

                    </a>
                    <?php endif; ?>


                    <!-- QUICK OVERVIEW -->
                    <div class="mt-4">

                        <h5 class="section-title mb-3">
                            Quick Overview
                        </h5>

                        <div class="quick-card mb-2">

                            <div class="info-label">
                                Category
                            </div>

                            <div class="info-value" id="quickCategory">
                                Retail Store
                            </div>

                        </div>


                        <div class="quick-card">

                            <div class="info-label">
                                Shift
                            </div>

                            <div class="info-value" id="quickShift">
                                Anytime
                            </div>

                        </div>

                    </div>

                </div>
            </div>

        </div>
        <?php endif; ?>
    </div>


    <!-- FOOTER -->
    <footer>

        <div class="container">
            <div class="row align-items-center gy-3">
                <div class="col-md-8 text-center text-md-start">
                    <strong>EarNova</strong> —
                    Connecting students with local opportunities.
                    <div class="mt-2">
                        © 2026 EarNova. All rights reserved.
                    </div>
                </div>
                <div class="col-md-4 text-center text-md-end">
                    <strong>Legal</strong>
                    <div class="mt-2">
                        <a href="#">Terms of Service</a>
                        <span class="mx-2">|</span>
                        <a href="#">Privacy Policy</a>
                    </div>
                </div>
            </div>

        </div>

    </footer>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>

        /* GET SELECTED GIG INFORMATION FROM URL */

        const params = new URLSearchParams(window.location.search);

        const gig = {
            id: params.get("id") || "",
            title: params.get("title") || "Gig Details",
            company: params.get("company") || "Company",
            category: params.get("category") || "Retail Store",
            location: params.get("location") || "Location not available",
            pay: params.get("pay") || "₹0",
            rating: params.get("rating") || "0",
            shift: params.get("shift") || "Anytime",
            date: params.get("date") || "",
            startTime: params.get("startTime") || "",
            endTime: params.get("endTime") || "",
            postedAt: params.get("postedAt") || "",
            description: params.get("description") || "No description available.",
            image: params.get("image") || <?php echo json_encode((string) ($gigRecord['gig_image'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>
        };

        function setRole(role) {
            document.getElementById('roleLabel').textContent = role;
        }

        function loginMessage() {
            const role = document.getElementById('roleLabel').textContent.trim();
            window.location.href = role === 'Admin' ? 'homepage.php?role=admin' : (role === 'Work Provider' ? 'homepage.php?role=provider' : 'homepage.php?role=student');
        }


        /* HELPER FUNCTION */

        function setText(id, value) {

            const element = document.getElementById(id);

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


        /* DISPLAY GIG INFORMATION */

        setText("gigTitle", gig.title);
        setText("companyName", gig.company);
        setText("categoryPill", gig.category);
        setText("quickCategory", gig.category);

        setText("gigLocation", gig.location);

        setText("gigPay", gig.pay);
        setText("gigPaymentInfo", gig.pay);
        setText("totalPayment", gig.pay);

        setText("gigRating", gig.rating);

        setText("quickShift", gig.shift);

        setText("gigDate", formatDate(gig.date));

        setText("gigStartTime", gig.startTime || "Not specified");
        setText("gigEndTime", gig.endTime || "Not specified");

        setText("gigDescription", gig.description);

        setText(
            "postedInfo",
            gig.postedAt || "Posted information not available."
        );


        /* DISPLAY IMAGE */

        const gigImage = document.getElementById("gigImage");

        if (gig.image) {
            gigImage.src = gig.image;
            gigImage.classList.remove("d-none");
        } else {
            gigImage.removeAttribute("src");
            gigImage.classList.add("d-none");
        }


        /* APPLY NOW */

        const applyBtn = document.getElementById("applyBtn");

        if (applyBtn) {

            applyBtn.addEventListener("click", function (event) {

                event.preventDefault();

                const query = new URLSearchParams({

                    id: gig.id,
                    title: gig.title,
                    company: gig.company,
                    category: gig.category,
                    location: gig.location,
                    pay: gig.pay,
                    rating: gig.rating,
                    shift: gig.shift,
                    date: gig.date,
                    startTime: gig.startTime,
                    endTime: gig.endTime,
                    postedAt: gig.postedAt,
                    description: gig.description,
                    image: gig.image

                });

                window.location.href =
                    "applynow.php?" + query.toString();

            });

        }
    </script>

</body>

</html>