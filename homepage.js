(function () {
  function showTab(name) {
    document.querySelectorAll(".view").forEach((v) =>
      v.classList.toggle("active", v.id === "view-" + name)
    );
    document.getElementById("authTabs").style.display =
      name === "signin" || name === "register" ? "flex" : "none";
    document.querySelectorAll(".tab-btn").forEach((b) =>
      b.classList.toggle("active", b.dataset.tab === name)
    );
  }

  document.querySelectorAll("[data-tab]").forEach((el) => {
    el.addEventListener("click", () => showTab(el.dataset.tab));
  });

  document.getElementById("rg-isStaff").addEventListener("change", (e) => {
    document.getElementById("staffFields").classList.toggle("show", e.target.checked);
  });

  // ---------- password show/hide (applies to every .toggle-password on the page) ----------
  document.querySelectorAll(".toggle-password").forEach((btn) => {
    btn.addEventListener("click", () => {
      const input = document.getElementById(btn.dataset.target);
      const showing = input.type === "text";
      input.type = showing ? "password" : "text";
      btn.querySelector(".icon-eye").style.display = showing ? "block" : "none";
      btn.querySelector(".icon-eye-off").style.display = showing ? "none" : "block";
      btn.setAttribute("aria-label", showing ? "Show password" : "Hide password");
    });
  });

  // ---------- live password requirements (register form) ----------
  // Mirrors the server-side check in signup.php — this is just for instant
  // feedback; signup.php still enforces the real rule since JS can be bypassed.
  const rgPass = document.getElementById("rg-pass");
  const requirementsList = document.getElementById("pwRequirements");
  if (rgPass && requirementsList) {
    const rules = {
      length: (v) => v.length >= 8,
      number: (v) => /[0-9]/.test(v),
      special: (v) => /[^A-Za-z0-9]/.test(v),
    };
    rgPass.addEventListener("input", () => {
      const value = rgPass.value;
      requirementsList.querySelectorAll("li[data-rule]").forEach((li) => {
        const passes = rules[li.dataset.rule](value);
        li.classList.toggle("met", passes);
      });
    });
  }

  // Block submission client-side if the password doesn't meet the rules yet
  // (signup.php rejects it either way — this just avoids the round trip).
  const registerForm = document.querySelector('#view-register form');
  if (registerForm && rgPass) {
    registerForm.addEventListener("submit", (e) => {
      const value = rgPass.value;
      const valid = value.length >= 8 && /[0-9]/.test(value) && /[^A-Za-z0-9]/.test(value);
      if (!valid) {
        e.preventDefault();
        rgPass.focus();
      }
    });
  }
})();