// Shared navigation, reveal-on-scroll, and toast behavior for pages that include this script.

// Mobile nav toggle
const burgerBtn = document.getElementById('burgerBtn');
const headerNavMobile = document.getElementById('headerNavMobile');
if (burgerBtn && headerNavMobile){
  burgerBtn.addEventListener('click', () => {
    headerNavMobile.classList.toggle('open');
    burgerBtn.innerHTML = headerNavMobile.classList.contains('open')
      ? '<i class="fa-solid fa-xmark"></i>' : '<i class="fa-solid fa-bars"></i>';
  });
}

// Profile dropdown
const profileBtn = document.getElementById('profileBtn');
if (profileBtn){
  const dropdown = profileBtn.closest('.profile-dropdown');
  profileBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    dropdown.classList.toggle('open');
  });
  document.addEventListener('click', (e) => {
    if (!dropdown.contains(e.target)) dropdown.classList.remove('open');
  });
}

// Scroll reveal (shared observer) — exposed globally so pages can
// re-run it after injecting new [data-reveal] elements via JS.
const revealObserver = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting){
      entry.target.classList.add('in');
      revealObserver.unobserve(entry.target);
    }
  });
}, { threshold: 0.12 });

function observeReveals(scope=document){
  scope.querySelectorAll('[data-reveal]:not(.in)').forEach(el => revealObserver.observe(el));
}
observeReveals();
window.observeReveals = observeReveals;

// Generic toast (used across pages)
function showToast(msg, icon='fa-circle-check'){
  let t = document.getElementById('sharedToast');
  if (!t){
    t = document.createElement('div');
    t.id = 'sharedToast';
    t.className = 'shared-toast';
    document.body.appendChild(t);
  }
  t.innerHTML = `<i class="fa-solid ${icon}"></i> ${msg}`;
  t.classList.add('show');
  clearTimeout(window._toastTimer);
  window._toastTimer = setTimeout(() => t.classList.remove('show'), 2600);
}