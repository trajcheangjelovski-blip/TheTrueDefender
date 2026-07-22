// ═══════════════════════════════════════════════════
// THE DAILY PULSE — main.js
// Renders category sections from data.js and wires up
// 3D tilt, scroll reveal, nav, search, and ticker.
// ═══════════════════════════════════════════════════

// ── Layout templates (each category gets its own look) ──

function initials(name) {
  return name.replace('Dr. ', '').split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();
}

const LAYOUTS = {

  // One big overlay story + compact side list
  feature: (cat, articles) => {
    const [main, ...rest] = articles;
    return `
      <div class="feat-grid">
        <article class="story-card tilt-card feat-main" data-tilt>
          <div class="story-bg img-ph ${cat.ph}"><span class="ph-icon">${main.icon}</span></div>
          <div class="story-scrim"></div>
          <div class="card-glare"></div>
          <div class="story-content">
            <span class="badge ${cat.badge}">${cat.name.toUpperCase()}</span>
            <h3 class="feat-title">${main.title}</h3>
            <p>${main.excerpt}</p>
            <span class="story-author">By ${main.author} · ${main.time}</span>
          </div>
        </article>
        <div class="feat-list">
          ${rest.map(a => `
            <article class="feat-item tilt-card" data-tilt>
              <div class="card-glare"></div>
              <div class="feat-thumb img-ph ${cat.ph}"><span class="ph-icon">${a.icon}</span></div>
              <div class="feat-body">
                <h3>${a.title}</h3>
                <span class="meta-time">By ${a.author} · ${a.time}</span>
              </div>
            </article>
          `).join('')}
        </div>
      </div>`;
  },

  // Hero-style overlay tiles
  overlay: (cat, articles) => `
    <div class="overlay-grid">
      ${articles.map(a => `
        <article class="story-card tilt-card ov-card" data-tilt>
          <div class="story-bg img-ph ${cat.ph}"><span class="ph-icon">${a.icon}</span></div>
          <div class="story-scrim"></div>
          <div class="card-glare"></div>
          <div class="story-content">
            <span class="badge ${cat.badge}">${cat.name.toUpperCase()}</span>
            <h3>${a.title}</h3>
            <span class="meta-time">By ${a.author} · ${a.time}</span>
          </div>
        </article>
      `).join('')}
    </div>`,

  // Horizontal rows, thumbnail left
  rows: (cat, articles) => `
    <div class="rows-list">
      ${articles.map(a => `
        <article class="row-card tilt-card" data-tilt>
          <div class="card-glare"></div>
          <div class="row-img img-ph ${cat.ph}"><span class="ph-icon">${a.icon}</span></div>
          <div class="row-body">
            <span class="badge ${cat.badge}">${cat.name.toUpperCase()}</span>
            <h3>${a.title}</h3>
            <p>${a.excerpt}</p>
            <div class="news-foot"><span>By ${a.author}</span><span>${a.time}</span></div>
          </div>
        </article>
      `).join('')}
    </div>`,

  // Clean text cards with colored top bar
  briefs: (cat, articles) => `
    <div class="briefs-grid">
      ${articles.map(a => `
        <article class="brief-card tilt-card" data-tilt>
          <span class="brief-bar" style="background:${cat.color}"></span>
          <div class="card-glare"></div>
          <span class="brief-icon">${a.icon}</span>
          <h3>${a.title}</h3>
          <p>${a.excerpt}</p>
          <div class="news-foot"><span>By ${a.author}</span><span>${a.time}</span></div>
        </article>
      `).join('')}
    </div>`,

  // Author-first opinion cards with big quote mark
  quotes: (cat, articles) => `
    <div class="quote-grid">
      ${articles.map(a => `
        <article class="quote-card tilt-card" data-tilt>
          <div class="card-glare"></div>
          <span class="quote-mark" style="color:${cat.color}">“</span>
          <h3>${a.title}</h3>
          <p>${a.excerpt}</p>
          <div class="quote-author">
            <span class="avatar" style="background:linear-gradient(135deg, ${cat.color}, #1a1030)">${initials(a.author)}</span>
            <div><strong>${a.author}</strong><span>${cat.name} · ${a.time}</span></div>
          </div>
        </article>
      `).join('')}
    </div>`,
};

