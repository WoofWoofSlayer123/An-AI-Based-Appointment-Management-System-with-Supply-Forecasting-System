
(function () {
  "use strict";

  // ---------- TAB NAVIGATION ----------
  function switchTab(name, updateUrl = false) {
    if (name === "home") {
      const homeTab = document.getElementById("tab-home");
      if (homeTab) {
        name = "home";
      } else {
        name = "overview";
      }
    }

    const target = document.getElementById("tab-" + name);

    // Ignore invalid tab names
    if (!target) return;

    document.body.dataset.tab = name;

    document.querySelectorAll(".dash-section").forEach((section) => {
      section.classList.toggle("active", section.id === "tab-" + name);
    });

    document.querySelectorAll(".nav-link[data-tab]").forEach((link) => {
      link.classList.toggle("active", link.dataset.tab === name);
    });

    if (updateUrl) {
      const url = new URL(window.location.href);
      url.searchParams.set("tab", name);
      url.searchParams.delete("msg");
      url.searchParams.delete("type");
      window.history.replaceState({}, "", url);
    }

    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  // Only navigation links should handle tab clicks.
  // Do not use [data-tab] here because other elements may have that attribute.
  document.querySelectorAll("a[data-tab]").forEach((link) => {
    link.addEventListener("click", (event) => {
      event.preventDefault();
      switchTab(link.dataset.tab, true);
    });
  });

  // Open the tab specified in the URL.
  const params = new URLSearchParams(window.location.search);
  const defaultTab = document.getElementById("tab-home") ? "home" : "overview";
  switchTab(params.get("tab") || document.body.dataset.tab || defaultTab);
  // Make tab navigation available to other dashboard functions.
  window.switchTab = switchTab;


  // ---------- APPOINTMENT HISTORY TOGGLE ----------
  const historyToggle = document.getElementById("historyToggle");
  const historyContent = document.getElementById("appointmentHistoryContent");
  const historyIcon = document.getElementById("historyToggleIcon");

  if (historyToggle && historyContent && historyIcon) {
    historyToggle.addEventListener("click", function () {
      const isExpanded =
        this.getAttribute("aria-expanded") === "true";

      this.setAttribute("aria-expanded", String(!isExpanded));
      historyContent.classList.toggle("is-collapsed", isExpanded);
      historyIcon.textContent = isExpanded ? "+" : "−";
    });
  }


  // ---------- PASSWORD SHOW / HIDE ----------
  document.querySelectorAll(".toggle-password").forEach((button) => {
    button.addEventListener("click", () => {
      const targetId = button.dataset.target;
      const input = targetId
        ? document.getElementById(targetId)
        : button.parentElement.querySelector("input");

      if (!input) return;

      const showing = input.type === "text";
      input.type = showing ? "password" : "text";

      const eye = button.querySelector(".icon-eye");
      const eyeOff = button.querySelector(".icon-eye-off");

      if (eye) eye.style.display = showing ? "block" : "none";
      if (eyeOff) eyeOff.style.display = showing ? "none" : "block";

      button.setAttribute(
        "aria-label",
        showing ? "Show password" : "Hide password"
      );
    });
  });

  // Support existing HTML that calls onclick="togglePassword(this)".
  window.togglePassword = function (button) {
    const input = button?.parentElement?.querySelector("input");
    if (!input) return;

    input.type = input.type === "password" ? "text" : "password";
  };


  // ---------- LIVE PASSWORD REQUIREMENTS ----------
  const newPass = document.getElementById("acc-newpass");
  const requirementsList = document.getElementById("pwRequirements");

  if (newPass && requirementsList) {
    const rules = {
      length: (value) => value.length >= 8,
      number: (value) => /[0-9]/.test(value),
      special: (value) => /[^A-Za-z0-9]/.test(value)
    };

    function updatePasswordRequirements() {
      const value = newPass.value;

      requirementsList.querySelectorAll("li[data-rule]").forEach((item) => {
        const rule = rules[item.dataset.rule];

        if (rule) {
          item.classList.toggle("met", rule(value));
        }
      });
    }

    newPass.addEventListener("input", updatePasswordRequirements);
    updatePasswordRequirements();
  }


  // ---------- LOCAL DATE HELPER ----------
  function getLocalDateString() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, "0");
    const day = String(today.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
  }


  // ---------- APPOINTMENT DATE VALIDATION ----------
  const dateInput = document.getElementById("ap-date");

  if (dateInput) {
    dateInput.min = getLocalDateString();
  }


  // ---------- DATE OF BIRTH VALIDATION ----------
  const dobInput = document.getElementById("pf-dob");

  if (dobInput) {
    dobInput.max = getLocalDateString();
  }



  // ---------- LOCAL DATE HELPER ----------
  function getLocalDateString() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, "0");
    const day = String(today.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
  }


  if (dateInput) {
    dateInput.min = getLocalDateString();
  }


  if (dobInput) {
    dobInput.max = getLocalDateString();
  }

  // ---------- APPOINTMENT TIME OPTIONS ----------
  const CENTER_HOURS = window.CENTER_HOURS || {};
  const CENTER_CLOSURES = window.CENTER_CLOSURES || {};

  function formatMinutes(minutes) {
    const hour = Math.floor(minutes / 60);
    const minute = minutes % 60;
    const hour12 = ((hour + 11) % 12) + 1;
    const period = hour >= 12 ? "PM" : "AM";

    return `${hour12}:${String(minute).padStart(2, "0")} ${period}`;
  }

  function updateTimeOptions() {
    const timeSelect = document.getElementById("timeSelect");
    const hint = document.getElementById("hoursHint");
    const centerSelect = document.getElementById("centerSelect");
    const appointmentDate = document.getElementById("ap-date");

    if (!timeSelect || !centerSelect) return;

    const centerId = centerSelect.value;
    const selectedDate = appointmentDate ? appointmentDate.value : "";
    const hours = CENTER_HOURS[centerId];

    // Reset the time options every time the center or date changes.
    timeSelect.innerHTML = '<option value="">Select time</option>';

    if (appointmentDate) {
      appointmentDate.setCustomValidity("");
    }

    function showMessage(message) {
      timeSelect.innerHTML = '<option value="">No available times</option>';

      if (hint) {
        hint.textContent = message;
      }
    }

    if (!centerId || !hours) {
      showMessage("Select a health center first.");
      return;
    }

    if (!selectedDate) {
      showMessage("Select an appointment date first.");
      return;
    }

    // Parse YYYY-MM-DD as a local date to avoid timezone shifts.
    const parts = selectedDate.split("-").map(Number);
    const selected = new Date(parts[0], parts[1] - 1, parts[2]);
    const selectedDay = selected.getDay(); // Sunday = 0, Saturday = 6

    // Prevent booking on weekends.
    if (selectedDay === 0 || selectedDay === 6) {
      const message = "Health centers are not available for online appointments on weekends.";

      if (appointmentDate) {
        appointmentDate.setCustomValidity(message);
      }

      showMessage(message);
      return;
    }

    // Prevent booking on dates marked as closed for this center.
    const closure = CENTER_CLOSURES[centerId]?.[selectedDate];

    if (closure && String(closure.status).toLowerCase() === "closed") {
      const note = closure.note ? ` Reason: ${closure.note}` : "";
      const message = `This health center is closed on the selected date.${note}`;

      if (appointmentDate) {
        appointmentDate.setCustomValidity(message);
      }

      showMessage(message);
      return;
    }

    // Prevent appointments in the past.
    const todayString = getLocalDateString();

    if (selectedDate < todayString) {
      const message = "Choose today or a future date.";

      if (appointmentDate) {
        appointmentDate.setCustomValidity(message);
      }

      showMessage(message);
      return;
    }

    const open = Number(hours.open);
    const close = Number(hours.close);

    if (
      !Number.isFinite(open) ||
      !Number.isFinite(close) ||
      open < 0 ||
      close > 1440 ||
      open >= close
    ) {
      showMessage("The operating hours for this health center are invalid.");
      return;
    }

    const now = new Date();
    const currentMinutes = now.getHours() * 60 + now.getMinutes();
    let availableCount = 0;

    // Create 30-minute appointment slots within operating hours.
    for (let minutes = open; minutes + 30 <= close; minutes += 30) {
      // For today, omit slots that have already started.
      if (selectedDate === todayString && minutes <= currentMinutes) {
        continue;
      }

      const hour = Math.floor(minutes / 60);
      const minute = minutes % 60;

      const value =
        `${String(hour).padStart(2, "0")}:` +
        `${String(minute).padStart(2, "0")}:00`;

      const option = document.createElement("option");
      option.value = value;
      option.textContent = formatMinutes(minutes);

      timeSelect.appendChild(option);
      availableCount++;
    }

    if (availableCount === 0) {
      showMessage(
        selectedDate === todayString
          ? "No appointment times remain today. Please select another date."
          : "No appointment times are available within the recorded operating hours."
      );
      return;
    }

    if (hint) {
      hint.textContent =
        `Open ${formatMinutes(open)} – ${formatMinutes(close)}` +
        (hours.parsed
          ? ""
          : " (default hours; this center has no readable hours on file)");
    }
  }

  // Keep this function available to the existing HTML onchange handler.
  window.updateTimeOptions = updateTimeOptions;

  // Refresh slots when the selected center or date changes.
  const centerSelect = document.getElementById("centerSelect");

  if (centerSelect) {
    centerSelect.addEventListener("change", updateTimeOptions);
  }

  if (dateInput) {
    dateInput.addEventListener("change", updateTimeOptions);
  }

  
  // Validate the booking form without resetting the user's selected time.
  const appointmentForm = dateInput?.closest(".appt-form");

  if (appointmentForm) {
    appointmentForm.addEventListener("submit", function (event) {
      if (dateInput && !dateInput.checkValidity()) {
        event.preventDefault();
        dateInput.reportValidity();
        return;
      }

      const timeSelect = document.getElementById("timeSelect");

      if (!timeSelect || !timeSelect.value) {
        event.preventDefault();

        if (timeSelect) {
          timeSelect.reportValidity();
        }

        return;
      }

      // Debugging: inspect the exact values being submitted.
      console.log("Appointment booking submission:", {
        health_center_id: document.getElementById("centerSelect")?.value,
        service: appointmentForm.querySelector('[name="service"]')?.value,
        appointment_date: dateInput?.value,
        appointment_time: timeSelect.value
      });
    });
  }


  // Refresh the options when the booking section is opened or preselected.
  updateTimeOptions();



  // ---------- PRESELECT CENTER FOR BOOKING ----------
  function bookAt(centerId) {
    switchTab("appointments");

    const centerSelect = document.getElementById("centerSelect");

    if (centerSelect) {
      centerSelect.value = centerId;
      updateTimeOptions();
    }
  }

  window.bookAt = bookAt;


  // ---------- CANCEL APPOINTMENT MODAL ----------
  function openCancelModal(id) {
    const cancelId = document.getElementById("cancelId");
    const modal = document.getElementById("cancelModal");

    if (cancelId) cancelId.value = id;
    if (modal) modal.classList.add("open");
  }

  function closeCancelModal() {
    document.getElementById("cancelModal")?.classList.remove("open");
  }

  window.openCancelModal = openCancelModal;
  window.closeCancelModal = closeCancelModal;

  const cancelModal = document.getElementById("cancelModal");

  if (cancelModal) {
    cancelModal.addEventListener("click", (event) => {
      if (event.target.id === "cancelModal") {
        closeCancelModal();
      }
    });
  }

})();
(function () {
  "use strict";

  const toggle = document.getElementById("visitHistoryToggle");
  const content = document.getElementById("visitHistoryContent");

  if (!toggle || !content) return;

  toggle.addEventListener("click", function () {
    const isExpanded = toggle.getAttribute("aria-expanded") === "true";

    toggle.setAttribute("aria-expanded", String(!isExpanded));
    content.hidden = isExpanded;
  });

  // Search visit history by patient, service, date, or outcome.
  const searchInput = document.getElementById("historySearch");
  const historyTable = document.getElementById("historyTable");

  if (searchInput && historyTable) {
    searchInput.addEventListener("input", function () {
      const query = this.value.toLowerCase().trim();
      const rows = historyTable.querySelectorAll("tbody tr");

      rows.forEach(function (row) {
        const text = row.textContent.toLowerCase();

        row.style.display = text.includes(query) ? "" : "none";
      });
    });
  }

})();
