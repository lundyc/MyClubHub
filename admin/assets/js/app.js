// assets/js/app.js — global JS helpers

// Restore the desktop navigation preference before the page is painted.
try {
  const isMembersArea = window.location.pathname.startsWith("/members/");
  if (!isMembersArea && window.localStorage.getItem("hubDesktopNavCollapsed") === "true") {
    document.documentElement.classList.add("hub-nav-collapsed");
  }
} catch (error) {
  // Storage can be unavailable in private browsing; the toggle still works for this page.
}

// Bootstrap tooltips — style every hover hint consistently instead of leaving
// most controls with the browser's inconsistent, untouchable native tooltip.
// This must run before Tooltip() below: Bootstrap moves `title` to
// `data-bs-original-title` once a tooltip is attached, so anything reading
// the raw title attribute (like the aria-label fallback here) has to go first.
document.querySelectorAll('[title]').forEach(el => {
  if (el.tagName === 'IFRAME') return; // title here is an accessible name, not a hint.
  const titleText = (el.getAttribute('title') || '').trim();
  if (!titleText) return;

  if (!el.hasAttribute('aria-label') && el.textContent.trim() === '') {
    el.setAttribute('aria-label', titleText);
  }
  if (!el.hasAttribute('data-bs-toggle')) {
    el.setAttribute('data-bs-toggle', 'tooltip');
  }
});
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
  new bootstrap.Tooltip(el);
});

// Shared helpers for form submission feedback. Several pages hijack a
// form's submit with fetch() and previously reported failures with a
// blocking alert() and no busy indicator on the button — these give every
// page a consistent, non-blocking way to do both.
window.hubSetBusy = function (button, busy) {
  if (!button) return;
  if (busy) {
    if (button.dataset.busyOriginalHtml === undefined) {
      button.dataset.busyOriginalHtml = button.innerHTML;
    }
    button.disabled = true;
    const label = button.dataset.busyOriginalHtml.replace(/<[^>]+>/g, '').trim();
    button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + label;
  } else {
    button.disabled = false;
    if (button.dataset.busyOriginalHtml !== undefined) {
      button.innerHTML = button.dataset.busyOriginalHtml;
    }
  }
};

window.hubClearFormError = function (form) {
  form.querySelectorAll('.is-invalid').forEach(field => field.classList.remove('is-invalid'));
  form.querySelectorAll('[data-hub-field-error]').forEach(el => el.remove());
  if (form._hubErrorEl && form._hubErrorEl.isConnected) {
    form._hubErrorEl.remove();
  }
};

window.hubShowFormError = function (form, message) {
  let alertEl = form._hubErrorEl;
  if (!alertEl || !alertEl.isConnected) {
    alertEl = document.createElement('div');
    alertEl.className = 'alert alert-danger hub-form-error mb-3';
    alertEl.setAttribute('role', 'alert');
    form._hubErrorEl = alertEl;
    const modalBody = form.querySelector('.modal-body');
    if (modalBody) {
      modalBody.prepend(alertEl);
    } else {
      form.before(alertEl);
    }
  }
  alertEl.textContent = message;
};

// Marks a single named field invalid using Bootstrap's is-invalid/invalid-feedback
// pattern, falling back to the generic banner above when the server didn't name
// a field or the field can't be found in this form.
window.hubShowFieldError = function (form, field, message) {
  const input = field ? form.querySelector('[name="' + field + '"]') : null;
  if (!input) {
    window.hubShowFormError(form, message);
    return;
  }

  input.classList.add('is-invalid');
  let feedback = input.nextElementSibling && input.nextElementSibling.hasAttribute('data-hub-field-error')
    ? input.nextElementSibling
    : null;
  if (!feedback) {
    feedback = document.createElement('div');
    feedback.className = 'invalid-feedback';
    feedback.setAttribute('data-hub-field-error', '');
    input.insertAdjacentElement('afterend', feedback);
  }
  feedback.textContent = message;

  const clear = () => {
    input.classList.remove('is-invalid');
    feedback.remove();
  };
  input.addEventListener('input', clear, { once: true });
  input.addEventListener('change', clear, { once: true });
};

