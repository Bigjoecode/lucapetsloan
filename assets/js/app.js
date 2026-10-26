// LucaPet Loan — small progressive enhancements
(function () {
  const CUR = document.documentElement.dataset.currency || '₦';
  const fmt = (n) => CUR + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // Dialogs: <button data-open="dlgId"> opens <dialog id="dlgId">
  document.querySelectorAll('[data-open]').forEach((b) =>
    b.addEventListener('click', () => {
      const d = document.getElementById(b.dataset.open);
      if (!d) return;
      if (b.dataset.plan) {
        const sel = d.querySelector('select[name=plan_id]');
        if (sel) sel.value = b.dataset.plan;
        const amt = d.querySelector('input[name=amount]');
        if (amt && b.dataset.amount) amt.value = b.dataset.amount;
      }
      d.showModal();
    })
  );
  document.querySelectorAll('dialog [data-close]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));
  document.querySelectorAll('dialog').forEach((d) => d.addEventListener('click', (e) => { if (e.target === d) d.close(); }));

  // Links like savings.php#withdraw open the matching dialog
  if (location.hash) {
    const t = document.getElementById(location.hash.slice(1));
    if (t && t.dataset.open) t.click();
  }

  // Prefill deposit amount when picking a plan
  document.querySelectorAll('select[data-fill-amount]').forEach((sel) =>
    sel.addEventListener('change', () => {
      const opt = sel.selectedOptions[0];
      const input = sel.form.querySelector('input[name=amount]');
      if (opt && opt.dataset.amount && input) input.value = opt.dataset.amount;
    })
  );

  // Confirm dangerous actions
  document.querySelectorAll('[data-confirm]').forEach((el) =>
    el.addEventListener('click', (e) => { if (!confirm(el.dataset.confirm)) e.preventDefault(); })
  );

  // Loan calculator: form[data-calc] with product select (data-rate/min/max/tmin/tmax), amount, tenure
  document.querySelectorAll('[data-calc]').forEach((form) => {
    const product = form.querySelector('[name=product_id]');
    const amount = form.querySelector('[name=amount]');
    const tenure = form.querySelector('[name=tenure_months]');
    const out = document.querySelector(form.dataset.calc);
    if (!product || !amount || !tenure || !out) return;

    function syncLimits() {
      const o = product.selectedOptions[0];
      if (!o) return;
      amount.min = o.dataset.min; amount.max = o.dataset.max;
      const tmin = +o.dataset.tmin, tmax = +o.dataset.tmax, cur = +tenure.value;
      tenure.innerHTML = '';
      for (let m = tmin; m <= tmax; m++) {
        const op = document.createElement('option');
        op.value = m; op.textContent = m + (m === 1 ? ' month' : ' months');
        tenure.appendChild(op);
      }
      tenure.value = cur >= tmin && cur <= tmax ? cur : tmin;
      const hint = form.querySelector('[data-range]');
      if (hint) hint.textContent = 'Between ' + fmt(o.dataset.min) + ' and ' + fmt(o.dataset.max);
    }
    function calc() {
      const o = product.selectedOptions[0];
      const P = parseFloat(amount.value) || 0, n = parseInt(tenure.value) || 1, r = (+o.dataset.rate) / 100;
      const interest = P * r * n, total = P + interest;
      out.querySelector('[data-o=rate]').textContent = o.dataset.rate + '% per month';
      out.querySelector('[data-o=interest]').textContent = fmt(interest);
      out.querySelector('[data-o=daily]').textContent = fmt(P * r / 30);
      out.querySelector('[data-o=total]').textContent = fmt(total);
      out.querySelector('[data-o=monthly]').textContent = fmt(total / n);
    }
    product.addEventListener('change', () => { syncLimits(); calc(); });
    amount.addEventListener('input', calc);
    tenure.addEventListener('change', calc);
    syncLimits(); calc();
  });

  // Shrink photos in the browser before upload (keeps requests small and saves mobile data)
  async function shrinkImage(file, maxSide = 1600, quality = 0.82) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 350 * 1024) return file;
    const bitmap = await createImageBitmap(file).catch(() => null);
    if (!bitmap) return file;
    const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise((r) => canvas.toBlob(r, 'image/jpeg', quality));
    if (!blob || blob.size >= file.size) return file;
    return new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
  }
  document.querySelectorAll('form[data-max-bytes]').forEach((form) => {
    const max = +form.dataset.maxBytes;
    form.querySelectorAll('input[type=file]').forEach((input) =>
      input.addEventListener('change', async () => {
        const file = input.files[0];
        if (!file) return;
        input.setCustomValidity('');
        const small = await shrinkImage(file);
        if (small !== file && window.DataTransfer) {
          const dt = new DataTransfer();
          dt.items.add(small);
          input.files = dt.files;
        }
        if (input.files[0].size > max) {
          input.setCustomValidity('This file is too large (max ' + (max / 1048576).toFixed(1) + ' MB). Please choose a smaller file or take a photo instead.');
          input.reportValidity();
        }
      })
    );
  });

  // "Use my location" for the home-visit address
  const geo = document.getElementById('geo-btn');
  if (geo) {
    geo.addEventListener('click', () => {
      const status = document.getElementById('geo-status');
      if (!navigator.geolocation) { status.textContent = 'Location is not available on this device.'; return; }
      status.textContent = 'Getting your location…';
      navigator.geolocation.getCurrentPosition(
        (p) => {
          document.querySelector('[name=latitude]').value = p.coords.latitude.toFixed(7);
          document.querySelector('[name=longitude]').value = p.coords.longitude.toFixed(7);
          status.textContent = 'Location saved (±' + Math.round(p.coords.accuracy) + ' m). This helps our officer find you.';
        },
        () => { status.textContent = 'Could not get your location. You can still submit — just describe the landmark well.'; },
        { enableHighAccuracy: true, timeout: 15000 }
      );
    });
  }
})();
