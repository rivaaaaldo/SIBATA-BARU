/* =========================================================
 * app.js — Toast notification global (Vanilla JS)
 *
 * Pemakaian manual dari JS:
 *   showToast('Tersimpan', 'ok');
 *   showToast(['Error 1', 'Error 2'], 'err', { title: 'Periksa kembali:' });
 *
 * Pemakaian dari PHP: cetak <script type="application/json" id="toast-data">
 * berisi array [{type, msg, title?}] — otomatis ditampilkan saat halaman dimuat.
 * ========================================================= */
(function () {
  'use strict';

  const DURATION = 4000; // ms — toast hilang otomatis setelah 4 detik

  function getWrap() {
    let w = document.getElementById('toastWrap');
    if (!w) {
      w = document.createElement('div');
      w.id = 'toastWrap';
      w.className = 'toast-wrap';
      w.setAttribute('aria-live', 'polite');
      document.body.appendChild(w);
    }
    return w;
  }

  /**
   * @param {string|string[]} message  teks, atau array teks (ditampilkan sebagai daftar)
   * @param {'ok'|'err'|'info'} type
   * @param {{title?: string, duration?: number}} opts
   */
  function showToast(message, type, opts) {
    type = type || 'ok';
    opts = opts || {};
    const duration = opts.duration || DURATION;

    const el = document.createElement('div');
    el.className = 'toast toast-' + type;
    el.setAttribute('role', type === 'err' ? 'alert' : 'status');

    const icon = document.createElement('span');
    icon.className = 'toast-icon';
    icon.textContent = type === 'err' ? '!' : (type === 'info' ? 'i' : '✓');

    const body = document.createElement('div');
    body.className = 'toast-body';
    if (opts.title) {
      const t = document.createElement('strong');
      t.textContent = opts.title;
      body.appendChild(t);
    }
    const lines = Array.isArray(message) ? message : [message];
    if (lines.length > 1) {
      const ul = document.createElement('ul');
      lines.forEach(function (m) {
        const li = document.createElement('li');
        li.textContent = m;
        ul.appendChild(li);
      });
      body.appendChild(ul);
    } else {
      const p = document.createElement('p');
      p.textContent = lines[0];
      body.appendChild(p);
    }

    const x = document.createElement('button');
    x.type = 'button';
    x.className = 'toast-x';
    x.setAttribute('aria-label', 'Tutup notifikasi');
    x.innerHTML = '&times;';

    const bar = document.createElement('span');
    bar.className = 'toast-bar';
    bar.style.animationDuration = duration + 'ms';

    el.append(icon, body, x, bar);
    getWrap().appendChild(el);

    // Dua rAF supaya transisi slide-in terpicu setelah elemen masuk DOM
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { el.classList.add('show'); });
    });

    let timer;
    let remaining = duration;
    let startedAt = 0;

    function remove() { if (el.parentNode) el.remove(); }
    function hide() {
      clearTimeout(timer);
      el.classList.remove('show');
      el.addEventListener('transitionend', remove, { once: true });
      setTimeout(remove, 500); // cadangan jika transitionend tidak terpicu
    }
    function start() {
      startedAt = Date.now();
      timer = setTimeout(hide, remaining);
    }

    // Jeda saat kursor di atas toast supaya pesan sempat dibaca
    el.addEventListener('mouseenter', function () {
      clearTimeout(timer);
      remaining -= Date.now() - startedAt;
      el.classList.add('paused');
    });
    el.addEventListener('mouseleave', function () {
      el.classList.remove('paused');
      start();
    });
    x.addEventListener('click', hide);

    start();
    return el;
  }

  window.showToast = showToast;

  // Tampilkan pesan dari PHP (flash message / error validasi)
  document.addEventListener('DOMContentLoaded', function () {
    const src = document.getElementById('toast-data');
    if (!src) return;
    let list = [];
    try { list = JSON.parse(src.textContent) || []; } catch (e) { return; }
    list.forEach(function (t, i) {
      setTimeout(function () {
        showToast(t.msg, t.type || 'ok', { title: t.title });
      }, i * 200);
    });
    
  // ==========================================
  // SIBATA — INTERACTIVE COMPUTER LAB BACKGROUND
  // ==========================================

  document.addEventListener('DOMContentLoaded', function () {
    // Cegah background dibuat dua kali
    if (document.querySelector('.sibata-mouse-bg')) return;

    const bg = document.createElement('div');
    bg.className = 'sibata-mouse-bg';
    bg.setAttribute('aria-hidden', 'true');

    document.body.prepend(bg);

    // Nonaktifkan gerakan pada touchscreen
    const isTouch = window.matchMedia('(pointer: coarse)').matches;
    const reduceMotion = window.matchMedia(
      '(prefers-reduced-motion: reduce)'
    ).matches;

    if (isTouch || reduceMotion) return;

    let animationFrame;

    document.addEventListener('mousemove', function (e) {
      cancelAnimationFrame(animationFrame);

      animationFrame = requestAnimationFrame(function () {
        const centerX = window.innerWidth / 2;
        const centerY = window.innerHeight / 2;

        const moveX = ((e.clientX - centerX) / centerX) * 10;
        const moveY = ((e.clientY - centerY) / centerY) * 10;

        bg.style.setProperty('--move-x', `${moveX}px`);
        bg.style.setProperty('--move-y', `${moveY}px`);
        bg.style.setProperty('--mouse-x', `${e.clientX}px`);
        bg.style.setProperty('--mouse-y', `${e.clientY}px`);
      });
    });
  });

  });
})();