/* Tartebz – orders board for the team (/staff).
   Sign in with a team account (role "Shop staff", or staff / manager / owner). Shows the orders with filters (date, country),
   a tick per order for "prepared", the order details (invoice, SMSA label, payment link), and a form to create an order
   for a customer who ordered on WhatsApp. A sound plays for every new order; phones and computers can also get
   notifications while the board is closed or the screen is asleep (service worker: /staff-sw.js). */
(function () {
  'use strict';
  var doc = document, root = doc.getElementById('app');
  var store = {
    get: function (k, d) { try { var v = localStorage.getItem('tz_staff_' + k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem('tz_staff_' + k, JSON.stringify(v)); } catch (e) { /* private mode */ } }
  };

  /* ---------------- words (English / Arabic) ---------------- */
  var W = {
    en: {
      title: 'Orders', board: 'Orders board', signin_sub: 'Sign in with your team account.', email: 'Email', password: 'Password', signin: 'Sign in',
      forgot: 'Forgot password?', shop: 'Shop', no_access: 'This account cannot use the orders board.', bad_login: 'Wrong email or password.',
      new_order: 'New order', prepare: 'To prepare', unpaid: 'Not paid', prepared: 'Prepared', sent: 'Sent', all: 'All',
      today: 'Today', yesterday: 'Yesterday', d7: '7 days', d30: '30 days', any: 'Any date', pick: 'Dates…', from: 'From', to: 'To',
      all_countries: 'All countries', search: 'Search name, phone or order number', none: 'No orders here.', more: 'Show more',
      paid: 'Paid', not_paid: 'Not paid', wa: 'WhatsApp', card: 'Card', link_sent: 'Payment link', by: 'by',
      tap_sound: 'Tap here to turn on the order sound', new_in: 'New order {n} – {name}', seen: 'Seen',
      settings: 'Settings', sound: 'Order sound', sound_hint: 'Plays for every new order.', test_sound: 'Test',
      awake: 'Keep the screen on', awake_hint: 'While this board is open, the screen does not go to sleep, so you always hear new orders.',
      push: 'Phone / computer notifications', push_hint: 'A notification for every new order, even when this page is closed or the screen is off.',
      push_on: 'Turn on', push_test: 'Send a test', push_blocked: 'Notifications are blocked for this site. Allow them in the browser’s site settings, then try again.',
      push_ios: 'On iPhone or iPad: tap Share → “Add to Home Screen”, open Orders from the Home Screen, then turn notifications on here.',
      push_none: 'This browser cannot show notifications.', push_ready: 'Notifications are on for this device.', push_sent: 'Test sent – it should arrive in a few seconds.',
      install: 'Add to Home Screen', language: 'Language', signout: 'Sign out', signed_in_as: 'Signed in as',
      customer: 'Customer', name: 'Full name', phone: 'Mobile (WhatsApp)', dial: 'Country code', bad_phone: 'This {c} mobile number doesn’t look right. Example: {e}', country: 'Country', city: 'City / area', address: 'Address (house, road, block)',
      address2: 'More details (flat, landmark)', notes: 'Notes', email_opt: 'Email (optional)', cust_lang: 'Customer’s language',
      products: 'Products', find: 'Find a product', left: '{n} left', sold_out: 'Sold out', add: 'Add',
      summary: 'Summary', items: 'Items', weight: 'Weight', subtotal: 'Subtotal', delivery: 'Delivery', total: 'Total', charged: 'Customer pays',
      payment: 'Payment', pay_link: 'Send a payment link', pay_link_hint: 'The customer pays by card on the website. You send the link on WhatsApp or by email.',
      pay_paid: 'Paid already', pay_paid_hint: 'The money is in (Benefit transfer, cash…). The order is ready to prepare.',
      pay_later: 'Pays later', pay_later_hint: 'Keep it as a WhatsApp order. Mark it paid when the money arrives.',
      how_paid: 'How was it paid?', benefit: 'Benefit transfer', cash: 'Cash', create: 'Create order', created: 'Order {n} created',
      tap_off: 'Card links need Tap switched on in Settings › Payments.',
      send_wa: 'Send the link on WhatsApp', send_email: 'Send the link by email', copy: 'Copy the link', copied: 'Link copied',
      invoice: 'Print invoice', open: 'Open the order', another: 'New order', close: 'Close', back: 'Back',
      items_n: '{n} items', no_items: 'Add at least one product.', changed: 'Stock or prices changed – check the order again.',
      order: 'Order', call: 'Call', whatsapp: 'WhatsApp', mark_paid: 'Mark as paid', mark_paid_q: 'How was it paid?', marked_paid: 'Marked as paid',
      ship: 'Book SMSA and get the label', label: 'Print SMSA label', track: 'Update tracking', shipped: 'Mark as shipped', delivered: 'Mark as delivered',
      boxes: 'Boxes', weight_kg: 'Weight (kg)', smsa_off: 'SMSA is not switched on yet (Settings › Shipping).', live_ship: 'This books a real SMSA parcel. Continue?',
      prepared_by: 'Prepared by {n}', tick_hint: 'Tick when prepared', history: 'History', add_note: 'Add a note for the team', save: 'Save',
      link_hint: 'Send the customer a link to pay by card.', saved: 'Saved', err: 'Something went wrong. Try again.', offline: 'No connection. Check the internet.',
      created_by: 'Created by {n}', ago_now: 'just now', ago_m: '{n} min ago', ago_h: '{n} h ago',
      status: { pending_payment: 'Awaiting payment', awaiting_whatsapp: 'WhatsApp – not paid', payment_failed: 'Payment failed', paid: 'Paid – to prepare',
        processing: 'Preparing', shipped: 'Shipped', delivered: 'Delivered', cancelled: 'Cancelled', refunded: 'Refunded' },
      countries: { BH: 'Bahrain', SA: 'Saudi Arabia', AE: 'UAE', QA: 'Qatar', KW: 'Kuwait', OM: 'Oman' }
    },
    ar: {
      title: 'الطلبات', board: 'لوحة الطلبات', signin_sub: 'سجّل الدخول بحساب فريق العمل.', email: 'البريد الإلكتروني', password: 'كلمة المرور', signin: 'تسجيل الدخول',
      forgot: 'نسيت كلمة المرور؟', shop: 'المتجر', no_access: 'هذا الحساب لا يمكنه استخدام لوحة الطلبات.', bad_login: 'البريد أو كلمة المرور غير صحيحة.',
      new_order: 'طلب جديد', prepare: 'للتجهيز', unpaid: 'غير مدفوع', prepared: 'تم التجهيز', sent: 'تم الإرسال', all: 'الكل',
      today: 'اليوم', yesterday: 'أمس', d7: '٧ أيام', d30: '٣٠ يوم', any: 'كل التواريخ', pick: 'تواريخ…', from: 'من', to: 'إلى',
      all_countries: 'كل الدول', search: 'ابحث بالاسم أو الهاتف أو رقم الطلب', none: 'لا توجد طلبات هنا.', more: 'عرض المزيد',
      paid: 'مدفوع', not_paid: 'غير مدفوع', wa: 'واتساب', card: 'بطاقة', link_sent: 'رابط دفع', by: 'بواسطة',
      tap_sound: 'اضغط هنا لتشغيل صوت الطلبات', new_in: 'طلب جديد {n} – {name}', seen: 'تم',
      settings: 'الإعدادات', sound: 'صوت الطلبات', sound_hint: 'يرن مع كل طلب جديد.', test_sound: 'تجربة',
      awake: 'إبقاء الشاشة مضاءة', awake_hint: 'ما دامت اللوحة مفتوحة لا تنطفئ الشاشة، فتسمع كل طلب جديد.',
      push: 'تنبيهات الجوال / الكمبيوتر', push_hint: 'تنبيه مع كل طلب جديد حتى لو الصفحة مغلقة أو الشاشة مطفأة.',
      push_on: 'تفعيل', push_test: 'إرسال تجربة', push_blocked: 'التنبيهات محظورة لهذا الموقع. اسمح بها من إعدادات الموقع في المتصفح ثم حاول مرة أخرى.',
      push_ios: 'في الآيفون أو الآيباد: اضغط مشاركة ← «إضافة إلى الشاشة الرئيسية»، افتح «Orders» من الشاشة الرئيسية، ثم فعّل التنبيهات من هنا.',
      push_none: 'هذا المتصفح لا يدعم التنبيهات.', push_ready: 'التنبيهات مفعّلة على هذا الجهاز.', push_sent: 'تم إرسال التجربة – ستصل خلال ثوانٍ.',
      install: 'إضافة للشاشة الرئيسية', language: 'اللغة', signout: 'تسجيل الخروج', signed_in_as: 'مسجّل باسم',
      customer: 'العميل', name: 'الاسم الكامل', phone: 'رقم الجوال (واتساب)', dial: 'رمز الدولة', bad_phone: 'رقم جوال {c} غير صحيح. مثال: {e}', country: 'الدولة', city: 'المدينة / المنطقة', address: 'العنوان (منزل، طريق، مجمع)',
      address2: 'تفاصيل إضافية (شقة، علامة مميزة)', notes: 'ملاحظات', email_opt: 'البريد الإلكتروني (اختياري)', cust_lang: 'لغة العميل',
      products: 'المنتجات', find: 'ابحث عن منتج', left: 'متبقي {n}', sold_out: 'نفد', add: 'أضف',
      summary: 'الملخص', items: 'القطع', weight: 'الوزن', subtotal: 'المجموع الفرعي', delivery: 'التوصيل', total: 'الإجمالي', charged: 'يدفع العميل',
      payment: 'الدفع', pay_link: 'إرسال رابط دفع', pay_link_hint: 'يدفع العميل بالبطاقة في الموقع. ترسل له الرابط على واتساب أو بالإيميل.',
      pay_paid: 'مدفوع مسبقًا', pay_paid_hint: 'المبلغ وصل (تحويل بنفت، نقدًا…). الطلب جاهز للتجهيز.',
      pay_later: 'يدفع لاحقًا', pay_later_hint: 'يبقى طلب واتساب. سجّله مدفوعًا عند وصول المبلغ.',
      how_paid: 'كيف تم الدفع؟', benefit: 'تحويل بنفت', cash: 'نقدًا', create: 'إنشاء الطلب', created: 'تم إنشاء الطلب {n}',
      tap_off: 'روابط الدفع تحتاج تفعيل Tap من الإعدادات › الدفع.',
      send_wa: 'إرسال الرابط على واتساب', send_email: 'إرسال الرابط بالإيميل', copy: 'نسخ الرابط', copied: 'تم نسخ الرابط',
      invoice: 'طباعة الفاتورة', open: 'فتح الطلب', another: 'طلب جديد', close: 'إغلاق', back: 'رجوع',
      items_n: '{n} قطع', no_items: 'أضف منتجًا واحدًا على الأقل.', changed: 'تغيّر المخزون أو السعر – راجع الطلب مرة أخرى.',
      order: 'الطلب', call: 'اتصال', whatsapp: 'واتساب', mark_paid: 'تسجيل كمدفوع', mark_paid_q: 'كيف تم الدفع؟', marked_paid: 'تم التسجيل كمدفوع',
      ship: 'حجز شحنة سمسا وطباعة البوليصة', label: 'طباعة بوليصة سمسا', track: 'تحديث التتبع', shipped: 'تم الشحن', delivered: 'تم التوصيل',
      boxes: 'عدد الصناديق', weight_kg: 'الوزن (كجم)', smsa_off: 'سمسا غير مفعّلة بعد (الإعدادات › الشحن).', live_ship: 'سيتم حجز شحنة حقيقية مع سمسا. متابعة؟',
      prepared_by: 'جهّزه {n}', tick_hint: 'علّم عند التجهيز', history: 'السجل', add_note: 'أضف ملاحظة للفريق', save: 'حفظ',
      link_hint: 'أرسل للعميل رابطًا ليدفع بالبطاقة.', saved: 'تم الحفظ', err: 'حدث خطأ. حاول مرة أخرى.', offline: 'لا يوجد اتصال. تأكد من الإنترنت.',
      created_by: 'أنشأه {n}', ago_now: 'الآن', ago_m: 'قبل {n} د', ago_h: 'قبل {n} س',
      status: { pending_payment: 'بانتظار الدفع', awaiting_whatsapp: 'واتساب – غير مدفوع', payment_failed: 'فشل الدفع', paid: 'مدفوع – للتجهيز',
        processing: 'قيد التجهيز', shipped: 'تم الشحن', delivered: 'تم التوصيل', cancelled: 'ملغي', refunded: 'مسترجع' },
      countries: { BH: 'البحرين', SA: 'السعودية', AE: 'الإمارات', QA: 'قطر', KW: 'الكويت', OM: 'عُمان' }
    }
  };
  var lang = store.get('lang', 'ar') === 'en' ? 'en' : 'ar';
  function tr(k, v) {
    var s = W[lang][k] != null ? W[lang][k] : (W.en[k] != null ? W.en[k] : k);
    if (v) Object.keys(v).forEach(function (n) { s = String(s).split('{' + n + '}').join(v[n]); });
    return s;
  }
  function setLang(l) {
    lang = l; store.set('lang', l);
    if (l === 'ar' && typeof loadDict === 'function') loadDict();
    doc.documentElement.lang = l; doc.documentElement.dir = l === 'ar' ? 'rtl' : 'ltr';
    doc.title = 'Tartebz · ' + tr('title');
    if ('caches' in window) caches.open('tz-staff').then(function (c) { return c.put('/staff-lang', new Response(l)); }).catch(function () {});
  }
  setLang(lang);

  /* ---------------- helpers ---------------- */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var I = {
    plus: '<path d="M12 5v14M5 12h14"/>', bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    bellOff: '<path d="M8.7 3A6 6 0 0 1 18 8a21 21 0 0 0 .6 5"/><path d="M17 17H3s3-2 3-9a4.7 4.7 0 0 1 .3-1.7"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/><path d="m2 2 20 20"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    close: '<path d="M18 6 6 18M6 6l12 12"/>', search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
    printer: '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/>',
    truck: '<path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    mail: '<path d="M4 4h16v16H4z"/><path d="m22 6-10 7L2 6"/>', check: '<path d="M20 6 9 17l-5-5"/>', box: '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
    copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>', cash: '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/>',
    wa: '<path d="M3 21l1.65-3.8a9 9 0 1 1 3.4 2.9z"/><path d="M9 10a.5.5 0 0 0 1 0V9a.5.5 0 0 0-1 0v1a5 5 0 0 0 5 5h1a.5.5 0 0 0 0-1h-1a.5.5 0 0 0 0 1"/>'
  };
  function icon(n) { return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true">' + (I[n] || '') + '</svg>'; }
  function flag(cc) { return String(cc || '').toUpperCase().replace(/./g, function (c) { return String.fromCodePoint(127397 + c.charCodeAt(0)); }); }
  function cname(cc) { return (W[lang].countries[cc] || cc); }
  function money(n, cur) {
    cur = cur || 'BHD';
    var dec = ['BHD', 'KWD', 'OMR', 'JOD'].indexOf(cur) > -1 ? 3 : 2;
    return '<bdi>' + cur + ' ' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + '</bdi>';
  }
  function bhDate(d) { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bahrain', year: 'numeric', month: '2-digit', day: '2-digit' }).format(d); }
  function when(v) {
    if (!v) return '';
    var dt = new Date(String(v).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(v) ? '' : '+03:00'));
    var mins = Math.round((Date.now() - dt) / 60000);
    if (mins < 1) return tr('ago_now');
    if (mins < 60) return tr('ago_m', { n: mins });
    if (mins < 12 * 60) return tr('ago_h', { n: Math.round(mins / 60) });
    return dt.toLocaleString(lang === 'ar' ? 'ar-BH-u-nu-latn' : 'en-GB', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Bahrain' });
  }
  function L(item) { return lang === 'ar' && item.nameAr ? item.nameAr : item.name; }

  /* Messages from the server are in English; on the Arabic board they are translated with the admin's dictionary. */
  var dictP = null;
  function loadDict() {
    if (window.ADMIN_I18N || dictP) return;
    dictP = new Promise(function (res) { var sc = doc.createElement('script'); sc.src = '/admin/js/i18n-dict.js'; sc.onload = sc.onerror = res; doc.head.appendChild(sc); });
  }
  function srv(msg) {
    var D = window.ADMIN_I18N;
    if (lang !== 'ar' || !D || !msg) return msg;
    if (D.phrases[msg]) return D.phrases[msg];
    var nums = [], tpl = msg.replace(/\d[\d,.]*/g, function (m) { nums.push(m); return '{' + (nums.length - 1) + '}'; });
    if (nums.length && D.phrases[tpl]) return D.phrases[tpl].replace(/\{(\d+)\}/g, function (m, i) { return nums[+i]; });
    for (var i = 0; i < (D.patterns || []).length; i++) {
      var m = msg.match(new RegExp('^' + D.patterns[i][0] + '$'));
      if (m) return D.patterns[i][1].replace(/\$(\d)/g, function (x, n) { return m[+n] || ''; });
    }
    return msg;
  }
  if (lang === 'ar') loadDict();

  var toastEl, toastT;
  function toast(msg, bad) {
    if (!toastEl) { toastEl = doc.createElement('div'); toastEl.className = 'toast'; toastEl.setAttribute('role', 'status'); doc.body.appendChild(toastEl); }
    toastEl.textContent = srv(msg); toastEl.className = 'toast show' + (bad ? ' err' : '');
    clearTimeout(toastT); toastT = setTimeout(function () { toastEl.className = 'toast'; }, 3200);
  }

  function api(path, opt) {
    opt = opt || {};
    var o = { method: opt.method || (opt.body ? 'POST' : 'GET'), credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (opt.body) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(opt.body); }
    var url = '/api/admin/' + path;
    if (opt.query) {
      var q = Object.keys(opt.query).filter(function (k) { return opt.query[k] !== '' && opt.query[k] != null; })
        .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(opt.query[k]); }).join('&');
      if (q) url += '?' + q;
    }
    return fetch(url, o).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (r.status === 401 && path !== 'login') { S.me = null; showLogin(); }
        if (!r.ok || d.ok === false) { var e = new Error((d.error && d.error.message) || tr('err')); e.status = r.status; e.data = d; throw e; }
        return d;
      });
    }, function () { var e = new Error(tr('offline')); e.status = 0; throw e; });
  }
  function busy(btn, p) { if (btn) btn.classList.add('busy'); return p.then(function (v) { if (btn) btn.classList.remove('busy'); return v; }, function (e) { if (btn) btn.classList.remove('busy'); throw e; }); }

  /* ---------------- sound ----------------
     Browsers only allow sound after the person has touched the page once. Signing in counts; after a reload the board shows
     "Tap here to turn on the order sound" until the first tap anywhere. The chime is made in the browser (no file). */
  var actx = null;
  function audioOn() { return actx && actx.state === 'running'; }
  function unlockAudio() {
    try {
      var C = window.AudioContext || window.webkitAudioContext;
      if (!C) return;
      if (!actx) actx = new C();
      if (actx.state !== 'running') actx.resume().then(paintSoundBanner, function () {});
      // A silent blip – some phones only unlock after something has actually played.
      var b = actx.createBuffer(1, 1, 22050), s = actx.createBufferSource(); s.buffer = b; s.connect(actx.destination); s.start(0);
    } catch (e) { /* no audio */ }
    paintSoundBanner();
  }
  ['pointerdown', 'keydown', 'touchend'].forEach(function (ev) { doc.addEventListener(ev, unlockAudio, { passive: true }); });
  function chime() {
    if (!S.sound || !audioOn()) return false;
    var t0 = actx.currentTime + 0.02;
    [[880, 0], [1175, 0.16], [1568, 0.32], [880, 0.75], [1175, 0.91], [1568, 1.07]].forEach(function (n) {
      var o = actx.createOscillator(), g = actx.createGain();
      o.type = 'triangle'; o.frequency.value = n[0];
      g.gain.setValueAtTime(0.0001, t0 + n[1]);
      g.gain.exponentialRampToValueAtTime(0.6, t0 + n[1] + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + n[1] + 0.45);
      o.connect(g); g.connect(actx.destination); o.start(t0 + n[1]); o.stop(t0 + n[1] + 0.5);
    });
    if (navigator.vibrate) navigator.vibrate([300, 120, 300]);
    return true;
  }
  function paintSoundBanner() {
    var b = doc.getElementById('sound-banner'); if (!b) return;
    b.hidden = !S.sound || audioOn();
    var dot = doc.getElementById('sound-dot'); if (dot) dot.classList.toggle('off', !S.sound || !audioOn());
  }

  /* ---------------- keep the screen on ---------------- */
  var lock = null;
  function wake() {
    if (!S.awake || !('wakeLock' in navigator) || doc.visibilityState !== 'visible' || lock) return;
    navigator.wakeLock.request('screen').then(function (l) { lock = l; l.addEventListener('release', function () { lock = null; }); }, function () {});
  }
  function sleep() { if (lock) { lock.release().catch(function () {}); lock = null; } }
  doc.addEventListener('visibilitychange', function () { if (doc.visibilityState === 'visible') { wake(); if (S.me) pulse(); } });

  /* ---------------- notifications (service worker + Web Push) ---------------- */
  var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  var swReg = null;
  function registerSW() {
    if (!('serviceWorker' in navigator)) return Promise.resolve(null);
    return navigator.serviceWorker.register('/staff-sw.js', { scope: '/staff' }).then(function (r) { swReg = r; return r; }, function () { return null; });
  }
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function (e) {
      if (!e.data) return;
      if (e.data.type === 'new-order') pulse();
      if (e.data.type === 'open' && e.data.url) { var m = String(e.data.url).match(/order-(\d+)/); if (m) openOrder(+m[1]); }
    });
  }
  function pushState() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return Promise.resolve(isIOS && !standalone ? 'ios' : 'none');
    if (Notification.permission === 'denied') return Promise.resolve('blocked');
    return (swReg ? Promise.resolve(swReg) : registerSW()).then(function (r) {
      if (!r) return 'none';
      return r.pushManager.getSubscription().then(function (s) { return s && Notification.permission === 'granted' ? 'on' : 'off'; });
    });
  }
  function b64ToBytes(s) {
    var pad = '='.repeat((4 - s.length % 4) % 4), raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/')), out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }
  function pushOn() {
    return Notification.requestPermission().then(function (p) {
      if (p !== 'granted') throw new Error(tr('push_blocked'));
      return Promise.all([swReg ? swReg : registerSW(), api('board/push-key')]);
    }).then(function (x) {
      var reg = x[0], key = x[1].publicKey;
      return navigator.serviceWorker.ready.then(function () {
        return reg.pushManager.getSubscription().then(function (old) {
          return old || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(key) });
        });
      });
    }).then(function (sub) {
      var device = (isIOS ? 'iPhone/iPad' : /Android/.test(navigator.userAgent) ? 'Android' : /Mac/.test(navigator.platform) ? 'Mac' : /Win/.test(navigator.platform) ? 'Windows' : 'Browser');
      return api('board/push', { body: { subscription: sub.toJSON(), device: device } });
    });
  }

  /* Mobile numbers: country code + the rest. Same rules as backend/app/Support/Phone.php. */
  var PH = { BH: ['973', 8, /^[36]/, '3600 1234'], SA: ['966', 9, /^5/, '50 123 4567'], AE: ['971', 9, /^5/, '50 123 4567'], QA: ['974', 8, /^[3567]/, '3312 3456'],
    KW: ['965', 8, /^[4569]/, '5012 3456'], OM: ['968', 8, /^[79]/, '9123 4567'], EG: ['20', 10, /^1/, '10 1234 5678'], JO: ['962', 9, /^7/, '79 123 4567'],
    GB: ['44', 10, /^7/, '7400 123456'], US: ['1', 10, /^[2-9]/, '201 555 0123'] };
  var PH_NAMES = { EG: ['Egypt', 'مصر'], JO: ['Jordan', 'الأردن'], GB: ['UK', 'بريطانيا'], US: ['USA', 'أمريكا'] };
  function phName(cc) { return W[lang].countries[cc] || (PH_NAMES[cc] ? PH_NAMES[cc][lang === 'ar' ? 1 : 0] : cc); }
  function phTidy(v, cc) {
    var raw = String(v || ''), d = raw.replace(/\D/g, ''), r = PH[cc] || PH.BH;
    if (/^\s*(\+|00)/.test(raw)) {
      if (d.indexOf('00') === 0) d = d.slice(2);
      Object.keys(PH).sort(function (a, b) { return PH[b][0].length - PH[a][0].length; }).some(function (k) { if (d.indexOf(PH[k][0]) === 0) { cc = k; r = PH[k]; d = d.slice(PH[k][0].length); return true; } return false; });
    }
    d = d.replace(/^0+/, '').slice(0, r[1]);
    return { cc: cc, digits: d, ok: d.length === r[1] && r[2].test(d), full: '+' + r[0] + ' ' + d, example: '\u2066+' + r[0] + ' ' + r[3] + '\u2069' };
  }

  /* ---------------- state ---------------- */
  var ALLOWED = ['shop_staff', 'staff', 'manager', 'owner'];
  var S = {
    me: null, tab: store.get('tab', 'prepare'), range: store.get('range', 'today'), from: '', to: '', country: store.get('country', ''), q: '',
    orders: [], counts: {}, page: 1, total: 0, countries: [], since: null, seen: {}, fresh: {},
    sound: store.get('sound', true), awake: store.get('awake', true), products: null, tap: false, pending: []
  };

  /* ---------------- sign in ---------------- */
  function showLogin(msg) {
    sleep();
    root.innerHTML = '<div class="login"><form class="login-card" id="lf" novalidate>' +
      '<img class="logo" src="/assets/img/staff-icon-192.png" alt="">' +
      '<h1>' + esc(tr('board')) + '</h1><p class="sub muted">' + esc(tr('signin_sub')) + '</p>' +
      (msg ? '<div class="msg" role="alert">' + esc(msg) + '</div>' : '') +
      '<div class="field"><label for="le">' + esc(tr('email')) + '</label><input id="le" name="email" type="email" autocomplete="username" dir="ltr" required></div>' +
      '<div class="field"><label for="lp">' + esc(tr('password')) + '</label><input id="lp" name="password" type="password" autocomplete="current-password" dir="ltr" required></div>' +
      '<button class="btn btn-red btn-block btn-lg" type="submit">' + esc(tr('signin')) + '</button>' +
      '<div class="login-foot"><a href="/admin/">' + esc(tr('forgot')) + '</a>' +
      '<button type="button" class="btn-ghost" style="border:0;background:none;color:var(--blue);font-weight:700" id="lang">' + (lang === 'ar' ? 'English' : 'العربية') + '</button></div>' +
      '</form></div>';
    var f = doc.getElementById('lf');
    doc.getElementById('lang').onclick = function () { setLang(lang === 'ar' ? 'en' : 'ar'); showLogin(); };
    f.onsubmit = function (e) {
      e.preventDefault();
      unlockAudio(); // the sign-in tap also switches the order sound on
      var btn = f.querySelector('button[type=submit]');
      busy(btn, api('login', { body: { email: f.email.value.trim(), password: f.password.value } })).then(function (d) {
        start(d.admin);
      }, function (err) { showLogin(err.status === 401 || err.status === 422 ? tr('bad_login') : srv(err.message)); });
    };
    setTimeout(function () { var i = doc.getElementById('le'); if (i) i.focus(); }, 50);
  }

  function start(admin) {
    if (!admin) { showLogin(); return; }
    if (ALLOWED.indexOf(admin.role) === -1) { showLogin(tr('no_access')); return; }
    S.me = admin;
    shell();
    registerSW();
    wake();
    load(true).then(function () { pulse(); });
    if (location.hash === '#new') { history.replaceState(null, '', '/staff'); newOrder(); }
    var m = location.hash.match(/^#order-(\d+)/); if (m) openOrder(+m[1]);
  }

  /* ---------------- board ---------------- */
  var TABS = ['prepare', 'unpaid', 'prepared', 'sent', 'all'];
  var RANGES = ['today', 'yesterday', 'd7', 'd30', 'any', 'pick'];
  function rangeDates() {
    var now = new Date(), day = 864e5;
    switch (S.range) {
      case 'today': return [bhDate(now), bhDate(now)];
      case 'yesterday': return [bhDate(new Date(now - day)), bhDate(new Date(now - day))];
      case 'd7': return [bhDate(new Date(now - 6 * day)), bhDate(now)];
      case 'd30': return [bhDate(new Date(now - 29 * day)), bhDate(now)];
      case 'pick': return [S.from, S.to];
      default: return ['', ''];
    }
  }

  function shell() {
    root.innerHTML =
      '<header class="top"><div class="brand"><img src="/assets/img/staff-icon-192.png" alt=""><span>' + esc(tr('title')) + '</span></div>' +
      '<button class="iconbtn" id="b-sound" aria-label="' + esc(tr('sound')) + '">' + icon(S.sound ? 'bell' : 'bellOff') + '<span class="dot" id="sound-dot"></span></button>' +
      '<button class="iconbtn" id="b-set" aria-label="' + esc(tr('settings')) + '">' + icon('gear') + '</button></header>' +
      '<div class="banner sound" id="sound-banner" role="button" tabindex="0" hidden>' + icon('bell') + '<span class="grow">' + esc(tr('tap_sound')) + '</span></div>' +
      '<div id="new-banner"></div>' +
      '<main class="wrap">' +
      '<div class="filters">' +
      '<div class="chips" id="f-range" role="group">' + RANGES.map(function (r) { return '<button class="chip" data-range="' + r + '" aria-pressed="' + (S.range === r) + '">' + esc(tr(r)) + '</button>'; }).join('') + '</div>' +
      '<div class="dates" id="f-dates"' + (S.range === 'pick' ? '' : ' hidden') + '><label>' + esc(tr('from')) + ' <input type="date" id="f-from" value="' + esc(S.from) + '"></label>' +
      '<label>' + esc(tr('to')) + ' <input type="date" id="f-to" value="' + esc(S.to) + '"></label></div>' +
      '<div class="chips" id="f-country" role="group"></div>' +
      '<div class="search">' + icon('search') + '<input class="input" id="f-q" type="search" placeholder="' + esc(tr('search')) + '" autocomplete="off"></div>' +
      '</div>' +
      '<div class="tabs" role="tablist" id="tabs"></div>' +
      '<div class="list" id="list"></div><div class="more" id="more"></div>' +
      '</main>' +
      '<button class="btn btn-red fab" id="b-new">' + icon('plus') + esc(tr('new_order')) + '</button>';
    paintSoundBanner();
    paintTabs(); paintCountries();
    doc.getElementById('sound-banner').onclick = unlockAudio;
    doc.getElementById('b-sound').onclick = function () {
      S.sound = !S.sound; store.set('sound', S.sound); unlockAudio();
      this.innerHTML = icon(S.sound ? 'bell' : 'bellOff') + '<span class="dot" id="sound-dot"></span>'; paintSoundBanner();
      if (S.sound) chime();
      toast(tr('sound') + ': ' + (S.sound ? '✓' : '✕'));
    };
    doc.getElementById('b-set').onclick = settings;
    doc.getElementById('b-new').onclick = function () { newOrder(); };
    doc.getElementById('f-range').onclick = function (e) {
      var b = e.target.closest('[data-range]'); if (!b) return;
      S.range = b.dataset.range; store.set('range', S.range);
      [].forEach.call(this.children, function (c) { c.setAttribute('aria-pressed', c === b); });
      doc.getElementById('f-dates').hidden = S.range !== 'pick';
      if (S.range !== 'pick' || (S.from && S.to)) load(true);
    };
    ['f-from', 'f-to'].forEach(function (id) {
      doc.getElementById(id).onchange = function () { S.from = doc.getElementById('f-from').value; S.to = doc.getElementById('f-to').value; if (S.from && S.to) load(true); };
    });
    doc.getElementById('f-country').onclick = function (e) {
      var b = e.target.closest('[data-country]'); if (!b) return;
      S.country = b.dataset.country; store.set('country', S.country); paintCountries(); load(true);
    };
    var qt;
    doc.getElementById('f-q').oninput = function () { var v = this.value.trim(); clearTimeout(qt); qt = setTimeout(function () { S.q = v; load(true); }, 350); };
    doc.getElementById('tabs').onclick = function (e) {
      var b = e.target.closest('[data-tab]'); if (!b) return;
      S.tab = b.dataset.tab; store.set('tab', S.tab); paintTabs(); load(true);
    };
    var list = doc.getElementById('list');
    list.addEventListener('change', function (e) {
      var cb = e.target.closest('input[data-tick]'); if (!cb) return;
      tick(+cb.dataset.tick, cb.checked, cb);
    });
    list.addEventListener('click', function (e) {
      if (e.target.closest('.tick')) return;
      var row = e.target.closest('[data-id]'); if (row) openOrder(+row.dataset.id);
    });
    list.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || e.target.closest('.tick')) return;
      var row = e.target.closest('[data-id]'); if (row) openOrder(+row.dataset.id);
    });
  }
  function paintTabs() {
    var t = doc.getElementById('tabs'); if (!t) return;
    t.innerHTML = TABS.map(function (k) {
      var n = S.counts[k];
      return '<button class="tab" role="tab" data-tab="' + k + '" aria-selected="' + (S.tab === k) + '">' + esc(tr(k)) + (n != null ? ' <span class="n">' + n + '</span>' : '') + '</button>';
    }).join('');
  }
  function paintCountries() {
    var c = doc.getElementById('f-country'); if (!c) return;
    var list = ['BH', 'SA', 'AE', 'QA', 'KW', 'OM'].concat(S.countries.filter(function (x) { return ['BH', 'SA', 'AE', 'QA', 'KW', 'OM'].indexOf(x) === -1; }));
    c.innerHTML = '<button class="chip" data-country="" aria-pressed="' + (!S.country) + '">' + esc(tr('all_countries')) + '</button>' +
      list.map(function (cc) { return '<button class="chip" data-country="' + cc + '" aria-pressed="' + (S.country === cc) + '">' + flag(cc) + ' ' + esc(cname(cc)) + '</button>'; }).join('');
  }

  var loading = 0;
  function load(reset) {
    if (reset) S.page = 1;
    var d = rangeDates(), mine = ++loading;
    var tabMap = { prepare: 'prepare', unpaid: 'unpaid', prepared: 'prepared', sent: 'sent', all: 'all' };
    return api('orders', { query: { tab: tabMap[S.tab], q: S.q, from: d[0], to: d[1], country: S.country, page: S.page, per: 40 } }).then(function (r) {
      if (mine !== loading) return;
      S.orders = reset ? r.orders : S.orders.concat(r.orders);
      S.counts = r.counts; S.total = r.total; S.countries = r.countries || [];
      paintTabs(); paintList();
      setBadge();
    }, function (e) { if (e.status !== 401) toast(e.message, true); });
  }
  /* One or two pills: "Paid – to prepare", or "Paid" + where it is now, or why it is not paid yet. */
  function payPill(o) {
    var st = tr('status')[o.status] || o.status;
    var tone = { pending_payment: 'amber', awaiting_whatsapp: 'amber', payment_failed: 'red', paid: 'green', processing: 'blue', shipped: 'blue', delivered: 'green' }[o.status] || '';
    var paidFirst = o.paymentStatus === 'paid' && o.status !== 'paid' ? '<span class="pill green">' + esc(tr('paid')) + '</span>' : '';
    return paidFirst + '<span class="pill ' + tone + '">' + esc(st) + '</span>';
  }
  function itemsText(o) {
    return (o.lines || []).map(function (l) { return l.qty + '× ' + L(l); }).join(' · ');
  }
  function row(o) {
    var canTick = ['cancelled', 'refunded'].indexOf(o.status) === -1;
    return '<article class="ord' + (o.prepared ? ' is-done' : '') + (S.fresh[o.id] ? ' is-new' : '') + '" data-id="' + o.id + '" tabindex="0" aria-label="' + esc(tr('order') + ' ' + o.number + ' – ' + o.name) + '">' +
      '<label class="tick" title="' + esc(tr('tick_hint')) + '"><input type="checkbox" data-tick="' + o.id + '" aria-label="' + esc(tr('prepared') + ' ' + o.number) + '"' + (o.prepared ? ' checked' : '') + (canTick ? '' : ' disabled') + '></label>' +
      '<span class="numcol"><bdi>#' + esc(o.number) + '</bdi></span>' +
      '<div class="who"><span class="num"><bdi>#' + esc(o.number) + '</bdi></span><b>' + esc(o.name) + '</b></div>' +
      '<div class="items">' + esc(itemsText(o) || tr('items_n', { n: o.items })) + '</div>' +
      '<div class="where">' + flag(o.country) + ' ' + esc(cname(o.country)) + ' · ' + esc(when(o.createdAt)) + (o.prepared && o.preparedBy ? ' · ' + esc(tr('prepared_by', { n: o.preparedBy })) : '') + '</div>' +
      '<div class="side"><span class="total">' + money(o.customerTotal != null ? o.customerTotal : o.total, o.customerTotal != null ? o.currency : 'BHD') + '</span>' + payPill(o) + '</div>' +
      '</article>';
  }
  function paintList() {
    var l = doc.getElementById('list'); if (!l) return;
    l.innerHTML = S.orders.length ? S.orders.map(row).join('') : '<div class="empty">' + icon('box') + '<p>' + esc(tr('none')) + '</p></div>';
    var m = doc.getElementById('more');
    m.innerHTML = S.orders.length < S.total ? '<button class="btn" id="b-more">' + esc(tr('more')) + '</button>' : '';
    var b = doc.getElementById('b-more'); if (b) b.onclick = function () { S.page++; load(false); };
  }
  function tick(id, done, cb) {
    var o = S.orders.filter(function (x) { return x.id === id; })[0];
    api('orders/' + id + '/prepared', { body: { done: done } }).then(function (r) {
      if (o) { o.prepared = r.order.prepared; o.preparedBy = r.order.preparedBy; o.status = r.order.status; }
      var el = cb.closest('.ord'); if (el) el.classList.toggle('is-done', done);
      toast(done ? tr('prepared') + ' ✓' : tr('saved'));
      setTimeout(function () { load(true); }, 700); // it moves to the "Prepared" tab
    }, function (e) { cb.checked = !done; toast(e.message, true); });
  }
  function setBadge() {
    var n = (S.counts && S.counts.prepare) || 0;
    if ('setAppBadge' in navigator) { (n ? navigator.setAppBadge(n) : navigator.clearAppBadge()).catch(function () {}); }
  }

  /* ---------------- new-order check (every 15 seconds) ---------------- */
  var pulseT = null, remindT = null, reminders = 0;
  function pulse() {
    clearTimeout(pulseT);
    if (!S.me) return;
    api('board/pulse', { query: { since: S.since || '' } }).then(function (r) {
      var fresh = (r.new || []).filter(function (o) { return !S.seen[o.id]; });
      S.since = r.now;
      fresh.forEach(function (o) { S.seen[o.id] = 1; S.fresh[o.id] = 1; });
      if (fresh.length) {
        S.pending = S.pending.concat(fresh);
        chime(); reminders = 0; remind();
        showNew();
        load(true);
      } else if (S.counts.prepare !== r.counts.prepare || S.counts.unpaid !== r.counts.unpaid) {
        load(true);
      }
    }, function () { /* try again next time */ }).then(function () { pulseT = setTimeout(pulse, 15000); });
  }
  /* Until someone presses "Seen", the chime repeats every minute (5 times at most). */
  function remind() {
    clearTimeout(remindT);
    if (!S.pending.length || reminders >= 5) return;
    remindT = setTimeout(function () { reminders++; chime(); remind(); }, 60000);
  }
  function showNew() {
    var box = doc.getElementById('new-banner'); if (!box) return;
    if (!S.pending.length) { box.innerHTML = ''; doc.title = 'Tartebz · ' + tr('title'); return; }
    var o = S.pending[S.pending.length - 1];
    box.innerHTML = '<div class="banner neworder" role="alert">' + icon('bell') + '<span class="grow">' +
      esc(tr('new_in', { n: o.number, name: o.name })) + ' · ' + flag(o.country) + ' · <bdi>' + esc(o.total) + '</bdi>' + (S.pending.length > 1 ? ' (+' + (S.pending.length - 1) + ')' : '') +
      '</span><button class="btn btn-dark" id="b-seen">' + esc(tr('seen')) + '</button></div>';
    doc.title = '(' + S.pending.length + ') ' + tr('new_order') + ' · Tartebz';
    doc.getElementById('b-seen').onclick = function () { S.pending = []; S.fresh = {}; clearTimeout(remindT); showNew(); paintList(); };
    box.querySelector('.grow').onclick = function () { openOrder(o.id); };
  }

  /* ---------------- side sheet ---------------- */
  var sheetEl, ovEl;
  function sheet(title, body, foot, onClose) {
    closeSheet(true);
    ovEl = doc.createElement('div'); ovEl.className = 'sheet-ov';
    sheetEl = doc.createElement('aside'); sheetEl.className = 'sheet'; sheetEl.setAttribute('role', 'dialog'); sheetEl.setAttribute('aria-modal', 'true');
    sheetEl.innerHTML = '<div class="sheet-head"><h2>' + title + '</h2><button class="iconbtn" data-close aria-label="' + esc(tr('close')) + '">' + icon('close') + '</button></div>' +
      '<div class="sheet-body">' + body + '</div>' + (foot ? '<div class="sheet-foot">' + foot + '</div>' : '');
    doc.body.appendChild(ovEl); doc.body.appendChild(sheetEl);
    sheetEl._onClose = onClose;
    requestAnimationFrame(function () { ovEl.classList.add('open'); sheetEl.classList.add('open'); });
    ovEl.onclick = function () { closeSheet(); };
    sheetEl.querySelector('[data-close]').onclick = function () { closeSheet(); };
    doc.body.style.overflow = 'hidden';
    return sheetEl;
  }
  function closeSheet(now) {
    if (!sheetEl) return;
    var s = sheetEl, o = ovEl; sheetEl = ovEl = null;
    if (s._onClose) s._onClose();
    doc.body.style.overflow = '';
    if (now) { s.remove(); o.remove(); return; }
    s.classList.remove('open'); o.classList.remove('open');
    setTimeout(function () { s.remove(); o.remove(); }, 260);
  }
  doc.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheetEl) closeSheet(); });

  /* ---------------- one order ---------------- */
  function openOrder(id) {
    delete S.fresh[id];
    S.pending = S.pending.filter(function (o) { return o.id !== id; }); showNew();
    var el = sheet(esc(tr('order')) + ' <bdi>…</bdi>', '<p class="muted">…</p>');
    api('orders/' + id).then(function (r) { if (sheetEl === el) orderView(el, r); }, function (e) { toast(e.message, true); closeSheet(); });
  }
  function orderView(el, r) {
    var o = r.order, paid = o.paymentStatus === 'paid', closed = ['cancelled', 'refunded'].indexOf(o.status) > -1;
    var dec = ['BHD', 'KWD', 'OMR'].indexOf(o.chargeCurrency) > -1 ? 3 : 2;
    var waNum = String(o.phone || '').replace(/\D/g, '');
    el.querySelector('.sheet-head h2').innerHTML = esc(tr('order')) + ' <bdi>#' + esc(o.number) + '</bdi>';
    var steps = paid && !closed ? [['shipped', tr('shipped')], ['delivered', tr('delivered')]].filter(function (s) { return s[0] !== o.status && !(s[0] === 'shipped' && o.status === 'delivered'); }) : [];
    var body =
      '<div class="card"><div class="btns" style="margin-bottom:8px">' + payPill(o) + (o.testMode ? '<span class="pill amber">TEST</span>' : '') + '</div>' +
      (!closed ? '<label class="set-row" style="border:0;padding:4px 0"><span class="grow"><b>' + esc(tr('prepared')) + '</b>' + (o.prepared && o.preparedBy ? '<br><span class="muted small">' + esc(tr('prepared_by', { n: o.preparedBy })) + '</span>' : '') + '</span><input type="checkbox" class="switch" id="o-prep"' + (o.prepared ? ' checked' : '') + '></label>' : '') +
      (o.createdBy ? '<p class="muted small" style="margin:6px 0 0">' + esc(tr('created_by', { n: o.createdBy })) + '</p>' : '') + '</div>' +
      '<div class="card"><h3>' + esc(tr('customer')) + '</h3><p style="margin:0 0 8px"><b>' + esc((o.firstName + ' ' + o.lastName).trim()) + '</b><br><bdi>' + esc(o.phone) + '</bdi>' + (o.email ? '<br><bdi>' + esc(o.email) + '</bdi>' : '') + '</p>' +
      '<p class="muted" style="margin:0 0 10px">' + esc([o.address1, o.address2].filter(Boolean).join(', ')) + '<br>' + esc([o.city, o.state].filter(Boolean).join(', ')) + ' · ' + flag(o.country) + ' ' + esc(cname(o.country)) + '</p>' +
      (o.notes ? '<p class="note">' + esc(o.notes) + '</p>' : '') +
      '<div class="btns"><a class="btn" href="tel:+' + waNum + '">' + icon('phone') + esc(tr('call')) + '</a><a class="btn btn-green" target="_blank" rel="noopener" href="https://wa.me/' + waNum + '">' + icon('wa') + esc(tr('whatsapp')) + '</a></div></div>' +
      '<div class="card"><h3>' + esc(tr('products')) + '</h3><ul class="lines">' + o.items.map(function (i) {
        return '<li>' + (i.image ? '<img src="' + esc(i.image) + '" alt="">' : '') + '<span class="grow"><b>' + esc(L(i)) + '</b><br><span class="muted small">' + i.qty + ' × ' + money(i.price) + '</span></span><b>' + money(i.lineTotal) + '</b></li>';
      }).join('') + '</ul>' +
      '<div class="sum"><div><span>' + esc(tr('subtotal')) + '</span>' + money(o.subtotal) + '</div><div><span>' + esc(tr('delivery')) + ' <span class="muted small">(<bdi>' + (o.weightGrams / 1000).toFixed(2) + ' kg</bdi>)</span></span>' + money(o.shipping) + '</div>' +
      '<div class="grand"><span>' + esc(tr('charged')) + '</span><bdi>' + esc(o.chargeCurrency) + ' ' + Number(o.chargeAmount).toFixed(dec) + '</bdi></div></div></div>' +
      (!paid && !closed ? '<div class="card"><h3>' + icon('link') + esc(tr('payment')) + '</h3><p class="muted small" style="margin:0 0 10px">' + esc(tr('link_hint')) + '</p>' +
        '<div class="btns"><button class="btn btn-green" id="o-link-wa">' + icon('wa') + esc(tr('send_wa')) + '</button>' + (o.email ? '<button class="btn" id="o-link-mail">' + icon('mail') + esc(tr('send_email')) + '</button>' : '') +
        '<button class="btn" id="o-paid">' + icon('cash') + esc(tr('mark_paid')) + '</button></div></div>' : '') +
      '<div class="card"><h3>' + icon('truck') + 'SMSA</h3>' + shipBox(o, r.smsa) + '</div>' +
      '<div class="card"><h3>' + esc(tr('history')) + '</h3><form id="o-note" class="btns" style="margin-bottom:10px"><input class="input" name="note" placeholder="' + esc(tr('add_note')) + '" style="flex:1 1 200px"><button class="btn">' + esc(tr('save')) + '</button></form>' +
      '<ul class="hist">' + o.events.slice(0, 30).map(function (e) { return '<li><span class="muted small">' + esc(when(e.at)) + (e.by ? ' · ' + esc(e.by) : '') + '</span><br>' + esc(srv(e.message)) + '</li>'; }).join('') + '</ul></div>';
    el.querySelector('.sheet-body').innerHTML = body;
    var foot = el.querySelector('.sheet-foot');
    if (!foot) { foot = doc.createElement('div'); foot.className = 'sheet-foot'; el.appendChild(foot); }
    foot.innerHTML = '<div class="btns"><a class="btn btn-dark" target="_blank" rel="noopener" href="/api/admin/orders/' + o.id + '/invoice">' + icon('printer') + esc(tr('invoice')) + '</a>' +
      steps.map(function (s) { return '<button class="btn" data-step="' + s[0] + '">' + icon('check') + esc(s[1]) + '</button>'; }).join('') + '</div>';
    var reload = function () { api('orders/' + o.id).then(function (x) { if (sheetEl === el) orderView(el, x); load(true); }); };
    var prep = el.querySelector('#o-prep');
    if (prep) prep.onchange = function () { api('orders/' + o.id + '/prepared', { body: { done: prep.checked } }).then(function () { toast(tr('saved')); reload(); }, function (e) { prep.checked = !prep.checked; toast(e.message, true); }); };
    el.querySelectorAll('[data-step]').forEach(function (b) {
      b.onclick = function () { busy(b, api('orders/' + o.id + '/status', { body: { status: b.dataset.step } })).then(function () { toast(tr('saved')); reload(); }, function (e) { toast(e.message, true); }); };
    });
    var lw = el.querySelector('#o-link-wa');
    if (lw) lw.onclick = function () {
      var win = window.open('', '_blank'); // opened now (inside the tap) so the phone does not block it
      busy(lw, api('orders/' + o.id + '/payment-link', { body: { via: 'whatsapp' } })).then(function (x) {
        if (win) win.location.href = x.whatsappUrl; else location.href = x.whatsappUrl;
        reload();
      }, function (e) { if (win) win.close(); toast(e.message, true); });
    };
    var lm = el.querySelector('#o-link-mail');
    if (lm) lm.onclick = function () { busy(lm, api('orders/' + o.id + '/payment-link', { body: { via: 'email' } })).then(function () { toast(tr('saved') + ' ✓'); reload(); }, function (e) { toast(e.message, true); }); };
    var mp = el.querySelector('#o-paid');
    if (mp) mp.onclick = function () {
      var how = window.prompt(tr('mark_paid_q'), tr('benefit'));
      if (!how) return;
      busy(mp, api('orders/' + o.id + '/paid', { body: { how: how } })).then(function () { toast(tr('marked_paid')); reload(); }, function (e) { toast(e.message, true); });
    };
    var nf = el.querySelector('#o-note');
    nf.onsubmit = function (e) {
      e.preventDefault(); var v = nf.note.value.trim(); if (!v) return;
      api('orders/' + o.id + '/note', { body: { note: v } }).then(function () { toast(tr('saved')); reload(); }, function (er) { toast(er.message, true); });
    };
    bindShip(el, o, r.smsa, reload);
  }
  function shipBox(o, smsa) {
    if (o.awb) {
      return '<p style="margin:0 0 8px"><b><bdi>' + esc(o.awb) + '</bdi></b>' + (o.trackingStatus ? ' · <span class="muted">' + esc(o.trackingStatus) + '</span>' : '') + '</p>' +
        '<div class="btns">' + (o.hasLabel ? '<a class="btn btn-dark" target="_blank" rel="noopener" href="/api/admin/orders/' + o.id + '/label">' + icon('printer') + esc(tr('label')) + '</a>' : '') +
        '<button class="btn" id="o-track">' + icon('truck') + esc(tr('track')) + '</button></div>';
    }
    if (o.paymentStatus !== 'paid' || ['cancelled', 'refunded'].indexOf(o.status) > -1) return '<p class="muted small" style="margin:0">' + esc(tr('not_paid')) + '</p>';
    if (!smsa.enabled) return '<p class="muted small" style="margin:0">' + esc(tr('smsa_off')) + '</p>';
    return (smsa.problems.length ? '<p class="note">' + smsa.problems.map(function (p) { return esc(srv(p)); }).join('<br>') + '</p>' : '') +
      '<div class="row2"><div class="field"><label for="o-boxes">' + esc(tr('boxes')) + '</label><input id="o-boxes" type="number" min="1" max="25" value="1" dir="ltr"></div>' +
      '<div class="field"><label for="o-kg">' + esc(tr('weight_kg')) + '</label><input id="o-kg" type="number" min="0.1" step="0.1" value="' + (Math.max(100, o.weightGrams) / 1000).toFixed(1) + '" dir="ltr"></div></div>' +
      '<button class="btn btn-red btn-block" id="o-ship"' + (smsa.problems.length ? ' disabled' : '') + '>' + icon('truck') + esc(tr('ship')) + '</button>';
  }
  function bindShip(el, o, smsa, reload) {
    var sh = el.querySelector('#o-ship');
    if (sh) sh.onclick = function () {
      if (smsa.mode === 'live' && !window.confirm(tr('live_ship'))) return;
      busy(sh, api('orders/' + o.id + '/ship', { body: { parcels: +el.querySelector('#o-boxes').value || 1, weightKg: +el.querySelector('#o-kg').value || null } }))
        .then(function (x) { toast('SMSA ✓'); if (x.order && x.order.hasLabel) window.open('/api/admin/orders/' + o.id + '/label', '_blank'); reload(); }, function (e) { toast(e.message, true); });
    };
    var tk = el.querySelector('#o-track');
    if (tk) tk.onclick = function () { busy(tk, api('orders/' + o.id + '/track', { body: {} })).then(function () { toast(tr('saved')); reload(); }, function (e) { toast(e.message, true); }); };
  }

  /* ---------------- new order ---------------- */
  function newOrder() {
    var N = store.get('draft', null) || { name: '', phone: '', email: '', country: 'BH', dial: '', city: '', address1: '', address2: '', notes: '', lang: 'ar', items: {}, payment: '', how: '' };
    var quote = null, qT = null, P = S.products, search = '';
    var el = sheet(esc(tr('new_order')), '<p class="muted">…</p>',
      '<div class="sum" id="n-sum" style="margin:0 0 10px"></div><button class="btn btn-red btn-block btn-lg" id="n-create">' + icon('check') + esc(tr('create')) + '</button>',
      function () { store.set('draft', N.name || Object.keys(N.items).length ? N : null); });
    (P ? Promise.resolve({ products: P, countries: S.boardCountries, tap: S.tap }) : api('board/products')).then(function (r) {
      S.products = P = r.products; S.boardCountries = r.countries; S.tap = r.tap;
      if (sheetEl !== el) return;
      draw();
    }, function (e) { toast(e.message, true); closeSheet(); });

    function dialCC() { return N.dial || (PH[N.country] ? N.country : 'BH'); }
    function draw() {
      var b = el.querySelector('.sheet-body');
      b.innerHTML =
        '<form id="n-form" novalidate>' +
        '<div class="card"><h3>' + esc(tr('customer')) + '</h3>' +
        fld('name', tr('name'), 'text', 'name') +
        '<div class="field" data-f="phone"><label for="n-phone">' + esc(tr('phone')) + '</label><div class="phone"><select class="cc" id="n-dial" name="dial" aria-label="' + esc(tr('dial')) + '">' +
        Object.keys(PH).map(function (k) { return '<option value="' + k + '"' + (k === dialCC() ? ' selected' : '') + '>' + flag(k) + ' +' + PH[k][0] + ' ' + esc(phName(k)) + '</option>'; }).join('') +
        '</select><input id="n-phone" name="phone" type="tel" inputmode="numeric" autocomplete="off" placeholder="' + esc(PH[dialCC()][3]) + '" value="' + esc(N.phone) + '"></div><span class="err"></span></div>' +
        '<div class="row2"><div class="field" data-f="country"><label for="n-country">' + esc(tr('country')) + '</label><select id="n-country" name="country">' +
        (S.boardCountries || []).map(function (c) { return '<option value="' + c.code + '"' + (c.code === N.country ? ' selected' : '') + '>' + flag(c.code) + ' ' + esc(cname(c.code)) + '</option>'; }).join('') + '</select><span class="err"></span></div>' +
        fld('city', tr('city'), 'text', 'address-level2') + '</div>' +
        fld('address1', tr('address'), 'text', 'off') + fld('address2', tr('address2'), 'text', 'off') +
        '<div class="row2">' + fld('email', tr('email_opt'), 'email', 'off', 'ltr') +
        '<div class="field"><label for="n-lang">' + esc(tr('cust_lang')) + '</label><select id="n-lang" name="lang"><option value="ar"' + (N.lang === 'ar' ? ' selected' : '') + '>العربية</option><option value="en"' + (N.lang === 'en' ? ' selected' : '') + '>English</option></select></div></div>' +
        '<div class="field"><label for="n-notes">' + esc(tr('notes')) + '</label><textarea id="n-notes" name="notes">' + esc(N.notes) + '</textarea></div></div>' +
        '<div class="card" data-f="items"><h3>' + esc(tr('products')) + '</h3><div class="search" style="margin-bottom:10px">' + icon('search') + '<input class="input" id="n-find" type="search" placeholder="' + esc(tr('find')) + '"></div>' +
        '<div class="pgrid" id="n-products"></div><p class="err" id="n-items-err" style="color:var(--red-d);font-weight:600"></p></div>' +
        '<div class="card" data-f="payment"><h3>' + esc(tr('payment')) + '</h3><div class="pay-opts">' +
        payOpt('link', tr('pay_link'), S.tap ? tr('pay_link_hint') : tr('tap_off'), !S.tap) + payOpt('paid', tr('pay_paid'), tr('pay_paid_hint')) + payOpt('later', tr('pay_later'), tr('pay_later_hint')) +
        '</div><div class="field" id="n-how" data-f="paid_how" style="margin-top:10px"' + (N.payment === 'paid' ? '' : ' hidden') + '><label for="n-howi">' + esc(tr('how_paid')) + '</label>' +
        '<input id="n-howi" name="how" value="' + esc(N.how) + '"><div class="quick"><button type="button" class="chip" data-how="' + esc(tr('benefit')) + '">' + esc(tr('benefit')) + '</button><button type="button" class="chip" data-how="' + esc(tr('cash')) + '">' + esc(tr('cash')) + '</button></div><span class="err"></span></div>' +
        '<p class="err" id="n-pay-err" style="color:var(--red-d);font-weight:600"></p></div>' +
        '</form>';
      paintProducts();
      var f = b.querySelector('#n-form');
      f.addEventListener('input', function (e) {
        var n = e.target.name; if (!n || n === 'pay') return;
        if (n === 'phone') {
          var tp = phTidy(e.target.value, dialCC());
          if (tp.cc !== dialCC()) { N.dial = tp.cc; el.querySelector('#n-dial').value = tp.cc; }
          e.target.value = tp.digits;
        }
        N[n] = e.target.value;
        var fl = e.target.closest('.field'); if (fl) { fl.classList.remove('bad'); var er = fl.querySelector('.err'); if (er) er.textContent = ''; }
      });
      f.addEventListener('change', function (e) {
        if (e.target.name === 'pay') { N.payment = e.target.value; el.querySelector('#n-how').hidden = N.payment !== 'paid'; el.querySelector('#n-pay-err').textContent = ''; }
        if (e.target.name === 'country') {
          N.country = e.target.value;
          if (!N.dialTouched && PH[N.country]) { N.dial = N.country; el.querySelector('#n-dial').value = N.country; el.querySelector('#n-phone').placeholder = PH[N.country][3]; }
          requote();
        }
        if (e.target.name === 'dial') { N.dial = e.target.value; N.dialTouched = true; el.querySelector('#n-phone').placeholder = PH[N.dial][3]; }
      });
      b.querySelector('#n-phone').addEventListener('blur', function () {
        var tp = phTidy(this.value, dialCC()), fl = this.closest('.field');
        if (this.value && !tp.ok) { fl.classList.add('bad'); fl.querySelector('.err').textContent = tr('bad_phone', { c: phName(tp.cc), e: tp.example }); }
      });
      b.querySelectorAll('[data-how]').forEach(function (c) { c.onclick = function () { N.how = c.dataset.how; el.querySelector('#n-howi').value = N.how; }; });
      b.querySelector('#n-find').oninput = function () { search = this.value.trim().toLowerCase(); paintProducts(); };
      b.querySelector('#n-products').onclick = function (e) {
        var btn = e.target.closest('[data-step]'); if (!btn) return;
        var id = +btn.dataset.id, p = P.filter(function (x) { return x.id === id; })[0];
        var q = (N.items[id] || 0) + (+btn.dataset.step);
        if (p && p.free != null) q = Math.min(q, p.free);
        q = Math.max(0, Math.min(99, q));
        if (q) N.items[id] = q; else delete N.items[id];
        el.querySelector('#n-items-err').textContent = '';
        paintProducts(); requote();
      };
      el.querySelector('#n-create').onclick = create;
      requote();
    }
    function fld(n, label, type, ac, dir) {
      return '<div class="field" data-f="' + n + '"><label for="n-' + n + '">' + esc(label) + '</label><input id="n-' + n + '" name="' + n + '" type="' + type + '" autocomplete="' + ac + '"' + (dir ? ' dir="' + dir + '"' : '') + ' value="' + esc(N[n]) + '"><span class="err"></span></div>';
    }
    function payOpt(v, title, hint, off) {
      return '<label class="pay-opt' + (off ? ' disabled' : '') + '"><input type="radio" name="pay" value="' + v + '"' + (N.payment === v ? ' checked' : '') + (off ? ' disabled' : '') + '><span><b>' + esc(title) + '</b><br><span class="muted small">' + esc(hint) + '</span></span></label>';
    }
    function paintProducts() {
      var box = el.querySelector('#n-products'); if (!box) return;
      var list = P.filter(function (p) { return !search || (p.name + ' ' + p.nameAr).toLowerCase().indexOf(search) > -1; });
      box.innerHTML = list.map(function (p) {
        var q = N.items[p.id] || 0, out = !p.available || p.free === 0;
        return '<div class="pcard' + (q ? ' on' : '') + (out && !q ? ' off' : '') + '">' + (p.image ? '<img src="' + esc(p.image) + '" alt="" loading="lazy">' : '<span style="aspect-ratio:1;background:var(--grey-s);border-radius:10px"></span>') +
          '<b>' + esc(L(p)) + '</b><span class="price">' + money(p.price) + '</span><span class="muted small">' + (out ? esc(tr('sold_out')) : p.free != null ? esc(tr('left', { n: p.free })) : '') + '</span>' +
          (q ? '<div class="stepper"><button type="button" data-step="-1" data-id="' + p.id + '" aria-label="−">−</button><output>' + q + '</output><button type="button" data-step="1" data-id="' + p.id + '" aria-label="+"' + (p.free != null && q >= p.free ? ' disabled' : '') + '>+</button></div>'
            : '<button type="button" class="btn" data-step="1" data-id="' + p.id + '"' + (out ? ' disabled' : '') + '>' + icon('plus') + esc(tr('add')) + '</button>') + '</div>';
      }).join('');
    }
    function items() { return Object.keys(N.items).map(function (id) { return { id: +id, qty: N.items[id] }; }); }
    function requote() {
      clearTimeout(qT);
      var box = el.querySelector('#n-sum');
      if (!items().length) { quote = null; box.innerHTML = '<div class="muted"><span>' + esc(tr('no_items')) + '</span></div>'; return; }
      qT = setTimeout(function () {
        api('board/quote', { body: { items: items(), country: N.country } }).then(function (r) {
          quote = r.quote; if (sheetEl !== el) return;
          var n = quote.lines.reduce(function (s, l) { return s + l.qty; }, 0);
          box.innerHTML = '<div><span>' + esc(tr('items')) + ' · ' + esc(tr('weight')) + '</span><span>' + n + ' · <bdi>' + (quote.weightGrams / 1000).toFixed(2) + ' kg</bdi></span></div>' +
            '<div><span>' + esc(tr('subtotal')) + '</span>' + money(quote.subtotal) + '</div>' +
            '<div><span>' + esc(tr('delivery')) + ' ' + flag(N.country) + '</span>' + money(quote.shipping) + '</div>' +
            '<div class="grand"><span>' + esc(tr('total')) + '</span>' + money(quote.total) + '</div>' +
            (quote.chargeCurrency !== 'BHD' ? '<div class="muted small"><span>' + esc(tr('charged')) + '</span>' + money(quote.chargeAmount, quote.chargeCurrency) + '</div>' : '');
        }, function (e) { box.innerHTML = '<div class="muted">' + esc(e.message) + '</div>'; });
      }, 250);
    }
    function create() {
      var f = el.querySelector('#n-form'); if (!f) return;
      el.querySelectorAll('.field.bad').forEach(function (x) { x.classList.remove('bad'); });
      var parts = N.name.trim().split(/\s+/);
      var body = {
        first_name: parts.shift() || '', last_name: parts.join(' '), phone: N.phone ? phTidy(N.phone, dialCC()).full : '', email: N.email, country: N.country, city: N.city,
        address1: N.address1, address2: N.address2, notes: N.notes, lang: N.lang, items: items(), payment: N.payment, paid_how: N.how,
        expected_total: quote ? quote.total : -1
      };
      if (!body.items.length) { el.querySelector('#n-items-err').textContent = tr('no_items'); el.querySelector('[data-f=items]').scrollIntoView({ behavior: 'smooth' }); return; }
      var btn = el.querySelector('#n-create');
      busy(btn, api('board/orders', { body: body })).then(function (r) {
        N = { name: '', phone: '', email: '', country: N.country, dial: '', city: '', address1: '', address2: '', notes: '', lang: N.lang, items: {}, payment: '', how: '' };
        store.set('draft', null);
        S.products = null; // stock changed
        done(r);
        load(true);
      }, function (e) {
        var d = e.data || {};
        if (d.error && d.error.code === 'changed') { toast(tr('changed'), true); S.products = null; api('board/products').then(function (x) { P = S.products = x.products; paintProducts(); requote(); }); return; }
        var fields = (d.error && d.error.fields) || {}, map = { first_name: 'name' }, first = null;
        Object.keys(fields).forEach(function (k) {
          var key = map[k] || k;
          fields[k] = srv(fields[k]);
          if (key === 'items') { el.querySelector('#n-items-err').textContent = fields[k]; first = first || el.querySelector('[data-f=items]'); return; }
          if (key === 'payment') { el.querySelector('#n-pay-err').textContent = fields[k]; first = first || el.querySelector('[data-f=payment]'); return; }
          var fl = el.querySelector('[data-f="' + key + '"]');
          if (fl) { fl.classList.add('bad'); var er = fl.querySelector('.err'); if (er) er.textContent = fields[k]; first = first || fl; }
        });
        if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' }); else toast(e.message, true);
      });
    }
    function done(r) {
      var o = r.order;
      el.querySelector('.sheet-head h2').textContent = tr('created', { n: o.number });
      el.querySelector('.sheet-body').innerHTML = '<div class="card done-card"><div class="big">' + icon('check') + '</div><h2 style="margin:0 0 4px">' + esc(tr('created', { n: o.number })) + '</h2>' +
        '<p class="muted" style="margin:0 0 16px">' + esc(o.name) + ' · ' + money(o.chargeAmount, o.chargeCurrency) + '</p>' +
        (r.payUrl ? '<div class="btns" style="flex-direction:column"><a class="btn btn-green btn-lg" target="_blank" rel="noopener" href="' + esc(r.whatsappUrl) + '">' + icon('wa') + esc(tr('send_wa')) + '</a>' +
          (o.email ? '<button class="btn" id="d-mail">' + icon('mail') + esc(tr('send_email')) + '</button>' : '') +
          '<button class="btn" id="d-copy">' + icon('copy') + esc(tr('copy')) + '</button></div>' : '') +
        '</div>';
      el.querySelector('.sheet-foot').innerHTML = '<div class="btns"><a class="btn btn-dark" target="_blank" rel="noopener" href="/api/admin/orders/' + o.id + '/invoice">' + icon('printer') + esc(tr('invoice')) + '</a>' +
        '<button class="btn" id="d-open">' + esc(tr('open')) + '</button><button class="btn btn-red" id="d-new">' + icon('plus') + esc(tr('another')) + '</button></div>';
      var dm = el.querySelector('#d-mail');
      if (dm) dm.onclick = function () { busy(dm, api('orders/' + o.id + '/payment-link', { body: { via: 'email' } })).then(function () { toast(tr('saved') + ' ✓'); }, function (e) { toast(e.message, true); }); };
      var dc = el.querySelector('#d-copy');
      if (dc) dc.onclick = function () {
        (navigator.clipboard ? navigator.clipboard.writeText(r.payUrl) : Promise.reject()).then(function () { toast(tr('copied')); }, function () { window.prompt(tr('copy'), r.payUrl); });
      };
      var wa = el.querySelector('a.btn-green');
      if (wa) wa.addEventListener('click', function () { api('orders/' + o.id + '/note', { body: { note: 'Payment link sent on WhatsApp.' } }).catch(function () {}); });
      el.querySelector('#d-open').onclick = function () { openOrder(o.id); };
      el.querySelector('#d-new').onclick = function () { newOrder(); };
    }
  }

  /* ---------------- settings ---------------- */
  function settings() {
    var el = sheet(esc(tr('settings')),
      '<div class="card">' +
      '<div class="set-row"><span class="grow"><b>' + esc(tr('sound')) + '</b><br><span class="muted small">' + esc(tr('sound_hint')) + '</span></span><button class="btn" id="s-test">' + esc(tr('test_sound')) + '</button><input type="checkbox" class="switch" id="s-sound"' + (S.sound ? ' checked' : '') + ' aria-label="' + esc(tr('sound')) + '"></div>' +
      ('wakeLock' in navigator ? '<div class="set-row"><span class="grow"><b>' + esc(tr('awake')) + '</b><br><span class="muted small">' + esc(tr('awake_hint')) + '</span></span><input type="checkbox" class="switch" id="s-awake"' + (S.awake ? ' checked' : '') + ' aria-label="' + esc(tr('awake')) + '"></div>' : '') +
      '<div class="set-row"><span class="grow"><b>' + esc(tr('push')) + '</b><br><span class="muted small">' + esc(tr('push_hint')) + '</span><span id="s-push-msg"></span></span><span id="s-push-btn"></span></div>' +
      '<div class="set-row"><span class="grow"><b>' + esc(tr('language')) + '</b></span><button class="btn" id="s-lang">' + (lang === 'ar' ? 'English' : 'العربية') + '</button></div>' +
      '</div>' +
      '<div class="card"><p class="muted small" style="margin:0 0 8px">' + esc(tr('signed_in_as')) + ' <b>' + esc(S.me.name) + '</b> · <bdi>' + esc(S.me.email) + '</bdi></p>' +
      '<div class="btns"><a class="btn" href="/">' + esc(tr('shop')) + '</a><button class="btn" id="s-out">' + esc(tr('signout')) + '</button></div></div>');
    el.querySelector('#s-test').onclick = function () { unlockAudio(); setTimeout(function () { if (!chime()) toast(tr('tap_sound'), true); }, 80); };
    el.querySelector('#s-sound').onchange = function () { S.sound = this.checked; store.set('sound', S.sound); unlockAudio(); shellIcons(); paintSoundBanner(); };
    var aw = el.querySelector('#s-awake');
    if (aw) aw.onchange = function () { S.awake = this.checked; store.set('awake', S.awake); if (S.awake) wake(); else sleep(); };
    el.querySelector('#s-lang').onclick = function () { setLang(lang === 'ar' ? 'en' : 'ar'); closeSheet(true); shell(); paintList(); paintTabs(); showNew(); settings(); };
    el.querySelector('#s-out').onclick = function () {
      api('logout', { body: {} }).catch(function () {}).then(function () { S.me = null; clearTimeout(pulseT); closeSheet(true); showLogin(); });
    };
    paintPush(el);
  }
  function shellIcons() { var b = doc.getElementById('b-sound'); if (b) b.innerHTML = icon(S.sound ? 'bell' : 'bellOff') + '<span class="dot" id="sound-dot"></span>'; }
  function paintPush(el) {
    var msg = el.querySelector('#s-push-msg'), btn = el.querySelector('#s-push-btn');
    pushState().then(function (st) {
      msg.innerHTML = st === 'on' ? '<br><span class="pill green">' + esc(tr('push_ready')) + '</span>'
        : st === 'blocked' ? '<br><span class="note" style="display:block;margin-top:6px">' + esc(tr('push_blocked')) + '</span>'
          : st === 'ios' ? '<br><span class="note" style="display:block;margin-top:6px">' + esc(tr('push_ios')) + '</span>'
            : st === 'none' ? '<br><span class="muted small">' + esc(tr('push_none')) + '</span>' : '';
      btn.innerHTML = st === 'off' ? '<button class="btn btn-red" id="s-push">' + esc(tr('push_on')) + '</button>' : st === 'on' ? '<button class="btn" id="s-push-test">' + esc(tr('push_test')) + '</button>' : '';
      var on = el.querySelector('#s-push');
      if (on) on.onclick = function () { busy(on, pushOn()).then(function () { toast(tr('push_ready')); paintPush(el); }, function (e) { toast(e.message, true); paintPush(el); }); };
      var tst = el.querySelector('#s-push-test');
      if (tst) tst.onclick = function () { busy(tst, api('board/push/test', { body: {} })).then(function (r) { toast(r.sent ? tr('push_sent') : tr('err'), !r.sent); }, function (e) { toast(e.message, true); }); };
    });
  }

  /* ---------------- go ---------------- */
  api('me').then(function (d) { start(d.admin); }, function () { showLogin(); });
})();
