(function () {
  if (typeof window === 'undefined' || !window.mhfgfwcBlocks) return;

    const cfg = window.mhfgfwcBlocks;
    const isCart = cfg.context === 'cart';

    // ✅ Define a single makeSlot() function that handles both cart and checkout
    function makeSlot() {
      const slot = document.createElement('div'); // keep as <div> for block compatibility
      slot.id = cfg.mountId || 'mhfgfwc-blocks-slot';
      slot.className = 'mhfgfwc-blocks-panel wc-block-components-panel';

      // Add totals-item style for checkout so it aligns correctly
      if (!isCart) {
        slot.className += ' wc-block-components-totals-item';
      }

      slot.innerHTML = `
        <div class="wc-block-components-panel__body">
          <div class="mhfgfwc-blocks-slot__inner"></div>
        </div>
      `;
      return slot;
    }

    const slot = makeSlot(); // ✅ create it after function definition
    if (!isCart) {
      // visually match the order-summary row AND mark as checkout layout
      slot.classList.add('mhfgfwc--checkout');
    }


  function placeInCartMain() {
    const wrap = document.querySelector(
      '.wc-block-components-sidebar-layout.wc-block-cart, .wp-block-woocommerce-filled-cart-block'
    );
    if (!wrap) return false;

    const main = wrap.querySelector('.wc-block-components-main, .wc-block-cart__main');
    if (!main) return false;

    const items = main.querySelector('.wp-block-woocommerce-cart-items-block, .wc-block-cart-items');
    (items && items !== main) ? items.insertAdjacentElement('afterend', slot) : main.appendChild(slot);
    return true;
  }

  function placeInCheckoutSidebar() {
    const wrap = document.querySelector(
      '.wc-block-components-sidebar-layout.wc-block-checkout, .wp-block-woocommerce-checkout'
    );
    if (!wrap) return false;

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
  const mo = new MutationObserver(() => ensurePlaced());
  mo.observe(document.body, { childList: true, subtree: true });

  // Fetch the HTML
  const url = `${cfg.renderUrl}&nonce=${encodeURIComponent(cfg.nonce)}`;
  fetch(url, { credentials: 'same-origin' })
    .then(r => r.text())
    .then(html => {
      const mount = document.querySelector(`#${CSS.escape(slot.id)} .mhfgfwc-blocks-slot__inner`);
      if (mount) mount.innerHTML = html;
    })
    .catch(() => {});
})();




(function () {
  function hideGiftQty() {
    var containers = document.querySelectorAll('.wc-block-cart-item, .wc-block-components-panel__content');
    containers.forEach(function (el) {
      if (el.querySelector('.mhfgfwc-badge')) {
        var qtyWrap = el.querySelector('.wc-block-components-quantity-selector');
        if (qtyWrap) {
          qtyWrap.style.display = 'none';
          // (Optional) show a small read-only Qty label
          if (!el.querySelector('.mhfgfwc-fixed-qty')) {
            var span = document.createElement('span');
            span.className = 'mhfgfwc-fixed-qty';
            span.textContent = '× 1';
            // Try to place it near the title/price area
            var title = el.querySelector('.wc-block-cart-item__product');
            (title || el).appendChild(span);
          }
        }
      }
    });
  }

  // Run on load
  document.addEventListener('DOMContentLoaded', hideGiftQty);

  // Re-run whenever Blocks re-renders (cart updates, remove/add, etc.)
  if (window.wp && wp.data && typeof wp.data.subscribe === 'function') {
    let last = null;
    wp.data.subscribe(function () {
      try {
        const sel = wp.data.select('wc/store');
        if (!sel || !sel.getCartData) return;
        const items = sel.getCartData().items || [];
        const hash = JSON.stringify(items.map(i => [i.key, i.quantity, i.extensions]));
        if (hash !== last) {
          last = hash;
          setTimeout(hideGiftQty, 0);
        }
      } catch (e) {
        // no-op
      }
    });
  }
})();

(function () {
  function parseMoneyToNumber(text) {
      const num = (text || '')
        .replace(/[^0-9,.\-]/g, '')
        .replace(/(\d)[,](\d{2})$/, '$1.$2');
      const val = parseFloat(num);
      return isNaN(val) ? null : val;
    }

    function isGiftRow(row) {
      // A) zero line total
      const totalEl =
        row.querySelector('.wc-block-cart-item__total') ||
        row.querySelector('.wc-block-cart-item__prices');
      const totalText = totalEl ? totalEl.textContent : '';
      const val = parseMoneyToNumber(totalText);
      if (val !== null && Math.abs(val) < 0.0001) return true;

      // B) product metadata explicitly says "Free gift"
      const metaVal = row.querySelector(
        '.wc-block-components-product-details__value'
      );
      if (metaVal && /free gift/i.test(metaVal.textContent || '')) return true;

      return false;
    }

    function tagGiftsAndHideQty() {
      // Your rows look like <tr class="wc-block-cart-items__row">…</tr>
      const rows = document.querySelectorAll('.wc-block-cart-items__row');
      rows.forEach((row) => {
        if (!isGiftRow(row)) return;

        row.classList.add('is-mhfgfwc-gift');

        // Hide and disable the qty selector
        const qtyWrap = row.querySelector('.wc-block-cart-item__quantity');
        const qtySel = row.querySelector('.wc-block-components-quantity-selector');
        const input  = row.querySelector('.wc-block-components-quantity-selector__input');
        const minus  = row.querySelector('.wc-block-components-quantity-selector__button--minus');
        const plus   = row.querySelector('.wc-block-components-quantity-selector__button--plus');

        if (input)  { input.readOnly = true; input.setAttribute('aria-readonly', 'true'); }
        if (minus)  { minus.disabled = true; }
        if (plus)   { plus.disabled  = true; }

        // Hide the whole control to prevent layout jiggle; keep the Remove button
        if (qtySel) qtySel.style.display = 'none';

        // Optional small fixed label so the area doesn’t look empty
        if (qtyWrap && !row.querySelector('.mhfgfwc-fixed-qty')) {
          const label = document.createElement('span');
          label.className = 'mhfgfwc-fixed-qty';
          label.textContent = '× 1';
          qtyWrap.insertBefore(label, qtyWrap.firstChild);
        }
      });
    }

    // Run once after DOM
    document.addEventListener('DOMContentLoaded', tagGiftsAndHideQty);

    // Re-run on cart state changes
    if (window.wp && wp.data && typeof wp.data.subscribe === 'function') {
      let lastSig = '';
      wp.data.subscribe(function () {
        try {
          const sel = wp.data.select('wc/store');
          if (!sel || !sel.getCartData) return;
          const items = sel.getCartData().items || [];
          const sig = JSON.stringify(items.map(i => [i.key, i.quantity, i.totals?.line_total]));
          if (sig !== lastSig) {
            lastSig = sig;
            setTimeout(tagGiftsAndHideQty, 0);
          }
        } catch (_) {}
      });
    }

})();

