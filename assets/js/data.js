/* Tartebz — store data.
   Products, prices, shipping and contact details come from the original tartebz.shop database.
   Prices are stored in BHD. Other Gulf currencies use the same fixed multipliers the old store charged. */
window.TZ = window.TZ || {};

TZ.store = {
  name: { ar: 'ترتيبز', en: 'Tartebz' },
  email: 'tartebzz@gmail.com',
  phone: '+973 3753 3757',
  phoneRaw: '97337533757',
  cr: '5-58506',
  address: { ar: 'البحرين، الرفاع الغربي - شارع العجيرة', en: 'Al-Ajeera Street, West Riffa, Bahrain' },
  mapsUrl: 'https://www.google.com/maps/search/?api=1&query=West+Riffa+Bahrain',
  social: {
    instagram: 'https://www.instagram.com/tartebz', // confirm the handle
    tiktok: 'https://www.tiktok.com/@tartebz'
  }
};

/* Country → currency. rate = multiplier on the BHD price (from real past orders). */
TZ.countries = [
  { code: 'BH', cur: 'BHD', rate: 1,    dec: 3, sym: { ar: 'د.ب', en: 'BHD' }, name: { ar: 'البحرين', en: 'Bahrain' }, flag: '🇧🇭', ship: 'flat' },
  { code: 'SA', cur: 'SAR', rate: 10,   dec: 2, sym: { ar: 'ر.س', en: 'SAR' }, name: { ar: 'السعودية', en: 'Saudi Arabia' }, flag: '🇸🇦', ship: 'ubex' },
  { code: 'AE', cur: 'AED', rate: 10,   dec: 2, sym: { ar: 'د.إ', en: 'AED' }, name: { ar: 'الإمارات', en: 'UAE' }, flag: '🇦🇪', ship: 'ubex' },
  { code: 'QA', cur: 'QAR', rate: 10,   dec: 2, sym: { ar: 'ر.ق', en: 'QAR' }, name: { ar: 'قطر', en: 'Qatar' }, flag: '🇶🇦', ship: 'ubex' },
  { code: 'KW', cur: 'KWD', rate: 0.82, dec: 3, sym: { ar: 'د.ك', en: 'KWD' }, name: { ar: 'الكويت', en: 'Kuwait' }, flag: '🇰🇼', ship: 'ubex' },
  { code: 'OM', cur: 'OMR', rate: 1.1,  dec: 3, sym: { ar: 'ر.ع', en: 'OMR' }, name: { ar: 'عُمان', en: 'Oman' }, flag: '🇴🇲', ship: 'ubex' }
];

/* Shipping in BHD. Bahrain is a flat rate; the rest of the GCC uses the UBEX weight table (kg → BHD). */
TZ.shipping = {
  flatBH: 1.5,
  ubex: [[0.5,3.8],[1,4.2],[1.5,5.1],[2,6.1],[2.5,7.3],[3,8.7],[3.5,9.6],[4,12.1],[4.5,12.2],[5,13.8],
         [5.5,14.9],[6,15.9],[6.5,17],[7,18.1],[7.5,19.1],[8,20.2],[8.5,21.2],[9,22.3],[9.5,23.3],[10,24.4]]
};

TZ.categories = [
  { id: 'best-sellers', name: { ar: 'الأكثر مبيعاً', en: 'Best sellers' }, desc: { ar: 'النكهات اللي الكل يرجع لها', en: 'The flavours everyone comes back for' }, icon: 'star' },
  { id: 'sweet-sour', name: { ar: 'حامض حلو', en: 'Sweet & sour' }, desc: { ar: 'توازن مثالي بين الحلاوة والحموضة', en: 'The perfect balance of sweet and tang' }, icon: 'drop' },
  { id: 'electric-sour', name: { ar: 'حامض كهرباء', en: 'Electric sour' }, desc: { ar: 'حموضة قصوى لعشّاق التحدي', en: 'Extreme sour for the brave' }, icon: 'bolt' },
  { id: 'mixed', name: { ar: 'مشكل', en: 'Mixed' }, desc: { ar: 'تشكيلة من كل النكهات في بكج واحد', en: 'Every flavour in one pack' }, icon: 'mix' }
];

