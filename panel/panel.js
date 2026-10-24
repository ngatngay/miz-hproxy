(() => {
  'use strict';

  document.documentElement.dataset.js = '1';

  /* Mobile navigation */
  const topbar = document.querySelector('.topbar');
  const toggle = document.querySelector('.menu-toggle');

  if (topbar && toggle) {
    const setOpen = (open) => {
      topbar.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Đóng menu' : 'Mở menu');
    };

    toggle.addEventListener('click', () => {
      setOpen(!topbar.classList.contains('is-open'));
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && topbar.classList.contains('is-open')) {
        setOpen(false);
        toggle.focus();
      }
    });
  }

  /* Dismissible flash messages */
  document.querySelectorAll('.alert [data-dismiss="alert"]').forEach((button) => {
    button.addEventListener('click', () => {
      const alert = button.closest('.alert');

      if (alert) {
        alert.remove();
      }
    });
  });

  /* Confirmation prompt for destructive forms */
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.confirm)) {
        event.preventDefault();
      }
    });
  });

  /* Live queue log */
  const log = document.getElementById('queue-log');
  const status = document.getElementById('queue-status');

  if (log) {
    const load = async () => {
      try {
        const response = await fetch('task.php', { method: 'POST' });

        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }

        log.textContent = (await response.text()) || 'Chưa có log.';
        log.scrollTop = log.scrollHeight;

        if (status) {
          status.textContent = 'Cập nhật: ' + new Date().toLocaleTimeString('vi-VN');
        }
      } catch (error) {
        if (status) {
          status.textContent = 'Lỗi: ' + error.message;
        }
      }
    };

    load();
    window.setInterval(load, 5000);
  }
})();