// Give plain (non-AJAX) form submissions the same busy feedback. Forms that
// hijack submission themselves already call event.preventDefault() before
// this runs, so defaultPrevented is the natural signal to leave them alone.
document.addEventListener('submit', event => {
  if (event.defaultPrevented) return;
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-no-busy')) return;

  const button = event.submitter;
  if (!button || button.tagName !== 'BUTTON' || button.disabled) return;
  if (button.querySelector('.spinner-border')) return;

  // The browser builds the submitted form data from the submit event's
  // submitter *after* this listener returns, and a disabled submitter is
  // dropped from that data — so name/value submit buttons (like
  // "Remove photo") would silently lose their value if disabled here
  // synchronously. Deferring one tick lets the real submission serialize
  // first; the busy state still appears effectively immediately.
  setTimeout(() => window.hubSetBusy(button, true), 0);
});

// Auto-hide success alerts after a few seconds
document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".nav-link.active").forEach(link => {
    if (!link.hasAttribute("aria-current")) link.setAttribute("aria-current", "page");
  });

  // Give responsive record tables usable mobile labels without requiring every
  // legacy list page to duplicate the column heading in its cell markup.
  document.querySelectorAll(".hub-data-table--responsive").forEach(table => {
    const headings = Array.from(table.querySelectorAll("thead th")).map(heading =>
      heading.textContent.trim()
    );
    table.querySelectorAll("tbody tr").forEach(row => {
      Array.from(row.children).forEach((cell, index) => {
        if (!cell.hasAttribute("data-label") && headings[index]) {
          cell.setAttribute("data-label", headings[index]);
        }
      });
    });
  });

  const seasonSelect = document.getElementById("hubSeasonSelect");
  if (seasonSelect) {
    seasonSelect.addEventListener("change", () => {
      seasonSelect.closest("form").submit();
      seasonSelect.disabled = true;
    });
  }

  const desktopNavToggle = document.getElementById("hubDesktopNavToggle");
  if (desktopNavToggle) {
    const updateDesktopNavToggle = () => {
      const collapsed = document.documentElement.classList.contains("hub-nav-collapsed");
      const label = collapsed ? "Show side navigation" : "Hide side navigation";

      desktopNavToggle.setAttribute("aria-expanded", collapsed ? "false" : "true");
      desktopNavToggle.setAttribute("aria-label", label);
      desktopNavToggle.setAttribute("title", label);
      desktopNavToggle.innerHTML = '<i class="fa-solid fa-chevron-' + (collapsed ? "right" : "left") + '" aria-hidden="true"></i>';
    };

    desktopNavToggle.addEventListener("click", () => {
      document.documentElement.classList.toggle("hub-nav-collapsed");
      try {
        window.localStorage.setItem(
          "hubDesktopNavCollapsed",
          document.documentElement.classList.contains("hub-nav-collapsed") ? "true" : "false"
        );
      } catch (error) {
        // The visual state still changes if storage is unavailable.
      }
      updateDesktopNavToggle();
    });

    updateDesktopNavToggle();
  }

  document.querySelectorAll('.alert-success, .alert-danger, .alert-warning, .alert-info').forEach(alert => {
    if (!alert.hasAttribute("role")) {
      alert.setAttribute("role", alert.classList.contains("alert-danger") ? "alert" : "status");
    }
    if (!alert.querySelector(".btn-close") && !alert.classList.contains("alert-permanent")) {
      alert.classList.add("alert-dismissible");
      const closeButton = document.createElement("button");
      closeButton.type = "button";
      closeButton.className = "btn-close";
      closeButton.setAttribute("data-bs-dismiss", "alert");
      closeButton.setAttribute("aria-label", "Dismiss message");
      alert.appendChild(closeButton);
    }
  });
});

