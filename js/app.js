(function () {
  "use strict";

  function each(selector, callback, root) {
    Array.prototype.forEach.call((root || document).querySelectorAll(selector), callback);
  }

  each("[data-nav-toggle]", function (toggle) {
    var target = document.getElementById(toggle.getAttribute("aria-controls"));
    if (!target) {
      return;
    }
    toggle.addEventListener("click", function () {
      var open = toggle.getAttribute("aria-expanded") === "true";
      toggle.setAttribute("aria-expanded", String(!open));
      target.classList.toggle("is-open", !open);
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && target.classList.contains("is-open")) {
        toggle.setAttribute("aria-expanded", "false");
        target.classList.remove("is-open");
        toggle.focus();
      }
    });
  });

  each("form[data-confirm]", function (form) {
    form.addEventListener("submit", function (event) {
      if (!window.confirm(form.getAttribute("data-confirm"))) {
        event.preventDefault();
      }
    });
  });

  each("form[data-once]", function (form) {
    form.addEventListener("submit", function (event) {
      if (form.getAttribute("data-submitted") === "true") {
        event.preventDefault();
        return;
      }
      form.setAttribute("data-submitted", "true");
      each("[type=submit]", function (button) {
        button.setAttribute("data-label", button.textContent);
        button.textContent = button.getAttribute("data-busy") || "Sending...";
        button.disabled = true;
      }, form);
    });
  });

  window.addEventListener("pageshow", function () {
    each("form[data-once]", function (form) {
      form.removeAttribute("data-submitted");
      each("[type=submit]", function (button) {
        if (button.hasAttribute("data-label")) {
          button.textContent = button.getAttribute("data-label");
        }
        button.disabled = false;
      }, form);
    });
  });

  each("[data-locate]", function (button) {
    var form = button.closest("form");
    var status = form.querySelector("[data-locate-status]");
    var latitude = form.querySelector("input[name='latitude']");
    var longitude = form.querySelector("input[name='longitude']");
    var accuracy = form.querySelector("input[name='location_accuracy']");

    function show(message, state) {
      status.textContent = message;
      status.className = "locate-status" + (state ? " is-" + state : "");
    }

    if (latitude.value && longitude.value) {
      show("Your location has been captured.", "success");
      button.textContent = "Update my location";
    }

    if (!("geolocation" in navigator) || !window.isSecureContext) {
      return;
    }
    button.hidden = false;

    button.addEventListener("click", function () {
      button.disabled = true;
      show("Finding your location...", "");
      navigator.geolocation.getCurrentPosition(function (position) {
        latitude.value = position.coords.latitude.toFixed(7);
        longitude.value = position.coords.longitude.toFixed(7);
        accuracy.value = Math.round(position.coords.accuracy);
        show("Location captured, accurate to about " + accuracy.value + " m. Please still describe a landmark below.", "success");
        button.textContent = "Update my location";
        button.disabled = false;
      }, function (error) {
        var messages = {
          1: "Location access was blocked. Please describe where you are below.",
          2: "We could not detect your location. Please describe where you are below.",
          3: "Finding your location took too long. Try again, or describe where you are below."
        };
        show(messages[error.code] || messages[2], "error");
        button.disabled = false;
      }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
    });
  });

  each("[data-vehicle-choice]", function (select) {
    var panel = document.querySelector("[data-new-vehicle]");
    if (!panel) {
      return;
    }
    function sync() {
      var isNew = select.value === "new";
      panel.hidden = !isNew;
      each("[data-required-when-new]", function (input) {
        input.required = isNew;
      }, panel);
    }
    select.addEventListener("change", sync);
    sync();
  });

  each("[data-saved-vehicle]", function (select) {
    var manual = document.querySelector("[data-manual-vehicle]");
    if (!manual) {
      return;
    }
    function sync() {
      manual.hidden = select.value !== "";
    }
    select.addEventListener("change", sync);
    sync();
  });

  each("[data-toggle-other]", function (select) {
    var fields = select.closest("form").querySelector("[data-other-fields]");
    if (!fields) {
      return;
    }
    function sync() {
      fields.hidden = select.value !== "other";
    }
    select.addEventListener("change", sync);
    sync();
  });

  each("[data-print]", function (button) {
    button.addEventListener("click", function () {
      window.print();
    });
  });

  each("[data-copy]", function (button) {
    button.addEventListener("click", function () {
      var text = button.getAttribute("data-copy");
      var label = button.textContent;
      function done() {
        button.textContent = "Link copied";
        window.setTimeout(function () {
          button.textContent = label;
        }, 2000);
      }
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done);
        return;
      }
      var field = document.createElement("textarea");
      field.value = text;
      field.setAttribute("readonly", "");
      field.style.position = "absolute";
      field.style.left = "-9999px";
      document.body.appendChild(field);
      field.select();
      document.execCommand("copy");
      field.remove();
      done();
    });
  });

  each("[data-item-form]", function (form) {
    var type = form.querySelector("[data-item-type]");
    var part = form.querySelector("[data-part-select]");
    var price = form.querySelector("input[name='unit_price']");
    var priceHint = form.querySelector("[data-price-hint]");
    var unit = form.querySelector("[data-unit-label]");
    if (!type) {
      return;
    }
    function sync() {
      var current = type.value;
      each("[data-show-for]", function (element) {
        element.hidden = element.getAttribute("data-show-for").split(" ").indexOf(current) === -1;
      }, form);
      each("[data-required-for]", function (input) {
        input.required = input.getAttribute("data-required-for").split(" ").indexOf(current) !== -1;
      }, form);
      var option = part && part.selectedIndex >= 0 ? part.options[part.selectedIndex] : null;
      var fromStock = current === "part" && option && option.getAttribute("data-price") !== null;
      if (price) {
        price.required = current !== "part";
        price.placeholder = fromStock ? Number(option.getAttribute("data-price")).toLocaleString("en-US") : "e.g. 50,000";
      }
      if (priceHint) {
        priceHint.hidden = current !== "part";
      }
      if (unit) {
        unit.textContent = fromStock ? option.getAttribute("data-unit") + " (" + option.getAttribute("data-stock") + " in stock)" : (current === "labour" ? "hours, or 1 for a fixed price" : "");
      }
    }
    type.addEventListener("change", sync);
    if (part) {
      part.addEventListener("change", sync);
    }
    sync();
  });

  var search = document.querySelector("[data-faq-search]");
  if (search) {
    var items = document.querySelectorAll("[data-faq-item]");
    var groups = document.querySelectorAll("[data-faq-group]");
    var empty = document.querySelector("[data-faq-empty]");

    search.addEventListener("input", function () {
      var term = search.value.trim().toLowerCase();
      var visible = 0;
      Array.prototype.forEach.call(items, function (item) {
        var match = term === "" || item.textContent.toLowerCase().indexOf(term) !== -1;
        item.hidden = !match;
        if (match) {
          visible += 1;
        }
      });
      Array.prototype.forEach.call(groups, function (group) {
        group.hidden = !group.querySelector("[data-faq-item]:not([hidden])");
      });
      if (empty) {
        empty.hidden = visible !== 0;
      }
    });
  }

  function openHashTarget() {
    if (!location.hash) {
      return;
    }
    var target = document.getElementById(decodeURIComponent(location.hash.slice(1)));
    if (target && target.tagName === "DETAILS") {
      target.open = true;
      target.scrollIntoView({ block: "start" });
    }
  }
  openHashTarget();
  window.addEventListener("hashchange", openHashTarget);

  var brandLogo = document.querySelector("[data-brand-shine]");
  if (brandLogo) {
    var shineSeen = false;
    try {
      shineSeen = window.sessionStorage.getItem("garage.shine") === "1";
      window.sessionStorage.setItem("garage.shine", "1");
    } catch (error) {
      shineSeen = false;
    }
    if (!shineSeen) {
      brandLogo.classList.add("is-shining");
    }
  }

  var lastInteraction = Date.now();
  var busy = false;
  ["pointerdown", "keydown", "input", "wheel", "touchstart"].forEach(function (type) {
    document.addEventListener(type, function () {
      lastInteraction = Date.now();
    }, { passive: true, capture: true });
  });

  function toast(type, text) {
    var stack = document.querySelector("[data-toasts]");
    if (!stack) {
      stack = document.createElement("div");
      stack.className = "toast-stack";
      stack.setAttribute("data-toasts", "");
      stack.setAttribute("aria-live", "polite");
      document.body.appendChild(stack);
    }
    var item = document.createElement("div");
    item.className = "toast toast-" + type;
    item.textContent = text;
    stack.appendChild(item);
    window.setTimeout(function () {
      item.remove();
    }, type === "error" ? 9000 : 5000);
  }

  function formatWait(ms) {
    var minutes = Math.max(0, Math.floor(ms / 60000));
    if (minutes < 1) {
      return "under 1 min";
    }
    if (minutes < 60) {
      return minutes + " min";
    }
    var hours = Math.floor(minutes / 60);
    if (hours < 24) {
      return hours + " h " + (minutes % 60) + " min";
    }
    var days = Math.floor(hours / 24);
    return days + (days === 1 ? " day " : " days ") + (hours % 24) + " h";
  }

  function tick() {
    each("[data-since]", function (element) {
      var since = Date.parse(element.getAttribute("data-since"));
      if (!isNaN(since)) {
        element.textContent = formatWait(Date.now() - since);
      }
    });
    each("[data-clock]", function (element) {
      element.textContent = new Date().toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
    });
  }

  if (document.querySelector("[data-since], [data-clock]")) {
    tick();
    window.setInterval(tick, 20000);
  }

  each("[data-spark]", function (spark) {
    var tip = spark.querySelector("[data-chart-tip]");
    var baseline = spark.querySelector(".spark-baseline");
    if (!tip) {
      return;
    }
    function show(column) {
      tip.querySelector("strong").textContent = column.getAttribute("data-tip-value");
      tip.querySelector("span").textContent = column.getAttribute("data-tip-label");
      var host = spark.getBoundingClientRect();
      var hit = column.querySelector(".spark-hit").getBoundingClientRect();
      var bar = column.querySelector(".spark-bar");
      var top = bar ? bar.getBoundingClientRect().top : baseline.getBoundingClientRect().top;
      tip.style.left = (hit.left + hit.width / 2 - host.left) + "px";
      tip.style.top = (top - host.top) + "px";
      tip.hidden = false;
    }
    function hide() {
      tip.hidden = true;
    }
    each(".spark-col", function (column) {
      column.addEventListener("pointerenter", function () {
        show(column);
      });
      column.addEventListener("pointerleave", hide);
      column.addEventListener("focus", function () {
        show(column);
      });
      column.addEventListener("blur", hide);
    }, spark);
  });

  each("[data-board]", function (board) {
    var endpoint = board.getAttribute("data-endpoint");
    var csrf = board.getAttribute("data-csrf");
    var drag = null;

    function refreshColumn(zone) {
      var cards = zone.querySelectorAll(".job-card").length;
      var column = zone.closest("[data-column]");
      column.querySelector("[data-count]").textContent = cards;
      var empty = zone.querySelector(".board-empty");
      if (empty) {
        empty.hidden = cards > 0;
      }
    }

    function closeMenus(except) {
      each(".job-move[open]", function (menu) {
        if (menu !== except) {
          menu.open = false;
        }
      }, board);
    }

    function moveCard(card, toZone) {
      var fromZone = card.parentElement;
      var fromNext = card.nextSibling;
      var status = toZone.getAttribute("data-dropzone");
      if (fromZone === toZone) {
        return;
      }
      toZone.insertBefore(card, toZone.querySelector(".board-empty"));
      refreshColumn(fromZone);
      refreshColumn(toZone);
      card.classList.add("is-saving");

      function revert() {
        fromZone.insertBefore(card, fromNext);
        refreshColumn(fromZone);
        refreshColumn(toZone);
      }

      var body = new FormData();
      body.append("csrf_token", csrf);
      body.append("job_id", card.getAttribute("data-job"));
      body.append("status", status);
      fetch(endpoint, {
        method: "POST",
        body: body,
        credentials: "same-origin",
        headers: { Accept: "application/json" }
      }).then(function (response) {
        return response.json().catch(function () {
          return { ok: false, messages: [{ type: "error", text: "The server did not answer properly. Refresh the page and try again." }] };
        });
      }).then(function (data) {
        card.classList.remove("is-saving");
        if (data.ok) {
          card.setAttribute("data-status", status);
          each("button[name='status']", function (button) {
            button.hidden = button.value === status;
          }, card);
          card.classList.add("is-flash");
          window.setTimeout(function () {
            card.classList.remove("is-flash");
          }, 1500);
        } else {
          revert();
        }
        (data.messages || []).forEach(function (message) {
          toast(message.type, message.text);
        });
      }).catch(function () {
        card.classList.remove("is-saving");
        revert();
        toast("error", "Could not reach the server. Check the connection and try again.");
      });
    }

    board.addEventListener("submit", function (event) {
      var form = event.target.closest(".job-move-menu");
      if (!form || !event.submitter) {
        return;
      }
      event.preventDefault();
      var card = form.closest(".job-card");
      var zone = board.querySelector("[data-dropzone='" + event.submitter.value + "']");
      form.closest(".job-move").open = false;
      if (zone) {
        moveCard(card, zone);
      }
    });

    board.addEventListener("toggle", function (event) {
      if (event.target.classList.contains("job-move") && event.target.open) {
        closeMenus(event.target);
      }
    }, true);

    document.addEventListener("click", function (event) {
      if (!event.target.closest(".job-move")) {
        closeMenus(null);
      }
    });

    board.addEventListener("click", function (event) {
      var handle = event.target.closest("[data-drag-handle]");
      if (!handle) {
        return;
      }
      var menu = handle.closest(".job-card").querySelector(".job-move");
      menu.open = true;
      var first = menu.querySelector("button:not([hidden])");
      if (first) {
        first.focus();
      }
    });

    board.addEventListener("pointerdown", function (event) {
      var handle = event.target.closest("[data-drag-handle]");
      if (!handle || event.button !== 0) {
        return;
      }
      event.preventDefault();
      closeMenus(null);
      var card = handle.closest(".job-card");
      var rect = card.getBoundingClientRect();
      var ghost = card.cloneNode(true);
      ghost.classList.add("drag-ghost");
      ghost.removeAttribute("data-job");
      ghost.style.width = rect.width + "px";
      ghost.style.left = rect.left + "px";
      ghost.style.top = rect.top + "px";
      document.body.appendChild(ghost);
      card.classList.add("is-dragging");
      document.body.classList.add("is-dragging");
      drag = {
        card: card,
        ghost: ghost,
        startX: event.clientX,
        startY: event.clientY,
        offsetX: event.clientX - rect.left,
        offsetY: event.clientY - rect.top,
        over: null,
        moved: false
      };
      busy = true;
    });

    window.addEventListener("pointermove", function (event) {
      if (!drag) {
        return;
      }
      if (Math.abs(event.clientX - drag.startX) + Math.abs(event.clientY - drag.startY) > 6) {
        drag.moved = true;
      }
      drag.ghost.style.left = (event.clientX - drag.offsetX) + "px";
      drag.ghost.style.top = (event.clientY - drag.offsetY) + "px";
      var below = document.elementFromPoint(event.clientX, event.clientY);
      var zone = below ? below.closest("[data-dropzone]") : null;
      if (zone && !board.contains(zone)) {
        zone = null;
      }
      if (zone !== drag.over) {
        if (drag.over) {
          drag.over.classList.remove("is-over");
        }
        if (zone) {
          zone.classList.add("is-over");
        }
        drag.over = zone;
      }
      var box = board.getBoundingClientRect();
      if (event.clientX > box.right - 60) {
        board.scrollLeft += 14;
      } else if (event.clientX < box.left + 60) {
        board.scrollLeft -= 14;
      }
    });

    function endDrag(cancelled) {
      if (!drag) {
        return;
      }
      var current = drag;
      drag = null;
      busy = false;
      current.ghost.remove();
      current.card.classList.remove("is-dragging");
      document.body.classList.remove("is-dragging");
      if (current.over) {
        current.over.classList.remove("is-over");
      }
      if (!cancelled && current.moved && current.over) {
        moveCard(current.card, current.over);
      }
    }

    window.addEventListener("pointerup", function () {
      endDrag(false);
    });
    window.addEventListener("pointercancel", function () {
      endDrag(true);
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        endDrag(true);
        closeMenus(null);
      }
    });
  });

  var soundKey = "garage.sound";

  function soundOn() {
    try {
      return window.localStorage.getItem(soundKey) === "on";
    } catch (error) {
      return false;
    }
  }

  function chime() {
    var AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) {
      return;
    }
    try {
      var context = new AudioCtx();
      [880, 660, 880].forEach(function (frequency, index) {
        var start = context.currentTime + index * 0.18;
        var oscillator = context.createOscillator();
        var gain = context.createGain();
        oscillator.type = "sine";
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(0.25, start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.16);
        oscillator.connect(gain);
        gain.connect(context.destination);
        oscillator.start(start);
        oscillator.stop(start + 0.18);
      });
      window.setTimeout(function () {
        context.close();
      }, 1200);
    } catch (error) {
      return;
    }
  }

  each("[data-sound-toggle]", function (button) {
    var label = button.querySelector("[data-sound-label]");
    function render() {
      var on = soundOn();
      button.setAttribute("aria-pressed", String(on));
      if (label) {
        label.textContent = on ? "Sound alerts on" : "Sound alerts off";
      }
    }
    button.addEventListener("click", function () {
      var on = !soundOn();
      try {
        window.localStorage.setItem(soundKey, on ? "on" : "off");
      } catch (error) {
        toast("error", "This browser will not remember the sound setting.");
      }
      render();
      if (on) {
        chime();
        toast("info", "You will hear a chime when a new breakdown request arrives. Keep a staff page open.");
      }
    });
    render();
  });

  var breakdownCount = document.body.getAttribute("data-new-breakdowns");
  if (breakdownCount !== null) {
    var currentCount = parseInt(breakdownCount, 10) || 0;
    var previousCount = null;
    try {
      previousCount = window.sessionStorage.getItem("garage.newBreakdowns");
      window.sessionStorage.setItem("garage.newBreakdowns", String(currentCount));
    } catch (error) {
      previousCount = null;
    }
    if (previousCount !== null && currentCount > parseInt(previousCount, 10) && soundOn()) {
      chime();
      toast("error", "New breakdown request received.");
    }
  }

  var refreshSeconds = parseInt(document.body.getAttribute("data-auto-refresh") || "0", 10);
  if (refreshSeconds > 0) {
    var loadedAt = Date.now();
    var note = document.querySelector("[data-refresh-note]");
    if (note) {
      note.hidden = false;
      note.textContent = "Updated " + new Date().toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }) + ". Refreshes by itself.";
    }
    window.setInterval(function () {
      if (busy || Date.now() - loadedAt < refreshSeconds * 1000 || Date.now() - lastInteraction < 15000) {
        return;
      }
      var active = document.activeElement;
      if (active && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName)) {
        return;
      }
      if (document.querySelector("details.job-move[open], details.chart-table[open]")) {
        return;
      }
      window.location.reload();
    }, 5000);
  }
})();
