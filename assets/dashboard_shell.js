(function () {
  function ready(fn) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn);
    } else {
      fn();
    }
  }

  ready(function () {
    var app = document.querySelector(".app");
    var sidebar = document.querySelector(".sidebar");
    if (!app || !sidebar || document.querySelector(".app-topbar")) return;

    document.body.classList.add("has-app-shell");

    var nav = sidebar.querySelector(".nav");
    if (nav) {
      nav.querySelectorAll("a").forEach(function (link) {
        var labelText = link.textContent.replace(/\s+/g, " ").trim();
        if (!link.querySelector(".nav-label")) {
          Array.from(link.childNodes).forEach(function (node) {
            if (node.nodeType === Node.TEXT_NODE) node.remove();
          });
          var label = document.createElement("span");
          label.className = "nav-label";
          label.textContent = labelText;
          link.appendChild(label);
        }

        var href = (link.getAttribute("href") || "").toLowerCase();
        if (href.indexOf("profile") !== -1) link.classList.add("nav-profile-link");
        if (href.indexOf("logout") !== -1) link.classList.add("nav-logout-link");
      });
    }

    var brand = sidebar.querySelector(".brand");
    var brandBadge = brand ? brand.querySelector(".brand-badge") : null;
    var brandTitle = brand ? brand.querySelector(".brand-title strong") : null;
    var pageTitle = sidebar.querySelector(".brand-title span");
    var profileLink = sidebar.querySelector(".nav-profile-link");
    var logoutLink = sidebar.querySelector(".nav-logout-link");

    var topbar = document.createElement("header");
    topbar.className = "app-topbar";
    topbar.innerHTML =
      '<div class="app-topbar-left">' +
        '<div class="app-topbar-brand">' +
          '<div class="app-topbar-badge">' + (brandBadge ? brandBadge.textContent.trim() : "ST") + '</div>' +
          '<div class="app-topbar-title">' +
            '<strong>' + (brandTitle ? brandTitle.textContent.trim() : "Smart Travel Itinerary Generator") + '</strong>' +
            '<span>' + (pageTitle ? pageTitle.textContent.trim() : "") + '</span>' +
          '</div>' +
        '</div>' +
        '<button type="button" class="sidebar-toggle" data-sidebar-toggle aria-label="Toggle navigation" aria-expanded="false">' +
          '<span></span><span></span><span></span>' +
        '</button>' +
      '</div>' +
      '<div class="app-topbar-actions"></div>';

    var actions = topbar.querySelector(".app-topbar-actions");
    if (profileLink) {
      var profile = profileLink.cloneNode(true);
      profile.className = "btn btn-ghost btn-small topbar-profile";
      profile.innerHTML = '<span class="topbar-action-label">Profile</span>';
      actions.appendChild(profile);
    }
    if (logoutLink) {
      var logout = logoutLink.cloneNode(true);
      logout.className = "btn btn-primary btn-small topbar-logout";
      logout.innerHTML = '<span class="topbar-action-label">Logout</span>';
      actions.appendChild(logout);
    }

    var overlay = document.createElement("button");
    overlay.type = "button";
    overlay.className = "sidebar-overlay";
    overlay.setAttribute("aria-label", "Close navigation");

    document.body.insertBefore(topbar, app);
    document.body.insertBefore(overlay, app);

    var toggle = topbar.querySelector("[data-sidebar-toggle]");
    var mobileQuery = window.matchMedia("(max-width: 980px)");

    function setExpanded(open) {
      if (toggle) toggle.setAttribute("aria-expanded", open ? "true" : "false");
    }

    function closeMobileSidebar() {
      document.body.classList.remove("sidebar-open");
      setExpanded(false);
    }

    if (!mobileQuery.matches && localStorage.getItem("sidebarCollapsed") === "1") {
      app.classList.add("sidebar-collapsed");
    }

    if (toggle) {
      toggle.addEventListener("click", function () {
        if (mobileQuery.matches) {
          var willOpen = !document.body.classList.contains("sidebar-open");
          document.body.classList.toggle("sidebar-open", willOpen);
          setExpanded(willOpen);
          return;
        }

        app.classList.toggle("sidebar-collapsed");
        localStorage.setItem("sidebarCollapsed", app.classList.contains("sidebar-collapsed") ? "1" : "0");
        setExpanded(!app.classList.contains("sidebar-collapsed"));
      });
    }

    overlay.addEventListener("click", closeMobileSidebar);

    if (nav) {
      nav.querySelectorAll("a").forEach(function (link) {
        link.addEventListener("click", function () {
          if (mobileQuery.matches) closeMobileSidebar();
        });
      });
    }

    mobileQuery.addEventListener("change", function () {
      closeMobileSidebar();
    });
  });
})();