// ── Render category sections ──
function renderCategories() {
  const container = document.getElementById('categorySections');
  if (!container) return;

  CATEGORIES.forEach(cat => {
    const articles = ARTICLES[cat.id] || [];
    if (!articles.length) return;

    const section = document.createElement('section');
    section.className = 'section reveal';
    section.id = cat.id;

    const layout = LAYOUTS[cat.layout] || LAYOUTS.rows;
    section.innerHTML = `
      <div class="section-head">
        <h2>
          <span class="head-icon" style="background:${cat.color}1f; border-color:${cat.color}55">${cat.icon}</span>
          ${cat.name}
        </h2>
        <div class="head-line" style="background:linear-gradient(90deg, ${cat.color}66, transparent)"></div>
        <a href="#${cat.id}" class="head-link" style="color:${cat.color}">View all →</a>
      </div>
      ${layout(cat, articles)}
    `;
    container.appendChild(section);
  });
}

// ── Render shop products ──
function renderShop() {
  const grid = document.getElementById('shopGrid');
  if (!grid || typeof PRODUCTS === 'undefined') return;

  grid.innerHTML = PRODUCTS.map(p => `
    <article class="product-card tilt-card" data-tilt>
      <div class="card-glare"></div>
      ${p.tag ? `<span class="prod-tag">${p.tag}</span>` : ''}
      <div class="prod-img"><span class="prod-icon">${p.icon}</span></div>
      <div class="prod-body">
        <h3>${p.name}</h3>
        <div class="prod-foot">
          <span class="prod-price">$${p.price.toFixed(2)}</span>
          <button class="btn-cart" type="button">Add to Cart</button>
        </div>
      </div>
    </article>
  `).join('');

  // Frontend-only stub: backend will handle a real cart later
  grid.querySelectorAll('.btn-cart').forEach(btn => {
    btn.addEventListener('click', () => {
      btn.textContent = '✓ Added';
      btn.classList.add('added');
      setTimeout(() => { btn.textContent = 'Add to Cart'; btn.classList.remove('added'); }, 2000);
    });
  });
}

// ── 3D tilt effect ──
function initTilt() {
  const maxTilt = 7; // degrees
  const cards = document.querySelectorAll('[data-tilt]');

  cards.forEach(card => {
    card.addEventListener('mousemove', e => {
      const rect = card.getBoundingClientRect();
      const px = (e.clientX - rect.left) / rect.width;
      const py = (e.clientY - rect.top) / rect.height;

      const rx = (0.5 - py) * maxTilt;
      const ry = (px - 0.5) * maxTilt;

      card.style.transform = `perspective(900px) rotateX(${rx}deg) rotateY(${ry}deg) translateZ(6px)`;
      card.style.setProperty('--mx', `${px * 100}%`);
      card.style.setProperty('--my', `${py * 100}%`);
    });

    card.addEventListener('mouseleave', () => {
      card.style.transform = 'perspective(900px) rotateX(0deg) rotateY(0deg) translateZ(0)';
    });
  });
}

// ── Scroll reveal ──
function initReveal() {
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.08 });

  document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
}

// ── Nav: scrolled shadow + active link highlight ──
function initNav() {
  const navWrap = document.getElementById('navWrap');
  if (!navWrap) return;
  const links = document.querySelectorAll('.nav-links a');

  window.addEventListener('scroll', () => {
    navWrap.classList.toggle('scrolled', window.scrollY > 10);
  }, { passive: true });

  links.forEach(link => {
    link.addEventListener('click', () => {
      links.forEach(l => l.classList.remove('active'));
      link.classList.add('active');
      document.getElementById('navLinks').classList.remove('open');
      document.getElementById('hamburger').classList.remove('open');
    });
  });

  // Mobile hamburger
  const hamburger = document.getElementById('hamburger');
  hamburger.addEventListener('click', () => {
    hamburger.classList.toggle('open');
    document.getElementById('navLinks').classList.toggle('open');
  });
}

