    </div>
  </main>
</div>
<script>
(function() {
  const toggleBtn = document.getElementById('mobileNavToggle');
  const drawer = document.getElementById('appSidebar');
  const backdrop = document.getElementById('mobileNavBackdrop');
  const closeBtn = document.getElementById('mobileDrawerClose');

  function openDrawer() {
    if (!drawer) return;
    drawer.classList.add('drawer-open');
    if (backdrop) backdrop.classList.add('active');
    if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
    document.body.classList.add('mobile-nav-locked');
  }

  function closeDrawer() {
    if (!drawer) return;
    drawer.classList.remove('drawer-open');
    if (backdrop) backdrop.classList.remove('active');
    if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('mobile-nav-locked');
  }

  if (toggleBtn) {
    toggleBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      if (drawer && drawer.classList.contains('drawer-open')) {
        closeDrawer();
      } else {
        openDrawer();
      }
    });
  }

  if (closeBtn) {
    closeBtn.addEventListener('click', closeDrawer);
  }

  if (backdrop) {
    backdrop.addEventListener('click', closeDrawer);
  }

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && drawer && drawer.classList.contains('drawer-open')) {
      closeDrawer();
    }
  });
})();
</script>
</body>
</html>

