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
      document.documentElement.dataset.theme = isDark ? 'dark' : 'light';

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
        button.querySelectorAll('[data-theme-icon]').forEach((icon) => {
          icon.hidden = icon.dataset.themeIcon !== (isDark ? 'light' : 'dark');
        });

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

  // Collapsing the whole sidebar down to an icon rail.
  //
  // The initial class is set by an inline script in the header partial, before
  // first paint; everything from here on is the toggling. The rail hides the
  // labels with CSS, so the labels move to title attributes — hovering an icon
  // is then the only way left to read what it is.
  const sidebarStorageKey = 'mbwSidebarCollapsed';
  const sidebarToggles = Array.from(document.querySelectorAll('[data-sidebar-toggle]'));

  const setSidebarCollapsed = (collapsed) => {
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    const action = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
    sidebarToggles.forEach((button) => {
      button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      button.setAttribute('aria-label', action);
      button.setAttribute('title', action + ' (Ctrl + B)');
    });
    try {
      // NOT REMEMBERED ON A PHONE. Under 900px this class drives a drawer, and
      // opening or shutting a drawer is a thing you do once, not a preference.
      // Persisting it wrote the phone's last tap into the same key the desktop
      // rail restores from — so a night spent on the phone came back as a
      // collapsed rail on the monitor next morning, with nothing to explain it.
      if (!(window.matchMedia && window.matchMedia('(max-width: 900px)').matches)) {
        localStorage.setItem(sidebarStorageKey, collapsed ? '1' : '0');
      }
    } catch (error) {
      // Private browsing denies storage; the rail still works this session.
    }
  };

  if (sidebarToggles.length) {
    document.querySelectorAll('.admin-nav a').forEach((link) => {
      // Only supply a tooltip where there is none already, and only from the
      // link's own text — an empty title on an icon is worse than no title.
      const label = link.textContent.trim();
      if (label !== '' && !link.hasAttribute('title')) {
        link.setAttribute('title', label);
      }
    });

    // Re-announce whatever the inline script restored, so the button's label
    // and aria-expanded match the state it is actually in.
    setSidebarCollapsed(document.body.classList.contains('sidebar-collapsed'));

    sidebarToggles.forEach((button) => {
      button.addEventListener('click', () => {
        setSidebarCollapsed(!document.body.classList.contains('sidebar-collapsed'));
      });
    });

    document.addEventListener('keydown', (event) => {
      if (!event.ctrlKey || event.altKey || event.metaKey || event.shiftKey) { return; }
      if (event.key !== 'b' && event.key !== 'B') { return; }
      // Never steal the key from somebody who is typing.
      if (event.target.closest('input, textarea, select, [contenteditable="true"]')) { return; }
      event.preventDefault();
      setSidebarCollapsed(!document.body.classList.contains('sidebar-collapsed'));
    });

    // ---- the phone drawer -------------------------------------------------
    // Under 900px the sidebar is a drawer OVER the page, not a column beside
    // it. Everything below is about getting out of it again: on a desktop the
    // sidebar has nothing on top of it, so none of this was ever needed.
    const isDrawer = () => window.matchMedia('(max-width: 900px)').matches;

    // Tapping the page behind it. The scrim is .admin-shell::before, a
    // pseudo-element, so it cannot carry a listener of its own — but it covers
    // everything except the drawer, so a press that lands outside the sidebar
    // while the drawer is open WAS a press on the scrim.
    document.addEventListener('click', (event) => {
      if (!isDrawer() || document.body.classList.contains('sidebar-collapsed')) { return; }
      if (event.target.closest('.admin-sidebar') || event.target.closest('[data-sidebar-toggle]')) { return; }
      setSidebarCollapsed(true);
    });

    // Escape, for a keyboard on a narrow window.
    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape' || !isDrawer()) { return; }
      if (!document.body.classList.contains('sidebar-collapsed')) {
        setSidebarCollapsed(true);
      }
    });

    // Following a link. The next page starts shut anyway — sidebar_boot.php
    // sees to that — but leaving it open until then means the drawer sits over
    // the page for the whole load, which reads as the tap not having worked.
    document.querySelectorAll('.admin-nav a[href]').forEach((link) => {
      link.addEventListener('click', () => {
        if (isDrawer()) { setSidebarCollapsed(true); }
      });
    });

    // Rotating a phone, or dragging a desktop window narrow, must not carry an
    // open drawer across the boundary: above 900px that same state is an
    // ordinary sidebar, below it a panel covering the screen.
    let wasDrawer = isDrawer();
    window.addEventListener('resize', () => {
      const nowDrawer = isDrawer();
      if (nowDrawer !== wasDrawer) {
        wasDrawer = nowDrawer;
        if (nowDrawer) { setSidebarCollapsed(true); }
      }
    });
  }

  // Sidebar collapsible groups, one open at a time.
  //
  // They used to toggle independently, so every group a user ever opened stayed
  // open and the sidebar grew until the item they wanted was below the fold.
  // Opening one now closes the rest.
  //
  // Only TOP-LEVEL groups take part: a group nested inside another belongs to
  // it, and closing its own ancestor when it opens would shut the child too.
  const navParents = Array.from(document.querySelectorAll('[data-nav-parent]')).filter(
    (parent) => !parent.parentElement || !parent.parentElement.closest('[data-nav-parent]')
  );

  const navStorageKey = (parent) => 'mbwNavOpen:' + parent.getAttribute('data-nav-parent');

  const setNavOpen = (parent, open) => {
    parent.classList.toggle('is-open', open);
    const toggle = parent.querySelector('[data-nav-toggle]');
    if (toggle) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    try {
      localStorage.setItem(navStorageKey(parent), open ? '1' : '0');
    } catch (error) {
      // Private browsing denies storage; the accordion still works, it just
      // will not be remembered.
    }
  };

  const openOnlyNav = (chosen) => {
    navParents.forEach((parent) => setNavOpen(parent, parent === chosen));
  };

  const navRemembered = (parent) => {
    try {
      return localStorage.getItem(navStorageKey(parent)) === '1';
    } catch (error) {
      return false;
    }
  };

  if (navParents.length) {
    // Which one to start with, in order of how much it is worth: the group
    // holding the page actually being looked at, then whatever the server
    // marked open, then whatever was open on the last visit.
    const initialNav = navParents.find((parent) => parent.querySelector('.mbw-subnav a.is-active'))
      || navParents.find((parent) => parent.classList.contains('is-open'))
      || navParents.find(navRemembered)
      || null;
    openOnlyNav(initialNav);

    navParents.forEach((parent) => {
      const toggle = parent.querySelector('[data-nav-toggle]');
      if (!toggle) {
        return;
      }
      toggle.addEventListener('click', (event) => {
        event.preventDefault();
        // On the collapsed rail the submenu has nowhere to appear, so pressing
        // a group would look like a dead click. Open the sidebar and the group
        // together instead.
        if (document.body.classList.contains('sidebar-collapsed')) {
          setSidebarCollapsed(false);
          openOnlyNav(parent);
          return;
        }
        if (parent.classList.contains('is-open')) {
          setNavOpen(parent, false);
        } else {
          openOnlyNav(parent);
        }
      });
    });
  }

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