// ── Search overlay ──
function initSearch() {
  const overlay = document.getElementById('searchOverlay');
  const input = document.getElementById('searchInput');
  if (!overlay || !input) return;

  document.getElementById('searchBtn').addEventListener('click', () => {
    overlay.classList.toggle('open');
    if (overlay.classList.contains('open')) input.focus();
  });
  document.getElementById('searchClose').addEventListener('click', () => {
    overlay.classList.remove('open');
  });

  // Frontend-only: filter visible articles/products by title as you type
  input.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    document.querySelectorAll('#categorySections article, #shopGrid article').forEach(card => {
      const title = card.querySelector('h3');
      if (!title) return;
      card.style.display = !q || title.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
}

// ── Ticker: duplicate content for seamless loop ──
function initTicker() {
  const track = document.getElementById('tickerContent');
  if (track) track.innerHTML += track.innerHTML;
}

// ── Newsletter (frontend-only stub) ──
function initNewsletter() {
  const form = document.getElementById('nlForm');
  if (!form) return;
  form.addEventListener('submit', e => {
    e.preventDefault();
    const btn = form.querySelector('button');
    btn.textContent = '✓ Subscribed!';
    btn.style.background = 'linear-gradient(135deg, #10b981, #047857)';
    form.querySelector('input').value = '';
    setTimeout(() => {
      btn.textContent = 'Sign Up Free';
      btn.style.background = '';
    }, 3000);
  });
}

// ── Animated background: drifting particle field ──
function initBgFx() {
  const canvas = document.getElementById('bgCanvas');
  if (!canvas) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  // Skip the per-frame particle animation on small screens — the CSS aura orbs
  // stay for ambiance, and mobile avoids the main-thread cost/battery drain.
  if (window.innerWidth < 768) return;

  const ctx = canvas.getContext('2d');
  const DPR = Math.min(window.devicePixelRatio || 1, 1.5);
  const LINK_DIST = 120;
  let w, h, particles;

  function build() {
    w = window.innerWidth;
    h = window.innerHeight;
    canvas.width = w * DPR;
    canvas.height = h * DPR;
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);

    // Density scales with viewport, hard-capped for performance
    const count = Math.min(75, Math.floor((w * h) / 24000));
    particles = Array.from({ length: count }, () => ({
      x: Math.random() * w,
      y: Math.random() * h,
      r: Math.random() * 1.7 + 0.6,
      vx: (Math.random() - 0.5) * 0.16,
      vy: -(Math.random() * 0.22 + 0.06),
      base: Math.random() * 0.45 + 0.15,
      twinkle: Math.random() * Math.PI * 2,
      red: Math.random() < 0.28,
    }));
  }

  build();
  window.addEventListener('resize', build);

  function tick() {
    ctx.clearRect(0, 0, w, h);

    // Faint constellation links
    for (let i = 0; i < particles.length; i++) {
      for (let j = i + 1; j < particles.length; j++) {
        const a = particles[i], b = particles[j];
        const dx = a.x - b.x, dy = a.y - b.y;
        const d2 = dx * dx + dy * dy;
        if (d2 < LINK_DIST * LINK_DIST) {
          const o = (1 - Math.sqrt(d2) / LINK_DIST) * 0.09;
          ctx.strokeStyle = `rgba(160, 170, 220, ${o})`;
          ctx.lineWidth = 1;
          ctx.beginPath();
          ctx.moveTo(a.x, a.y);
          ctx.lineTo(b.x, b.y);
          ctx.stroke();
        }
      }
    }

    // Glowing dots, drifting upward with a soft twinkle
    for (const p of particles) {
      p.x += p.vx;
      p.y += p.vy;
      p.twinkle += 0.014;
      if (p.y < -12) { p.y = h + 12; p.x = Math.random() * w; }
      if (p.x < -12) p.x = w + 12;
      else if (p.x > w + 12) p.x = -12;

      const alpha = p.base * (0.55 + 0.45 * Math.sin(p.twinkle));
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
      ctx.fillStyle = p.red
        ? `rgba(227, 59, 78, ${alpha})`
        : `rgba(212, 220, 255, ${alpha * 0.85})`;
      ctx.fill();
    }

    requestAnimationFrame(tick);
  }

  requestAnimationFrame(tick);
}

// ── Hero post slider ──
function initSlider() {
  const slider = document.getElementById('heroSlider');
  if (!slider) return;

  const track = document.getElementById('sliderTrack');
  const slides = Array.from(track.children);
  const dotsWrap = document.getElementById('sliderDots');
  const counter = document.getElementById('sliderCount');
  const total = slides.length;
  const AUTOPLAY_MS = 6000;
  let idx = 0;
  let timer = null;

  slides.forEach((_, i) => {
    const dot = document.createElement('button');
    dot.className = 'dot' + (i === 0 ? ' active' : '');
    dot.setAttribute('aria-label', `Go to story ${i + 1}`);
    dot.innerHTML = '<i></i>';
    dot.addEventListener('click', () => { goTo(i); restart(); });
    dotsWrap.appendChild(dot);
  });
  const dots = Array.from(dotsWrap.children);

  function goTo(i) {
    idx = (i + total) % total;
    track.style.transform = `translateX(-${idx * 100}%)`;
    slides.forEach((s, j) => s.classList.toggle('active', j === idx));
    dots.forEach((d, j) => d.classList.toggle('active', j === idx));
    counter.textContent = `${String(idx + 1).padStart(2, '0')} / ${String(total).padStart(2, '0')}`;
  }

  const next = () => goTo(idx + 1);
  const prev = () => goTo(idx - 1);

  function restart() {
    clearInterval(timer);
    timer = setInterval(next, AUTOPLAY_MS);
  }

  document.getElementById('sliderNext').addEventListener('click', () => { next(); restart(); });
  document.getElementById('sliderPrev').addEventListener('click', () => { prev(); restart(); });

  // Pause autoplay while hovering (progress bar pauses via CSS)
  slider.addEventListener('mouseenter', () => clearInterval(timer));
  slider.addEventListener('mouseleave', restart);

  // Touch swipe
  let touchX = null;
  slider.addEventListener('touchstart', e => { touchX = e.touches[0].clientX; }, { passive: true });
  slider.addEventListener('touchend', e => {
    if (touchX === null) return;
    const dx = e.changedTouches[0].clientX - touchX;
    if (Math.abs(dx) > 45) { dx < 0 ? next() : prev(); restart(); }
    touchX = null;
  }, { passive: true });

  // Keyboard arrows when slider is focused/hovered
  slider.tabIndex = 0;
  slider.addEventListener('keydown', e => {
    if (e.key === 'ArrowRight') { next(); restart(); }
    if (e.key === 'ArrowLeft') { prev(); restart(); }
  });

  goTo(0);

  // Delay autoplay until after load (+ a buffer). An auto-advancing carousel
  // paints later slides' images during the LCP window and inflates LCP on slow
  // connections — keeping only slide 1 visible until then fixes that.
  const startAutoplay = () => setTimeout(restart, 3000);
  if (document.readyState === 'complete') startAutoplay();
  else window.addEventListener('load', startAutoplay, { once: true });
}

// ── Article page: reading progress + copy link ──
function initArticle() {
  const bar = document.getElementById('readProgress');
  if (bar) {
    const onScroll = () => {
      const h = document.documentElement;
      const max = h.scrollHeight - h.clientHeight;
      bar.style.width = (max > 0 ? (h.scrollTop / max) * 100 : 0) + '%';
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  document.querySelectorAll('[data-copy]').forEach(btn => {
    btn.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(btn.dataset.copy);
        const original = btn.innerHTML;
        btn.classList.add('copied');
        btn.innerHTML = '✓';
        setTimeout(() => { btn.innerHTML = original; btn.classList.remove('copied'); }, 1600);
      } catch (e) { /* clipboard unavailable */ }
    });
  });
}

