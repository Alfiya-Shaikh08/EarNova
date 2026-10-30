<?php // Public page describing EarNova and linking visitors to its main sections.
session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>About EarNova — Flexible Work Opportunities</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">





    <link rel="stylesheet" href="app.css?v=20261025-8">
    <link rel="stylesheet" href="theme.css">
    <link rel="stylesheet" href="site-nav.css">
</head>

<body class="page-about">
<!-- ================= NAVBAR ================= -->

    <?php
    $activeSiteNavPage = 'about';
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

<!-- HERO -->
<section class="about-hero">
<div class="about-hero-inner">
<div class="ah-text reveal">
<div class="eyebrow">ABOUT EARNOVA</div>
<h1>Empowering Students Through <span>Flexible Work</span> Opportunities</h1>
<p>EarNova connects students with trusted local businesses offering short-term, flexible jobs while helping employers quickly find reliable workers.</p>
<div class="ah-cta-row">
<a href="#how" class="btn-primary-teal">See How It Works <i class="fa-solid fa-arrow-right ms-1"></i></a>
<a href="browse-gigs.php" class="btn-outline-teal">Browse Gigs</a>
</div>
</div>
<img src="1.jpeg" class="about-img" alt="EarNova">
</div>
</section>

<!-- ABOUT -->
<section class="section">
<div class="section-inner narrow reveal">
<div class="eyebrow center">WHO WE ARE</div>
<h2 class="center">Bridging Students and Local Businesses</h2>
<p class="about-lead">EarNova is a web-based platform designed to bridge the gap between students seeking short-term work and businesses looking for temporary staff. It makes finding, applying for and managing flexible work opportunities easier through one centralized platform.</p>
</div>
</section>

<!-- MISSION -->
<section class="section section-tint">
<div class="section-inner">
<div class="grid-head center-head reveal">
<div class="eyebrow center">OUR MISSION</div>
<h2 class="center">What Drives EarNova</h2>
</div>
<div class="mission-grid" id="missionGrid"></div>
</div>
</section>

<!-- HOW IT WORKS -->
<section class="section" id="how">
<div class="section-inner">
<div class="grid-head reveal">
<div class="eyebrow center">THE PROCESS</div>
<h2 class="center">How EarNova Works</h2>
</div>
<div class="steps-flow" id="stepsFlow"></div>
</div>
</section>

<!-- FEATURES -->
<section class="section section-tint">
<div class="section-inner">
<div class="grid-head reveal">
<div class="eyebrow center">PLATFORM FEATURES</div>
<h2 class="center">Everything You Need in One Place</h2>
</div>
<div class="features-grid" id="featuresGrid"></div>
</div>
</section>

<!-- WHY CHOOSE -->
<section class="section">
<div class="section-inner">
<div class="grid-head reveal">
<div class="eyebrow center">WHY CHOOSE EARNOVA</div>
<h2 class="center">Built with Trust at the Core</h2>
</div>
<div class="why-grid" id="whyGrid"></div>
</div>
</section>

<!-- TEAM -->
<section class="section">
<div class="section-inner">
<div class="grid-head reveal">
<div class="eyebrow center">THE PEOPLE BEHIND IT</div>
<h2 class="center">EarNova Ecosystem</h2>
</div>
<div class="team-grid" id="teamGrid"></div>
</div>
</section>

<!-- CTA -->
<section class="final-cta-band">
<div class="fc-inner reveal">
<h2>Start Your Gig Journey Today</h2>
<p>Whether you're looking for flexible work or reliable help, EarNova connects you with the right opportunity.</p>
<div class="fc-buttons">
<a href="browse-gigs.php" class="btn-white-teal"><i class="fa-solid fa-graduation-cap me-1"></i> Join as Student</a>
<a href="categories.php" class="btn-outline-white"><i class="fa-solid fa-store me-1"></i> Hire Students</a>
</div>
</div>
</section>

<!-- ===== FOOTER ===== -->
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
                        <span>Accounts are provisioned by an administrator</span>
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

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script>
const mission=[
["fa-bolt","Flexible Opportunities","Help students discover short-term work that fits their skills and availability."],
["fa-handshake","Easy Connection","Create a simple connection between students and local work providers."],
["fa-shield-halved","Trusted Ecosystem","Support a more organized process for applications, verification and payments."]
];
const steps=[
["01","Find a Gig","Browse available gigs and check the work, date, time and payment details."],
["02","Apply","Submit your basic information and apply for the gig that suits you."],
["03","Complete & Verify","Get selected, complete the shift and verify the work through the platform."]
];
const features=[
["fa-magnifying-glass","Browse Gigs","Explore available short-term opportunities."],
["fa-paper-plane","Easy Application","Apply for suitable gigs through a simple process."],
["fa-clock","Flexible Work","Find opportunities based on your available time."],
["fa-circle-check","Shift Verification","Verify completed work using a shift code."],
["fa-wallet","Payment Tracking","Keep track of payment information for completed gigs."],
["fa-chart-line","Activity Monitoring","Support monitoring of gigs and platform activities."]
];
const why=[
["fa-location-dot","Local Opportunities","Focuses on connecting students with nearby businesses and work providers."],
["fa-user-group","Two-Way Platform","Designed for both students seeking work and providers seeking temporary support."],
["fa-layer-group","Centralized System","Applications, gigs, verification and payment information are organized in one place."]
];
const team=[
["fa-graduation-cap","Students","Find flexible short-term work opportunities."],
["fa-store","Work Providers","Post gigs and find suitable student workers."],
["fa-user-shield","Admin","Monitor and manage the overall platform."]
];
function renderCards(id,data,type){
document.getElementById(id).innerHTML=data.map(x=>type==="step"?`<div class="step-card reveal"><div class="step-number">${x[0]}</div><div class="card-title">${x[1]}</div><p class="card-text">${x[2]}</p></div>`:type==="team"?`<div class="team-card reveal"><div class="team-icon"><i class="fa-solid ${x[0]}"></i></div><div class="card-title">${x[1]}</div><div class="team-role">${x[2]}</div></div>`:`<div class="${type}-card reveal"><div class="icon-box"><i class="fa-solid ${x[0]}"></i></div><div class="card-title">${x[1]}</div><p class="card-text">${x[2]}</p></div>`).join("");
}
renderCards("missionGrid",mission,"info");
renderCards("stepsFlow",steps,"step");
renderCards("featuresGrid",features,"feature");
renderCards("whyGrid",why,"why");
renderCards("teamGrid",team,"team");

const observer=new IntersectionObserver(entries=>{
entries.forEach(entry=>{if(entry.isIntersecting)entry.target.classList.add("show")});
},{threshold:.12});
document.querySelectorAll(".reveal").forEach(el=>observer.observe(el));
/* ================= LOGIN ROLE ================= */
        function setRole(role) {
            document.getElementById('roleLabel')
                .textContent = role;
        }
        /* ================= DEMO LOGIN ================= */
        function loginMessage() {
    const role = document.getElementById('roleLabel').textContent.trim();

    if (role === 'Work Provider') {
        window.location.href = "homepage.php?role=provider";
    } else if (role === 'Student') {
        window.location.href = "homepage.php?role=student";
    } else if (role === 'Admin') {
        window.location.href = "homepage.php?role=admin";
    }
}
</script>
</body>
</html>