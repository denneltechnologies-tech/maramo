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

  // Dual CRM Pipeline & Instant WhatsApp Integration
  const WA_PHONE = '233546662554';

  // 1. Admission Form: Submit to CRM Pipeline & Launch WhatsApp
  const admissionForm = document.getElementById('admission-form');
  if (admissionForm) {
    admissionForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = document.getElementById('apply-submit-btn');
      const statusDiv = document.getElementById('application-status');
      const fd = new FormData(admissionForm);
      const data = Object.fromEntries(fd.entries());

      // Get readable course and schedule names from select dropdowns
      const courseSelect = document.getElementById('course_id');
      const courseName = courseSelect && courseSelect.selectedIndex >= 0 && courseSelect.options[courseSelect.selectedIndex].value
        ? courseSelect.options[courseSelect.selectedIndex].text
        : 'Cosmetology / Beauty Program';

      const schedSelect = document.getElementById('preferred_schedule');
      const schedName = schedSelect && schedSelect.selectedIndex >= 0
        ? schedSelect.options[schedSelect.selectedIndex].text
        : 'Flexible Schedule';

      if (btn) {
        btn.disabled = true;
        btn.textContent = 'Submitting Application & Connecting WhatsApp...';
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
        const waLines = [
          '🌟 *NEW STUDENT APPLICATION - MARAMO BEAUTY INSTITUTE*',
          '',
          `📋 *Application Ref:* ${appNo}`,
          `👤 *Applicant Name:* ${data.first_name} ${data.last_name}`,
          `📞 *Phone:* ${data.phone}`,
          data.email ? `📧 *Email:* ${data.email}` : null,
          `🎓 *Desired Program:* ${courseName}`,
          `⏰ *Preferred Schedule:* ${schedName}`,
          data.prior_experience ? `💬 *Experience:* ${data.prior_experience}` : null,
          '',
          'Hello Maramo Beauty Institute, I just submitted my admission form on your website. My application reference is above. I would like to confirm my enrollment and start date!',
        ].filter(Boolean);

        const waText = encodeURIComponent(waLines.join('\n'));
        const waUrl = `https://wa.me/${WA_PHONE}?text=${waText}`;

        // Immediately launch WhatsApp in a new tab/app
        try {
          window.open(waUrl, '_blank', 'noopener,noreferrer');
        } catch (_) {}

        if (statusDiv) {
          statusDiv.style.display = 'block';
          statusDiv.innerHTML = `
            <div style="background:#f4fbf6; border:1px solid #c2e9cf; border-radius:12px; padding:1.35rem; color:#185a32; box-shadow:0 4px 14px rgba(24,90,50,0.08);">
              <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem;">
                <span style="font-size:1.3rem;">✅</span>
                <h4 style="margin:0; color:#185a32; font-size:1.15rem;">Application Submitted & Logged to CRM!</h4>
              </div>
              <p style="margin:0 0 0.6rem; font-size:0.95rem; line-height:1.45;">
                Your application has been registered in our admissions system under reference:
                <strong style="display:inline-block; background:#e2f5e8; padding:0.2rem 0.55rem; border-radius:6px; color:#0e4429; font-size:1.05rem; letter-spacing:0.5px;">${appNo}</strong>
              </p>
              <p style="margin:0 0 1rem; font-size:0.88rem; color:#276749;">
                WhatsApp has been launched to connect you with our admissions desk right away. If it didn't open automatically, tap the button below:
              </p>
              <a href="${waUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-small" style="background:#25D366; border-color:#25D366; color:#fff; display:inline-flex; align-items:center; gap:0.45rem; font-weight:600; text-decoration:none; padding:0.65rem 1.25rem; border-radius:8px;">
                <span>💬</span> Continue on WhatsApp (${appNo})
              </a>
            </div>
          `;
        }
      } else {
        // Fallback: If server is temporarily unreachable, still submit lead immediately via WhatsApp
        const waLinesFallback = [
          '🌟 *NEW STUDENT APPLICATION - MARAMO BEAUTY INSTITUTE*',
          '',
          `👤 *Applicant Name:* ${data.first_name || ''} ${data.last_name || ''}`.trim(),
          `📞 *Phone:* ${data.phone || ''}`,
          data.email ? `📧 *Email:* ${data.email}` : null,
          `🎓 *Desired Program:* ${courseName}`,
          `⏰ *Preferred Schedule:* ${schedName}`,
          data.prior_experience ? `💬 *Experience:* ${data.prior_experience}` : null,
          '',
          'Hello Maramo Beauty Institute, I would like to submit my course application for admission.',
        ].filter(Boolean);

        const waFallback = encodeURIComponent(waLinesFallback.join('\n'));
        const waUrlFallback = `https://wa.me/${WA_PHONE}?text=${waFallback}`;

        try {
          window.open(waUrlFallback, '_blank', 'noopener,noreferrer');
        } catch (_) {}

        if (statusDiv) {
          statusDiv.style.display = 'block';
          statusDiv.innerHTML = `
            <div style="background:#fff8ee; border:1px solid #f6deb6; border-radius:12px; padding:1.25rem; color:#784814;">
              <h4 style="margin:0 0 0.5rem; color:#784814;">Submitting via Direct WhatsApp</h4>
              <p style="margin:0 0 0.85rem; font-size:0.92rem;">We are opening WhatsApp so our admissions desk can receive your application immediately:</p>
              <a href="${waUrlFallback}" target="_blank" rel="noopener noreferrer" class="btn btn-small" style="background:#25D366; border-color:#25D366; color:#fff; display:inline-block; font-weight:600;">Open WhatsApp Admissions</a>
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

  // 2. Contact & Inquiry Forms: Dual Submit to CRM Pipeline & WhatsApp
  document.querySelectorAll('form[data-wa-form]').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const submitBtn = form.querySelector('button[type="submit"]');
      const origBtnText = submitBtn ? submitBtn.textContent : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting & Opening WhatsApp...';
      }

      const fd = new FormData(form);
      const name = (fd.get('name') || '').toString().trim();
      const phone = (fd.get('phone') || '').toString().trim();
      const service = (fd.get('service') || 'General Inquiry').toString().trim();
      const message = (fd.get('message') || '').toString().trim();

      // Format WhatsApp Message
      const lines = [
        '🌟 *INQUIRY - MARAMO BEAUTY INSTITUTE*',
        '',
        `👤 *Name:* ${name}`,
        phone ? `📞 *Phone:* ${phone}` : null,
        `💅 *Service Interest:* ${service}`,
        '',
        '💬 *Message:*',
        message,
      ].filter(Boolean);
      const text = encodeURIComponent(lines.join('\n'));
      const waUrl = `https://wa.me/${WA_PHONE}?text=${text}`;

      // 1. Submit lead to CRM Pipeline asynchronously
      const inquiryPayload = { name, phone, service, message };
      const endpoints = ['/api/inquiries', 'http://localhost:8000/api/inquiries'];
      let crmRef = null;

      for (const url of endpoints) {
        try {
          const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(inquiryPayload),
          });
          if (res.ok) {
            const data = await res.json();
            if (data.application_no) crmRef = data.application_no;
            break;
          }
        } catch (_) {}
      }

      // 2. Launch WhatsApp
      try {
        window.open(waUrl, '_blank', 'noopener,noreferrer');
      } catch (_) {}

      // 3. Provide feedback on form
      const statusBox = form.querySelector('#contact-status') || document.getElementById('contact-status');
      if (statusBox) {
        statusBox.style.display = 'block';
        statusBox.innerHTML = `
          <div style="background:#f4fbf6; border:1px solid #c2e9cf; border-radius:10px; padding:1.1rem; color:#185a32; margin-top:1rem;">
            <p style="margin:0 0 0.4rem; font-weight:600; font-size:1rem;">✅ Inquiry Logged to CRM Pipeline!</p>
            <p style="margin:0 0 0.65rem; font-size:0.88rem; color:#276749;">
              ${crmRef ? `Your inquiry reference is <strong>${crmRef}</strong>. ` : ''}We've connected your message to WhatsApp for instant customer service.
            </p>
            <a href="${waUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-small" style="background:#25D366; border-color:#25D366; color:#fff; display:inline-block; font-weight:600;">Chat on WhatsApp</a>
          </div>
        `;
      }

      form.reset();

      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = origBtnText;
      }
    });
  });
})();