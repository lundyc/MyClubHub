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

  /* Mobile nav drawer -----------------------------------------------------*/
  var toggle = doc.querySelector(".nav-toggle");
  var nav = doc.querySelector(".primary-nav");
  if (toggle && nav) {
    toggle.addEventListener("click", function () {
      var open = nav.classList.toggle("is-open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      doc.body.classList.toggle("nav-open", open);
    });
    nav.addEventListener("click", function (e) {
      if (e.target.closest("a")) {
        nav.classList.remove("is-open");
        doc.body.classList.remove("nav-open");
        toggle.setAttribute("aria-expanded", "false");
      }
    });
  }

  /* Sub-menu toggles on narrow screens -------------------------------------
     On desktop the CSS :hover / :focus-within handles this; the buttons only
     do work when the viewport is in the mobile drawer layout. */
  var mq = window.matchMedia("(max-width: 960px)");
  Array.prototype.forEach.call(
    doc.querySelectorAll(".primary-nav .navbtn"),
    function (btn) {
      btn.addEventListener("click", function () {
        if (!mq.matches) return;
        var sub = btn.parentElement.querySelector(".subnav");
        if (!sub) return;
        var open = sub.classList.toggle("is-open");
        btn.setAttribute("aria-expanded", open ? "true" : "false");
      });
    }
  );

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