// ---------------------------------------------------------------------------
// "+ Add new…" at the end of every master-data dropdown
// ---------------------------------------------------------------------------
//
// A clerk halfway through a bill discovers the customer is not on the list, or
// the purity they need was never set up. Without this the only way out is to
// abandon the document, go and create the record, and start again.
//
// One option is appended to the END of each list — the end, so it is never
// picked by accident when someone is arrowing through — and choosing it opens
// the master screen in a NEW TAB and puts the dropdown back where it was.
// Nothing typed on the page is lost, which is the whole point.
//
// WHERE IT APPEARS is deliberately narrow:
//   - data-entry forms only. A GET form is a filter, and "add new" in a filter
//     is meaningless.
//   - never inside the filter bar, and never on a report page. The user asked
//     for exactly this exclusion, and it is also just correct: those lists are
//     for narrowing what you are looking at, not for creating anything.
//
// The map is keyed by module first because the same field name means different
// things in different places — item_id is a jewellery item on one page and an
// inventory item on another, and sending someone to the wrong master screen is
// worse than not offering the link.
(function () {
  const MASTERS = {
    jewellery: {
      item_id: 'admin/jewellery.php?view=items',
      'l_item_id[]': 'admin/jewellery.php?view=items',
      'x_item_id[]': 'admin/jewellery.php?view=items',
      advance_item_id: 'admin/jewellery.php?view=items',
      received_item_id: 'admin/jewellery.php?view=items',
      purity_id: 'admin/jewellery.php?view=masters#purity-grades',
      'l_purity_id[]': 'admin/jewellery.php?view=masters#purity-grades',
      'x_purity_id[]': 'admin/jewellery.php?view=masters#purity-grades',
      advance_purity_id: 'admin/jewellery.php?view=masters#purity-grades',
      received_purity_id: 'admin/jewellery.php?view=masters#purity-grades',
      unit_id: 'admin/jewellery.php?view=masters#weight-units',
      'l_unit_id[]': 'admin/jewellery.php?view=masters#weight-units',
      'x_unit_id[]': 'admin/jewellery.php?view=masters#weight-units',
      advance_unit_id: 'admin/jewellery.php?view=masters#weight-units',
      metal_id: 'admin/jewellery.php?view=masters#metals-stones',
      category: 'admin/jewellery.php?view=masters#item-categories',
      karigar_id: 'admin/jewellery-workshop.php?view=karigars',
      'l_karigar_id[]': 'admin/jewellery-workshop.php?view=karigars',
      party_id: 'admin/accounting-parties.php',
      ledger_id: 'admin/chart-ledgers.php',
      settle_ledger_id: 'admin/chart-ledgers.php',
    },
    accounting: {
      party_id: 'admin/accounting-parties.php',
      ledger_id: 'admin/chart-ledgers.php',
      item_id: 'admin/accounting-inventory.php?view=inventory',
      group_id: 'admin/chart-groups.php',
      // The specialised voucher screens name each ledger slot for the job it
      // does — the bank a payment leaves, the head a sale credits — so every
      // one has to be listed for itself.
      'ledger_id[]': 'admin/chart-ledgers.php',
      'tender_ledger[]': 'admin/chart-ledgers.php',
      'line_ledger[]': 'admin/chart-ledgers.php',
      'value_ledger[]': 'admin/chart-ledgers.php',
      contra_from_ledger: 'admin/chart-ledgers.php',
      contra_to_ledger: 'admin/chart-ledgers.php',
      settlement_ledger_id: 'admin/chart-ledgers.php',
      tax_ledger_id: 'admin/chart-ledgers.php',
    },
    hospitality: {
      ingredient_id: 'admin/hospitality.php?view=ingredients',
      menu_item_id: 'admin/hospitality.php?view=menu-items',
      sales_item_id: 'admin/hospitality.php?view=menu-items',
      recipe_id: 'admin/hospitality.php?view=recipes',
      ledger_id: 'admin/chart-ledgers.php',
    },
    assets: {
      category_id: 'admin/fixed-assets.php?view=categories',
      buyer_party_id: 'admin/accounting-parties.php',
      lessor_party_id: 'admin/accounting-parties.php',
      ledger_id: 'admin/chart-ledgers.php',
    },
    hr: {
      leave_type_id: 'admin/hr.php?view=leave',
      client_id: 'admin/workspace.php?view=clients',
    },
    payroll: {
      employee_id: 'admin/payroll-employees.php',
      ledger_id: 'admin/chart-ledgers.php',
    },
    workspace: {
      client_id: 'admin/workspace.php?view=clients',
      team_id: 'admin/workspace.php?view=teams',
      industry_id: 'admin/workspace.php?view=industries',
      contract_id: 'admin/workspace.php?view=contracts',
      compliance_type_id: 'admin/compliance.php?view=types',
      type_id: 'admin/compliance.php?view=types',
      fine_ledger_id: 'admin/chart-ledgers.php',
      party_id: 'admin/accounting-parties.php',
    },
  };

  // Which module this page belongs to, for the map above. Most specific first:
  // "accounting-inventory" contains "accounting", and a page that matches two
  // patterns should get the narrower one.
  const MODULE_OF = [
    ['jewellery', 'jewellery'],
    ['hospitality', 'hospitality'],
    ['fixed-assets', 'assets'],
    ['payroll', 'payroll'],
    ['hr.php', 'hr'],
    ['workspace', 'workspace'],
    ['compliance', 'workspace'],
    ['tickets', 'workspace'],
    ['service-agreements', 'workspace'],
    ['documents', 'workspace'],
    ['accounting', 'accounting'],
    ['invoice', 'accounting'],
    ['voucher', 'accounting'],
    ['banking', 'accounting'],
    ['chart-', 'accounting'],
  ];
  const path = window.location.pathname;
  let moduleKey = null;
  for (let i = 0; i < MODULE_OF.length; i += 1) {
    if (path.indexOf(MODULE_OF[i][0]) !== -1) { moduleKey = MODULE_OF[i][1]; break; }
  }
  if (!moduleKey) { return; }

  // Reports never get it, however their selects are named.
  if (path.indexOf('report') !== -1) { return; }

  const map = MASTERS[moduleKey];
  // Set when somebody picks "+ Add new", read when this tab comes back.
  let pendingRefresh = false;

  document.querySelectorAll('select[name]').forEach((select) => {
    const target = map[select.getAttribute('name')];
    if (!target) { return; }
    // Filters are for narrowing a list, not for creating a record.
    const form = select.closest('form');
    if (!form || (form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }
    if (select.closest('.jw-filter, [data-filter-bar]')) { return; }
    if (select.multiple) { return; }

    // Remember what is selected NOW, before anyone touches it. Without this, a
    // user whose FIRST action is picking "add new" — on an edit form where the
    // field already had a value — would find it silently reset to blank.
    select.setAttribute('data-last-value', select.value);

    const option = document.createElement('option');
    option.value = '__add_new__';
    option.textContent = '+ Add new…';
    option.setAttribute('data-add-new', target);
    select.appendChild(option);

    select.addEventListener('change', function () {
      if (select.value !== '__add_new__') {
        // Remember what was chosen, so picking "add new" can come back to it.
        select.setAttribute('data-last-value', select.value);
        return;
      }
      // A new tab, so the half-filled document on this one survives.
      // The app is mounted at the domain root — every stylesheet and script
      // on the page is linked the same way.
      window.open('/' + target, '_blank', 'noopener');
      select.value = select.getAttribute('data-last-value') || '';
      pendingRefresh = true;
    });
  });

  // Coming BACK is the half that matters.
  //
  // Opening the master screen in a new tab kept the half-filled bill intact,
  // but the new customer still was not in the dropdown on return — and the only
  // way to get them there was a reload, which threw the bill away. The feature
  // saved the form and then made you lose it anyway.
  //
  // So when this tab regains focus after an "add new", the page is fetched
  // again in the background and any options that appeared since are merged in.
  // Nothing on screen is touched apart from adding options: what was typed, and
  // what was already chosen, stay exactly as they are.
  //
  // Only the path and its view/tab parameter are re-fetched, never the whole
  // query. A URL like ?delete=5 or ?edit=12 would re-run on the server, and a
  // background request that quietly repeats an action is far worse than a
  // dropdown being one option short.
  window.addEventListener('focus', () => {
    if (!pendingRefresh) { return; }
    pendingRefresh = false;

    const current = new URLSearchParams(window.location.search);
    const safe = new URLSearchParams();
    ['view', 'tab'].forEach((key) => {
      if (current.has(key)) { safe.set(key, current.get(key)); }
    });
    const query = safe.toString();

    window.fetch(window.location.pathname + (query ? '?' + query : ''), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then((response) => (response.ok ? response.text() : Promise.reject(response.status)))
      .then((html) => {
        const fresh = new DOMParser().parseFromString(html, 'text/html');
        document.querySelectorAll('select[name]').forEach((select) => {
          if (!map[select.getAttribute('name')]) { return; }
          const source = fresh.querySelector('select[name="' + CSS.escape(select.name) + '"]');
          if (!source) { return; }
          const have = new Set(Array.from(select.options).map((option) => option.value));
          const addNew = select.querySelector('option[data-add-new]');
          Array.from(source.options).forEach((option) => {
            if (have.has(option.value) || option.value === '' || option.value === '__add_new__') { return; }
            const fresher = document.createElement('option');
            fresher.value = option.value;
            fresher.textContent = option.textContent;
            // Inserted BEFORE "+ Add new", so that stays last on the list.
            select.insertBefore(fresher, addNew);
          });
        });
      })
      .catch(() => { /* offline, or the session needs a login — leave the form alone */ });
  });
})();

// ---------------------------------------------------------------------------
// Collapsible workspace sections
// ---------------------------------------------------------------------------
//
// Settings, Masters and the stock screens stack a dozen cards on one page, so
// finding the one you want means scrolling past all the others every time.
//
// Any card marked data-collapsible folds to its heading. The state is kept per
// page and per heading, so a shop that always works in Taxes finds Taxes open
// and the rest out of the way — and it survives a save, which is when a page
// reloads and would otherwise throw the arrangement away.
//
// A card containing the field that is currently focused, or one holding a
// validation error, opens itself: a section that hides the reason a save failed
// is worse than a long page.
(function () {
  const cards = document.querySelectorAll('[data-collapsible]');
  if (!cards.length) { return; }

  const pageKey = 'mbwSection:' + window.location.pathname + window.location.search.replace(/[?&]edit=\d+/, '');

  cards.forEach((card) => {
    const head = card.querySelector('.mbw-card-head, .jw-card-head');
    const title = head ? (head.querySelector('h2') || head).textContent.trim().slice(0, 60) : '';
    if (!head || title === '') { return; }

    const key = pageKey + '|' + title;
    const body = document.createElement('div');
    body.className = 'mbw-card-body';
    // Everything after the heading becomes the collapsible body — kept in the
    // heading's OWN parent, because on some screens the heading sits inside the
    // form rather than beside it, and lifting its fields out of the form would
    // stop them being submitted at all.
    const host = head.parentNode;
    while (head.nextSibling) { body.appendChild(head.nextSibling); }
    host.appendChild(body);

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'mbw-card-toggle';
    toggle.setAttribute('aria-label', 'Show or hide ' + title);
    head.appendChild(toggle);

    // A name the card can be linked to. "Purity Grades (14)" becomes
    // #purity-grades, so "+ Add new" on a purity dropdown can point at the
    // section that adds one rather than at the top of a page of folded cards.
    if (!card.id) {
      const slug = title
        .replace(/\(.*?\)/g, ' ')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
      if (slug !== '') { card.id = slug; }
    }

    const apply = (open) => {
      card.classList.toggle('is-collapsed', !open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    let open = true;
    try {
      const stored = localStorage.getItem(key);
      if (stored !== null) { open = stored === '1'; }
      else if (card.hasAttribute('data-collapsed')) { open = false; }
    } catch (error) {
      open = !card.hasAttribute('data-collapsed');
    }
    // Never hide a problem, or the field somebody is already in.
    if (body.querySelector('.notice, .field-error, .is-invalid, [autofocus]')) { open = true; }
    // Nor the section somebody was sent here to use. This beats both a folded
    // default AND a fold the user set earlier — arriving at a link that appears
    // to do nothing is worse than either.
    const wanted = window.location.hash.slice(1);
    if (wanted !== '' && (card.id === wanted || body.querySelector('#' + CSS.escape(wanted)))) {
      open = true;
      window.setTimeout(() => { card.scrollIntoView({ block: 'start', behavior: 'smooth' }); }, 60);
    }
    apply(open);

    const flip = () => {
      const next = card.classList.contains('is-collapsed');
      apply(next);
      try { localStorage.setItem(key, next ? '1' : '0'); } catch (error) { /* private mode */ }
    };
    toggle.addEventListener('click', flip);

    // On a panel you can drag by its heading, the heading is NOT also a collapse
    // target: dragging it and letting go is a click, and the card would fold
    // every time somebody moved it. There the chevron is the only way in.
    if (!card.hasAttribute('data-draggable')) {
      head.addEventListener('click', (event) => {
        // A link or button in the heading keeps doing its own job.
        if (event.target.closest('a, button:not(.mbw-card-toggle), input, select')) { return; }
        flip();
      });
      head.classList.add('is-collapsible');
    }
  });
})();