/* art.type: box | strips | cup | bag. colors: [main, deep], pieces: candy colours inside the art. */
TZ.products = [
  {
    id: 9448, slug: 'tartebz-fruits', order: 0, price: 6.5, inStock: true, bestSeller: true,
    cats: ['best-sellers', 'mixed'], sour: 3, weight: 0.3, sold: 871,
    name: { ar: 'ترتيبز فرووتس', en: 'Tartebz Fruits' },
    flavor: { ar: 'أناناس · مانجو · توت', en: 'Pineapple · Mango · Berry' },
    desc: {
      ar: 'بكج ترتيبز فروتس هو توليفة فريدة من قمر الدين الأصلي ممزوج بنكهات استوائية مختارة بعناية هي الأناناس، المانجو والتوت، ليمنح مذاقًا متوازنًا يجمع بين الحموضة والانتعاش بلمسة عصرية. يتميز البكج بتصميم أنيق وحيوي يعكس هوية العلامة، مما يجعله خيارًا مثاليًا كهدية راقية أو كرفيق ممتع في المناسبات والتجمعات الخاصة.',
      en: 'The Tartebz Fruits pack is a unique blend of original qamar al-din mixed with hand-picked tropical flavours: pineapple, mango and berry. The taste is a balance of tang and freshness with a modern twist. Its lively, elegant packaging makes it a perfect gift or a fun companion for gatherings and special occasions.'
    },
    art: { type: 'box', colors: ['#FF8A00', '#E30613'], pieces: ['#FFD700', '#FF8A00', '#C2185B'] }
  },
  {
    id: 9844, slug: 'electric-sour-strips', order: 1, price: 5, inStock: false, bestSeller: true,
    cats: ['electric-sour'], sour: 5, weight: 0.2, sold: 481,
    name: { ar: 'شرايط حامض كهرباء', en: 'Electric Sour Strips' },
    flavor: { ar: 'حموضة كهرباء ⚡', en: 'Electric sour ⚡' },
    desc: {
      ar: 'شرايط ترتيبز بحموضة «كهرباء» قوية تلسع من أول قضمة، مغطاة بسكر حامض لعشّاق التحدي والنكهات الجريئة.',
      en: 'Tartebz strips with a powerful “electric” sourness that zaps from the very first bite, coated in sour sugar for lovers of bold flavour.'
    },
    art: { type: 'strips', colors: ['#2B2F8F', '#141652'], pieces: ['#FFD700', '#00C2FF', '#7CFF4F', '#FF2D87'] }
  },
  {
    id: 9833, slug: 'gharqana-electric-red-berry', order: 2, price: 3, inStock: false, bestSeller: false,
    cats: ['electric-sour'], sour: 5, weight: 0.5, sold: 77,
    name: { ar: 'الغرقانة حامض كهرباء توت أحمر', en: 'Gharqana Electric Sour Red Berry' },
    flavor: { ar: 'توت أحمر', en: 'Red berry' },
    desc: {
      ar: 'الغرقانة من ترتيبز: قطع حلوى غارقة بالحموضة بنكهة التوت الأحمر، بدرجة «كهرباء» لمحبي الحموضة القصوى.',
      en: 'Tartebz Gharqana: candy pieces drenched in red-berry sourness, at “electric” level for extreme-sour fans.'
    },
    art: { type: 'cup', colors: ['#E30613', '#8E0010'], pieces: ['#FF4D6D', '#B5001F', '#FFD700'] }
  },
  {
    id: 10024, slug: 'gharqana-sweet-red-berry', order: 3, price: 3, inStock: false, bestSeller: false,
    cats: ['sweet-sour'], sour: 2, weight: 1, sold: 4,
    name: { ar: 'الغرقانة حامض حلو توت أحمر', en: 'Gharqana Sweet & Sour Red Berry' },
    flavor: { ar: 'توت أحمر', en: 'Red berry' },
    desc: {
      ar: 'الغرقانة بنكهة التوت الأحمر بتوازن حامض حلو لطيف، تجربة منعشة تناسب الجميع.',
      en: 'Gharqana in red berry with a gentle sweet-and-sour balance, a refreshing treat for everyone.'
    },
    art: { type: 'cup', colors: ['#FF4D6D', '#C2185B'], pieces: ['#FF8FA3', '#E30613', '#FFD1DC'] }
  },
  {
    id: 9849, slug: 'sweet-sour-strips', order: 4, price: 5, inStock: true, bestSeller: true,
    cats: ['sweet-sour'], sour: 3, weight: 0.2, sold: 281,
    name: { ar: 'شرايط حامض حلو', en: 'Sweet & Sour Strips' },
    flavor: { ar: 'فواكه مشكلة', en: 'Mixed fruit' },
    desc: {
      ar: 'شرايط طرية بتوازن مثالي بين الحلاوة والحموضة، ألوان مبهجة ونكهة فواكه تخليك تكمّل للآخر.',
      en: 'Soft strips with the perfect balance of sweet and sour: bright colours and a fruity flavour you won’t want to stop eating.'
    },
    art: { type: 'strips', colors: ['#FF4D9D', '#C2185B'], pieces: ['#FFD700', '#FF8A00', '#7CFF4F', '#FFFFFF'] }
  },
  {
    id: 9472, slug: 'tartebz-fruits-mango', order: 5, price: 3, inStock: true, bestSeller: false,
    cats: ['best-sellers', 'sweet-sour'], sour: 2, weight: 0.5, sold: 56,
    name: { ar: 'ترتيبز فرووتس مانجو', en: 'Tartebz Fruits Mango' },
    flavor: { ar: 'مانجو', en: 'Mango' },
    desc: {
      ar: 'مانجو ترتيبز فروتس يحمل مزيجًا من الحلاوة الطبيعية واللمسة الحامضة الخفيفة، بطابع غني يعبّر عن الأصالة والطعم الشرقي المميز، ليقدّم تجربة فاخرة ومحببة لدى الجميع.',
      en: 'Tartebz Fruits Mango carries natural sweetness with a light sour touch: rich and authentic, with a distinctly eastern character everyone loves.'
    },
    art: { type: 'box', colors: ['#FFC21A', '#FF8A00'], pieces: ['#FFD700', '#FFB000', '#7BC043'] }
  },
  {
    id: 9470, slug: 'tartebz-fruits-pineapple', order: 6, price: 3, inStock: true, bestSeller: false,
    cats: ['best-sellers', 'electric-sour'], sour: 4, weight: 0.5, sold: 29,
    name: { ar: 'ترتيبز فرووتس أناناس', en: 'Tartebz Fruits Pineapple' },
    flavor: { ar: 'أناناس', en: 'Pineapple' },
    desc: {
      ar: 'نكهة الأناناس في ترتيبز فروتس تمنحك انتعاشًا استوائيًا بطابع حامض متوازن، يذكّرك بروح الصيف ولذّة الفواكه الطازجة، لتكون الخيار الأمثل لعشّاق الطعم الجريء والمنعش.',
      en: 'Tartebz Fruits Pineapple brings tropical freshness with a balanced sour edge, a taste of summer and fresh fruit for lovers of bold, refreshing flavour.'
    },
    art: { type: 'box', colors: ['#FFE24A', '#F5B700'], pieces: ['#FFD700', '#7BC043', '#FF8A00'] }
  },
  {
    id: 9467, slug: 'tartebz-fruits-berry', order: 7, price: 3, inStock: true, bestSeller: false,
    cats: ['best-sellers', 'sweet-sour'], sour: 3, weight: 0.5, sold: 92,
    name: { ar: 'ترتيبز فرووتس توت', en: 'Tartebz Fruits Berry' },
    flavor: { ar: 'توت', en: 'Berry' },
    desc: {
      ar: 'نكهة التوت في ترتيبز فروتس تجمع بين الحموضة المبهجة والحلاوة الرقيقة، لتمنح إحساسًا فاكهيًا عصريًا يعكس روح الشباب والطاقة، ويُكمل التشكيلة بأناقة لا تقاوم.',
      en: 'The berry flavour of Tartebz Fruits blends a cheerful tang with gentle sweetness, a modern, fruity bite full of youthful energy.'
    },
    art: { type: 'box', colors: ['#C2185B', '#7B1040'], pieces: ['#FF4D6D', '#7B1FA2', '#FFD700'] }
  },
  {
    id: 10022, slug: 'marshmallow-blueberry', order: 8, price: 3, inStock: true, bestSeller: false,
    cats: ['sweet-sour'], sour: 2, weight: 0.5, sold: 53,
    name: { ar: 'مارشميلو توت أزرق', en: 'Blueberry Marshmallow' },
    flavor: { ar: 'توت أزرق', en: 'Blueberry' },
    desc: {
      ar: 'مارشميلو هش وطري بنكهة التوت الأزرق مع لمسة حموضة خفيفة تذوب في الفم.',
      en: 'Light, fluffy marshmallow in blueberry with a gentle sour touch that melts in your mouth.'
    },
    art: { type: 'bag', colors: ['#2E86FF', '#1747B8'], pieces: ['#BFE0FF', '#7FB8FF', '#FFFFFF'] }
  },
  {
    id: 8986, slug: 'marshmallow-yellow', order: 9, price: 3, inStock: false, bestSeller: false,
    cats: ['best-sellers', 'sweet-sour'], sour: 2, weight: 0.5, sold: 39,
    name: { ar: 'مارشميلو الأصفر', en: 'Yellow Marshmallow' },
    flavor: { ar: 'فاكهة صفراء', en: 'Sunny fruit' },
    desc: {
      ar: 'مارشميلو أصفر طري بنكهة فاكهية مشمسة ولمسة حامضة منعشة.',
      en: 'Soft yellow marshmallow with a sunny fruit flavour and a refreshing sour touch.'
    },
    art: { type: 'bag', colors: ['#FFD700', '#F59E0B'], pieces: ['#FFF3B0', '#FFE066', '#FFFFFF'] }
  },
  {
    id: 9851, slug: 'marshmallow-red-berry', order: 10, price: 3, inStock: true, bestSeller: true,
    cats: ['sweet-sour'], sour: 2, weight: 0.5, sold: 61,
    name: { ar: 'مارشميلو توت أحمر', en: 'Red Berry Marshmallow' },
    flavor: { ar: 'توت أحمر', en: 'Red berry' },
    desc: {
      ar: 'مارشميلو بنكهة التوت الأحمر، طري وخفيف ويذوب في الفم مع لمسة حموضة.',
      en: 'Red berry marshmallow: soft, light and melt-in-your-mouth, with a hint of sour.'
    },
    art: { type: 'bag', colors: ['#FF3B5C', '#C8102E'], pieces: ['#FFC2CD', '#FF8FA3', '#FFFFFF'] }
  }
];

