/**
 * RestaurantOS — SaaS Landing Page & Onboarding Script
 */

(function () {
  'use strict';

  // --- Header Scroll Effect ---
  const header = document.querySelector('.landing-header');
  window.addEventListener('scroll', () => {
    if (window.scrollY > 40) {
      header?.classList.add('scrolled');
    } else {
      header?.classList.remove('scrolled');
    }
  });

  // --- Toast System ---
  function showToast(message, type = 'info') {
    let container = document.querySelector('.toast-container');
    if (!container) {
      container = document.createElement('div');
      container.className = 'toast-container';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    const icon = type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-circle-exclamation' : 'fa-info-circle');
    toast.innerHTML = `<i class="fas ${icon}"></i> <span>${escapeHtml(message)}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(10px)';
      setTimeout(() => toast.remove(), 300);
    }, 4500);
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
  }

  // --- Billing Switcher (Monthly / Annual) ---
  const billingSwitch = document.getElementById('billingSwitch');
  const monthLabel = document.getElementById('monthLabel');
  const yearLabel = document.getElementById('yearLabel');
  const priceElements = document.querySelectorAll('.plan-price');
  const periodElements = document.querySelectorAll('.plan-period');
  let currentCycle = 'monthly';

  if (billingSwitch) {
    billingSwitch.addEventListener('click', () => {
      const isAnnual = billingSwitch.classList.toggle('annual');
      currentCycle = isAnnual ? 'yearly' : 'monthly';

      if (isAnnual) {
        yearLabel?.classList.add('active');
        monthLabel?.classList.remove('active');
      } else {
        monthLabel?.classList.add('active');
        yearLabel?.classList.remove('active');
      }

      priceElements.forEach((el) => {
        const monthly = parseFloat(el.getAttribute('data-monthly') || '0');
        const yearly = parseFloat(el.getAttribute('data-yearly') || '0');
        // When annual, show the monthly equivalent (e.g. $790/12 = $65/mo)
        if (isAnnual) {
          const equiv = Math.round(yearly / 12);
          el.textContent = equiv;
        } else {
          el.textContent = monthly;
        }
      });

      periodElements.forEach((el) => {
        el.textContent = isAnnual ? '/mo (billed annually)' : '/mo';
      });

      // Update plan cycle selector in signup form if present
      const cycleInput = document.getElementById('billingCycleInput');
      if (cycleInput) cycleInput.value = currentCycle;
    });
  }

  // --- Select Plan Buttons ---
  document.querySelectorAll('.btn-select-plan').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const planId = btn.getAttribute('data-plan-id');
      const planName = btn.getAttribute('data-plan-name');
      const planInput = document.getElementById('selectedPlanInput');
      const planBadge = document.getElementById('selectedPlanDisplay');

      if (planInput) planInput.value = planId;
      if (planBadge) planBadge.textContent = planName;

      // Scroll to get started form
      const getStarted = document.getElementById('get-started');
      if (getStarted) {
        getStarted.scrollIntoView({ behavior: 'smooth' });
        const nameField = document.getElementById('restaurantName');
        if (nameField) nameField.focus();
      }
    });
  });

  // --- Slug Generator & Live Availability Check ---
  const nameInput = document.getElementById('restaurantName');
  const slugInput = document.getElementById('restaurantSlug');
  const slugStatus = document.getElementById('slugStatus');
  let slugDebounceTimer = null;
  let userManuallyEditedSlug = false;

  function toSlug(text) {
    return text
      .toString()
      .toLowerCase()
      .trim()
      .replace(/[\s\W-]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  if (slugInput) {
    slugInput.addEventListener('input', () => {
      userManuallyEditedSlug = true;
      triggerSlugCheck(slugInput.value);
    });
  }

  if (nameInput) {
    nameInput.addEventListener('input', () => {
      if (!userManuallyEditedSlug && slugInput) {
        const candidate = toSlug(nameInput.value);
        slugInput.value = candidate;
        triggerSlugCheck(candidate);
      }
    });
  }

  function triggerSlugCheck(slug) {
    clearTimeout(slugDebounceTimer);
    if (!slug || slug.length < 2) {
      if (slugStatus) {
        slugStatus.className = 'slug-status';
        slugStatus.textContent = '';
      }
      return;
    }

    if (slugStatus) {
      slugStatus.className = 'slug-status checking';
      slugStatus.textContent = 'Checking...';
    }

    slugDebounceTimer = setTimeout(async () => {
      try {
        const base = window.RESTAURANT_APP_BASE || '';
        const res = await fetch(`${base}/api/v1/public/check-slug?slug=${encodeURIComponent(slug)}`);
        const data = await res.json();

        if (slugStatus) {
          if (data.available) {
            slugStatus.className = 'slug-status available';
            slugStatus.innerHTML = '<i class="fas fa-check"></i> Available';
          } else {
            slugStatus.className = 'slug-status unavailable';
            slugStatus.innerHTML = `<i class="fas fa-times"></i> ${data.reason || 'Taken'}`;
          }
        }
      } catch (err) {
        if (slugStatus) {
          slugStatus.className = 'slug-status';
          slugStatus.textContent = '';
        }
      }
    }, 350);
  }

  // --- Registration Wizard Submission ---
  const signupForm = document.getElementById('tenantSignupForm');
  const submitBtn = document.getElementById('btnSubmitSignup');
  const progressBox = document.getElementById('provisionProgress');
  const successCard = document.getElementById('provisionSuccessCard');

  if (signupForm) {
    signupForm.addEventListener('submit', async (e) => {
      e.preventDefault();

      const name = document.getElementById('restaurantName')?.value.trim();
      const slug = document.getElementById('restaurantSlug')?.value.trim();
      const ownerName = document.getElementById('ownerName')?.value.trim();
      const ownerEmail = document.getElementById('ownerEmail')?.value.trim();
      const password = document.getElementById('ownerPassword')?.value;
      const phone = document.getElementById('ownerPhone')?.value.trim();
      const city = document.getElementById('city')?.value.trim();
      const country = document.getElementById('country')?.value.trim();
      const currency = document.getElementById('currency')?.value || 'USD';
      const planId = document.getElementById('selectedPlanInput')?.value;
      const billingCycle = currentCycle;

      if (!name || !ownerName || !ownerEmail || !password) {
        showToast('Please fill in all required fields.', 'error');
        return;
      }

      if (password.length < 8) {
        showToast('Password must be at least 8 characters long.', 'error');
        return;
      }

      // UI States
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Provisioning Restaurant...';
      }

      if (progressBox) progressBox.style.display = 'block';
      const steps = progressBox?.querySelectorAll('.provisioning-step') || [];

      function markStep(idx) {
        steps.forEach((s, i) => {
          if (i < idx) {
            s.className = 'provisioning-step done';
            s.querySelector('i').className = 'fas fa-check-circle';
          } else if (i === idx) {
            s.className = 'provisioning-step active';
            s.querySelector('i').className = 'fas fa-spinner fa-spin';
          } else {
            s.className = 'provisioning-step';
            s.querySelector('i').className = 'far fa-circle';
          }
        });
      }

      markStep(0);
      const stepTimer1 = setTimeout(() => markStep(1), 1200);
      const stepTimer2 = setTimeout(() => markStep(2), 2600);

      try {
        const base = window.RESTAURANT_APP_BASE || '';
        const response = await fetch(`${base}/api/v1/public/register`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            name,
            slug,
            owner_name: ownerName,
            owner_email: ownerEmail,
            owner_phone: phone,
            password,
            city,
            country,
            currency,
            plan_id: planId,
            billing_cycle: billingCycle,
          }),
        });

        clearTimeout(stepTimer1);
        clearTimeout(stepTimer2);
        markStep(3);

        const result = await response.json();

        if (!response.ok || !result.success) {
          throw new Error(result.error?.message || result.message || 'Failed to create restaurant.');
        }

        // Complete steps
        steps.forEach((s) => {
          s.className = 'provisioning-step done';
          s.querySelector('i').className = 'fas fa-check-circle';
        });

        // Show Success View
        signupForm.style.display = 'none';
        if (progressBox) progressBox.style.display = 'none';
        if (successCard) {
          successCard.style.display = 'block';
          const tenant = result.tenant;

          document.getElementById('resStorefrontUrl').textContent = tenant.storefront_url;
          document.getElementById('resStorefrontLink').href = tenant.storefront_url;

          document.getElementById('resAdminUrl').textContent = tenant.admin_url;
          document.getElementById('resAdminLink').href = tenant.admin_url;

          document.getElementById('resAccessCode').textContent = tenant.access_code;
          document.getElementById('btnEnterAdmin').href = tenant.admin_url;
        }

        showToast('Your restaurant workspace is live!', 'success');
      } catch (err) {
        if (progressBox) progressBox.style.display = 'none';
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = '<i class="fas fa-rocket"></i> Create Restaurant & Launch Storefront';
        }
        showToast(err.message, 'error');
      }
    });
  }

  // --- Copy URL Helpers ---
  document.querySelectorAll('.btn-copy-url').forEach((btn) => {
    btn.addEventListener('click', () => {
      const targetId = btn.getAttribute('data-target');
      const targetEl = document.getElementById(targetId);
      if (targetEl) {
        navigator.clipboard.writeText(targetEl.textContent.trim());
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        setTimeout(() => (btn.innerHTML = original), 2000);
      }
    });
  });

  // --- Contact Form Submission ---
  const contactForm = document.getElementById('contactForm');
  if (contactForm) {
    contactForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const submitBtn = contactForm.querySelector('button[type="submit"]');
      const name = document.getElementById('contactName')?.value.trim();
      const email = document.getElementById('contactEmail')?.value.trim();
      const restaurant = document.getElementById('contactRestaurant')?.value.trim();
      const subject = document.getElementById('contactSubject')?.value;
      const message = document.getElementById('contactMessage')?.value.trim();

      if (!name || !email || !message) {
        showToast('Please fill in your name, email, and message.', 'error');
        return;
      }

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
      }

      try {
        const base = window.RESTAURANT_APP_BASE || '';
        const res = await fetch(`${base}/api/v1/public/contact`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name, email, restaurant, subject, message }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error?.message || 'Failed to send message.');

        contactForm.reset();
        showToast(data.data?.message || 'Message sent! We will contact you soon.', 'success');
      } catch (err) {
        showToast(err.message, 'error');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Message';
        }
      }
    });
  }

  // --- "Find My Restaurant" Modal ---
  const modalFind = document.getElementById('modalFindRestaurant');
  const btnOpenFind = document.getElementById('btnOpenFindModal');
  const btnCloseFind = document.getElementById('btnCloseFindModal');
  const formFind = document.getElementById('formFindRestaurant');

  if (btnOpenFind && modalFind) {
    btnOpenFind.addEventListener('click', (e) => {
      e.preventDefault();
      modalFind.classList.add('active');
      document.getElementById('inputRestaurantCode')?.focus();
    });
  }

  if (btnCloseFind && modalFind) {
    btnCloseFind.addEventListener('click', () => {
      modalFind.classList.remove('active');
    });
  }

  modalFind?.addEventListener('click', (e) => {
    if (e.target === modalFind) modalFind.classList.remove('active');
  });

  if (formFind) {
    formFind.addEventListener('submit', (e) => {
      e.preventDefault();
      const codeOrSlug = document.getElementById('inputRestaurantCode')?.value.trim();
      if (!codeOrSlug) return;
      const base = window.RESTAURANT_APP_BASE || '';
      window.location.href = `${base}/t/${encodeURIComponent(codeOrSlug)}/`;
    });
  }

  // --- Theme Switcher (Dark / Light Mode) ---
  const themeToggleBtn = document.getElementById('themeToggleBtn');
  function getCurrentTheme() {
    return document.documentElement.getAttribute('data-theme') || 'dark';
  }

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('resto_theme', theme);
  }

  if (themeToggleBtn) {
    themeToggleBtn.addEventListener('click', () => {
      const current = getCurrentTheme();
      const next = current === 'dark' ? 'light' : 'dark';
      applyTheme(next);
      showToast(`Switched to ${next === 'light' ? 'Light' : 'Dark'} theme`, 'info');
    });
  }

  // --- Mobile Drawer Toggle ---
  const mobileToggle = document.getElementById('mobileMenuToggle');
  const mobileDrawer = document.getElementById('mobileNavDrawer');
  if (mobileToggle && mobileDrawer) {
    mobileToggle.addEventListener('click', () => {
      mobileDrawer.classList.toggle('active');
      const icon = mobileToggle.querySelector('i');
      if (icon) {
        if (mobileDrawer.classList.contains('active')) {
          icon.className = 'fas fa-xmark';
        } else {
          icon.className = 'fas fa-bars';
        }
      }
    });

    // Close drawer when clicking mobile links
    mobileDrawer.querySelectorAll('.mobile-nav-link, button, a').forEach((link) => {
      link.addEventListener('click', () => {
        mobileDrawer.classList.remove('active');
        const icon = mobileToggle.querySelector('i');
        if (icon) icon.className = 'fas fa-bars';
      });
    });
  }

  // Mobile Find modal trigger
  const btnMobileFind = document.getElementById('btnMobileFindModal');
  if (btnMobileFind && modalFind) {
    btnMobileFind.addEventListener('click', () => {
      modalFind.classList.add('active');
      document.getElementById('inputRestaurantCode')?.focus();
    });
  }

  // --- Active Nav Link ScrollSpy ---
  const navSections = document.querySelectorAll('section[id]');
  const desktopNavLinks = document.querySelectorAll('.nav-links .nav-link');
  window.addEventListener('scroll', () => {
    let currentId = '';
    const scrollPos = window.scrollY + 130;
    navSections.forEach((section) => {
      const top = section.offsetTop;
      const height = section.offsetHeight;
      if (scrollPos >= top && scrollPos < top + height) {
        currentId = section.getAttribute('id');
      }
    });

    if (currentId) {
      desktopNavLinks.forEach((link) => {
        if (link.getAttribute('href') === `#${currentId}`) {
          link.classList.add('active');
        } else {
          link.classList.remove('active');
        }
      });
    }
  });
})();
