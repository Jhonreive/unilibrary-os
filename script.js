/* =========================================================
   UniLibrary OS - client-side behavior only.
   Data and rendering happen in PHP; this file handles the
   in-browser interactions: modals, confirmations, the
   type-to-filter pickers on the borrow page, and search
   bar conveniences.
   ========================================================= */

document.addEventListener("DOMContentLoaded", function () {

  /* ---------------------------------------------------------
     Modals
     --------------------------------------------------------- */
  function openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove("hidden");
    const first = modal.querySelector("input:not([type=hidden]), select, textarea");
    if (first) first.focus();
  }

  function closeModals() {
    document.querySelectorAll(".modal").forEach(function (m) {
      m.classList.add("hidden");
    });
  }

  document.querySelectorAll("[data-open-modal]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      openModal(btn.dataset.openModal);
    });
  });

  document.querySelectorAll("[data-close-modal]").forEach(function (btn) {
    btn.addEventListener("click", closeModals);
  });

  // clicking the dim area outside the box closes it
  document.querySelectorAll(".modal").forEach(function (modal) {
    modal.addEventListener("click", function (e) {
      if (e.target === modal) closeModals();
    });
  });

  /* ---------------------------------------------------------
     "Edit" buttons: copy the row's data- attributes into the
     matching edit modal, then open it.
     --------------------------------------------------------- */
  function wireEditButtons(attribute, modalId, fieldMap) {
    document.querySelectorAll("[" + attribute + "]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        Object.keys(fieldMap).forEach(function (dataKey) {
          const field = document.getElementById(fieldMap[dataKey]);
          if (field) field.value = btn.dataset[dataKey] || "";
        });
        openModal(modalId);
      });
    });
  }

  wireEditButtons("data-edit-member", "editMemberModal", {
    id: "em-id", schoolId: "em-school", name: "em-name",
    type: "em-type", email: "em-email", status: "em-status"
  });

  wireEditButtons("data-edit-book", "editBookModal", {
    id: "eb-id", isbn: "eb-isbn", title: "eb-title", author: "eb-author",
    genre: "eb-genre", copies: "eb-copies"
  });

  wireEditButtons("data-edit-user", "editUserModal", {
    id: "eu-id", name: "eu-name", email: "eu-email", role: "eu-role"
  });

  // "Set / Reset Password" puts the name in the copy, not just a value field
  document.querySelectorAll("[data-set-password]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      const idField = document.getElementById("sp-id");
      const nameEl  = document.getElementById("sp-name");
      if (idField) idField.value = btn.dataset.id || "";
      if (nameEl) nameEl.textContent = btn.dataset.name || "";
      openModal("setPasswordModal");
    });
  });

  /* ---------------------------------------------------------
     Destructive actions ask first.
     --------------------------------------------------------- */
  document.querySelectorAll("form[data-confirm]").forEach(function (form) {
    form.addEventListener("submit", function (e) {
      if (!window.confirm(form.dataset.confirm)) e.preventDefault();
    });
  });

  /* ---------------------------------------------------------
     Combo box: a search input that filters a list of options
     and writes the chosen id into a hidden field.
     Used for the member and book pickers on borrow.php.
     --------------------------------------------------------- */
  function setupCombo(config) {
    const input  = document.getElementById(config.input);
    const list   = document.getElementById(config.list);
    const hidden = document.getElementById(config.hidden);
    const chosen = document.getElementById(config.chosen);
    if (!input || !list || !hidden || !chosen) return;

    const options   = Array.from(list.querySelectorAll(".combo-option"));
    const emptyNote = list.querySelector(".combo-empty");
    let highlighted = -1;

    function visibleOptions() {
      return options.filter(function (o) { return !o.classList.contains("hidden"); });
    }

    function clearHighlight() {
      options.forEach(function (o) { o.classList.remove("highlight"); });
      highlighted = -1;
    }

    function showList() {
      list.classList.remove("hidden");
      input.setAttribute("aria-expanded", "true");
    }

    function hideList() {
      list.classList.add("hidden");
      input.setAttribute("aria-expanded", "false");
      clearHighlight();
    }

    function filter() {
      const term = input.value.trim().toLowerCase();
      let matches = 0;

      options.forEach(function (option) {
        const hit = term === "" || (option.dataset.search || "").indexOf(term) !== -1;
        option.classList.toggle("hidden", !hit);
        if (hit) matches++;
      });

      if (emptyNote) emptyNote.classList.toggle("hidden", matches > 0);
      clearHighlight();
      showList();
    }

    function choose(option) {
      if (!option || option.disabled) return;
      hidden.value = option.dataset.value;
      chosen.querySelector("[data-chosen-label]").textContent  = option.dataset.label || "";
      chosen.querySelector("[data-chosen-detail]").textContent = option.dataset.detail || "";
      chosen.classList.add("show");
      input.value = "";
      input.closest(".search-bar").style.display = "none";
      hideList();
    }

    function reset() {
      hidden.value = "";
      chosen.classList.remove("show");
      const bar = input.closest(".search-bar");
      bar.style.display = "";
      input.value = "";
      filter();
      input.focus();
    }

    input.addEventListener("focus", filter);
    input.addEventListener("input", filter);

    input.addEventListener("keydown", function (e) {
      const vis = visibleOptions();
      if (e.key === "ArrowDown" || e.key === "ArrowUp") {
        e.preventDefault();
        if (!vis.length) return;
        showList();
        clearHighlight();
        highlighted = e.key === "ArrowDown"
          ? Math.min(vis.length - 1, highlighted + 1)
          : Math.max(0, highlighted - 1);
        vis[highlighted].classList.add("highlight");
        vis[highlighted].scrollIntoView({ block: "nearest" });
      } else if (e.key === "Enter") {
        // don't submit the whole loan form just because the user hit Enter
        e.preventDefault();
        if (highlighted >= 0 && vis[highlighted]) choose(vis[highlighted]);
        else if (vis.length === 1) choose(vis[0]);
      } else if (e.key === "Escape") {
        hideList();
      }
    });

    options.forEach(function (option) {
      option.addEventListener("click", function () { choose(option); });
    });

    chosen.querySelector("[data-combo-clear]").addEventListener("click", reset);

    document.addEventListener("click", function (e) {
      if (!list.contains(e.target) && e.target !== input) hideList();
    });
  }

  setupCombo({ input: "memberSearch", list: "memberList", hidden: "memberId", chosen: "memberChosen" });
  setupCombo({ input: "bookSearch",   list: "bookList",   hidden: "bookId",   chosen: "bookChosen" });

  /* ---------------------------------------------------------
     Borrow form: make sure both pickers were actually used.
     --------------------------------------------------------- */
  const borrowForm = document.getElementById("borrowForm");
  if (borrowForm) {
    borrowForm.addEventListener("submit", function (e) {
      const member = document.getElementById("memberId");
      const book   = document.getElementById("bookId");
      if (member && !member.value) {
        e.preventDefault();
        window.alert("Choose the student or faculty member first.");
        document.getElementById("memberSearch").focus();
      } else if (book && !book.value) {
        e.preventDefault();
        window.alert("Choose a book to check out.");
        document.getElementById("bookSearch").focus();
      }
    });
  }

  /* ---------------------------------------------------------
     Search suggestions: as you type in a search bar marked with
     data-suggest, show matching titles / authors / ISBNs / members
     from suggest.php. Arrow keys + Enter pick one; Esc closes.
     --------------------------------------------------------- */
  const TAG_LABELS = { book: "Book", author: "Author", member: "Member" };

  // Put the matched part of the text in <mark>, without using innerHTML.
  function appendHighlighted(el, text, term) {
    const at = term ? text.toLowerCase().indexOf(term.toLowerCase()) : -1;
    if (at < 0) { el.appendChild(document.createTextNode(text)); return; }
    el.appendChild(document.createTextNode(text.slice(0, at)));
    const mark = document.createElement("mark");
    mark.textContent = text.slice(at, at + term.length);
    el.appendChild(mark);
    el.appendChild(document.createTextNode(text.slice(at + term.length)));
  }

  function setupSuggest(input) {
    const form = input.closest("form");
    const bar  = input.closest(".search-bar");
    if (!form || !bar || typeof fetch !== "function") return;

    if (input.dataset.suggestReady) return;   // never wire the same box twice
    input.dataset.suggestReady = "1";

    const scope = input.dataset.suggest || "books";
    const list  = document.createElement("div");
    const listId = "suggest-" + Math.random().toString(36).slice(2, 8);
    list.id = listId;
    list.className = "suggest-list hidden";
    list.setAttribute("role", "listbox");
    bar.classList.add("has-suggest");
    bar.appendChild(list);

    input.setAttribute("autocomplete", "off");
    input.setAttribute("aria-autocomplete", "list");
    input.setAttribute("aria-controls", listId);
    input.setAttribute("aria-expanded", "false");

    let items = [];          // the rendered option buttons
    let highlighted = -1;
    let timer = null;
    let controller = null;
    let lastRequest = 0;

    function hide() {
      list.classList.add("hidden");
      input.setAttribute("aria-expanded", "false");
      highlighted = -1;
    }

    function setHighlight(i) {
      items.forEach(function (o) { o.classList.remove("highlight"); });
      highlighted = i;
      if (i >= 0 && items[i]) {
        items[i].classList.add("highlight");
        items[i].scrollIntoView({ block: "nearest" });
      }
    }

    function choose(value) {
      input.value = value;
      hide();
      if (typeof form.requestSubmit === "function") form.requestSubmit();
      else form.submit();
    }

    function render(results, term) {
      list.textContent = "";
      items = [];
      highlighted = -1;
      if (!results.length) { hide(); return; }

      results.forEach(function (r) {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "suggest-option";
        btn.setAttribute("role", "option");

        const text = document.createElement("span");
        text.className = "suggest-text";
        const strong = document.createElement("strong");
        appendHighlighted(strong, r.label, term);
        const small = document.createElement("small");
        appendHighlighted(small, r.detail || "", term);
        text.appendChild(strong);
        text.appendChild(small);

        const tag = document.createElement("span");
        tag.className = "suggest-tag";
        tag.textContent = TAG_LABELS[r.type] || "";

        btn.appendChild(text);
        btn.appendChild(tag);
        btn.addEventListener("click", function () { choose(r.value); });
        list.appendChild(btn);
        items.push(btn);
      });

      list.classList.remove("hidden");
      input.setAttribute("aria-expanded", "true");
    }

    function fetchSuggestions() {
      const term = input.value.trim();
      if (term === "") { hide(); return; }

      if (controller) controller.abort();
      controller = typeof AbortController === "function" ? new AbortController() : null;
      const mine = ++lastRequest;

      fetch("suggest.php?scope=" + encodeURIComponent(scope) + "&q=" + encodeURIComponent(term), {
        credentials: "same-origin",
        headers: { "Accept": "application/json" },
        signal: controller ? controller.signal : undefined
      })
        .then(function (res) { return res.ok ? res.json() : []; })
        .then(function (data) {
          // ignore answers to something the user has already typed past
          if (mine !== lastRequest || input.value.trim() !== term) return;
          render(Array.isArray(data) ? data : [], term);
        })
        .catch(function () { /* aborted or offline: just leave the plain search */ });
    }

    input.addEventListener("input", function () {
      clearTimeout(timer);
      timer = setTimeout(fetchSuggestions, 150);
    });

    input.addEventListener("keydown", function (e) {
      const open = !list.classList.contains("hidden") && items.length > 0;
      if (e.key === "ArrowDown" || e.key === "ArrowUp") {
        if (!open) return;
        e.preventDefault();
        const last = items.length - 1;
        if (e.key === "ArrowDown") setHighlight(highlighted >= last ? 0 : highlighted + 1);
        else setHighlight(highlighted <= 0 ? last : highlighted - 1);
      } else if (e.key === "Enter") {
        // only hijack Enter if the user actually arrowed onto a suggestion
        if (open && highlighted >= 0) {
          e.preventDefault();
          items[highlighted].click();
        }
      } else if (e.key === "Escape" && open) {
        // first Esc closes the list; a second Esc clears the field (handler below)
        e.preventDefault();
        e.stopImmediatePropagation();
        hide();
      }
    });

    // keep focus in the input when clicking a suggestion, close when leaving it
    list.addEventListener("mousedown", function (e) { e.preventDefault(); });
    input.addEventListener("blur", hide);
    form.addEventListener("submit", function () { clearTimeout(timer); hide(); });
  }

  document.querySelectorAll('input[data-suggest]').forEach(setupSuggest);

  /* ---------------------------------------------------------
     Search bars: don't submit an unchanged empty search, and
     let Escape clear the field.
     --------------------------------------------------------- */
  document.querySelectorAll('form[role="search"]').forEach(function (form) {
    const field = form.querySelector('input[type="search"]');
    if (!field) return;

    form.addEventListener("submit", function () {
      field.value = field.value.trim();
    });

    field.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && field.value !== "") {
        e.preventDefault();
        field.value = "";
      }
    });
  });

  /* ---------------------------------------------------------
     Collapsible "Transactions" group in the sidebar.
     --------------------------------------------------------- */
  const txToggle = document.getElementById("txToggle");
  if (txToggle) {
    txToggle.addEventListener("click", function () {
      const submenu  = document.getElementById("group-transactions");
      const expanded = txToggle.getAttribute("aria-expanded") === "true";
      txToggle.setAttribute("aria-expanded", String(!expanded));
      if (submenu) submenu.classList.toggle("open", !expanded);
    });
  }

  /* ---------------------------------------------------------
     Keyboard shortcuts.
     --------------------------------------------------------- */
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") closeModals();

    // Ctrl/Cmd+K focuses the search box in the top bar
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") {
      e.preventDefault();
      const global = document.getElementById("globalSearchInput")
                  || document.getElementById("searchPageInput");
      if (global) { global.focus(); global.select(); }
      else { window.location.href = "search.php"; }
    }
  });
});


