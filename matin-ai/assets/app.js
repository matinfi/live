(() => {
  'use strict';
  const conversation = document.querySelector('#conversation');
  const chatHistory = []; // Short in-memory context for natural follow-up questions; never persisted in the browser.
  const form = document.querySelector('#assistant-form');
  const prompt = document.querySelector('#assistant-prompt');
  const dialog = document.querySelector('#reservation-dialog');
  const reservation = document.querySelector('#reservation-form');
  let userCoords = null; // Set only after the visitor presses "کافه‌های نزدیک من" and grants permission.
  const esc = value => String(value || '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
  const api = (path, options = {}) => fetch(matinAI.root + path, { ...options, headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': matinAI.nonce, ...(options.headers || {}) } }).then(async response => { const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.message || matinAI.strings.error); return data; });
  const scroll = () => conversation?.scrollTo({ top: conversation.scrollHeight, behavior: 'smooth' });
  const userMessage = text => `<article class="message user-message"><p>${esc(text)}</p></article>`;
  const loading = () => `<article class="message ai-message loading-message"><div class="ai-avatar" aria-hidden="true">م</div><div><p class="message-label">ماتین AI</p><span></span><span></span><span></span></div></article>`;
  const cards = items => !items?.length ? '' : `<div class="chat-cards">${items.map(item => `<article class="chat-card"><div class="chat-card-image">${item.image ? `<img src="${esc(item.image)}" alt="" loading="lazy">` : '<span>MATIN<br>CAFE</span>'}</div><div><p class="card-meta">${esc(item.cafe.location || 'مکان ثبت نشده')} · ${esc(item.startsAt || 'زمان در حال تکمیل')}${item.distanceKm != null ? ` · حدود ${esc(item.distanceKm)} کیلومتر` : ''}</p><h3>${esc(item.title)}</h3><p>${esc(item.cafe.name)} · ${esc(item.cafe.address || 'نشانی در صفحهٔ جزئیات')} · ${item.remaining} نفر ظرفیت باقی‌مانده</p>${item.performer ? `<p class="card-meta">هنرمند: ${esc(item.performer)}</p>` : ''}${item.matchTeams ? `<p class="card-meta">مسابقه: ${esc(item.matchTeams)}</p>` : ''}<p class="card-meta">${Number(item.price) > 0 ? `ورودی: ${esc(item.price)} لیر` : 'قیمت: رایگان / اعلام‌نشده'}</p><div class="chat-card-actions"><a class="details-link" href="${esc(item.url)}">مشاهده جزئیات</a><button class="reserve-button" data-reserve="${item.id}" data-title="${esc(item.title)}" ${item.bookable ? '' : 'disabled'}>${item.bookable ? 'درخواست رزرو' : 'تکمیل ظرفیت'}</button></div></div></article>`).join('')}</div>`;
  const aiMessage = (answer, items) => `<article class="message ai-message"><div class="ai-avatar" aria-hidden="true">م</div><div class="ai-content"><p class="message-label">ماتین AI</p><p>${esc(answer)}</p>${Array.isArray(items) && items.length ? cards(items) : ''}</div></article>`;
  const setSending = sending => { form?.classList.toggle('is-loading', sending); prompt.disabled = sending; form?.querySelector('button').toggleAttribute('disabled', sending); };
  const remember = (user, assistant) => { chatHistory.push({ role: 'user', text: user }, { role: 'assistant', text: assistant }); if (chatHistory.length > 8) chatHistory.splice(0, chatHistory.length - 8); };
  const send = async text => {
    const clean = text.trim();
    if (clean.length < 3 || prompt.disabled) return;
    conversation.insertAdjacentHTML('beforeend', userMessage(clean) + loading());
    prompt.value = ''; prompt.style.height = 'auto'; setSending(true); scroll();
    try {
      const data = await api('assistant', { method: 'POST', body: JSON.stringify({ prompt: clean, history: chatHistory.slice(-8), ...(userCoords || {}) }) });
      conversation.querySelector('.loading-message')?.remove();
      conversation.insertAdjacentHTML('beforeend', aiMessage(data.answer, data.items));
      remember(clean, data.answer);
    } catch (error) {
      conversation.querySelector('.loading-message')?.remove();
      conversation.insertAdjacentHTML('beforeend', aiMessage(error.message, null));
      remember(clean, error.message);
    } finally { setSending(false); prompt.focus(); }
    scroll();
  };
  const locate = () => new Promise((resolve, reject) => {
    if (!navigator.geolocation) { reject(new Error('موقعیت‌یابی در این مرورگر پشتیبانی نمی‌شود.')); return; }
    navigator.geolocation.getCurrentPosition(
      position => resolve({ lat: position.coords.latitude, lng: position.coords.longitude }),
      () => reject(new Error('اجازهٔ دسترسی به موقعیت داده نشد؛ می‌توانید نام محله را بنویسید.')),
      { timeout: 10000, maximumAge: 300000 }
    );
  });

  form?.addEventListener('submit', event => { event.preventDefault(); send(prompt.value); });
  prompt?.addEventListener('keydown', event => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); form.requestSubmit(); } });
  prompt?.addEventListener('input', () => { prompt.style.height = 'auto'; prompt.style.height = `${Math.min(prompt.scrollHeight, 160)}px`; });
  document.addEventListener('click', event => {
    const starter = event.target.closest('[data-prompt]');
    if (starter && conversation) send(starter.dataset.prompt);
    const near = event.target.closest('#near-me');
    if (near && conversation) {
      locate().then(coords => { userCoords = coords; send('کافه‌ها و برنامه‌های نزدیک من را پیشنهاد بده'); })
        .catch(error => conversation.insertAdjacentHTML('beforeend', aiMessage(error.message, null)));
    }
    const reserve = event.target.closest('[data-reserve]');
    if (reserve && dialog) {
      reservation.eventId.value = reserve.dataset.reserve;
      document.querySelector('#reservation-event').textContent = reserve.dataset.title;
      document.querySelector('#reservation-message').textContent = '';
      dialog.showModal();
      reservation.querySelector('[name=name]').focus();
    }
  });
  document.querySelector('#new-chat')?.addEventListener('click', () => { chatHistory.length = 0; userCoords = null; conversation.innerHTML = `<section class="welcome-message"><div class="ai-avatar" aria-hidden="true">م</div><div><p class="message-label">ماتین AI · راهنمای رزرو</p><h2>گفت‌وگوی تازه شروع شد.</h2><p>بگویید برای چه زمانی، چه محله‌ای و چند نفر دنبال کافه یا ایونت هستید.</p></div></section>`; prompt.focus(); });
  document.querySelector('.dialog-close')?.addEventListener('click', () => dialog.close());

  reservation?.addEventListener('submit', async event => {
    event.preventDefault();
    const message = document.querySelector('#reservation-message');
    const button = reservation.querySelector('button[type=submit]');
    button.disabled = true;
    message.textContent = matinAI.strings.loading;
    try {
      const data = await api('reservation', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(reservation))) });
      message.innerHTML = `<div class="receipt-card" id="matin-receipt"><p class="receipt-kicker">رسید درخواست رزرو</p><h3>${esc(data.cafe)}</h3><p>${esc(data.event)}</p><dl><div><dt>کد پیگیری</dt><dd>${esc(data.ticketCode)}</dd></div><div><dt>نام مهمان</dt><dd>${esc(data.guestName)}</dd></div><div><dt>تعداد نفرات</dt><dd>${esc(data.guests)}</dd></div><div><dt>زمان رویداد</dt><dd>${esc(data.startsAt || 'طبق اطلاعات رویداد')}</dd></div><div><dt>وضعیت</dt><dd>در انتظار تأیید کافه</dd></div></dl><p class="receipt-notice">${esc(data.message)}</p><button type="button" class="button print-receipt">چاپ / ذخیره رسید</button>${data.ticketUrl ? `<a class="button" target="_blank" rel="noopener noreferrer" href="${esc(data.ticketUrl)}">دانلود بلیت (تصویر)</a>` : ''}</div>`;
    } catch (error) {
      message.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  });

  // Confirmation for destructive forms (e.g. reservation cancellation). The server still validates everything.
  document.addEventListener('submit', event => {
    const question = event.target.dataset?.confirm;
    if (question && !window.confirm(question)) event.preventDefault();
  });

  document.addEventListener('click', event => {
    const print = event.target.closest('.print-receipt');
    if (!print) return;
    const receipt = document.querySelector('#matin-receipt');
    if (!receipt) return;
    const win = window.open('', '_blank', 'width=720,height=800');
    if (!win) { window.print(); return; }
    win.document.write(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>رسید رزرو ماتین</title><style>body{font-family:Tahoma,Arial,sans-serif;max-width:620px;margin:32px auto;padding:24px;color:#17202a;line-height:1.9}h3{margin-bottom:4px}.receipt-card{border:1px solid #ddd;border-radius:16px;padding:24px}.receipt-kicker{color:#555}dl div{display:flex;justify-content:space-between;border-bottom:1px solid #eee;padding:8px 0}dt{color:#666}dd{margin:0;font-weight:bold}.receipt-notice{background:#fff5d8;padding:12px;border-radius:8px}@media print{button,a{display:none}}</style></head><body>${receipt.outerHTML}<script>window.onload=()=>window.print()<\/script></body></html>`);
    win.document.close();
  });
  document.querySelector('#mobile-menu')?.addEventListener('click', event => {
    document.body.classList.toggle('sidebar-open');
    event.currentTarget.setAttribute('aria-expanded', document.body.classList.contains('sidebar-open'));
  });
})();
