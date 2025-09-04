
</div>
<script>
(function () {
  const body = document.body;

  // =============== Theme toggle ===============

  const toggleBtn = document.getElementById("themeToggle");
  const icon = toggleBtn.querySelector("i");

  const THEME_KEY = "app-theme";

  // Function to update icon based on theme
  function updateIcon(theme) {
    if (theme === "dark") {
      icon.className = "fas fa-moon";  // قمر
      toggleBtn.classList.remove("btn-outline-light");
      toggleBtn.classList.add("btn-outline-warning");
    } else {
      icon.className = "fas fa-sun";   // شمس
      toggleBtn.classList.remove("btn-outline-warning");
      toggleBtn.classList.add("btn-outline-light");
    }
  }

  // Load saved theme
  const saved = localStorage.getItem(THEME_KEY) || "light";
  body.setAttribute("data-theme", saved);
  updateIcon(saved);

  // Toggle theme on click
  toggleBtn.addEventListener("click", () => {
    const current = body.getAttribute("data-theme") === "dark" ? "dark" : "light";
    const next = current === "dark" ? "light" : "dark";
    body.setAttribute("data-theme", next);
    localStorage.setItem(THEME_KEY, next);
    updateIcon(next);
  });


  // =============== Sidebar state ===============
const SIDEBAR_KEY = "sidebar-state"; // values: "open" | "hidden"
  const savedSidebar = localStorage.getItem(SIDEBAR_KEY);
  if (savedSidebar) body.setAttribute("data-sidebar", savedSidebar);

  const sidebarToggle = document.getElementById("sidebarToggle");

  const setSidebar = (state) => {
    if (!state) {
      body.removeAttribute("data-sidebar");
      localStorage.removeItem(SIDEBAR_KEY);
      return;
    }
    body.setAttribute("data-sidebar", state);
    localStorage.setItem(SIDEBAR_KEY, state);
  };

  if (sidebarToggle) {
    sidebarToggle.addEventListener("click", () => {
      const cur = body.getAttribute("data-sidebar");
      setSidebar(cur === "open" ? "hidden" : "open");
    });
  }

  // إغلاق تلقائي عند الضغط على لينك (موبايل فقط)
  document.addEventListener("click", (e) => {
    if (window.matchMedia("(max-width: 992px)").matches && e.target.closest(".sidebar .nav-link")) {
      setSidebar("hidden");
    }
  });
})();
</script>

     <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>