/* =========================================================
   Responsive helpers: mobile menu drawer + stacked tables
   ========================================================= */
(function () {
  var body = document.body;
  var toggle = document.getElementById("navToggle");
  var scrim = document.getElementById("navScrim");
  var side = document.getElementById("sidebar");

  function setNav(open) {
    body.classList.toggle("nav-open", open);
    if (toggle) {
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      toggle.setAttribute("aria-label", open ? "Close menu" : "Open menu");
    }
  }
  if (toggle) toggle.addEventListener("click", function () { setNav(!body.classList.contains("nav-open")); });
  if (scrim) scrim.addEventListener("click", function () { setNav(false); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") setNav(false); });
  if (side) side.addEventListener("click", function (e) {
    var a = e.target.closest ? e.target.closest("a") : null;
    if (a) setNav(false);
  });
  window.addEventListener("resize", function () { if (window.innerWidth > 900) setNav(false); });

  // label every table cell so the phone layout can show "Column: value" rows
  document.querySelectorAll("table").forEach(function (t) {
    var heads = Array.prototype.map.call(t.querySelectorAll("thead th"), function (th) { return th.textContent.trim(); });
    if (!heads.length) return;
    t.classList.add("stack");
    t.querySelectorAll("tbody tr").forEach(function (tr) {
      var col = 0;
      Array.prototype.forEach.call(tr.children, function (td) {
        if (td.hasAttribute("colspan")) { col += parseInt(td.getAttribute("colspan"), 10) || 1; return; }
        if (!td.hasAttribute("data-label")) td.setAttribute("data-label", heads[col] || "");
        col++;
      });
    });
  });
})();


/* =========================================================
   Wow helpers: counting numbers + cursor spotlight on cards
   ========================================================= */
(function () {
  var calm = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  if (!calm) {
    document.querySelectorAll(".metric-value").forEach(function (el) {
      var m = el.textContent.trim().match(/^(\D*?)(\d[\d,]*)(\.\d+)?(\D*)$/);
      if (!m) return;
      var dec = m[3] ? m[3].length - 1 : 0, comma = m[2].indexOf(",") > -1;
      var end = parseFloat((m[2] + (m[3] || "")).replace(/,/g, ""));
      if (!isFinite(end) || end === 0) return;
      function fmt(v) {
        var s = v.toFixed(dec);
        if (comma) { var p = s.split("."); p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ","); s = p.join("."); }
        return m[1] + s + m[4];
      }
      var t0 = null;
      el.textContent = fmt(0);
      (function step(ts) {
        if (!t0) t0 = ts;
        var k = Math.min(1, (ts - t0) / 1100); k = 1 - Math.pow(1 - k, 3);
        el.textContent = k < 1 ? fmt(end * k) : fmt(end);
        if (k < 1) requestAnimationFrame(step);
      })(performance.now());
    });
  }
  document.querySelectorAll(".metric, .book-card-item").forEach(function (c) {
    c.addEventListener("mousemove", function (e) {
      var r = c.getBoundingClientRect();
      c.style.setProperty("--mx", (e.clientX - r.left) + "px");
      c.style.setProperty("--my", (e.clientY - r.top) + "px");
    });
  });
})();


/* Auto-submit filter dropdowns (replaces inline onchange= handlers, which the
   Content-Security-Policy blocks). Mark a <select> with data-autosubmit. */
document.addEventListener('change', function (e) {
  var t = e.target;
  if (t && t.hasAttribute && t.hasAttribute('data-autosubmit') && t.form) t.form.submit();
});
