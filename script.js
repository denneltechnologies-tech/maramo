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

  // Admission Form submission to ERP CRM
  const admissionForm = document.getElementById('admission-form');
  if (admissionForm) {
    admissionForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = document.getElementById('apply-submit-btn');
      const statusDiv = document.getElementById('application-status');
      const fd = new FormData(admissionForm);
      const data = Object.fromEntries(fd.entries());

      if (btn) {
        btn.disabled = true;
        btn.textContent = 'Submitting Application...';
      }

      const endpoints = ['/api/applications', 'http://localhost:8000/api/applications'];
      let resData = null;

      for (const url of endpoints) {
        try {
          const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(data),
          });
          if (res.ok) {
            resData = await res.json();
            break;
          }
        } catch (_) {}
      }

      if (resData && resData.success) {
        admissionForm.reset();
        const appNo = resData.application_no;
        const waText = encodeURIComponent(`Hello Maramo Beauty Institute, I just submitted an application for admission on your website. My application reference number is: ${appNo}.`);
        if (statusDiv) {
          statusDiv.style.display = 'block';
          statusDiv.innerHTML = `
            <div style="background:#f4fbf6; border:1px solid #c2e9cf; border-radius:12px; padding:1.25rem; color:#185a32;">
              <h4 style="margin:0 0 0.5rem; color:#185a32;">Application Received!</h4>
              <p style="margin:0 0 0.75rem; font-size:0.92rem;">Your reference number is <strong>${appNo}</strong>. Management will review your application shortly.</p>
              <a href="https://wa.me/233546662554?text=${waText}" target="_blank" rel="noopener noreferrer" class="btn btn-small" style="background:#25D366; border-color:#25D366; color:#fff;">Confirm on WhatsApp</a>
            </div>
          `;
        }
      } else {
        if (statusDiv) {
          statusDiv.style.display = 'block';
          const waFallback = encodeURIComponent(`Hello Maramo Beauty Institute, I would like to apply for training.\nName: ${data.first_name} ${data.last_name}\nPhone: ${data.phone}`);
          statusDiv.innerHTML = `
            <div style="background:#fff8ee; border:1px solid #f6deb6; border-radius:12px; padding:1.25rem; color:#784814;">
              <p style="margin:0 0 0.5rem;">Could not connect to ERP server directly. You can submit your application directly on WhatsApp:</p>
              <a href="https://wa.me/233546662554?text=${waFallback}" target="_blank" rel="noopener noreferrer" class="btn btn-small" style="background:#25D366; border-color:#25D366; color:#fff;">Send Application via WhatsApp</a>
            </div>
          `;
        }
      }

      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Submit Application';
      }
    });
  }

  // WhatsApp contact forms + ERP Inquiry sync
  document.querySelectorAll('form[data-wa-form]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      const name = (fd.get('name') || '').toString().trim();
      const phone = (fd.get('phone') || '').toString().trim();
      const service = (fd.get('service') || 'General Inquiry').toString().trim();
      const message = (fd.get('message') || '').toString().trim();

      // Async sync to ERP CRM
      const inquiryPayload = { name, phone, service, message };
      ['/api/inquiries', 'http://localhost:8000/api/inquiries'].forEach((url) => {
        fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify(inquiryPayload),
        }).catch(() => {});
      });

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
      const waNumber = '233546662554';
      window.open(`https://wa.me/${waNumber}?text=${text}`, '_blank', 'noopener,noreferrer');
    });
  });
})();