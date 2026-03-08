/**
 * Solana Agent Bot — Admin JS
 */

(function () {
  'use strict';

  // ─── Auto-refresh dashboard stats every 30s ─────────────────────────────────
  const isDashboard = document.querySelector('.stats-grid');
  if (isDashboard) {
    let countdown = 30;
    const badge = document.querySelector('.badge-live');

    setInterval(() => {
      countdown--;
      if (badge) badge.textContent = `● ${countdown}s`;
      if (countdown <= 0) {
        window.location.reload();
      }
    }, 1000);
  }

  // ─── Log scroll to bottom on load ────────────────────────────────────────────
  document.querySelectorAll('.log-scroll, .log-scroll-lg').forEach(el => {
    el.scrollTop = el.scrollHeight;
  });

  // ─── Fade-in alerts ──────────────────────────────────────────────────────────
  document.querySelectorAll('.alert').forEach(alert => {
    alert.style.opacity = '0';
    alert.style.transform = 'translateY(-8px)';
    alert.style.transition = 'all 0.3s ease';
    requestAnimationFrame(() => {
      alert.style.opacity = '1';
      alert.style.transform = 'translateY(0)';
    });

    // Auto-dismiss success alerts
    if (alert.classList.contains('alert-success')) {
      setTimeout(() => {
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 300);
      }, 4000);
    }
  });

  // ─── Copy to clipboard on mono click ─────────────────────────────────────────
  document.querySelectorAll('.mono, code').forEach(el => {
    el.style.cursor = 'pointer';
    el.title = 'Click to copy';
    el.addEventListener('click', () => {
      const text = el.textContent.trim();
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
          const orig = el.textContent;
          el.textContent = 'Copied!';
          setTimeout(() => { el.textContent = orig; }, 1200);
        });
      }
    });
  });

  // ─── Animate stat numbers ─────────────────────────────────────────────────────
  document.querySelectorAll('.stat-value').forEach(el => {
    const target = parseInt(el.textContent.replace(/[^0-9]/g, ''), 10);
    if (isNaN(target) || target === 0) return;

    let current = 0;
    const step  = Math.max(1, Math.floor(target / 30));
    const timer = setInterval(() => {
      current = Math.min(current + step, target);
      el.textContent = current.toLocaleString();
      if (current >= target) clearInterval(timer);
    }, 30);
  });

  // ─── Setup: show/hide secret fields ──────────────────────────────────────────
  document.querySelectorAll('input[type="password"]').forEach(input => {
    const wrapper = input.parentElement;
    const toggle  = document.createElement('button');
    toggle.type   = 'button';
    toggle.textContent = '👁';
    toggle.style.cssText = 'position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:16px;';
    wrapper.style.position = 'relative';
    wrapper.appendChild(toggle);

    toggle.addEventListener('click', () => {
      input.type = input.type === 'password' ? 'text' : 'password';
    });
  });

})();