// ── Hero date ──
function initHeroDate() {
  const el = document.getElementById('heroDate');
  if (!el) return;
  const TZ = 'America/New_York'; // official site time: Washington, D.C. (US Eastern)
  const render = () => {
    const now = new Date();
    const date = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', timeZone: TZ });
    const time = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone: TZ });
    el.textContent = `${date} · ${time} ET`;
  };
  render();
  setInterval(render, 30000);
}

// ── Mini-cart drawer: slides in after add-to-cart with contents + checkout CTA ──
function initCartDrawer() {
  const drawer = document.getElementById('cartDrawer');
  const overlay = document.getElementById('cartDrawerOverlay');
  if (!drawer || !overlay) return null;

  const close = () => {
    drawer.classList.remove('show');
    overlay.classList.remove('show');
    drawer.setAttribute('aria-hidden', 'true');
  };
  overlay.addEventListener('click', close);
  drawer.querySelectorAll('[data-drawer-close]').forEach(b => b.addEventListener('click', close));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

  return function open(cart, addedName) {
    document.getElementById('cartDrawerAdded').textContent = addedName ? '✓ ' + addedName + ' added to your cart' : '';
    document.getElementById('cartDrawerCount').textContent = '(' + cart.count + ' item' + (cart.count === 1 ? '' : 's') + ')';
    document.getElementById('cartDrawerSubtotal').textContent = '$' + cart.subtotal;
    document.getElementById('cartDrawerShipping').textContent = cart.shipping ? '$' + cart.shipping : 'Free';
    document.getElementById('cartDrawerTotal').textContent = '$' + cart.total;

    const list = document.getElementById('cartDrawerLines');
    list.innerHTML = '';
    cart.lines.forEach(l => {
      const row = document.createElement('div');
      row.className = 'cart-drawer-line';
      row.innerHTML =
        '<div class="cart-drawer-thumb"></div>' +
        '<div class="cart-drawer-line-info"><a></a><span></span></div>' +
        '<div class="cart-drawer-line-price"></div>';
      const thumb = row.querySelector('.cart-drawer-thumb');
      if (l.image) {
        const img = document.createElement('img');
        img.src = l.image; img.alt = '';
        thumb.appendChild(img);
      } else {
        thumb.textContent = l.icon || '🛍️';
      }
      const link = row.querySelector('a');
      link.href = l.url;
      link.textContent = l.name;
      row.querySelector('.cart-drawer-line-info span').textContent =
        (l.variant ? l.variant + ' · ' : '') + 'Qty ' + l.quantity;
      const price = row.querySelector('.cart-drawer-line-price');
      if (l.is_free && l.line_total === '0.00') {
        price.innerHTML = '<span class="free">FREE</span>';
      } else {
        price.textContent = '$' + l.line_total;
      }
      list.appendChild(row);
    });

    overlay.classList.add('show');
    drawer.classList.add('show');
    drawer.setAttribute('aria-hidden', 'false');
  };
}