/* ---------- interface text ---------- */
TZ.i18n = {
  ar: {
    skip: 'تخطَّ إلى المحتوى',
    nav_home: 'الرئيسية', nav_shop: 'المنتجات', nav_contact: 'تواصل معنا', nav_about: 'من نحن',
    order_now: 'اطلب الآن', menu: 'القائمة', close: 'إغلاق',
    lang_switch: 'English', lang_short: 'EN', lang_label: 'تغيير اللغة',
    country_label: 'الدولة والعملة', ship_to_label: 'التوصيل إلى',
    cart: 'السلة', cart_title: 'سلة التسوق', cart_items: '{n} منتج',
    cart_empty_title: 'سلتك فاضية', cart_empty_text: 'ضيف نكهتك المفضلة وخلّنا نوصلها لك.',
    cart_subtotal: 'المجموع الفرعي', cart_shipping: 'التوصيل', cart_total: 'الإجمالي',
    cart_checkout: 'إتمام الطلب', cart_continue: 'متابعة التسوق', cart_remove: 'حذف',
    cart_ship_note_flat: 'سعر ثابت داخل البحرين', cart_ship_note_ubex: 'تقديري حسب الوزن ({w} كجم) عبر UBEX',
    added: 'أُضيف «{name}» إلى السلة', view_cart: 'عرض السلة',
    qty: 'الكمية', increase: 'زيادة', decrease: 'إنقاص',
    in_stock: 'متوفر', out_of_stock: 'غير متوفر', best_seller: 'الأكثر مبيعاً',
    add_to_cart: 'أضف للسلة', add: 'أضف', sold_out: 'نفدت الكمية',
    sour_meter: 'مقياس الحموضة', sour_1: 'لطيف', sour_2: 'خفيف', sour_3: 'متوازن', sour_4: 'قوي', sour_5: 'كهرباء',

    hero_badge: 'الأصلي',
    hero_title: 'قمر الدين<br>بروح <span class="hl">جديدة</span>',
    hero_text: 'في ترتيبز نصنع أشهى الحلويات التي تبعث الفرح في كل مناسبة، بشغفٍ ووصفاتٍ مميزة. حامض حلو أو حامض كهرباء… اختر حموضتك.',
    hero_cta: 'اطلب الآن', hero_cta2: 'قصتنا',
    stat_sold: 'عبوة وصلت لعملائنا', stat_countries: 'دول خليجية نوصل لها', stat_flavors: 'نكهات استوائية',
    scroll: 'اكتشف',
    marquee: ['حامض حلو', 'حامض كهرباء', 'مانجو', 'أناناس', 'توت', 'مارشميلو', 'قمر الدين الأصلي', 'صنع في البحرين'],

    cats_kicker: 'اختر حموضتك', cats_title: 'تسوق حسب النكهة', cats_count: '{n} منتجات',
    bs_kicker: 'الأكثر طلباً', bs_title: 'الأكثر مبيعاً', view_all: 'كل المنتجات',
    why_kicker: 'ليش ترتيبز؟', why_title: 'ما يميزنا',
    why_1_t: 'منتجات حصرية', why_1: 'منتجات بتوقيع ترتيبز لا تتوفر في أي مكان آخر.',
    why_2_t: 'بكجات تفتح النفس', why_2: 'تصميمات جذابة وعصرية تناسب مختلف المناسبات.',
    why_3_t: 'جودة صارمة', why_3: 'معايير دقيقة في اختيار المكونات وتحضير المنتجات.',
    why_4_t: 'دائماً جديد', why_4: 'تحديث مستمر للتشكيلة لتقديم كل ما هو جديد ومميز.',
    ch_1: 'الطلب أونلاين والدفع الآمن بأكثر من وسيلة', ch_2: 'طلبات خاصة للمناسبات والفعاليات', ch_3: 'بيع التجزئة داخل المتجر',
    story_kicker: 'قصتنا', story_title: 'من مبادرة منزلية إلى علامة بحرينية',
    story_text: 'انطلقت فكرة «ترتيبز» من شغف بتقديم منتج عربي كلاسيكي كـ«قمر الدين» بروح جديدة، موجهة للجيل الشاب والذوّاقة من محبي النكهات الجريئة. بدأ المشروع كمبادرة منزلية صغيرة، سرعان ما لاقت إقبالاً واسعًا على منصات التواصل الاجتماعي.',
    story_cta: 'اعرف أكثر عنا', float_big: '100%', float_small: 'طبيعي',
    ship_kicker: 'نوصل لك وين ما كنت', ship_title: 'التوصيل لكل دول الخليج',
    ship_text: 'اطلب من موقعنا وبيوصلك منتجك، والأسعار تظهر بعملة بلدك تلقائياً.',
    ship_from: 'يبدأ من', ship_flat: 'سعر ثابت',
    press_title: 'شافونا في', press_1: 'صحيفة الوطن', press_2: 'تلفزيون البحرين – القناة الأولى', press_3: 'مشروع بحريني شبابي',
    cta_title: 'جاهز تجرب الحموضة؟', cta_text: 'اختر نكهتك وخلّ الباقي علينا.', cta_btn: 'تسوق الآن',

    footer_about: 'في ترتيبز نصنع أشهى الحلويات التي تبعث الفرح في كل مناسبة، بشغفٍ ووصفاتٍ مميزة.',
    footer_links: 'روابط سريعة', footer_cats: 'الأقسام', footer_contact: 'تواصل معنا', footer_follow: 'تابعنا',
    footer_cr: 'السجل التجاري: {cr}', footer_rights: '© {y} ترتيبز. جميع الحقوق محفوظة.', footer_pay: 'دفع آمن عبر',

    shop_title: 'منتجاتنا', shop_text: 'كل نكهات ترتيبز في مكان واحد. فلتر حسب الحموضة واختر اللي يناسبك.',
    filter_all: 'الكل', sort_label: 'الترتيب', sort_default: 'الترتيب المقترح', sort_best: 'الأكثر مبيعاً',
    sort_price_asc: 'السعر: من الأقل', sort_price_desc: 'السعر: من الأعلى', sort_sour: 'الأكثر حموضة',
    instock_only: 'المتوفر فقط', search_ph: 'ابحث عن نكهة…', search_label: 'بحث', results: '{n} منتج',
    no_results: 'ما لقينا شي', no_results_text: 'جرّب كلمة ثانية أو امسح الفلاتر.', reset_filters: 'مسح الفلاتر',

    home: 'الرئيسية', weight: 'الوزن', kg: 'كجم', flavor: 'النكهة',
    secure_pay: 'دفع آمن (بطاقة / بنفت)', gcc_delivery: 'توصيل لـ6 دول خليجية', natural: 'مكونات مختارة بعناية',
    tab_desc: 'الوصف', tab_ship: 'الشحن والتوصيل',
    ship_tab_text: 'داخل البحرين بسعر ثابت، ولباقي دول الخليج عبر شركة UBEX حسب وزن الطلب. أسعار التوصيل لهذا المنتج وحده:',
    related: 'قد يعجبك أيضاً', not_found: 'المنتج غير موجود', back_to_shop: 'الرجوع للمنتجات',
    prices_in: 'الأسعار بـ{cur}', change: 'تغيير',

    co_title: 'إتمام الطلب', co_text: 'خطوة وحدة وتوصلك الحموضة.', co_billing: 'بيانات التوصيل',
    f_first: 'الاسم الأول', f_last: 'الاسم الأخير', f_country: 'الدولة / المنطقة',
    f_addr1: 'عنوان الشارع / الحي', f_addr1_ph: 'رقم المنزل واسم الشارع / الحي',
    f_addr2: 'الشقة، الجناح، الوحدة، إلخ.', f_addr2_ph: 'رقم الشقة، الجناح، الوحدة، إلخ. (اختياري)',
    f_city: 'المدينة', f_state: 'المنطقة', f_phone: 'الهاتف', f_email: 'البريد الإلكتروني', f_notes: 'ملاحظات الطلب (اختياري)',
    optional: '(اختياري)', co_payment: 'طريقة الدفع', pay_gatee: 'بطاقة بنكية / بنفت بي',
    pay_note: 'تتم معالجة الدفع بأمان عبر بوابة Gate-E.', place_order: 'تأكيد الطلب', co_summary: 'ملخص طلبك',
    co_empty: 'سلتك فاضية، أضف منتجات أولاً.',
    err_required: 'هذا الحقل مطلوب', err_email: 'أدخل بريداً إلكترونياً صحيحاً', err_phone: 'أدخل رقم هاتف صحيح',
    co_done_title: 'تم تجهيز طلبك! 🎉',
    co_done_text: 'هذه نسخة تجريبية من الواجهة ولم يتم أي دفع بعد. أرسل طلبك لنا عبر واتساب ونكمل معك.',
    co_whatsapp: 'أرسل الطلب عبر واتساب', co_back: 'تعديل الطلب', wa_order_intro: 'مرحباً ترتيبز، أبغى أطلب:',

    about_title: 'من نحن؟',
    about_text: 'ترتيبز هو متجر متخصص في تقديم الحلويات الفريدة بطابع عصري، مع التركيز على منتجات قمر الدين الحامض والحلو، التي نقدمها بتشكيلة متنوعة وبجودة عالية تلائم جميع الأذواق. منذ انطلاقتنا في مملكة البحرين، حرصنا على الجمع بين النكهات التقليدية والابتكار في التقديم، لنمنح عملاءنا تجربة لا تُنسى في عالم الحلوى.',
    values_kicker: 'اللي نؤمن فيه', values_title: 'قيمنا',
    val_1_t: 'الأصالة', val_1: 'نستلهم من تراث الحلويات العربية ونقدمه بشكل عصري.',
    val_2_t: 'الابتكار', val_2: 'نسعى دائمًا لتطوير وصفات جديدة وتقديم تجارب طعم غير تقليدية.',
    val_3_t: 'الجودة', val_3: 'نلتزم بأفضل المكونات وأدق معايير التحضير.',
    val_4_t: 'رضا العميل', val_4: 'نضع تجربة العميل في مقدمة أولوياتنا، من لحظة الطلب وحتى التوصيل.',
    val_5_t: 'التميز', val_5: 'نهدف إلى تقديم منتج لا يُنسى بصريًا وذوقيًا.',
    founding_title: 'قصة التأسيس',
    founding_1: 'انطلقت فكرة «ترتيبز» من شغف بتقديم منتج عربي كلاسيكي كـ«قمر الدين» بروح جديدة، موجهة للجيل الشاب والذوّاقة من محبي النكهات الجريئة.',
    founding_2: 'بدأ المشروع كمبادرة منزلية صغيرة، سرعان ما لاقت إقبالاً واسعًا على منصات التواصل الاجتماعي، وتحولت إلى علامة تجارية قائمة بذاتها تجمع بين التقاليد والحداثة، وتحظى اليوم بانتشار واسع واهتمام إعلامي وجماهيري.',
    media_title: 'الجوائز والتغطيات الإعلامية',
    media_1: 'حظيت علامة ترتيبز باهتمام واسع من وسائل الإعلام المحلية، حيث تم تسليط الضوء على قصة نجاحنا في صحيفة الوطن، بالإضافة إلى ظهورنا في تلفزيون البحرين – القناة الأولى ضمن تقارير تسلّط الضوء على المشاريع الشبابية الناجحة.',
    media_2: 'كما شرفنا بزيارة شخصيات بارزة إلى المتجر، من ضمنهم سعادة الشيخ خليفة بن أحمد آل خليفة، تعبيرًا عن دعمهم للمشاريع الوطنية.',
    media_3: 'هذا التقدير يعكس فخرنا بكون «ترتيبز» ليس مجرد محل حلويات، بل نموذجًا لنجاح المشاريع البحرينية التي تجمع بين الأصالة والتجديد.',

    contact_title: 'تواصل معنا', contact_text: 'عندك سؤال أو طلب خاص لمناسبة؟ احنا هنا.',
    c_email: 'البريد الإلكتروني', c_phone: 'الهاتف', c_whatsapp: 'واتساب', c_address: 'العنوان', c_cr: 'السجل التجاري',
    c_form_title: 'أرسل لنا رسالة', c_name: 'الاسم', c_msg: 'رسالتك', c_send: 'أرسل عبر واتساب', open_maps: 'افتح في الخرائط',
    c_special: 'طلبات المناسبات', c_special_text: 'نجهّز بكجات خاصة للأعراس، المواليد، الفعاليات والشركات.',

    iab_text: '📱 لإتمام الدفع عبر «بنفت» بنجاح، يُفضّل فتح الموقع في متصفحك (Safari أو Chrome): اضغط زر «⋯» في الأعلى ثم «فتح في المتصفح».',
    iab_copy: 'نسخ رابط الموقع', iab_copied: '✓ تم النسخ'
  },

  en: {
    skip: 'Skip to content',
    nav_home: 'Home', nav_shop: 'Shop', nav_contact: 'Contact', nav_about: 'About',
    order_now: 'Order now', menu: 'Menu', close: 'Close',
    lang_switch: 'العربية', lang_short: 'ع', lang_label: 'Change language',
    country_label: 'Country & currency', ship_to_label: 'Deliver to',
    cart: 'Cart', cart_title: 'Your cart', cart_items: '{n} items',
    cart_empty_title: 'Your cart is empty', cart_empty_text: 'Add your favourite flavour and we’ll deliver it to you.',
    cart_subtotal: 'Subtotal', cart_shipping: 'Delivery', cart_total: 'Total',
    cart_checkout: 'Checkout', cart_continue: 'Continue shopping', cart_remove: 'Remove',
    cart_ship_note_flat: 'Flat rate inside Bahrain', cart_ship_note_ubex: 'Estimated by weight ({w} kg) via UBEX',
    added: '“{name}” added to your cart', view_cart: 'View cart',
    qty: 'Quantity', increase: 'Increase', decrease: 'Decrease',
    in_stock: 'In stock', out_of_stock: 'Out of stock', best_seller: 'Best Seller',
    add_to_cart: 'Add to cart', add: 'Add', sold_out: 'Sold out',
    sour_meter: 'Sour meter', sour_1: 'Gentle', sour_2: 'Light', sour_3: 'Balanced', sour_4: 'Strong', sour_5: 'Electric',

    hero_badge: 'The Original',
    hero_title: 'Qamar Al-Deen<br>with a <span class="hl">bold</span> new soul',
    hero_text: 'At Tartebz we make treats that bring joy to every occasion, with passion and our own recipes. Sweet & sour or electric sour: pick your level.',
    hero_cta: 'Order now', hero_cta2: 'Our story',
    stat_sold: 'packs delivered', stat_countries: 'Gulf countries we deliver to', stat_flavors: 'tropical flavours',
    scroll: 'Explore',
    marquee: ['Sweet & sour', 'Electric sour', 'Mango', 'Pineapple', 'Berry', 'Marshmallow', 'The original qamar al-din', 'Made in Bahrain'],

    cats_kicker: 'Pick your sour', cats_title: 'Shop by flavour', cats_count: '{n} products',
    bs_kicker: 'Most loved', bs_title: 'Best sellers', view_all: 'All products',
    why_kicker: 'Why Tartebz?', why_title: 'What makes us different',
    why_1_t: 'Exclusive products', why_1: 'Signature Tartebz treats you won’t find anywhere else.',
    why_2_t: 'Packs that pop', why_2: 'Eye-catching, modern packaging for every occasion.',
    why_3_t: 'Strict quality', why_3: 'Careful ingredient selection and preparation standards.',
    why_4_t: 'Always new', why_4: 'A constantly refreshed line-up of new flavours.',
    ch_1: 'Order online with secure payment', ch_2: 'Special orders for occasions and events', ch_3: 'In-store retail',
    story_kicker: 'Our story', story_title: 'From a home project to a Bahraini brand',
    story_text: 'Tartebz began with a passion for giving a classic Arabic treat, qamar al-din, a new soul for a young generation that loves bold flavours. It started as a small home project and quickly took off on social media.',
    story_cta: 'More about us', float_big: '100%', float_small: 'Natural',
    ship_kicker: 'Wherever you are', ship_title: 'Delivery across the Gulf',
    ship_text: 'Order online and we’ll deliver to you. Prices show in your local currency automatically.',
    ship_from: 'From', ship_flat: 'Flat rate',
    press_title: 'As seen in', press_1: 'Al Watan newspaper', press_2: 'Bahrain TV – Channel 1', press_3: 'Young Bahraini business',
    cta_title: 'Ready for the sour?', cta_text: 'Pick your flavour and leave the rest to us.', cta_btn: 'Shop now',

    footer_about: 'At Tartebz we make treats that bring joy to every occasion, with passion and our own recipes.',
    footer_links: 'Quick links', footer_cats: 'Categories', footer_contact: 'Contact', footer_follow: 'Follow us',
    footer_cr: 'Commercial registration: {cr}', footer_rights: '© {y} Tartebz. All rights reserved.', footer_pay: 'Secure payment with',

    shop_title: 'Our products', shop_text: 'Every Tartebz flavour in one place. Filter by sourness and find yours.',
    filter_all: 'All', sort_label: 'Sort', sort_default: 'Recommended', sort_best: 'Best selling',
    sort_price_asc: 'Price: low to high', sort_price_desc: 'Price: high to low', sort_sour: 'Most sour',
    instock_only: 'In stock only', search_ph: 'Search a flavour…', search_label: 'Search', results: '{n} products',
    no_results: 'Nothing found', no_results_text: 'Try another word or clear the filters.', reset_filters: 'Clear filters',

    home: 'Home', weight: 'Weight', kg: 'kg', flavor: 'Flavour',
    secure_pay: 'Secure payment (card / Benefit)', gcc_delivery: 'Delivery to 6 Gulf countries', natural: 'Carefully chosen ingredients',
    tab_desc: 'Description', tab_ship: 'Shipping & delivery',
    ship_tab_text: 'Flat rate inside Bahrain; the rest of the Gulf ships with UBEX based on order weight. Delivery for this item on its own:',
    related: 'You may also like', not_found: 'Product not found', back_to_shop: 'Back to shop',
    prices_in: 'Prices in {cur}', change: 'Change',

    co_title: 'Checkout', co_text: 'One step away from the sour.', co_billing: 'Delivery details',
    f_first: 'First name', f_last: 'Last name', f_country: 'Country / Region',
    f_addr1: 'Street / Area', f_addr1_ph: 'House number and street / area',
    f_addr2: 'Apartment, suite, unit, etc.', f_addr2_ph: 'Apartment, suite, unit, etc. (optional)',
    f_city: 'City', f_state: 'Region', f_phone: 'Phone', f_email: 'Email', f_notes: 'Order notes (optional)',
    optional: '(optional)', co_payment: 'Payment method', pay_gatee: 'Debit / credit card or BenefitPay',
    pay_note: 'Payments are processed securely through Gate-E.', place_order: 'Place order', co_summary: 'Your order',
    co_empty: 'Your cart is empty, add some products first.',
    err_required: 'This field is required', err_email: 'Enter a valid email', err_phone: 'Enter a valid phone number',
    co_done_title: 'Your order is ready! 🎉',
    co_done_text: 'This is a front-end preview, so no payment was taken. Send your order to us on WhatsApp and we’ll complete it with you.',
    co_whatsapp: 'Send order on WhatsApp', co_back: 'Edit order', wa_order_intro: 'Hi Tartebz, I’d like to order:',

    about_title: 'Who are we?',
    about_text: 'Tartebz is a shop for unique, modern treats, focused on sour and sweet qamar al-din in a wide, high-quality range for every taste. Since we started in the Kingdom of Bahrain, we’ve combined traditional flavours with creative presentation to give our customers an unforgettable treat.',
    values_kicker: 'What we believe in', values_title: 'Our values',
    val_1_t: 'Authenticity', val_1: 'Inspired by Arabic sweets heritage, served in a modern way.',
    val_2_t: 'Innovation', val_2: 'Always developing new recipes and unusual taste experiences.',
    val_3_t: 'Quality', val_3: 'The best ingredients and the most careful preparation.',
    val_4_t: 'Customer happiness', val_4: 'Your experience comes first, from order to delivery.',
    val_5_t: 'Excellence', val_5: 'A product that’s unforgettable to look at and to taste.',
    founding_title: 'How it started',
    founding_1: 'Tartebz began with a passion for giving a classic Arabic treat, qamar al-din, a new soul, aimed at a young generation of food lovers who enjoy bold flavours.',
    founding_2: 'It started as a small home project that quickly took off on social media, and grew into a brand of its own that blends tradition and modernity, with wide reach and media attention today.',
    media_title: 'Awards & media coverage',
    media_1: 'Tartebz has received wide attention from local media: our story was featured in Al Watan newspaper, and we appeared on Bahrain TV – Channel 1 in reports on successful youth businesses.',
    media_2: 'We were also honoured by visits from prominent figures, including His Excellency Sheikh Khalifa bin Ahmed Al Khalifa, in support of national businesses.',
    media_3: 'This recognition reflects our pride that Tartebz is more than a sweet shop: it’s a Bahraini success story that blends authenticity and renewal.',

    contact_title: 'Contact us', contact_text: 'Got a question or a special order for an occasion? We’re here.',
    c_email: 'Email', c_phone: 'Phone', c_whatsapp: 'WhatsApp', c_address: 'Address', c_cr: 'Commercial registration',
    c_form_title: 'Send us a message', c_name: 'Name', c_msg: 'Your message', c_send: 'Send on WhatsApp', open_maps: 'Open in Maps',
    c_special: 'Occasion orders', c_special_text: 'Custom packs for weddings, newborns, events and companies.',

    iab_text: '📱 To pay with Benefit successfully, open the site in your browser (Safari or Chrome): tap “⋯” at the top, then “Open in browser”.',
    iab_copy: 'Copy site link', iab_copied: '✓ Copied'
  }
};
