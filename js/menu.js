const navbar = document.querySelector('.navbar');
const navLinks = document.querySelector('.nav-links');
const loginBox = document.querySelector('.login-box');
const loginTrigger =
  document.querySelector('.login-box [data-login-toggle]') ||
  document.querySelector('.login-box .login-trigger') ||
  document.querySelector('.login-box .bx-user');
const loginCaret = document.querySelector('.login-trigger-caret');

function setAccountMenuOpen(isOpen) {
  if (!navbar) return;
  navbar.classList.toggle('showInput', isOpen);
  if (loginTrigger) loginTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  if (loginCaret) {
    loginCaret.classList.toggle('bx-chevron-down', !isOpen);
    loginCaret.classList.toggle('bx-x', isOpen);
  }
}

loginTrigger?.addEventListener('click', (e) => {
  e.preventDefault();
  e.stopPropagation();
  setAccountMenuOpen(!navbar?.classList.contains('showInput'));
});
document.addEventListener('click', (e) => {
  if (!navbar?.classList.contains('showInput')) return;
  if (!loginBox?.contains(e.target)) {
    setAccountMenuOpen(false);
  }
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') setAccountMenuOpen(false);
});
document.querySelector('.navbar .bx-menu')?.addEventListener('click', () => {
  if (navLinks) navLinks.style.left = '0';
});
document.querySelector('.nav-links .sidebar-logo .fa-times, .nav-links .sidebar-logo .bx-x')?.addEventListener('click', () => {
  if (navLinks) navLinks.style.left = '-100%';
});
document.querySelectorAll('.js-arrow').forEach((arrow) => {
  arrow.addEventListener('click', (e) => {
    const li = e.currentTarget.closest('li');
    if (li) li.classList.toggle('show-submenu');
  });
});
const helpButton = document.getElementsByClassName("help-button")[0];
if (helpButton) {
  helpButton.addEventListener('click', (e) => {
    var menu = document.querySelector('.help-button-wrapper');
    menu.classList.toggle('expanded');
  });
}