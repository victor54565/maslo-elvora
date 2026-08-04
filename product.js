/* ═══════════════════════════════════════════════════════════
   product.js — страница отдельного масла (product.html?id=…)

   Данные берутся из двух источников:
     • каталог  — api/catalog.php, резерв data/catalog.json
       (название, группа, цены, наличие — то, что правит админка);
     • описания — data/product-info.json
       (состав, применение, хранение — статичный текст).

   Корзина живёт в памяти главной страницы, поэтому «В корзину»
   отсюда кладёт позицию в sessionStorage и уводит на index.html
   — там main.js её подхватывает (см. initPendingCart).
═══════════════════════════════════════════════════════════ */

(function initProductPage() {
  const VOLUMES = ['100', '250', '500'];
  const PENDING_KEY = 'elvora:pending-cart';

  const els = {
    state:       document.getElementById('pdp-state'),
    main:        document.getElementById('pdp-main'),
    details:     document.getElementById('pdp-details'),
    crumb:       document.getElementById('pdp-crumb'),
    group:       document.getElementById('pdp-group'),
    title:       document.getElementById('pdp-title'),
    lead:        document.getElementById('pdp-lead'),
    volRow:      document.getElementById('pdp-vol-row'),
    total:       document.getElementById('pdp-total'),
    buy:         document.getElementById('pdp-buy'),
    composition: document.getElementById('pdp-composition'),
    usage:       document.getElementById('pdp-usage'),
    storage:     document.getElementById('pdp-storage'),
    related:     document.getElementById('pdp-related'),
    relatedRow:  document.getElementById('pdp-related-row'),
    bottles: {
      100: document.getElementById('pdp-img-100'),
      250: document.getElementById('pdp-img-250'),
      500: document.getElementById('pdp-img-500')
    }
  };
  if (!els.main) return;

  const productId = new URLSearchParams(window.location.search).get('id') || '';

  const fmtPrice = n => n.toLocaleString('ru-RU');

  function fail(msg) {
    if (els.state) {
      els.state.hidden = false;
      els.state.textContent = msg;
    }
  }

  /* --- загрузка каталога: сначала API, потом статический файл --- */
  function loadCatalog() {
    return fetch('api/catalog.php', { headers: { Accept: 'application/json' } })
      .then(r => { if (!r.ok) throw new Error('api'); return r.json(); })
      .then(d => {
        if (!d || !d.ok || !Array.isArray(d.products)) throw new Error('bad_data');
        return d.products;
      })
      .catch(() => fetch('data/catalog.json', { headers: { Accept: 'application/json' } })
        .then(r => r.json())
        .then(d => (d && Array.isArray(d.products))
          ? d.products.filter(p => p.visible !== false)
          : [])
      );
  }

  function loadInfo() {
    return fetch('data/product-info.json', { headers: { Accept: 'application/json' } })
      .then(r => r.json())
      .then(d => (d && d.items) ? d.items : {})
      .catch(() => ({}));
  }

  /* --- отрисовка --- */
  function render(product, info, allProducts) {
    document.title = product.name + ' — ELVORA';
    els.crumb.textContent = product.name;
    els.title.textContent = product.name;
    if (product.group) els.group.textContent = product.group;

    // Бутылки: 500 мл сразу, остальные подтягиваются при первом показе.
    VOLUMES.forEach(vol => {
      const img = els.bottles[vol];
      if (!img) return;
      img.alt = product.name + ', ' + vol + ' мл';
      if (vol === '500') {
        img.src = 'masla/' + product.id + '-500.png';
      } else {
        img.dataset.src = 'masla/' + product.id + '-' + vol + '.png';
      }
    });

    // Тексты описания. Если для товара ещё нет карточки в product-info.json,
    // прячем соответствующие блоки, а не показываем пустые заголовки.
    if (info) {
      els.lead.textContent = info.lead || '';
      els.usage.textContent = info.usage || '';
      els.storage.textContent = info.storage || '';
      els.composition.innerHTML = '';
      (info.composition || []).forEach(line => {
        const li = document.createElement('li');
        li.textContent = line;
        els.composition.appendChild(li);
      });
      els.details.hidden = false;
      els.details.querySelectorAll('.pdp__panel').forEach(panel => {
        const filled = panel.querySelector('li') ||
                       (panel.querySelector('.pdp__panel-text') || {}).textContent;
        if (!filled) panel.hidden = true;
      });
    }

    buildVolumes(product);
    buildRelated(product, allProducts);

    els.state.hidden = true;
    els.main.hidden = false;
  }

  /* --- переключатель объёма + цена --- */
  function buildVolumes(product) {
    els.volRow.innerHTML = '';
    const soldOut = product.available === false;

    VOLUMES.forEach(vol => {
      const price = parseInt(product['price' + vol], 10) || 0;

      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pdp__vol' + (vol === '500' ? ' is-active' : '');
      btn.dataset.vol = vol;
      btn.dataset.price = String(price);
      btn.disabled = soldOut;

      const size = document.createElement('span');
      size.className = 'pdp__vol-size';
      size.textContent = vol + ' мл';

      const cost = document.createElement('span');
      cost.className = 'pdp__vol-price';
      cost.textContent = price > 0 ? fmtPrice(price) + ' ₸' : 'по запросу';

      btn.append(size, cost);
      btn.addEventListener('click', () => select(vol));
      els.volRow.appendChild(btn);
    });

    if (soldOut) {
      els.buy.disabled = true;
      els.buy.textContent = 'Нет в наличии';
    }

    select('500');
  }

  function select(vol) {
    els.volRow.querySelectorAll('.pdp__vol').forEach(b => {
      b.classList.toggle('is-active', b.dataset.vol === vol);
    });

    Object.entries(els.bottles).forEach(([v, img]) => {
      if (!img) return;
      const active = v === vol;
      if (active && !img.getAttribute('src') && img.dataset.src) {
        img.src = img.dataset.src;
      }
      img.classList.toggle('is-active', active);
    });

    const btn = els.volRow.querySelector('.pdp__vol[data-vol="' + vol + '"]');
    const price = btn ? parseInt(btn.dataset.price, 10) || 0 : 0;
    els.total.textContent = price > 0 ? fmtPrice(price) + ' ₸' : 'Цена по запросу';
  }

  /* --- «В корзину»: передаём позицию на главную через sessionStorage --- */
  function bindBuy(product) {
    els.buy.addEventListener('click', () => {
      const active = els.volRow.querySelector('.pdp__vol.is-active');
      if (!active) return;

      const item = {
        name: product.name,
        volume: parseInt(active.dataset.vol, 10),
        price: parseInt(active.dataset.price, 10) || 0
      };

      let queue = [];
      try {
        queue = JSON.parse(sessionStorage.getItem(PENDING_KEY) || '[]');
        if (!Array.isArray(queue)) queue = [];
      } catch (e) { queue = []; }
      queue.push(item);
      try { sessionStorage.setItem(PENDING_KEY, JSON.stringify(queue)); } catch (e) {}

      window.location.href = 'index.html#checkout';
    });
  }

  /* --- другие масла той же категории --- */
  function buildRelated(product, allProducts) {
    if (!els.relatedRow || !product.group) return;

    const siblings = allProducts
      .filter(p => p.group === product.group && p.id !== product.id)
      .slice(0, 4);
    if (!siblings.length) return;

    siblings.forEach(p => {
      const a = document.createElement('a');
      a.className = 'pdp__related-card';
      a.href = 'product.html?id=' + encodeURIComponent(p.id);

      const img = document.createElement('img');
      img.src = 'masla/' + p.id + '-500.png';
      img.alt = p.name;
      img.loading = 'lazy';

      const name = document.createElement('span');
      name.className = 'pdp__related-name';
      name.textContent = p.name;

      a.append(img, name);
      els.relatedRow.appendChild(a);
    });

    els.related.hidden = false;
  }

  /* --- старт --- */
  if (!productId) {
    fail('Товар не указан. Вернитесь в каталог и выберите масло.');
    return;
  }

  Promise.all([loadCatalog(), loadInfo()])
    .then(([products, info]) => {
      const product = products.find(p => p.id === productId);
      if (!product) {
        fail('Такое масло не найдено. Возможно, оно снято с витрины.');
        return;
      }
      render(product, info[productId], products);
      bindBuy(product);
    })
    .catch(() => fail('Не удалось загрузить данные о товаре. Обновите страницу.'));
})();

/* ─────────────────────────────────────────
   Бургер-меню — та же логика, что на главной,
   но здесь нужен свой экземпляр (main.js не подключён).
───────────────────────────────────────── */
(function initNav() {
  const nav    = document.querySelector('.nav');
  const burger = document.getElementById('nav-burger');
  const menu   = document.getElementById('nav-menu');
  if (!nav || !burger || !menu) return;

  const closeMenu = () => {
    nav.classList.remove('is-menu-open');
    burger.setAttribute('aria-expanded', 'false');
  };

  burger.addEventListener('click', (e) => {
    e.stopPropagation();
    const open = nav.classList.toggle('is-menu-open');
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  menu.addEventListener('click', (e) => {
    if (e.target.closest('a')) closeMenu();
  });

  document.addEventListener('click', (e) => {
    if (!nav.classList.contains('is-menu-open')) return;
    if (!nav.contains(e.target)) closeMenu();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMenu();
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 768) closeMenu();
  });
})();
