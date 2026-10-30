<?php
// Serves the approved-student gig browser and its JSON feed of currently active gigs.
session_start();
$isGigFeedRequest = ($_GET['feed'] ?? '') === '1';
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Student') {
    if ($isGigFeedRequest) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'An authenticated student account is required.']);
        exit;
    }
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/php/require_approved.php';
$browseVerification = getStudentVerification($pdo, (int) $_SESSION['user_id']);
if (($browseVerification['status'] ?? '') !== 'approved') {
    if ($isGigFeedRequest) {
        http_response_code(($browseVerification['status'] ?? '') === 'error' ? 500 : 403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $browseVerification['message'] ?? 'Your college ID must be approved before you can view gigs.']);
        exit;
    }
    header('Location: student.php');
    exit;
}

if ($isGigFeedRequest) {
    // The browser loads this JSON feed so filters and pagination reflect current database records.
    header('Content-Type: application/json; charset=utf-8');
    $conn = new mysqli('localhost', 'root', '', 'user_database');
    if ($conn->connect_error) {
        error_log('EarNova gig feed database connection failed: ' . $conn->connect_error);
        http_response_code(500);
        echo json_encode(['error' => 'Gig feed is temporarily unavailable.']);
        exit;
    }

    $query = "
        SELECT g.gig_id,
               COALESCE(g.task_title, 'Untitled Gig') AS title,
               COALESCE(u.full_name, 'Provider') AS company,
               COALESCE(NULLIF(TRIM(p.business_name), ''), '') AS business_name,
               COALESCE(c.category_name, g.category, 'General') AS category,
               COALESCE(g.location, '') AS location,
               COALESCE(g.payment_amount, 0) AS salary,
               COALESCE(g.shift_date, '') AS date,
               COALESCE(g.start_time, '') AS start_time,
               COALESCE(g.end_time, '') AS end_time,
               COALESCE(g.task_description, '') AS description,
               COALESCE(g.gig_image, '') AS gig_image
        FROM gig_database.gigs g
        INNER JOIN users u ON u.user_id = g.provider_id AND u.role = 'Provider' AND u.status = 'Active'
        INNER JOIN students verified_student ON verified_student.user_id = ? AND verified_student.verification_status = 'approved'
        LEFT JOIN providers p ON p.user_id = g.provider_id
        LEFT JOIN gig_database.categories c ON c.category_id = g.category_id
        WHERE LOWER(COALESCE(g.status, 'Pending')) = 'active'
        ORDER BY g.gig_id DESC
    ";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log('EarNova gig feed query preparation failed: ' . $conn->error);
        $conn->close();
        http_response_code(500);
        echo json_encode(['error' => 'Gig feed is temporarily unavailable.']);
        exit;
    }

    $studentId = (int) $_SESSION['user_id'];
    $stmt->bind_param('i', $studentId);
    if (!$stmt->execute()) {
        error_log('EarNova gig feed query failed: ' . $stmt->error);
        $stmt->close();
        $conn->close();
        http_response_code(500);
        echo json_encode(['error' => 'Gig feed is temporarily unavailable.']);
        exit;
    }

    $gigs = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $startTime = $row['start_time'] ?: '12:00:00';
        $endTime = $row['end_time'] ?: $startTime;
        $startHour = (int) date('G', strtotime($startTime));
        $shift = $startHour < 12 ? 'morning' : ($startHour < 17 ? 'afternoon' : 'evening');
        $gigs[] = [
            'id' => (int) $row['gig_id'],
            'title' => (string) $row['title'],
            'company' => (string) $row['company'],
            'businessName' => (string) $row['business_name'],
            'category' => (string) $row['category'],
            'location' => (string) $row['location'],
            'salary' => (float) $row['salary'],
            'duration' => '1 shift',
            'hours' => date('g:i A', strtotime($startTime)) . ' – ' . date('g:i A', strtotime($endTime)),
            'shift' => $shift,
            'vacancies' => 1,
            'rating' => 0,
            'posted' => 0,
            'type' => 'part-time',
            'date' => (string) $row['date'],
            'description' => (string) $row['description'],
            'gigImage' => (string) $row['gig_image'],
        ];
    }
    $stmt->close();
    $conn->close();
    echo json_encode($gigs, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Browse Gigs - EarNova</title>
  <link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap"
    rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  
  
    <link rel="stylesheet" href="app.css?v=20261025-8">
    <link rel="stylesheet" href="common-category.css">
    <link rel="stylesheet" href="site-nav.css">
    <link rel="stylesheet" href="browse-gigs.css?v=20261029-2">
</head>

<body class="page-browse-gigs" id="browsePage">
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

  <section class="bg-hero">
    <div class="bg-hero-inner">
      <div class="eyebrow">BROWSE OPEN GIGS</div>
      <h1 id="pageTitle">Find Your Next Gig</h1>
      <p>Browse flexible short-term gigs and find an opportunity that suits you.</p>
    </div>
  </section>
  <section class="search-band">
    <div class="search-band-inner">
      <div class="sf-field"><label>Gig title</label>
        <div class="sf-input"><i class="fa-solid fa-magnifying-glass"></i><input id="fTitle"
            placeholder="e.g. Cafe, Delivery..."></div>
      </div>
      <div class="sf-field"><label>Location</label>
        <div class="sf-input"><i class="fa-solid fa-location-dot"></i><input id="fLocation" placeholder="Koregaon Park">
        </div>
      </div>
      <div class="sf-field" id="categoryFilterField"><label>Category</label>
        <div class="sf-input"><i class="fa-solid fa-layer-group"></i><select id="fCategory">
            <option value="">All Categories</option>
          </select></div>
      </div>
      <div class="sf-field"><label>Amount</label>
        <div class="sf-input"><i class="fa-solid fa-indian-rupee-sign"></i><select id="fSalary">
            <option value="0">Any</option>
            <option value="300">₹300+</option>
            <option value="350">₹350+</option>
            <option value="400">₹400+</option>
          </select></div>
      </div>
      <div class="sf-field"><label>Date</label>
        <div class="sf-input"><i class="fa-regular fa-calendar"></i><input type="date" id="fDate"></div>
      </div>
      <div class="sf-field"><label>Availability</label>
        <div class="sf-input"><i class="fa-regular fa-clock"></i><select id="fAvailability">
            <option value="">Anytime</option>
            <option value="morning">Morning</option>
            <option value="afternoon">Afternoon</option>
            <option value="evening">Evening</option>
          </select></div>
      </div>
      <button class="btn-search-main" id="mainSearchBtn"><i class="fa-solid fa-magnifying-glass"></i> Search
        Gigs</button>
    </div>
  </section>
  <section class="section">
    <div class="section-inner">
      <div class="gigs-top-bar">
        <div><span class="gtb-count" id="gtbCount"></span><span class="gtb-filters" id="gtbActiveFilters"></span></div>
        <div class="gtb-right"><label>Sort by</label><select id="sortGigs">
            <option value="newest">Newest</option>
            <option value="salary">Highest Salary</option>
            <option value="popular">Most Vacancies</option>
            <option value="rating">Highest Rated</option>
          </select></div>
      </div>
      <div class="gigs-list" id="gigsList"></div>
      <div class="pagination" id="pagination"></div>
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
  <div class="toast-box" id="toast"></div>
  <script>
    let allGigs = [];
    const categoryPage = window.location.pathname.toLowerCase().endsWith('/gigs.php') || window.location.pathname.toLowerCase().endsWith('\\gigs.php');
    const selectedCategoryParam = new URLSearchParams(window.location.search).get('category');
    const selectedCategory = selectedCategoryParam ? selectedCategoryParam.trim() : '';
    if (categoryPage) {
      document.body.classList.add('category-page');
      document.getElementById('categoryFilterField').style.display = 'none';
      document.getElementById('pageTitle').textContent = selectedCategory || 'Gigs';
    }
    let sort = 'newest', page = 1, perPage = 6;
    const cats = [...new Set(allGigs.map(g => g.category))];
    fCategory.innerHTML += cats.map(c => `<option>${c}</option>`).join('');

    function img(g) {
      return g.gigImage || '';
    }

    function q(g) {
      return new URLSearchParams({ id: g.id || g.title.toLowerCase().replace(/[^a-z0-9]+/g, '-'), title: g.title, company: g.company, category: g.category, location: g.location, pay: '₹' + g.salary, rating: g.rating, shift: g.duration, date: g.date, startTime: g.hours.split(' – ')[0] || g.hours, endTime: g.hours.split(' – ')[1] || '', postedAt: g.posted + ' days ago', description: g.description, image: img(g) }).toString();
    }
    function card(g) {
      const imageMarkup = img(g) ? `<div class="gig-img-wrap"><img class="gig-image" src="${img(g)}" alt="${g.title}"></div>` : '';
      const businessName = g.businessName || g.company;
      return `<article class="gig-card">
  ${imageMarkup}
  <div class="gig-body">
  <div class="gig-top"><span class="gig-price">₹${g.salary}</span></div>
  <h3 class="gig-title">${g.title}</h3>
  <div class="gig-company"><i class="fa-solid fa-store"></i>${businessName}</div>
  <div class="gig-company"><i class="fa-solid fa-user"></i>${g.company}</div>
  <div class="gig-location"><i class="fa-solid fa-location-dot"></i>${g.location}</div>
  <div class="gig-time"><i class="fa-regular fa-clock"></i>${g.hours}</div>
  <div class="gig-meta"><span>${g.vacancies} vacancies</span><span>${g.type}</span></div>
  <div class="gig-actions"><button class="btn-details" onclick="viewGigDetails(${allGigs.indexOf(g)})">Details</button>
  <button class="btn-apply" onclick="applyGig(${allGigs.indexOf(g)})">Apply Now</button>
  <button class="btn-save" onclick="toggleSave(this,'${g.title.replace(/'/g, "\\'")}')"><i class="fa-regular fa-bookmark"></i></button></div></div></article>`;
    }

    function filtered() {
      let a = [...allGigs], t = fTitle.value.toLowerCase().trim(), l = fLocation.value.toLowerCase().trim(), c = categoryPage ? selectedCategory : fCategory.value, s = +fSalary.value, sh = fAvailability.value, d = fDate.value; if (t) a = a.filter(g => (g.title + ' ' + g.company + ' ' + g.category).toLowerCase().includes(t));
      if (l) a = a.filter(g => g.location.toLowerCase().includes(l));
      if (c) a = a.filter(g => g.category.toLowerCase() === c.toLowerCase());
      if (s) a = a.filter(g => g.salary >= s); if (sh) a = a.filter(g => g.shift === sh);
      if (d) a = a.filter(g => g.date === d); return a;
    }

    function render() {
      let a = filtered();
      if (sort === 'salary') a.sort((x, y) => y.salary - x.salary);
      else if (sort === 'rating') a.sort((x, y) => y.rating - x.rating);
      else if (sort === 'popular') a.sort((x, y) => y.vacancies - x.vacancies);
      else a.sort((x, y) => x.posted - y.posted); gtbCount.textContent = `${a.length} job${a.length === 1 ? '' : 's'} found`;
      let pages = Math.max(1, Math.ceil(a.length / perPage)); page = Math.min(page, pages);
      let show = a.slice((page - 1) * perPage, page * perPage);
      gigsList.innerHTML = show.length ? show.map(card).join('') : `<div class="empty"><i class="fa-solid fa-magnifying-glass fs-3"></i><p class="mt-2 mb-0">No gigs match your search.</p></div>`;
      let h = `<button class="page-btn" ${page === 1 ? 'disabled' : ''} onclick="changePage(${page - 1})">‹</button>`;
      for (let i = 1; i <= pages; i++)h += `<button class="page-btn ${i === page ? 'active' : ''}" onclick="changePage(${i})">${i}</button>`;
      h += `<button class="page-btn" ${page === pages ? 'disabled' : ''} onclick="changePage(${page + 1})">›</button>`;
      pagination.innerHTML = h;
    }

    function changePage(p) {
      if (p < 1) return; page = p;
      render(); gigsList.scrollIntoView({ behavior: 'smooth' });
    }
    window.changePage = changePage;

    function toast(t) {
      let x = document.getElementById('toast');
      x.textContent = t; x.classList.add('show');
      setTimeout(() => x.classList.remove('show'), 1800);
    }

    function toggleSave(button, title) {
      button.classList.toggle('saved');
      button.innerHTML = button.classList.contains('saved') ? '<i class="fa-solid fa-bookmark"></i>' : '<i class="fa-regular fa-bookmark"></i>';
      toast(button.classList.contains('saved') ? title + ' saved' : 'Removed from saved');
    }

    function viewGigDetails(index) {
      const g = allGigs[index]; if (g) window.location.href = 'Gigdetails.php?' + q(g);
    }

    function applyGig(index) {
      const g = allGigs[index];
      if (g) window.location.href = 'applynow.php?' + q(g);
    }
    window.toggleSave = toggleSave; window.viewGigDetails = viewGigDetails; window.applyGig = applyGig;
    mainSearchBtn.onclick = () => {
      page = 1; render();
    };
    sortGigs.onchange = e => {
      sort = e.target.value; page = 1; render();
    };
    ['fTitle', 'fLocation', 'fCategory', 'fSalary', 'fDate', 'fAvailability'].forEach(id => document.getElementById(id).addEventListener('change', () => { page = 1; render(); }));
    fTitle.oninput = fLocation.oninput = () => { page = 1; render(); };
    function loginMessage() {
      window.location.href = 'homepage.php?role=student';
    }

    const gigsFeedUrl = new URL(window.location.href);
    gigsFeedUrl.searchParams.set('feed', '1');
    fetch(gigsFeedUrl).then(response => response.ok ? response.json() : Promise.reject(new Error('Unable to load gigs'))).then(gigs => {
      allGigs = gigs;
      const categories = [...new Set(allGigs.map(g => g.category))].sort(); fCategory.innerHTML = '<option value="">All Categories</option>' + categories.map(category => `<option>${category}</option>`).join(''); render();
    }).catch(error => {
      console.error(error);
      allGigs = [];
      render();
      toast('Unable to load gigs. Please refresh and try again.');
    });
  </script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
</body>

</html>