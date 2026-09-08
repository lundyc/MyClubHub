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

  /* Photo album lightbox ------------------------------------------------- */
  var albumGrid = doc.querySelector(".album-grid[data-lightbox]");
  var lightbox = doc.getElementById("lightbox");
  if (albumGrid && lightbox) {
    var lbImg = lightbox.querySelector(".lightbox__img");
    var links = Array.prototype.slice.call(albumGrid.querySelectorAll(".album-grid__item"));
    var cur = 0;

    var show = function (i) {
      cur = (i + links.length) % links.length;
      lbImg.src = links[cur].getAttribute("data-full");
    };
    var open = function (i) {
      show(i);
      lightbox.hidden = false;
      doc.body.classList.add("nav-open");
    };
    var close = function () {
      lightbox.hidden = true;
      lbImg.src = "";
      doc.body.classList.remove("nav-open");
    };

    albumGrid.addEventListener("click", function (e) {
      var a = e.target.closest(".album-grid__item");
      if (!a) return;
      e.preventDefault();
      open(links.indexOf(a));
    });
    lightbox.addEventListener("click", function (e) {
      if (e.target.closest(".lightbox__nav--next")) show(cur + 1);
      else if (e.target.closest(".lightbox__nav--prev")) show(cur - 1);
      else if (e.target === lightbox || e.target.closest(".lightbox__close")) close();
    });
    doc.addEventListener("keydown", function (e) {
      if (lightbox.hidden) return;
      if (e.key === "Escape") close();
      else if (e.key === "ArrowRight") show(cur + 1);
      else if (e.key === "ArrowLeft") show(cur - 1);
    });
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
