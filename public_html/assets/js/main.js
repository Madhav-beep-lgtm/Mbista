document.addEventListener('DOMContentLoaded', () => {
  const year = document.querySelector('[data-year]');
  if (year) {
    year.textContent = new Date().getFullYear();
  }

  const openButtons = document.querySelectorAll('[data-modal-open]');
  const closeButtons = document.querySelectorAll('[data-modal-close]');

  openButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const modalId = button.getAttribute('data-modal-open');
      if (!modalId) {
        return;
      }

      const modal = document.querySelector(`[data-modal="${modalId}"]`);
      if (!modal) {
        return;
      }

      modal.classList.add('is-open');
    });
  });

  closeButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const modalId = button.getAttribute('data-modal-close');
      if (!modalId) {
        return;
      }

      const modal = document.querySelector(`[data-modal="${modalId}"]`);
      if (!modal) {
        return;
      }

      modal.classList.remove('is-open');
    });
  });

  document.querySelectorAll('.modal-overlay').forEach((modal) => {
    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        modal.classList.remove('is-open');
      }
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
      return;
    }

    document.querySelectorAll('.modal-overlay.is-open').forEach((modal) => {
      modal.classList.remove('is-open');
    });
  });

  const contactsFilterForm = document.getElementById('contacts-filter-form');
  const contactsApplyButton = document.getElementById('contacts-apply-button');
  if (contactsFilterForm && contactsApplyButton) {
    contactsFilterForm.addEventListener('submit', () => {
      contactsApplyButton.textContent = 'Loading...';
      contactsApplyButton.setAttribute('disabled', 'disabled');
    });
  }

  const contactCreateForm = document.getElementById('contact-create-form');
  const contactCreateSubmit = document.getElementById('contact-create-submit');
  const fileInputs = document.querySelectorAll('input[type="file"][name="attachment"]');
  const allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'txt', 'doc', 'docx'];
  const maxSizeBytes = 5 * 1024 * 1024;

  const ensureFieldError = (field, message) => {
    let error = field.parentElement?.querySelector('.field-error');
    if (!error) {
      error = document.createElement('p');
      error.className = 'field-error';
      field.parentElement?.appendChild(error);
    }
    error.textContent = message;
    field.classList.add('is-invalid');
  };

  const clearFieldError = (field) => {
    field.classList.remove('is-invalid');
    const error = field.parentElement?.querySelector('.field-error');
    if (error) {
      error.remove();
    }
  };

  const validateEmailInput = (input) => {
    const value = (input.value || '').trim();
    if (!value) {
      ensureFieldError(input, 'Email is required.');
      return false;
    }

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(value)) {
      ensureFieldError(input, 'Enter a valid email address.');
      return false;
    }

    clearFieldError(input);
    return true;
  };

  const validateRequiredInput = (input, message) => {
    if (!(input.value || '').trim()) {
      ensureFieldError(input, message);
      return false;
    }

    clearFieldError(input);
    return true;
  };

  fileInputs.forEach((input) => {
    const hint = document.createElement('p');
    hint.className = 'contacts-file-hint';
    hint.textContent = 'Allowed: pdf, png, jpg, jpeg, webp, txt, doc, docx. Max 5 MB.';
    input.parentElement?.appendChild(hint);

    input.addEventListener('change', () => {
      clearFieldError(input);
      const file = input.files && input.files[0] ? input.files[0] : null;
      if (!file) {
        return;
      }

      const extension = (file.name.split('.').pop() || '').toLowerCase();
      if (!allowedExtensions.includes(extension)) {
        ensureFieldError(input, 'Unsupported file type.');
        input.value = '';
        return;
      }

      if (file.size > maxSizeBytes) {
        ensureFieldError(input, 'File must be 5 MB or smaller.');
        input.value = '';
      }
    });
  });

  document.querySelectorAll('form[enctype="multipart/form-data"]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      let isValid = true;

      const nameInput = form.querySelector('input[name="name"]');
      if (nameInput && !validateRequiredInput(nameInput, 'Name is required.')) {
        isValid = false;
      }

      const emailInput = form.querySelector('input[name="email"]');
      if (emailInput && !validateEmailInput(emailInput)) {
        isValid = false;
      }

      const subjectInput = form.querySelector('input[name="subject"]');
      if (subjectInput && !validateRequiredInput(subjectInput, 'Subject is required.')) {
        isValid = false;
      }

      const messageInput = form.querySelector('textarea[name="message"]');
      if (messageInput && !validateRequiredInput(messageInput, 'Message is required.')) {
        isValid = false;
      }

      const fileInput = form.querySelector('input[type="file"][name="attachment"]');
      if (fileInput && fileInput.files && fileInput.files[0]) {
        const file = fileInput.files[0];
        const extension = (file.name.split('.').pop() || '').toLowerCase();
        if (!allowedExtensions.includes(extension)) {
          ensureFieldError(fileInput, 'Unsupported file type.');
          isValid = false;
        }
        if (file.size > maxSizeBytes) {
          ensureFieldError(fileInput, 'File must be 5 MB or smaller.');
          isValid = false;
        }
      }

      if (!isValid) {
        event.preventDefault();
      }
    });
  });

  if (contactCreateForm && contactCreateSubmit) {
    contactCreateForm.addEventListener('submit', () => {
      contactCreateSubmit.textContent = 'Saving...';
      contactCreateSubmit.setAttribute('disabled', 'disabled');
    });
  }

  const usersFilterForm = document.getElementById('users-filter-form');
  const usersApplyButton = document.getElementById('users-apply-button');
  if (usersFilterForm && usersApplyButton) {
    usersFilterForm.addEventListener('submit', () => {
      usersApplyButton.textContent = 'Loading...';
      usersApplyButton.setAttribute('disabled', 'disabled');
    });
  }

  const userCreateForm = document.getElementById('user-create-form');
  const userCreateSubmit = document.getElementById('user-create-submit');
  const userEditForm = document.getElementById('user-edit-form');
  const userEditSubmit = document.getElementById('user-edit-submit');

  const validateUserForm = (form) => {
    let isValid = true;

    const nameInput = form.querySelector('input[name="name"]');
    if (nameInput && !validateRequiredInput(nameInput, 'Name is required.')) {
      isValid = false;
    }

    const emailInput = form.querySelector('input[name="email"]');
    if (emailInput && !validateEmailInput(emailInput)) {
      isValid = false;
    }

    const passwordInput = form.querySelector('input[name="password"]');
    if (passwordInput && (passwordInput.value || '').trim() !== '' && passwordInput.value.length < 8) {
      ensureFieldError(passwordInput, 'Password must be at least 8 characters.');
      isValid = false;
    }
    if (passwordInput && (passwordInput.value || '').trim() === '') {
      clearFieldError(passwordInput);
    }

    return isValid;
  };

  if (userCreateForm && userCreateSubmit) {
    userCreateForm.addEventListener('submit', (event) => {
      if (!validateUserForm(userCreateForm)) {
        event.preventDefault();
        return;
      }

      userCreateSubmit.textContent = 'Saving...';
      userCreateSubmit.setAttribute('disabled', 'disabled');
    });
  }

  if (userEditForm && userEditSubmit) {
    userEditForm.addEventListener('submit', (event) => {
      if (!validateUserForm(userEditForm)) {
        event.preventDefault();
        return;
      }

      userEditSubmit.textContent = 'Saving...';
      userEditSubmit.setAttribute('disabled', 'disabled');
    });
  }

  const portalCompanySelect = document.getElementById('portal-company');
  const portalFiscalYearSelect = document.getElementById('portal-fiscal-year');
  if (portalCompanySelect && portalFiscalYearSelect && window.portalFiscalYears) {
    portalCompanySelect.addEventListener('change', () => {
      const selectedCompanyId = portalCompanySelect.value;
      const fiscalYears = window.portalFiscalYears[selectedCompanyId] || [];

      while (portalFiscalYearSelect.firstChild) {
        portalFiscalYearSelect.removeChild(portalFiscalYearSelect.firstChild);
      }

      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'Select fiscal year';
      portalFiscalYearSelect.appendChild(placeholder);

      fiscalYears.forEach((fy) => {
        const option = document.createElement('option');
        option.value = String(fy.id);
        option.textContent = `${fy.label} (${fy.start_date} to ${fy.end_date})`;
        portalFiscalYearSelect.appendChild(option);
      });
    });
  }

  const voucherTypeSelect = document.getElementById('voucher_type_select');
  if (voucherTypeSelect && window.voucherLedgerData) {
    const rebuildLedgerOptions = (rowEl) => {
      const ledgerSelect = rowEl.querySelector('.voucher-ledger-select');
      const entryTypeSelect = rowEl.querySelector('.voucher-entrytype-select');
      if (!ledgerSelect) {
        return;
      }
      const voucherType = voucherTypeSelect.value;
      const entryType = entryTypeSelect ? entryTypeSelect.value : '';
      const restrictToCashBank =
        voucherType === 'contra' ||
        (voucherType === 'payment' && entryType === 'credit') ||
        (voucherType === 'receipt' && entryType === 'debit');
      const defaultMasterKey =
        voucherType === 'sales' && entryType === 'credit' ? 'direct_income' :
        voucherType === 'purchase' && entryType === 'debit' ? 'direct_expense' : null;

      const previousValue = ledgerSelect.value;
      ledgerSelect.innerHTML = '';
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'Select ledger';
      ledgerSelect.appendChild(placeholder);

      const pool = restrictToCashBank
        ? window.voucherLedgerData.filter((l) => l.is_cash_or_bank)
        : window.voucherLedgerData;

      if (defaultMasterKey && !restrictToCashBank) {
        const suggested = pool.filter((l) => l.master_key === defaultMasterKey);
        if (suggested.length) {
          const grp = document.createElement('optgroup');
          grp.label = 'Suggested';
          suggested.forEach((l) => grp.appendChild(new Option(l.label, l.id)));
          ledgerSelect.appendChild(grp);
        }
      }

      pool.forEach((l) => ledgerSelect.appendChild(new Option(l.label, l.id)));

      if (pool.some((l) => String(l.id) === previousValue)) {
        ledgerSelect.value = previousValue;
      }
    };

    const allRows = () => document.querySelectorAll('.voucher-entry-row');
    const rebuildAll = () => allRows().forEach(rebuildLedgerOptions);

    voucherTypeSelect.addEventListener('change', rebuildAll);
    allRows().forEach((rowEl) => {
      const entryTypeSelect = rowEl.querySelector('.voucher-entrytype-select');
      if (entryTypeSelect) {
        entryTypeSelect.addEventListener('change', () => rebuildLedgerOptions(rowEl));
      }
    });
    rebuildAll();
  }

  const themeToggleButtons = document.querySelectorAll('[data-theme-toggle]');
  if (themeToggleButtons.length > 0) {
    const storageKey = 'appTheme';

    const applyTheme = (theme) => {
      const isDark = theme === 'dark';
      document.body.classList.toggle('theme-dark', isDark);

      // Keep compatibility with the admin workspace styles that also key off this class.
      if (document.body.classList.contains('admin-workspace') || document.body.classList.contains('admin-layout')) {
        document.body.classList.toggle('admin-dark', isDark);
      }

      // Let canvas charts re-read design tokens for the new theme.
      window.dispatchEvent(new CustomEvent('mbw:theme'));

      themeToggleButtons.forEach((button) => {
        const labelText = isDark ? 'Light mode' : 'Dark mode';
        const actionText = isDark ? 'Switch to light mode' : 'Switch to dark mode';
        const label = button.querySelector('[data-theme-toggle-label]');

        if (label) {
          label.textContent = labelText;
        } else {
          button.textContent = labelText;
        }

        button.setAttribute('aria-label', actionText);
        button.setAttribute('title', actionText);
      });
    };

    const storedTheme = localStorage.getItem(storageKey);
    applyTheme(storedTheme === 'dark' ? 'dark' : 'light');

    themeToggleButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const isDark = document.body.classList.contains('theme-dark');
        const nextTheme = isDark ? 'light' : 'dark';
        localStorage.setItem(storageKey, nextTheme);
        applyTheme(nextTheme);
      });
    });
  }

  // Creation forms are always visible now (modern fields, no Open form).
  if (document.body.classList.contains('admin-layout')) {
    document.querySelectorAll('details.feature-disclosure').forEach((disclosure) => {
      disclosure.setAttribute('open', '');
    });
  }

  // Sidebar collapsible groups (Accounting workspace submenu).
  document.querySelectorAll('[data-nav-parent]').forEach((parent) => {
    const toggle = parent.querySelector('[data-nav-toggle]');
    if (!toggle) {
      return;
    }
    const storageKey = 'mbwNavOpen:' + parent.getAttribute('data-nav-parent');
    const stored = localStorage.getItem(storageKey);
    if (stored === '1' && !parent.classList.contains('is-open')) {
      parent.classList.add('is-open');
      toggle.setAttribute('aria-expanded', 'true');
    }
    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      const isOpen = parent.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      localStorage.setItem(storageKey, isOpen ? '1' : '0');
    });
  });

  const currentParams = new URLSearchParams(window.location.search);
  document.querySelectorAll('.admin-nav a, .site-header .nav a').forEach((link) => {
    let linkUrl;
    try {
      linkUrl = new URL(link.href, window.location.origin);
    } catch (error) {
      return;
    }
    if (linkUrl.hash || linkUrl.pathname !== window.location.pathname) {
      return;
    }
    const linkParams = new URLSearchParams(linkUrl.search);
    const linkView = linkParams.get('view');
    if (linkView !== null && linkView !== currentParams.get('view')) {
      return;
    }
    const linkTab = linkParams.get('tab');
    if (linkTab !== null && linkTab !== (currentParams.get('tab') || 'sales')) {
      return;
    }
    link.classList.add('is-active');
  });
});

