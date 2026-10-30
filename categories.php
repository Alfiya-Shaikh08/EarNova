<?php
// Lists active gig categories and the number of available gigs in each category.
session_start();
require __DIR__ . '/php/db.php';

// Read active categories from MySQL and show them on the page
$categoryResult = $conn->query("SELECT * FROM gig_database.categories WHERE status = 'Active' ORDER BY category_id DESC");
$gigCountMap = [];
$gigCountResult = $conn->query("SELECT LOWER(TRIM(g.category)) AS category_key, COUNT(*) AS total FROM gig_database.gigs g INNER JOIN users u ON u.user_id = g.provider_id AND u.role = 'Provider' AND u.status = 'Active' WHERE g.status = 'Active' GROUP BY LOWER(TRIM(g.category))");
if ($gigCountResult) {
    while ($gigCountRow = $gigCountResult->fetch_assoc()) {
        $gigCountMap[(string) $gigCountRow['category_key']] = (int) $gigCountRow['total'];
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Explore Gig Categories — EarNova</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">




    <link rel="stylesheet" href="app.css?v=20261025-8">
    <link rel="stylesheet" href="common-category.css">
    <link rel="stylesheet" href="site-nav.css">
    <link rel="stylesheet" href="categories.css?v=20261029-5">
</head>
<body class="page-categories">
<?php
$activeSiteNavPage = 'categories';
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

<section class="cat-hero">
  <div class="cat-hero-inner">
    <div class="cat-hero-text" data-reveal>
      <h1>Explore Gig Categories</h1>
      <p>Find flexible part-time and short-term opportunities that match your skills.</p>
    </div>

    <div class="cat-search-box" data-reveal>
      <div class="cs-field cs-search">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" placeholder="Search a category — e.g. Cafe, Tutor, Delivery">
      </div>
      <div class="cs-field cs-select">
        <i class="fa-solid fa-filter"></i>
        <select id="sortSelect">
          <option value="popular">Most Popular</option>
          <option value="gigs">Most Gigs</option>
          <option value="pay">Highest Pay</option>
          <option value="az">A–Z</option>
        </select>
      </div>
      <button class="btn-teal-search"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
    </div>
  </div>
</section>

<section class="section category-section">
  <div class="section-inner">
    <div class="grid-head" data-reveal>
      <div class="quick-filters" id="quickFilters">
        <button class="qf-chip active" data-filter="all">All</button>
        <button class="qf-chip" data-filter="popular">Popular</button>
        <button class="qf-chip" data-filter="trending">Trending</button>
      </div>
      <div class="result-count" id="resultCount">0 categories</div>
    </div>

    <div class="category-grid" id="categoryGrid">
      <?php while ($row = $categoryResult->fetch_assoc()) { ?>
        <?php
            $categoryName = strtolower(trim($row['category_name']));
            $gigsCount = (int) ($gigCountMap[$categoryName] ?? 0);
            $pageLink = 'gigs.php?category=' . rawurlencode($row['category_name']);
            $payValue = 300;
            if ($categoryName === 'retail store') $payValue = 280;
            if ($categoryName === 'medical store') $payValue = 320;
            if ($categoryName === 'restaurant') $payValue = 300;
            if ($categoryName === 'data entry') $payValue = 300;
            if ($categoryName === 'event staff') $payValue = 400;
            if ($categoryName === 'cafe') $payValue = 400;
            if ($categoryName === 'video editing') $payValue = 500;
            if ($categoryName === 'sales promotion') $payValue = 350;
        ?>
        <div class="category-card glass-card" data-tag="popular" data-gigs="<?php echo $gigsCount; ?>" data-pay="<?php echo $payValue; ?>">
            <div class="cc-image">
                <a href="<?php echo htmlspecialchars($pageLink); ?>">
                    <img src="<?php echo htmlspecialchars($row['category_image']); ?>" alt="<?php echo htmlspecialchars($row['category_name']); ?>">
                </a>
            </div>
            <div class="cc-body">
                <div class="cc-name"><?php echo htmlspecialchars($row['category_name']); ?></div>
                <div class="cc-stats">
                    <span class="cc-stat"><i class="fa-solid fa-briefcase"></i> <strong><?php echo $gigsCount; ?></strong> gigs</span>
                </div>
                <a href="<?php echo htmlspecialchars($pageLink); ?>" class="btn-view-jobs">
                    View Gigs <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
      <?php } ?>
    </div>
  </div>
</section>

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
<script src="shared.js"></script>
<script>
const grid = document.getElementById('categoryGrid');
const resultCount = document.getElementById('resultCount');

function updateVisibleCount() {
  const visible = grid.querySelectorAll('.category-card:not(.is-hidden)').length;
  resultCount.textContent = `${visible} categor${visible === 1 ? 'y' : 'ies'}`;
}

document.getElementById('quickFilters').addEventListener('click', (event) => {
  const chip = event.target.closest('.qf-chip');
  if (!chip) return;
  document.querySelectorAll('.qf-chip').forEach((item) => item.classList.remove('active'));
  chip.classList.add('active');

  const filter = chip.dataset.filter;
  grid.querySelectorAll('.category-card').forEach((card) => {
    const matches = filter === 'all' || card.dataset.tag === filter;
    card.classList.toggle('is-hidden', !matches);
    card.style.display = matches ? '' : 'none';
  });
  updateVisibleCount();
});

document.getElementById('sortSelect').addEventListener('change', (event) => {
  const value = event.target.value;
  const cards = Array.from(grid.querySelectorAll('.category-card'));

  cards.sort((a, b) => {
    if (value === 'gigs') return parseInt(b.dataset.gigs, 10) - parseInt(a.dataset.gigs, 10);
    if (value === 'pay') return parseInt(b.dataset.pay, 10) - parseInt(a.dataset.pay, 10);
    if (value === 'az') {
      return a.querySelector('.cc-name').textContent.localeCompare(b.querySelector('.cc-name').textContent);
    }
    return 0;
  });

  cards.forEach((card) => grid.appendChild(card));
});

document.querySelector('.cs-search input').addEventListener('input', (event) => {
  const query = event.target.value.toLowerCase().trim();
  grid.querySelectorAll('.category-card').forEach((card) => {
    const name = card.querySelector('.cc-name').textContent.toLowerCase();
    const matches = name.includes(query);
    card.classList.toggle('is-hidden', !matches);
    card.style.display = matches ? '' : 'none';
  });
  updateVisibleCount();

  if (query) {
    document.querySelectorAll('.qf-chip').forEach((chip) => {
      chip.classList.toggle('active', chip.dataset.filter === 'all');
    });
  }
});

updateVisibleCount();

function setRole(role) {
  document.getElementById('roleLabel').textContent = role;
  const loginRole = document.getElementById('loginRole');
  if (loginRole) {
    loginRole.value = role === 'Work Provider' ? 'Provider' : role;
  }
}
</script>
</body>
</html>