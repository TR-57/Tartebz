/* Tartebz — built-in shop data, used ONLY when the server cannot be reached and the browser has no saved copy
   (live.js loads this file on demand). The real catalogue comes from /api/storefront. */
window.TZ = window.TZ || {};
TZ.fallback = {};
TZ.fallback.categories = [
  { id: 'best-sellers', name: { ar: 'الأكثر مبيعاً', en: 'Best sellers' }, desc: { ar: 'النكهات اللي الكل يرجع لها', en: 'The flavours everyone comes back for' }, icon: 'star' },
  { id: 'sweet-sour', name: { ar: 'حامض حلو', en: 'Sweet & sour' }, desc: { ar: 'توازن مثالي بين الحلاوة والحموضة', en: 'The perfect balance of sweet and tang' }, icon: 'drop' },
  { id: 'electric-sour', name: { ar: 'حامض كهرباء', en: 'Electric sour' }, desc: { ar: 'حموضة قصوى لعشّاق التحدي', en: 'Extreme sour for the brave' }, icon: 'bolt' },
  { id: 'mixed', name: { ar: 'مشكل', en: 'Mixed' }, desc: { ar: 'تشكيلة من كل النكهات في بكج واحد', en: 'Every flavour in one pack' }, icon: 'mix' }
];

TZ.fallback.products = [
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
