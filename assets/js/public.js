/* Public site — progressive enhancement only. The site is fully usable with
   this file absent. */
(function () {
  "use strict";

  var doc = document;

  /* Sticky header condense ---------------------------------------------------*/
  if (doc.querySelector(".site-header")) {
    var onScroll = function () {
      doc.body.classList.toggle("is-stuck", window.scrollY > 10);
    };
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
  }

  /* Primary navigation --------------------------------------------------- */
  var toggle = doc.querySelector(".nav-toggle");
  var nav = doc.querySelector(".primary-nav");
  var scrim = doc.querySelector(".nav-scrim");
  var navClose = doc.querySelector(".primary-nav__close");
  var deskMq = window.matchMedia("(min-width: 1025px)");
  var scrimTimer;

  var closePanels = function () {
    Array.prototype.forEach.call(doc.querySelectorAll(".primary-nav .subnav.is-open"), function (sub) {
      sub.classList.remove("is-open");
      var b = sub.parentElement.querySelector(".nav-item__toggle");
      if (b) b.setAttribute("aria-expanded", "false");
    });
  };

  var openDrawer = function () {
    if (scrim) { clearTimeout(scrimTimer); scrim.hidden = false; }
    requestAnimationFrame(function () {
      nav.classList.add("is-open");
      doc.body.classList.add("nav-open");
    });
    if (toggle) toggle.setAttribute("aria-expanded", "true");
  };

  var closeDrawer = function () {
    nav.classList.remove("is-open");
    doc.body.classList.remove("nav-open");
    if (toggle) toggle.setAttribute("aria-expanded", "false");
    if (scrim) scrimTimer = setTimeout(function () { scrim.hidden = true; }, 300);
    closePanels();
  };

  if (toggle && nav) {
    toggle.addEventListener("click", function () {
      if (nav.classList.contains("is-open")) closeDrawer(); else openDrawer();
    });
    if (navClose) navClose.addEventListener("click", closeDrawer);
    if (scrim) scrim.addEventListener("click", closeDrawer);

    /* tapping a real destination link closes the drawer */
    nav.addEventListener("click", function (e) {
      if (e.target.closest("a") && nav.classList.contains("is-open")) closeDrawer();
    });

    /* per-item sub-panel toggles — work in both layouts (desktop also has :hover) */
    Array.prototype.forEach.call(doc.querySelectorAll(".nav-item__toggle"), function (btn) {
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        var item = btn.closest(".nav-item");
        var sub = item && item.querySelector(".subnav");
        if (!sub) return;
        var willOpen = !sub.classList.contains("is-open");
        if (willOpen && deskMq.matches) closePanels();
        sub.classList.toggle("is-open", willOpen);
        btn.setAttribute("aria-expanded", willOpen ? "true" : "false");
      });
    });

    /* desktop: click outside any nav item closes open panels */
    doc.addEventListener("click", function (e) {
      if (deskMq.matches && !e.target.closest(".nav-item")) closePanels();
    });

    /* Escape closes the drawer (mobile) or open panels (desktop) */
    doc.addEventListener("keydown", function (e) {
      if (e.key !== "Escape") return;
      if (nav.classList.contains("is-open")) { closeDrawer(); if (toggle) toggle.focus(); }
      else closePanels();
    });

    /* reset drawer state when the viewport grows past the breakpoint */
    var onBpChange = function (ev) {
      if (!ev.matches) return;
      nav.classList.remove("is-open");
      doc.body.classList.remove("nav-open");
      if (toggle) toggle.setAttribute("aria-expanded", "false");
      if (scrim) scrim.hidden = true;
      closePanels();
    };
    if (deskMq.addEventListener) deskMq.addEventListener("change", onBpChange);
    else if (deskMq.addListener) deskMq.addListener(onBpChange);
  }

  /* Quantity steppers (shop product + basket + tickets) ------------------- */
  Array.prototype.forEach.call(doc.querySelectorAll("[data-stepper]"), function (stepper) {
    var input = stepper.querySelector("input");
    if (!input) return;
    Array.prototype.forEach.call(stepper.querySelectorAll("button[data-step]"), function (btn) {
      btn.addEventListener("click", function () {
        var min = parseInt(input.getAttribute("min") || "0", 10);
        var max = parseInt(input.getAttribute("max") || "999", 10);
        var v = parseInt(input.value || "0", 10) + parseInt(btn.getAttribute("data-step"), 10);
        input.value = Math.max(min, Math.min(max, isNaN(v) ? min : v));
        input.dispatchEvent(new Event("change", { bubbles: true }));
      });
    });
  });

  /* Image lightbox — photo albums + news-article galleries -------------- */
  var lbGrid = doc.querySelector("[data-lightbox]");
  var lightbox = doc.getElementById("lightbox");
  if (lbGrid && lightbox) {
    var lbImg = lightbox.querySelector(".lightbox__img");
    var lbCount = lightbox.querySelector(".lightbox__count");
    var lbCap = lightbox.querySelector(".lightbox__caption");
    var lbThumbsWrap = lightbox.querySelector(".lightbox__thumbs");
    var lbStage = lightbox.querySelector(".lightbox__stage");
    var items = Array.prototype.slice.call(lbGrid.querySelectorAll("[data-full]"));
    var cur = 0;
    var thumbEls = [];

    if (items.length < 2) lightbox.classList.add("lightbox--single");

    items.forEach(function (it, i) {
      var innerImg = it.querySelector("img");
      var t = doc.createElement("button");
      t.type = "button";
      t.className = "lightbox__thumb";
      t.setAttribute("aria-label", "Show image " + (i + 1));
      var tImg = doc.createElement("img");
      tImg.src = innerImg ? innerImg.getAttribute("src") : it.getAttribute("data-full");
      tImg.alt = "";
      t.appendChild(tImg);
      t.addEventListener("click", function () { show(i); });
      if (lbThumbsWrap) lbThumbsWrap.appendChild(t);
      thumbEls.push(t);
    });

    var show = function (i) {
      cur = (i + items.length) % items.length;
      lbImg.src = items[cur].getAttribute("data-full");
      var cap = items[cur].getAttribute("data-caption") || "";
      if (lbCap) { lbCap.textContent = cap; lbCap.hidden = cap === ""; }
      if (lbCount) lbCount.textContent = (cur + 1) + " / " + items.length;
      thumbEls.forEach(function (t, k) { t.classList.toggle("is-active", k === cur); });
      var act = thumbEls[cur];
      if (act && act.scrollIntoView) act.scrollIntoView({ inline: "center", block: "nearest" });
    };
    var open = function (i) {
      show(i);
      lightbox.hidden = false;
      doc.body.classList.add("nav-open");
      var c = lightbox.querySelector(".lightbox__close");
      if (c) c.focus();
    };
    var close = function () {
      lightbox.hidden = true;
      lbImg.src = "";
      doc.body.classList.remove("nav-open");
    };

    lbGrid.addEventListener("click", function (e) {
      var a = e.target.closest("[data-full]");
      if (!a) return;
      e.preventDefault();
      open(items.indexOf(a));
    });
    lightbox.addEventListener("click", function (e) {
      if (e.target.closest(".lightbox__nav--next")) show(cur + 1);
      else if (e.target.closest(".lightbox__nav--prev")) show(cur - 1);
      else if (e.target.closest(".lightbox__thumb")) { /* handled per-thumb */ }
      else if (e.target === lightbox || e.target === lbStage || e.target.closest(".lightbox__close")) close();
    });
    doc.addEventListener("keydown", function (e) {
      if (lightbox.hidden) return;
      if (e.key === "Escape") close();
      else if (e.key === "ArrowRight") show(cur + 1);
      else if (e.key === "ArrowLeft") show(cur - 1);
    });
  }

  /* Match-ticket order summary ------------------------------------------- */
  var tktForm = doc.querySelector("[data-ticket-form]");
  if (tktForm) {
    var tktItems = tktForm.querySelector(".js-tkt-items");
    var tktTotalEl = tktForm.querySelector(".js-tkt-total");
    var tktBtn = tktForm.querySelector(".js-tkt-submit");
    var tktLines = Array.prototype.slice.call(tktForm.querySelectorAll(".tkt-line"));
    var money = function (n) { return "£" + n.toFixed(2); };

    var tktRender = function () {
      var total = 0, count = 0, rows = "";
      tktLines.forEach(function (line) {
        var input = line.querySelector(".tkt-qty");
        if (!input) return;
        var qty = parseInt(input.value || "0", 10);
        if (isNaN(qty) || qty < 0) qty = 0;
        var price = parseFloat(line.getAttribute("data-price") || "0");
        if (qty > 0) {
          total += qty * price;
          count += qty;
          var name = line.getAttribute("data-name") || "Ticket";
          rows += '<div class="tkt-si"><span><b>' + qty + '×</b>' +
                  name.replace(/[&<>"]/g, function (c) {
                    return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c];
                  }) +
                  '</span><span>' + money(qty * price) + "</span></div>";
        }
      });
      if (tktItems) {
        tktItems.innerHTML = rows ||
          '<p class="tkt-summary__empty">No tickets selected yet.</p>';
      }
      if (tktTotalEl) tktTotalEl.textContent = money(total);
      if (tktBtn) {
        tktBtn.disabled = count === 0;
        tktBtn.textContent = count === 0
          ? "Continue to payment"
          : "Continue to payment · " + money(total);
      }
    };

    tktForm.addEventListener("input", function (e) {
      if (e.target.classList.contains("tkt-qty")) tktRender();
    });
    tktForm.addEventListener("change", function (e) {
      if (e.target.classList.contains("tkt-qty")) tktRender();
    });
    tktRender();
  }

  /* Policy tabs — show one document at a time, deep-linkable via #slug ---- */
  var policyTabs = doc.querySelector("[data-policytabs]");
  if (policyTabs) {
    var pTabs = Array.prototype.slice.call(policyTabs.querySelectorAll(".policytabs__tab"));
    var pPanels = Array.prototype.slice.call(policyTabs.querySelectorAll(".policydoc"));
    var pIds = pPanels.map(function (panel) { return panel.id; });

    var pActivate = function (id, moveFocus) {
      if (pIds.indexOf(id) === -1) id = pIds[0];
      if (!id) return;
      pPanels.forEach(function (panel) { panel.hidden = panel.id !== id; });
      pTabs.forEach(function (tab) {
        var on = tab.getAttribute("href") === "#" + id;
        tab.classList.toggle("is-active", on);
        if (on) tab.setAttribute("aria-current", "page");
        else tab.removeAttribute("aria-current");
      });
      if (moveFocus) {
        var active = doc.getElementById(id);
        if (active) {
          active.setAttribute("tabindex", "-1");
          active.focus({ preventScroll: true });
        }
      }
    };

    pTabs.forEach(function (tab) {
      tab.addEventListener("click", function (e) {
        e.preventDefault();
        var id = tab.getAttribute("href").slice(1);
        if (window.history && history.pushState) history.pushState(null, "", "#" + id);
        else location.hash = id;
        pActivate(id, true);
        if (window.matchMedia("(max-width: 820px)").matches) {
          policyTabs.scrollIntoView({ behavior: "smooth", block: "start" });
        }
      });
    });

    var pFromHash = function () { pActivate((location.hash || "").replace("#", ""), false); };
    window.addEventListener("hashchange", pFromHash);
    window.addEventListener("popstate", pFromHash);
    pFromHash();
  }

  /* Product image gallery ------------------------------------------------- */
  var galleryMain = doc.querySelector("[data-gallery-main]");
  if (galleryMain) {
    Array.prototype.forEach.call(doc.querySelectorAll("[data-gallery-thumb]"), function (thumb) {
      thumb.addEventListener("click", function () {
        galleryMain.style.backgroundImage = "url('" + thumb.dataset.src + "')";
        galleryMain.classList.remove("is-placeholder");
        Array.prototype.forEach.call(doc.querySelectorAll("[data-gallery-thumb]"), function (t) {
          t.classList.remove("is-active");
        });
        thumb.classList.add("is-active");
      });
    });
  }
})();
