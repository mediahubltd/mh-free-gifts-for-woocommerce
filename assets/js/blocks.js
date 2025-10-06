(function () {
  if (typeof window === 'undefined' || !window.mhfgfwcBlocks) return;

  const cfg    = window.mhfgfwcBlocks || {};
  const isCart = cfg.context === 'cart';

  // ---------------------------
  // Slot creation + placement
  // ---------------------------
  function makeSlot() {
    const el = document.createElement('div'); // div works best with WP Blocks
    el.id = cfg.mountId || 'mhfgfwc-blocks-slot';
    el.className = 'mhfgfwc-blocks-panel wc-block-components-panel';
    if (!isCart) {
      // visually align with order summary rows on checkout
      el.classList.add('wc-block-components-totals-item', 'mhfgfwc--checkout');
    }
    el.innerHTML =
      '<div class="wc-block-components-panel__body">' +
        '<div class="mhfgfwc-blocks-slot__inner"></div>' +
      '</div>';
    return el;
  }

  const slot = makeSlot();

  function placeInCartMain() {
    const wrap = document.querySelector(
      '.wc-block-components-sidebar-layout.wc-block-cart, .wp-block-woocommerce-filled-cart-block'
    );
    if (!wrap) return false;

    const main = wrap.querySelector('.wc-block-components-main, .wc-block-cart__main');
    if (!main) return false;

    // Insert right after the cart items block so it sits beneath products
    const items = main.querySelector('.wp-block-woocommerce-cart-items-block, .wc-block-cart-items');
    (items && items !== main) ? items.insertAdjacentElement('afterend', slot) : main.appendChild(slot);
    return true;
  }

  function placeInCheckoutSidebar() {
    const wrap = document.querySelector(
      '.wc-block-components-sidebar-layout.wc-block-checkout, .wp-block-woocommerce-checkout'
    );
    if (!wrap) return false;

    // For checkout we mount within the totals area so spacing/padding matches
    const sidebar = wrap.querySelector(
      '.wc-block-components-sidebar, .wp-block-woocommerce-checkout-order-summary, .wp-block-woocommerce-checkout-order-summary-block'
    );
    if (!sidebar) return false;

    sidebar.insertAdjacentElement('afterbegin', slot);
    return true;
  }

  function ensurePlaced() {
    if (document.getElementById(slot.id)) return true;
    return isCart ? placeInCartMain() : placeInCheckoutSidebar();
  }

  (document.readyState === 'loading')
    ? document.addEventListener('DOMContentLoaded', ensurePlaced)
    : ensurePlaced();

  // Re-place after Blocks rerenders
  const placeObserver = new MutationObserver(() => ensurePlaced());
  placeObserver.observe(document.body, { childList: true, subtree: true });

  // ---------------------------
  // Render gifts HTML via AJAX
  // ---------------------------
  function renderGifts() {
    const url = `${cfg.renderUrl}&nonce=${encodeURIComponent(cfg.nonce || '')}`;
    return fetch(url, { credentials: 'same-origin' })
      .then(r => r.text())
      .then(html => {
        const mount = document.querySelector(`#${CSS.escape(slot.id)} .mhfgfwc-blocks-slot__inner`);
        if (mount) {
          mount.innerHTML = html;
          // let anyone listening know the cart UI changed
          document.dispatchEvent(new CustomEvent('mhfgfwc:cartChanged'));
        }
      })
      .catch(() => {});
  }

  // First load
  (document.readyState === 'loading')
    ? document.addEventListener('DOMContentLoaded', renderGifts)
    : renderGifts();

  // ---------------------------
  // Robust “gift row” qty lock
  // ---------------------------

  // Mark and lock a single row
  function markAndLockRow(row) {
    if (!row) return;
    // qty input + +/- buttons (Blocks cart)
    const input = row.querySelector('.wc-block-components-quantity-selector__input');
    const minus = row.querySelector('.wc-block-components-quantity-selector__button--minus');
    const plus  = row.querySelector('.wc-block-components-quantity-selector__button--plus');

    if (input) {
      input.readOnly = true;
      input.setAttribute('aria-readonly', 'true');
      input.classList.add('mhfgfwc-qty-locked');
    }
    if (minus) minus.disabled = true;
    if (plus)  plus.disabled  = true;

    // Optionally hide selector to avoid jiggle; remove this line if you want to keep it visible but disabled
    const qtySel = row.querySelector('.wc-block-components-quantity-selector');
    if (qtySel) qtySel.style.display = 'none';

    // Small “× 1” label if area now empty
    const qtyWrap = row.querySelector('.wc-block-cart-item__quantity');
    if (qtyWrap && !row.querySelector('.mhfgfwc-fixed-qty')) {
      const label = document.createElement('span');
      label.className = 'mhfgfwc-fixed-qty';
      label.textContent = '× 1';
      qtyWrap.insertBefore(label, qtyWrap.firstChild);
    }

    row.classList.add('mhfgfwc-is-gift');
  }

  // Detect if a cart row is a gift (language-safe)
  function isGiftRow(row) {
    // Prefer explicit metadata: “Free gift” (localized)
    const metaVal = row.querySelector('.wc-block-components-product-details__value');
    if (metaVal) {
      const want = String(cfg.i18nFreeGift || 'Free gift').trim().toLowerCase();
      const txt  = (metaVal.textContent || '').trim().toLowerCase();
      if (txt === want) return true;
    }

    // Fallback: zero total
    const total = row.querySelector('.wc-block-cart-item__total .wc-block-components-product-price__value')
               || row.querySelector('.wc-block-cart-item__prices .wc-block-components-product-price__value');
    if (total) {
      const t = (total.textContent || '').replace(/\s+/g,'').toLowerCase();
      if (t === '0.00' || /\b0\.00\b/.test(t) || /\$0\.00|£0\.00|€0\.00/.test(t)) {
        return true;
      }
    }

    return false;
  }

  function lockGiftQtyInBlocks(root = document) {
    const rows = root.querySelectorAll('.wc-block-cart-items__row, tr.wc-block-cart-items__row');
    rows.forEach(row => {
      if (!row.classList.contains('mhfgfwc-is-gift') && isGiftRow(row)) {
        markAndLockRow(row);
      }
    });
  }

  // Run now and after we inject our HTML
  (document.readyState === 'loading')
    ? document.addEventListener('DOMContentLoaded', () => lockGiftQtyInBlocks(document))
    : lockGiftQtyInBlocks(document);

  document.addEventListener('mhfgfwc:cartChanged', () => lockGiftQtyInBlocks(document));

  // Also observe the cart/checkout blocks so we re-apply on any rerender
  const targets = [
    document.querySelector('.wc-block-cart'),
    document.querySelector('.wp-block-woocommerce-cart'),
    document.querySelector('.wc-block-checkout'),
    document.querySelector('.wp-block-woocommerce-checkout')
  ].filter(Boolean);

  targets.forEach(t => {
    new MutationObserver(() => lockGiftQtyInBlocks(t))
      .observe(t, { childList: true, subtree: true });
  });

  // For extra reliability, tie into wp.data changes (if present)
  if (window.wp && wp.data && typeof wp.data.subscribe === 'function') {
    let lastSig = '';
    wp.data.subscribe(function () {
      try {
        const sel = wp.data.select('wc/store');
        if (!sel || !sel.getCartData) return;
        const items = sel.getCartData().items || [];
        const sig = JSON.stringify(items.map(i => [i.key, i.quantity, i.totals?.line_total, i.extensions]));
        if (sig !== lastSig) {
          lastSig = sig;
          // re-fetch our panel (in case rules changed) and re-lock
          renderGifts().then(() => lockGiftQtyInBlocks(document));
        }
      } catch (_) {}
    });
  }
})();