// Consistent confirmation for destructive links and forms.
document.addEventListener("DOMContentLoaded", () => {
  const dialogElement = document.getElementById("hubConfirmDialog");
  const actionButton = document.getElementById("hubConfirmDialogAction");
  const title = document.getElementById("hubConfirmDialogTitle");
  const message = document.getElementById("hubConfirmDialogMessage");
  if (!dialogElement || !actionButton || typeof bootstrap === "undefined") return;

  const dialog = new bootstrap.Modal(dialogElement);
  let pendingAction = null;

  const openDialog = (trigger, action) => {
    pendingAction = action;
    title.textContent = trigger.getAttribute("data-confirm-title") || "Confirm this action";
    message.textContent = trigger.getAttribute("data-confirm") || "This action cannot be undone.";
    actionButton.textContent = trigger.getAttribute("data-confirm-action") || "Continue";
    actionButton.className = "btn " + (trigger.getAttribute("data-confirm-class") || "btn-danger");
    dialog.show();
  };

  document.addEventListener("click", event => {
    const link = event.target.closest("a[data-confirm]");
    if (link) {
      event.preventDefault();
      openDialog(link, () => { window.location.assign(link.href); });
      return;
    }

    const submitButton = event.target.closest("button[data-confirm]");
    if (!submitButton || submitButton.dataset.confirmed === "true" || !submitButton.form) return;
    event.preventDefault();
    openDialog(submitButton, () => {
      submitButton.dataset.confirmed = "true";
      submitButton.form.requestSubmit(submitButton);
    });
  });

  document.addEventListener("submit", event => {
    const form = event.target;
    if (!form.matches("form[data-confirm]") || form.dataset.confirmed === "true") return;
    event.preventDefault();
    event.stopImmediatePropagation();
    openDialog(form, () => {
      form.dataset.confirmed = "true";
      form.requestSubmit(event.submitter || undefined);
    });
  }, true);

  actionButton.addEventListener("click", () => {
    const action = pendingAction;
    pendingAction = null;
    dialog.hide();
    if (action) action();
  });

  dialogElement.addEventListener("hidden.bs.modal", () => { pendingAction = null; });
});

// Close the mobile navbar after using a navigation link.
document.addEventListener("click", (e) => {
  const link = e.target.closest("#navbarMain .nav-link, #navbarMain .dropdown-item");
  if (!link) return;
  if (link.classList.contains("dropdown-toggle")) return;
  if (window.innerWidth >= 992) return;

  const navbar = document.getElementById("navbarMain");
  if (!navbar) return;

  const collapse = bootstrap.Collapse.getInstance(navbar);
  if (collapse) {
    collapse.hide();
  }
});

// Sidebar sections — personalisation. Every collapse/expand the user makes is
// remembered per browser (localStorage "hubNavSections") and reapplied on
// every page. The one exception: the section that contains the current page
// is revealed on load so you can always see where you are — but that reveal
// is transient and does NOT change the stored preference, so it collapses
// again as soon as you navigate elsewhere.
(function () {
  const KEY = "hubNavSections";
  const toggles = document.querySelectorAll(".nav-section-toggle[data-nav-section]");
  if (!toggles.length) return;

  let store = {};
  try {
    store = JSON.parse(window.localStorage.getItem(KEY) || "{}") || {};
  } catch (error) {
    store = {};
  }
  const save = () => {
    try {
      window.localStorage.setItem(KEY, JSON.stringify(store));
    } catch (error) {
      /* storage unavailable (private mode) — preference just won't persist */
    }
  };

  const setOpen = (btn, panel, open) => {
    panel.classList.toggle("show", open);
    btn.setAttribute("aria-expanded", open ? "true" : "false");
  };

  toggles.forEach((btn) => {
    const key = btn.getAttribute("data-nav-section");
    const target = btn.getAttribute("data-bs-target");
    const panel = target ? document.querySelector(target) : null;
    if (!panel) return;

    // 1. Apply the remembered preference (falls back to the server render).
    if (Object.prototype.hasOwnProperty.call(store, key)) {
      setOpen(btn, panel, store[key] === 1);
    }
    // 2. Always reveal the section you're currently in — without recording it.
    if (panel.querySelector(".nav-link.active") && !panel.classList.contains("show")) {
      setOpen(btn, panel, true);
    }
    // 3. Any deliberate toggle from here on is the preference.
    panel.addEventListener("shown.bs.collapse", () => { store[key] = 1; save(); });
    panel.addEventListener("hidden.bs.collapse", () => { store[key] = 0; save(); });
  });
})();