document.addEventListener('DOMContentLoaded', () => {
  const isMobileView = () => window.matchMedia('(max-width: 992px)').matches;

  const closeAllDropdowns = (except) => {
    document.querySelectorAll('.has-dropdown[data-open]').forEach((item) => {
      if (item !== except) {
        item.removeAttribute('data-open');
        const toggle = item.querySelector('.dropdown-toggle');
        if (toggle) {
          toggle.setAttribute('aria-expanded', 'false');
        }
      }
    });
  };

  document.querySelectorAll('.has-dropdown > .dropdown-toggle').forEach((toggle) => {
    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      const item = toggle.closest('.has-dropdown');
      const isOpen = item.hasAttribute('data-open');
      closeAllDropdowns(item);
      if (isOpen) {
        item.removeAttribute('data-open');
        toggle.setAttribute('aria-expanded', 'false');
      } else {
        item.setAttribute('data-open', '');
        toggle.setAttribute('aria-expanded', 'true');
      }
    });
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('.has-dropdown')) {
      closeAllDropdowns();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeAllDropdowns();
      // :focus-within keeps the panel visible, so release focus too.
      if (document.activeElement && document.activeElement.closest('.has-dropdown')) {
        document.activeElement.blur();
      }
      const nav = document.querySelector('[data-main-nav].is-open');
      if (nav) {
        closeMobileNav();
      }
    }
  });

  const mainNav = document.querySelector('[data-main-nav]');
  const openButton = document.querySelector('[data-mobile-menu-open]');
  const overlay = document.querySelector('[data-mobile-menu-overlay]');

  const closeMobileNav = () => {
    if (!mainNav) {
      return;
    }
    mainNav.classList.remove('is-open');
    document.body.classList.remove('mobile-nav-locked');
    if (overlay) {
      overlay.hidden = true;
    }
    if (openButton) {
      openButton.setAttribute('aria-expanded', 'false');
    }
  };

  if (mainNav && openButton) {
    openButton.addEventListener('click', () => {
      mainNav.classList.add('is-open');
      document.body.classList.add('mobile-nav-locked');
      if (overlay) {
        overlay.hidden = false;
      }
      openButton.setAttribute('aria-expanded', 'true');
    });

    document.querySelectorAll('[data-mobile-menu-close]').forEach((btn) => {
      btn.addEventListener('click', closeMobileNav);
    });

    if (overlay) {
      overlay.addEventListener('click', closeMobileNav);
    }

    mainNav.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        if (isMobileView()) {
          closeMobileNav();
        }
      });
    });
  }

  const siteHeader = document.querySelector('[data-site-header]');
  if (siteHeader) {
    const onScroll = () => {
      siteHeader.classList.toggle('is-scrolled', window.scrollY > 12);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  // --- Toast notifications -------------------------------------------------
  // Server-rendered flash notices slide in as toasts instead of pushing the
  // page down. Errors stay longer; both can be dismissed. Only one-shot flash
  // messages (tagged .flash-notice by the header partials) float; contextual
  // in-page notices stay where the page rendered them.
  const flashNotices = document.querySelectorAll('.notice.flash-notice');
  if (flashNotices.length) {
    const toastRoot = document.createElement('div');
    toastRoot.id = 'toast-root';
    document.body.appendChild(toastRoot);
    flashNotices.forEach((notice) => {
      const holder = notice.parentElement;
      notice.classList.add('toast');
      const close = document.createElement('button');
      close.type = 'button';
      close.className = 'toast-close';
      close.setAttribute('aria-label', 'Dismiss');
      close.innerHTML = '&times;';
      notice.appendChild(close);
      toastRoot.appendChild(notice);
      if (holder && holder.classList.contains('container') && holder.children.length === 0) {
        holder.remove();
      }
      const dismiss = () => {
        notice.classList.add('toast-out');
        window.setTimeout(() => notice.remove(), 300);
      };
      const ttl = notice.classList.contains('error') ? 9000 : 5500;
      const timer = window.setTimeout(dismiss, ttl);
      close.addEventListener('click', () => { window.clearTimeout(timer); dismiss(); });
    });
  }

  // --- Confirmation for destructive actions --------------------------------
  document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.getAttribute('data-confirm') || 'Are you sure?')) {
      event.preventDefault();
    }
  }, true);
  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[data-confirm]');
    if (link && !window.confirm(link.getAttribute('data-confirm') || 'Are you sure?')) {
      event.preventDefault();
    }
  });

  // --- Friendly empty states ------------------------------------------------
  document.querySelectorAll('td[colspan]').forEach((cell) => {
    const text = cell.textContent.trim();
    if (/^(no |none |nothing |start typing|not available)/i.test(text)) {
      cell.classList.add('empty-cell');
    }
  });

  // --- Password strength meter ----------------------------------------------
  document.querySelectorAll('input[type="password"][data-strength]').forEach((input) => {
    const meter = document.createElement('div');
    meter.className = 'pw-meter';
    meter.innerHTML = '<span class="pw-meter-track"><span class="pw-meter-bar"></span></span><small class="pw-meter-label"></small>';
    input.insertAdjacentElement('afterend', meter);
    const bar = meter.querySelector('.pw-meter-bar');
    const label = meter.querySelector('.pw-meter-label');
    input.addEventListener('input', () => {
      const value = input.value;
      let score = 0;
      if (value.length >= 8) score++;
      if (value.length >= 12) score++;
      if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
      if (/\d/.test(value)) score++;
      if (/[^A-Za-z0-9]/.test(value)) score++;
      const levels = [
        ['', ''],
        ['is-weak', 'Weak password'],
        ['is-weak', 'Weak password'],
        ['is-fair', 'Fair password'],
        ['is-good', 'Good password'],
        ['is-strong', 'Strong password'],
      ];
      meter.className = 'pw-meter ' + (value ? levels[score][0] : '');
      bar.style.width = (score * 20) + '%';
      label.textContent = value ? levels[score][1] : '';
    });
  });

  // --- Password confirmation match -------------------------------------------
  // Pair inside one form: [data-confirm-source] = the password being set,
  // [data-confirm-target] = the repeat field. Blocks submit until they match.
  document.querySelectorAll('input[type="password"][data-confirm-target]').forEach((confirm) => {
    const form = confirm.closest('form');
    const source = form ? form.querySelector('input[type="password"][data-confirm-source]') : null;
    if (!source) return;
    const validate = () => {
      confirm.setCustomValidity(confirm.value && confirm.value !== source.value ? 'Passwords do not match.' : '');
    };
    confirm.addEventListener('input', validate);
    source.addEventListener('input', validate);
  });
});
