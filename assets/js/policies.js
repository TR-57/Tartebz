/* Tartebz — legal pages: Terms and Conditions, Refund Policy, Privacy Policy, Delivery and Pricing.
   The shop's name, CR number, address, email and phone come from Admin › Settings (TZ.store); the delivery table is built
   from the live delivery rates (TZ.shipping), so the pages always match what checkout charges.
   {name} {cr} {email} {phone} {address} are filled in. Keep Arabic and English saying the same thing. */
(function () {
  'use strict';
  var TZ = window.TZ;

  var P = {
    terms: {
      updated: '2026-11-09',
      ar: [
        ['من نحن', ['هذا الموقع تديره {name}، سجل تجاري رقم {cr}، {address}، مملكة البحرين. للتواصل: {email} أو {phone}.',
          'باستخدامك الموقع أو طلبك منه فإنك توافق على هذه الشروط. إذا لم توافق عليها فالرجاء عدم استخدام الموقع.']],
        ['المنتجات', ['نبيع حلويات ومنتجات غذائية مغلّفة. نحرص أن تكون الصور والأوصاف والأوزان دقيقة، وقد يختلف شكل التغليف قليلًا عن الصورة.',
          'المكوّنات ومسببات الحساسية مذكورة على العبوة. إذا كانت عندك حساسية من أي مكوّن فاقرأ العبوة أو تواصل معنا قبل الطلب.',
          'كل المنتجات حسب توفر المخزون. إذا نفد منتج بعد طلبك نتواصل معك ونرجع لك ثمنه كاملًا.']],
        ['الأسعار والدفع', ['الأسعار بالدينار البحريني (د.ب). الأسعار بعملات دول الخليج الأخرى تقريبية للعرض فقط، ويظهر المبلغ الذي ستدفعه بالضبط قبل الدفع.',
          'سعر التوصيل يُحسب حسب الدولة وطريقة التوصيل ووزن الطلب، ويظهر في السلة وصفحة الدفع قبل تأكيد الطلب (انظر صفحة «التوصيل والأسعار»).',
          'الدفع الإلكتروني يتم عبر بوابة Tap Payments الآمنة (البطاقات، بنفت باي، Apple Pay). لا نحفظ بيانات بطاقتك على موقعنا أبدًا.',
          'يمكن أيضًا الطلب عبر واتساب والدفع بالطريقة التي نتفق عليها معك. لا يُشحن الطلب قبل استلام المبلغ.']],
        ['الطلب وقبوله', ['يصلك تأكيد بالبريد الإلكتروني بعد الدفع. نحتفظ بحق رفض أو إلغاء أي طلب (مثل خطأ واضح في السعر أو نفاد المخزون أو اشتباه احتيال)، وفي هذه الحالة نرجع المبلغ كاملًا.']],
        ['التوصيل', ['داخل البحرين يوصل فريقنا الطلبات، ولدول الخليج نشحن مع سمسا إكسبريس. مدة التوصيل بأيام العمل (الجمعة والسبت عطلة)، والطلبات بعد الساعة 2 ظهرًا بتوقيت البحرين تُحسب من يوم العمل التالي. المدد تقديرية.',
          'للشحنات خارج البحرين قد تُفرض ضريبة استيراد أو رسوم جمركية في بلد الاستلام، ويدفعها المستلم لسمسا عند التسليم ما لم يُذكر غير ذلك.',
          'تأكد من صحة العنوان ورقم الهاتف؛ لا نتحمّل التأخير أو الإرجاع بسبب عنوان أو رقم غير صحيح.']],
        ['الإرجاع والاسترداد', ['راجع «سياسة الاسترداد».']],
        ['المسؤولية', ['مسؤوليتنا عن أي طلب لا تتجاوز قيمة ذلك الطلب. لا نتحمّل الأضرار الناتجة عن سوء التخزين بعد الاستلام أو عن تجاهل تحذيرات الحساسية المذكورة على العبوة.']],
        ['الملكية الفكرية', ['اسم ترتيبز وشعارها والصور والنصوص في الموقع ملك لنا، ولا يجوز نسخها أو استخدامها دون إذن كتابي.']],
        ['القانون', ['تخضع هذه الشروط لقوانين مملكة البحرين، وتختص محاكم البحرين بأي نزاع. قد نعدّل الشروط، ويسري التعديل على الطلبات بعد نشره.']]
      ],
      en: [
        ['Who we are', ['This website is run by {name}, Commercial Registration {cr}, {address}, Kingdom of Bahrain. Contact: {email} or {phone}.',
          'By using this website or ordering from it you agree to these terms. If you do not agree, please do not use the website.']],
        ['Products', ['We sell sweets and packaged food products. We take care that photos, descriptions and weights are accurate; packaging may look slightly different from the photo.',
          'Ingredients and allergens are printed on the pack. If you have an allergy, read the pack or contact us before ordering.',
          'All products are subject to stock. If a product runs out after you order, we contact you and refund it in full.']],
        ['Prices and payment', ['Prices are in Bahraini dinars (BHD). Prices shown in other Gulf currencies are approximate, for display; the exact amount you pay is shown before you pay.',
          'Delivery is priced by country, delivery method and parcel weight, and is shown in the cart and at checkout before you confirm (see “Delivery and pricing”).',
          'Online payments go through Tap Payments’ secure gateway (cards, BenefitPay, Apple Pay). We never store your card details on our website.',
          'You can also order on WhatsApp and pay as agreed with us. An order is not shipped before payment is received.']],
        ['Your order', ['You receive an email confirmation after payment. We may refuse or cancel an order (for example an obvious price error, no stock, or suspected fraud); we then refund it in full.']],
        ['Delivery', ['Inside Bahrain our own team delivers; to the other Gulf countries we ship with SMSA Express. Delivery times are in working days (Friday and Saturday off); orders after 2 pm Bahrain time count from the next working day. Times are estimates.',
          'Shipments outside Bahrain may be charged import VAT or customs duty in the destination country, paid by the receiver to SMSA on delivery unless stated otherwise.',
          'Please check your address and phone number; we are not responsible for delays or returns caused by a wrong address or number.']],
        ['Returns and refunds', ['See the Refund Policy.']],
        ['Liability', ['Our liability for any order is limited to the value of that order. We are not responsible for damage caused by storage after delivery or by ignoring the allergen information on the pack.']],
        ['Intellectual property', ['The Tartebz name, logo, photos and texts on this website belong to us and may not be copied or used without written permission.']],
        ['Law', ['These terms are governed by the laws of the Kingdom of Bahrain, and the courts of Bahrain decide any dispute. We may change these terms; changes apply to orders placed after they are published.']]
      ]
    },
    refund: {
      updated: '2026-11-09',
      ar: [
        ['إلغاء الطلب', ['تقدر تلغي طلبك واسترداد المبلغ كاملًا (مع رسوم التوصيل) قبل أن نشحنه. تواصل معنا بأسرع وقت عبر واتساب {phone} أو البريد {email} مع رقم الطلب.']],
        ['بعد الاستلام', ['منتجاتنا غذائية، لذلك لا نقبل إرجاع المنتجات المفتوحة أو التي استُلمت بحالة سليمة، حفاظًا على سلامة الطعام.',
          'إذا وصلك منتج تالف أو منتهي الصلاحية أو غير الذي طلبته، أو كان ناقصًا، أبلغنا خلال 48 ساعة من الاستلام مع صور للمنتج والعبوة ورقم الطلب، ونرسل لك بديلًا أو نرجع لك ثمنه كاملًا حسب اختيارك.']],
        ['إذا لم يصل الطلب', ['إذا لم يصلك الطلب خلال المدة المتوقعة تواصل معنا ونتابع مع شركة الشحن. إذا ضاع الطلب نرسل بديلًا أو نرجع المبلغ كاملًا.',
          'إذا رجع الطلب بسبب عنوان أو رقم غير صحيح أو رفض الاستلام، نرجع ثمن المنتجات بعد خصم رسوم التوصيل والإرجاع.']],
        ['طريقة ومدة الاسترداد', ['نرجع المبلغ بنفس طريقة الدفع. المبالغ المدفوعة بالبطاقة أو بنفت باي أو Apple Pay تُرجع عبر Tap Payments إلى نفس البطاقة أو الحساب، وتظهر عادة خلال 5 إلى 10 أيام عمل حسب البنك.',
          'رسوم التوصيل لا تُسترد بعد شحن الطلب إلا إذا كان الخطأ منا (منتج تالف أو خاطئ أو طلب ضائع).',
          'ضريبة الاستيراد أو الرسوم الجمركية المدفوعة لسمسا خارج البحرين لا تُسترد منا.']],
        ['تواصل معنا', ['{name} – سجل تجاري {cr} – {address}. البريد: {email} – الهاتف/واتساب: {phone}.']]
      ],
      en: [
        ['Cancelling an order', ['You can cancel your order for a full refund (including delivery) before we ship it. Contact us as soon as possible on WhatsApp {phone} or by email {email} with your order number.']],
        ['After delivery', ['Our products are food, so for food safety we do not accept returns of products that were opened or delivered in good condition.',
          'If a product arrives damaged, expired, wrong or missing, tell us within 48 hours of delivery with photos of the product and pack and your order number. We send a replacement or refund it in full – your choice.']],
        ['If your order does not arrive', ['If your order has not arrived within the expected time, contact us and we follow it up with the courier. If it is lost, we send a replacement or refund it in full.',
          'If an order comes back because of a wrong address or phone number, or because it was refused, we refund the products minus the delivery and return costs.']],
        ['How and when you are refunded', ['We refund the same way you paid. Card, BenefitPay and Apple Pay payments are refunded through Tap Payments to the same card or account, usually within 5 to 10 working days depending on your bank.',
          'Delivery charges are not refunded once the order has shipped, unless the mistake was ours (damaged or wrong product, lost order).',
          'Import VAT or customs duty paid to SMSA outside Bahrain cannot be refunded by us.']],
        ['Contact us', ['{name} – CR {cr} – {address}. Email: {email} – Phone/WhatsApp: {phone}.']]
      ]
    },
    privacy: {
      updated: '2026-11-09',
      ar: [
        ['ما نجمعه', ['عند الطلب: الاسم، الهاتف، البريد الإلكتروني، عنوان التوصيل، وما طلبته. لا نرى ولا نحفظ بيانات بطاقتك؛ يدخلها العميل مباشرة في صفحة Tap Payments الآمنة.',
          'يحفظ متصفحك سلتك ولغتك ودولتك (التخزين المحلي) حتى لا تضيع عند الرجوع للموقع.']],
        ['لماذا نستخدمها', ['لتجهيز طلبك وتوصيله، وإرسال تأكيد الطلب ورقم التتبع، والرد على استفساراتك. لا نبيع بياناتك لأي جهة.']],
        ['مع من نشاركها', ['Tap Payments لإتمام الدفع، وسمسا إكسبريس لتوصيل الطلبات خارج البحرين (الاسم والهاتف والعنوان فقط)، ومزوّد البريد لإرسال الرسائل. يلتزم كل منهم بحماية البيانات.']],
        ['الحفظ وحقوقك', ['نحتفظ بسجلات الطلبات للمدة التي يتطلبها القانون. تقدر تطلب نسخة من بياناتك أو تصحيحها أو حذفها بمراسلتنا على {email}.']],
        ['تواصل معنا', ['{name} – سجل تجاري {cr} – {address}.']]
      ],
      en: [
        ['What we collect', ['When you order: your name, phone, email, delivery address and what you ordered. We never see or store your card details; you enter them directly on Tap Payments’ secure page.',
          'Your browser keeps your cart, language and country (local storage) so they are still there when you come back.']],
        ['Why we use it', ['To prepare and deliver your order, send the order confirmation and tracking number, and answer your questions. We never sell your data.']],
        ['Who we share it with', ['Tap Payments to take the payment, SMSA Express to deliver orders outside Bahrain (name, phone and address only), and our email provider to send messages. Each of them must protect the data.']],
        ['Keeping it, and your rights', ['We keep order records for as long as the law requires. You can ask for a copy of your data, or for it to be corrected or deleted, by writing to {email}.']],
        ['Contact us', ['{name} – CR {cr} – {address}.']]
      ]
    },
    delivery: {
      updated: '2026-11-09',
      ar: [
        ['المنتجات والأسعار', ['كل منتجاتنا وأسعارها في صفحة «المتجر». الأسعار بالدينار البحريني، وتظهر بعملة دولتك تقريبيًا. المبلغ النهائي (المنتجات + التوصيل) يظهر في صفحة الدفع قبل الدفع.']],
        ['كيف نوصّل', ['داخل البحرين يوصل فريقنا، ويمكنك الاستلام من الفرع مجانًا. لدول الخليج نشحن مع سمسا إكسبريس ويصلك رقم التتبع بالبريد.',
          'المدة بأيام العمل؛ الجمعة والسبت ليسا يومي عمل، والطلبات بعد الساعة 2 ظهرًا بتوقيت البحرين تُحسب من يوم العمل التالي.',
          'سعر التوصيل حسب وزن الطلب. المنتج الذي ليس له وزن يُحسب 0.5 كجم. خارج البحرين قد تُفرض ضريبة استيراد أو رسوم جمركية يدفعها المستلم لسمسا عند التسليم.']]
      ],
      en: [
        ['Products and prices', ['All our products and their prices are in the Shop. Prices are in Bahraini dinars and shown approximately in your own currency. The final amount (products + delivery) is shown at checkout before you pay.']],
        ['How we deliver', ['Inside Bahrain our own team delivers, and you can collect from our branch for free. To the other Gulf countries we ship with SMSA Express and email you the tracking number.',
          'Times are in working days; Friday and Saturday are not working days, and orders after 2 pm Bahrain time count from the next working day.',
          'Delivery is priced by the parcel’s weight; a product with no weight counts as 0.5 kg. Outside Bahrain, import VAT or customs duty may be charged, paid by the receiver to SMSA on delivery.']]
      ]
    }
  };

  function fill(s) {
    var st = TZ.store || {};
    return s.replace(/\{(name|cr|email|phone|address)\}/g, function (m, k) {
      if (k === 'name') return st.legalName || TZ.L(st.name) || 'Tartebz';
      if (k === 'address') return TZ.L(st.address) || '';
      return st[k] || '';
    });
  }

  /* The live delivery prices as a table: every destination, every method, with its price rule and time. */
  function ratesTable() {
    var ar = TZ.lang() === 'ar', bhd = function (v) { return TZ.money(+v || 0, TZ.countries[0]); };
    var rule = function (m) {
      if (m.pricing === 'quoted') return TZ.t('ship_quoted');
      if (m.pricing === 'flat') return +m.base ? bhd(m.base) : TZ.t('free');
      if (m.pricing === 'table') return ar ? 'حسب جدول الأوزان' : 'By weight table';
      return ar ? bhd(m.base) + ' حتى ' + (+m.firstKg || 0) + ' كجم، ثم ' + bhd(m.perStep) + ' لكل ' + (+m.stepKg || 0) + ' كجم إضافية'
        : bhd(m.base) + ' up to ' + (+m.firstKg || 0) + ' kg, then ' + bhd(m.perStep) + ' per extra ' + (+m.stepKg || 0) + ' kg';
    };
    var extra = function (m) {
      var x = [];
      if (m.max != null && m.max !== '') x.push((ar ? 'بحد أقصى ' : 'max ') + bhd(m.max));
      if (m.freeOver != null && m.freeOver !== '') x.push(TZ.t('ship_free_over', { amount: bhd(m.freeOver) }));
      if (m.maxKg != null && m.maxKg !== '') x.push((ar ? 'حتى ' : 'up to ') + m.maxKg + (ar ? ' كجم' : ' kg'));
      return x.join(' · ');
    };
    var rows = (TZ.shipping.destinations || []).map(function (d) {
      var names = (d.countries || []).map(function (cc) {
        if (cc === '*') return ar ? 'باقي الدول' : 'Other countries';
        var c = TZ.countries.filter(function (x) { return x.code === cc; })[0];
        return c ? c.flag + ' ' + TZ.L(c.name) : cc;
      }).join(ar ? '، ' : ', ');
      return (d.methods || []).map(function (m) {
        return '<tr><td data-label="' + TZ.esc(ar ? 'الوجهة' : 'Destination') + '"><span>' + TZ.esc(names) + '</span></td><td data-label="' + TZ.esc(ar ? 'طريقة التوصيل' : 'Method') + '"><span><b>' + TZ.esc(TZ.L(m.name)) + '</b></span></td>' +
          '<td data-label="' + TZ.esc(ar ? 'السعر' : 'Price') + '"><span>' + TZ.esc(rule(m)) + (extra(m) ? '<small>' + TZ.esc(extra(m)) + '</small>' : '') + '</span></td>' +
          '<td data-label="' + TZ.esc(ar ? 'المدة' : 'Time') + '"><span>' + TZ.esc(TZ.etaText(m)) + '</span></td></tr>';
      }).join('');
    }).join('');
    return '<div class="table-scroll"><table class="policy-table"><thead><tr><th>' + TZ.esc(ar ? 'الوجهة' : 'Destination') + '</th><th>' + TZ.esc(ar ? 'طريقة التوصيل' : 'Method') +
      '</th><th>' + TZ.esc(ar ? 'السعر' : 'Price') + '</th><th>' + TZ.esc(ar ? 'المدة' : 'Time') + '</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
  }

  TZ.policyPage = function (key) {
    var page = P[key], el = document.getElementById('policy-body');
    if (!page || !el) return;
    var ar = TZ.lang() === 'ar';
    el.innerHTML = page[ar ? 'ar' : 'en'].map(function (sec, i) {
      return '<section class="policy-sec"><h2>' + TZ.esc(sec[0]) + '</h2>' + sec[1].map(function (p) { return '<p>' + TZ.esc(fill(p)) + '</p>'; }).join('') +
        (key === 'delivery' && i === 1 ? ratesTable() : '') + '</section>';
    }).join('') +
      (key === 'delivery' ? '<p class="policy-cta"><a class="btn btn-primary" href="' + TZ.url('shop.html') + '">' + TZ.esc(TZ.t('nav_shop')) + '</a> <a class="btn btn-outline" href="' + TZ.url('checkout.html') + '">' + TZ.esc(TZ.t('co_title')) + '</a></p>' : '') +
      '<p class="policy-updated">' + TZ.esc(ar ? 'آخر تحديث: ' : 'Last updated: ') + '<bdi>' + page.updated + '</bdi></p>';
  };
})();