// ── Cart buttons: AJAX add-to-cart → nav badge + mini-cart drawer ──
function wireCart() {
  const token = document.querySelector('meta[name="csrf-token"]')?.content;
  const openDrawer = initCartDrawer();

  async function addToCart(id, qty, variantId) {
    const res = await fetch('/cart/add', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token,
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ product_id: id, quantity: qty || 1, variant_id: variantId || null }),
    });
    if (!res.ok) throw new Error('add failed');
    const data = await res.json();

    // Update nav badge
    const badge = document.getElementById('cartCount');
    if (badge) {
      badge.textContent = data.count;
      badge.classList.remove('hidden');
      badge.classList.add('bump');
      setTimeout(() => badge.classList.remove('bump'), 300);
    }

    if (openDrawer && data.cart) openDrawer(data.cart, data.added);
    return data;
  }

  // Product cards (shop grid, homepage)
  document.querySelectorAll('.btn-cart[data-product-id]').forEach(btn => {
    // Skip buttons that are inside a real <form> (product detail page — wired below)
    if (btn.closest('form')) return;

    btn.addEventListener('click', async () => {
      const original = btn.textContent;
      try {
        await addToCart(btn.dataset.productId, 1);
        btn.textContent = '✓ Added';
        btn.classList.add('added');
      } catch (e) {
        btn.textContent = 'Try again';
      }
      setTimeout(() => { btn.textContent = original; btn.classList.remove('added'); }, 1800);
    });
  });

  // Product detail page form (falls back to a normal POST if JS is unavailable)
  const buyForm = document.querySelector('form.pd-buy');
  if (buyForm) {
    buyForm.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = buyForm.querySelector('.btn-cart');
      const original = btn ? btn.textContent : '';
      try {
        await addToCart(
          buyForm.querySelector('[name="product_id"]').value,
          parseInt(buyForm.querySelector('[name="quantity"]')?.value || '1', 10) || 1,
          buyForm.querySelector('[name="variant_id"]')?.value || null,
        );
        if (btn) { btn.textContent = '✓ Added'; btn.classList.add('added'); }
      } catch (err) {
        if (btn) btn.textContent = 'Try again';
      }
      if (btn) setTimeout(() => { btn.textContent = original; btn.classList.remove('added'); }, 1800);
    });
  }
}

