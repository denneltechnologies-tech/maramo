// Maramo Beauty Institute - Site Script
(() => {
  const header = document.getElementById('site-header');
  const navToggle = document.getElementById('nav-toggle');
  const siteNav = document.getElementById('site-nav');
  const yearEl = document.getElementById('year');

  if (yearEl) yearEl.textContent = new Date().getFullYear();

  const onScroll = () => {
    if (!header) return;
    header.classList.toggle('scrolled', window.scrollY > 10);
  };
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  const closeNav = () => {
    if (!navToggle || !siteNav) return;
    navToggle.setAttribute('aria-expanded', 'false');
    navToggle.setAttribute('aria-label', 'Open menu');
    siteNav.classList.remove('open');
  };

  if (navToggle && siteNav) {
    navToggle.addEventListener('click', () => {
      const isOpen = navToggle.getAttribute('aria-expanded') === 'true';
      navToggle.setAttribute('aria-expanded', String(!isOpen));
      navToggle.setAttribute('aria-label', isOpen ? 'Open menu' : 'Close menu');
      siteNav.classList.toggle('open', !isOpen);
    });

    siteNav.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        if (window.innerWidth <= 760) closeNav();
      });
    });

    document.addEventListener('click', (e) => {
      if (!siteNav.classList.contains('open')) return;
      const target = e.target;
      if (!(target instanceof Node)) return;
      if (!siteNav.contains(target) && !navToggle.contains(target)) closeNav();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeNav();
    });

    window.addEventListener('resize', () => {
      if (window.innerWidth > 760) closeNav();
    });
  }

  // Active nav link
  const path = (location.pathname.split('/').pop() || 'index.html').toLowerCase();
  document.querySelectorAll('.site-nav a[data-page]').forEach((a) => {
    const page = (a.getAttribute('data-page') || '').toLowerCase();
    const active = page === path || (path === '' && page === 'index.html');
    a.classList.toggle('active', active);
    if (active) a.setAttribute('aria-current', 'page');
  });

  // WhatsApp contact forms
  document.querySelectorAll('form[data-wa-form]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      const name = (fd.get('name') || '').toString().trim();
      const phone = (fd.get('phone') || '').toString().trim();
      const service = (fd.get('service') || 'General Inquiry').toString().trim();
      const message = (fd.get('message') || '').toString().trim();
      const lines = [
        'Hello Maramo Beauty Institute,',
        '',
        `Name: ${name}`,
        phone ? `Phone: ${phone}` : null,
        `Service Interest: ${service}`,
        '',
        'Message:',
        message,
      ].filter(Boolean);
      const text = encodeURIComponent(lines.join('\n'));
      const waNumber = '233546662554'; // Confirm before publishing
      window.open(`https://wa.me/${waNumber}?text=${text}`, '_blank', 'noopener,noreferrer');
    });
  });
})();