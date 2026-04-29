// Pantry inventory data model + helpers
// An "item" is { name, inStock, qty, unit, category, lastBought, purchaseCount, addedAt }

(function() {
  // -------- Normalization --------
  function normalizeName(s) {
    return String(s || '').toLowerCase().replace(/[^a-z0-9\s]/g, '').replace(/\s+/g, ' ').trim();
  }

  // -------- Auto-categorize --------
  // Order: most specific keywords first; first match wins
  const CATEGORY_RULES = [
    ['Spices',     ['salt','pepper','cumin','paprika','cinnamon','nutmeg','turmeric','curry','chili powder','chilli powder','oregano','thyme','rosemary','basil','bay leaf','cardamom','clove','coriander seed','mustard seed','fennel','sumac','za\'atar','zaatar','gochugaru','sichuan pepper','star anise','allspice','saffron','vanilla','dried','spice','herbs de provence','garam masala','five spice','everything bagel']],
    ['Produce',    ['onion','garlic','tomato','potato','carrot','celery','lemon','lime','orange','apple','banana','grape','berry','strawberry','blueberry','raspberry','spinach','kale','lettuce','arugula','cabbage','broccoli','cauliflower','cucumber','zucchini','squash','pumpkin','pepper','chili','jalapeno','cilantro','parsley','mint','basil','dill','scallion','green onion','leek','shallot','ginger','avocado','mushroom','eggplant','aubergine','pear','peach','plum','mango','pineapple','watermelon','melon','radish','beet','turnip','sweet potato','yam','corn','peas','green bean','asparagus','bok choy','chard','okra','fennel bulb','daikon','sprouts','herb']],
    ['Dairy',      ['milk','cream','butter','yogurt','yoghurt','cheese','feta','mozzarella','parmesan','cheddar','ricotta','cream cheese','sour cream','cottage cheese','mascarpone','halloumi','paneer','goat cheese','brie','swiss','provolone','egg','eggs']],
    ['Meat & Fish',['chicken','beef','pork','lamb','turkey','duck','bacon','sausage','ham','prosciutto','salami','steak','ground beef','mince','chop','rib','brisket','tenderloin','fish','salmon','tuna','cod','tilapia','halibut','trout','sardine','anchovy','shrimp','prawn','scallop','mussel','clam','oyster','lobster','crab','squid','octopus']],
    ['Bakery',     ['bread','baguette','roll','bun','tortilla','pita','naan','focaccia','ciabatta','sourdough','bagel','english muffin','crumpet','pastry','croissant','cracker','breadcrumb']],
    ['Frozen',     ['frozen','ice cream','sorbet','frozen pea','frozen berry']],
    ['Pantry',     ['oil','olive oil','vegetable oil','sesame oil','vinegar','soy sauce','tamari','fish sauce','oyster sauce','hoisin','sriracha','ketchup','mustard','mayo','mayonnaise','tahini','miso','peanut butter','jam','jelly','honey','maple syrup','sugar','brown sugar','flour','rice','pasta','noodle','spaghetti','penne','linguine','ramen','udon','soba','quinoa','couscous','bulgur','farro','barley','oats','oatmeal','cornmeal','polenta','semolina','baking powder','baking soda','yeast','cornstarch','starch','stock','broth','bouillon','can','canned','tomato paste','tomato sauce','passata','coconut milk','chickpea','bean','black bean','kidney bean','lentil','split pea','tuna can','sardine can','olive','caper','pickle','sun-dried','nuts','almond','walnut','cashew','pistachio','pecan','peanut','seed','sesame','poppy','chia','flax','sunflower','pumpkin seed','raisin','date','dried fruit','chocolate','cocoa','tea','coffee','wine','sake','mirin','rice wine']],
  ];

  function categorize(name) {
    const n = normalizeName(name);
    for (const [cat, keywords] of CATEGORY_RULES) {
      for (const kw of keywords) {
        if (n === kw || n.includes(kw) || kw.includes(n)) return cat;
      }
    }
    return 'Other';
  }

  // -------- Defaults: 30 common kitchen staples --------
  const STAPLES = [
    // Spices
    'salt','black pepper','cumin','paprika','cinnamon','dried oregano','red pepper flakes',
    // Produce (long-keepers)
    'onion','garlic','lemon','ginger',
    // Dairy
    'butter','eggs','milk','parmesan',
    // Pantry
    'olive oil','vegetable oil','soy sauce','vinegar','flour','rice','pasta','sugar','brown sugar',
    'baking powder','baking soda','tomato paste','canned tomatoes','chickpeas','black beans','honey','peanut butter',
  ];

  function makeItem(name, opts = {}) {
    const now = Date.now();
    return {
      name: name.trim(),
      key: normalizeName(name),
      inStock: opts.inStock !== false,
      qty: opts.qty || '',
      unit: opts.unit || '',
      category: opts.category || categorize(name),
      lastBought: opts.lastBought || null,
      purchaseCount: opts.purchaseCount || 0,
      addedAt: opts.addedAt || now,
    };
  }

  function defaultPantryV2() {
    const seen = new Set();
    return STAPLES.filter(n => {
      const k = normalizeName(n);
      if (seen.has(k)) return false; seen.add(k); return true;
    }).map(name => makeItem(name, { inStock: true }));
  }

  // -------- Migration: array of strings -> array of items --------
  function migratePantry(raw) {
    if (!Array.isArray(raw)) return defaultPantryV2();
    if (raw.length === 0) return defaultPantryV2();
    // already migrated?
    if (typeof raw[0] === 'object' && raw[0] !== null && 'inStock' in raw[0]) return raw;
    // legacy array of strings -> all in stock, auto-categorized
    const seen = new Set();
    return raw.filter(s => typeof s === 'string').map(s => {
      const k = normalizeName(s);
      if (seen.has(k)) return null; seen.add(k);
      return makeItem(s, { inStock: true });
    }).filter(Boolean);
  }

  // -------- Item ops (return NEW arrays — pure) --------
  function findIndex(pantry, name) {
    const k = normalizeName(name);
    return pantry.findIndex(p => p.key === k);
  }

  function addOrUpdate(pantry, name, patch = {}) {
    const i = findIndex(pantry, name);
    if (i >= 0) {
      const next = pantry.slice();
      next[i] = { ...next[i], ...patch };
      // re-sync key if name changed
      if (patch.name) next[i].key = normalizeName(patch.name);
      return next;
    }
    return [...pantry, makeItem(name, patch)];
  }

  function removeByKey(pantry, name) {
    const k = normalizeName(name);
    return pantry.filter(p => p.key !== k);
  }

  function toggleStock(pantry, name) {
    const i = findIndex(pantry, name);
    if (i < 0) return pantry;
    const next = pantry.slice();
    next[i] = { ...next[i], inStock: !next[i].inStock };
    return next;
  }

  function recordPurchase(pantry, name) {
    const i = findIndex(pantry, name);
    const now = Date.now();
    if (i < 0) {
      // first time: create as in-stock with count=1
      return [...pantry, makeItem(name, { inStock: true, lastBought: now, purchaseCount: 1 })];
    }
    const next = pantry.slice();
    next[i] = { ...next[i], inStock: true, lastBought: now, purchaseCount: (next[i].purchaseCount || 0) + 1 };
    return next;
  }

  // -------- Recipe matching --------
  // Returns true if `ingredientName` is satisfied by an in-stock pantry item
  function hasIngredient(pantry, ingredientName) {
    const ing = normalizeName(ingredientName);
    if (!ing) return false;
    return pantry.some(p => p.inStock && (
      p.key === ing ||
      ing.includes(p.key) ||
      p.key.includes(ing)
    ));
  }

  // -------- Display helpers --------
  function timeAgo(ts) {
    if (!ts) return null;
    const days = Math.floor((Date.now() - ts) / 86400000);
    if (days < 1) return 'today';
    if (days === 1) return 'yesterday';
    if (days < 7) return `${days} days ago`;
    if (days < 14) return 'last week';
    if (days < 30) return `${Math.floor(days / 7)} weeks ago`;
    if (days < 60) return 'last month';
    if (days < 365) return `${Math.floor(days / 30)} months ago`;
    return `${Math.floor(days / 365)} years ago`;
  }

  const CATEGORIES = ['Produce', 'Dairy', 'Meat & Fish', 'Bakery', 'Pantry', 'Spices', 'Frozen', 'Other'];
  const CATEGORY_GLYPHS = {
    'Produce': '🥕', 'Dairy': '🧀', 'Meat & Fish': '🥩', 'Bakery': '🍞',
    'Pantry': '🥫', 'Spices': '🌶️', 'Frozen': '🧊', 'Other': '📦',
  };

  window.PantryData = {
    normalizeName, categorize, defaultPantryV2, migratePantry,
    findIndex, addOrUpdate, removeByKey, toggleStock, recordPurchase,
    hasIngredient, timeAgo, CATEGORIES, CATEGORY_GLYPHS, makeItem,
  };
})();