// ── Product variant selector (Color / Size / Style → price, image, add button) ──
function initProductVariants() {
  const form = document.querySelector('form.pd-buy[data-variants]');
  if (!form) return;

  let variants = [];
  try { variants = JSON.parse(form.dataset.variants); } catch (e) { return; }
  const axisField = { 'Color': 'color', 'Size': 'size', 'Style': 'style' };

  const hidden = form.querySelector('#pdVariantId');
  const addBtn = form.querySelector('.pd-add');
  const note = document.getElementById('pdVariantNote');
  const priceEl = document.getElementById('pdPrice');
  const mainImg = document.getElementById('pdMainImage');
  const shipStr = priceEl ? (' <span style="display:block;font-size:.45em;color:var(--text-dim);font-weight:600;margin-top:4px">+ $' + priceEl.dataset.ship + ' shipping</span>') : '';
  const selected = {};

  const usedAxes = [...form.querySelectorAll('.pd-option')].map(o => o.dataset.axis);

  function money(n) { return '$' + Number(n).toFixed(2); }

  function findVariant() {
    return variants.find(v => usedAxes.every(axis => v[axisField[axis]] === selected[axis]));
  }

  function refresh() {
    const allChosen = usedAxes.every(a => selected[a] != null);
    const v = allChosen ? findVariant() : null;

    if (v && v.stock !== 0) {
      hidden.value = v.id;
      addBtn.disabled = false;
      note.textContent = '✓ ' + usedAxes.map(a => selected[a]).join(' / ') + ' — in stock';
      note.style.color = '#10b981';
      if (priceEl) {
        const ship = parseFloat(priceEl.dataset.ship) > 0 ? shipStr : '';
        const free = Number(v.price) === 0;
        priceEl.innerHTML = free
          ? '<span style="color:#10b981">FREE</span><span style="display:block;font-size:.45em;color:var(--text-dim);font-weight:600;margin-top:4px">Just pay $' + priceEl.dataset.ship + ' shipping &amp; handling</span>'
          : (v.on_sale ? '<span class="old">' + money(v.regular) + '</span> ' : '') + money(v.price) + ship;
      }
      if (mainImg && v.image) mainImg.src = v.image;
    } else {
      hidden.value = '';
      addBtn.disabled = true;
      if (v && v.stock === 0) { note.textContent = 'That option is out of stock.'; note.style.color = '#f59e0b'; }
      else if (allChosen) { note.textContent = 'That combination isn\'t available.'; note.style.color = '#f59e0b'; }
      else { note.textContent = 'Choose your options above.'; note.style.color = ''; }
    }
  }

  form.querySelectorAll('.pd-swatch').forEach(sw => {
    sw.addEventListener('click', () => {
      const axis = sw.dataset.axis;
      form.querySelectorAll('.pd-swatch[data-axis="' + CSS.escape(axis) + '"]').forEach(s => s.classList.remove('active'));
      sw.classList.add('active');
      selected[axis] = sw.dataset.value;
      refresh();
    });
  });

  refresh();
}

// ── Product photo click-to-zoom lightbox ──
function initProductZoom() {
  const main = document.getElementById('pdMainImage');
  const box = document.getElementById('imgLightbox');
  if (!main || !box) return;
  const img = document.getElementById('imgLightboxImg');

  const open = () => {
    img.src = main.src; // zoom whatever photo is currently shown (variant-aware)
    img.classList.remove('zoomed');
    box.classList.add('show');
    box.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  };
  const close = () => {
    box.classList.remove('show');
    box.setAttribute('aria-hidden', 'true');
    img.classList.remove('zoomed');
    document.body.style.overflow = '';
  };

  main.style.cursor = 'zoom-in';
  main.addEventListener('click', open);
  document.querySelector('.pd-zoom-hint')?.addEventListener('click', e => { e.stopPropagation(); open(); });

  box.addEventListener('click', e => {
    if (e.target === box || e.target.hasAttribute('data-lb-close')) close();
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && box.classList.contains('show')) close(); });

  // Click the enlarged photo to toggle a 2x magnify; move the mouse to pan.
  img.addEventListener('click', e => { e.stopPropagation(); img.classList.toggle('zoomed'); });
  img.addEventListener('mousemove', e => {
    if (!img.classList.contains('zoomed')) return;
    const r = img.getBoundingClientRect();
    img.style.transformOrigin = ((e.clientX - r.left) / r.width * 100) + '% ' + ((e.clientY - r.top) / r.height * 100) + '%';
  });
}

document.addEventListener('DOMContentLoaded', () => {
  // Content (slider, category sections, shop) is now server-rendered by Laravel.
  initBgFx();
  initSlider();
  initHeroDate();
  initArticle();
  initTilt();
  initReveal();
  initNav();
  initSearch();
  initTicker();
  wireCart();
  initProductVariants();
  initProductZoom();
  // Newsletter subscribe is handled by audience.js (real backend).
});
