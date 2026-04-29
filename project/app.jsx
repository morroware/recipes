// Main app shell

const { useState: useStateApp, useEffect: useEffectApp } = React;

const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "density": "cozy",
  "theme": "rainbow",
  "mode": "light",
  "fontPair": "default",
  "radius": "default",
  "cardStyle": "mix",
  "stickerRotate": true,
  "dotGrid": true,
  "units": "metric"
}/*EDITMODE-END*/;

function App() {
  const [tweaks, setTweak] = useTweaks(TWEAK_DEFAULTS);
  const [route, setRoute] = useStateApp({ page: 'browse', recipeId: null });
  const [favorites, setFavorites] = useStateApp([]);
  const [pantry, setPantry] = useStateApp(window.PantryData.defaultPantryV2());
  const [shopping, setShopping] = useStateApp([]);
  const [plan, setPlan] = useStateApp(window.RECIPE_DATA.DEFAULT_MEAL_PLAN);
  const [notes, setNotesObj] = useStateApp({});
  const [customRecipes, setCustomRecipes] = useStateApp([]);

  // persist
  useEffectApp(() => {
    try {
      const saved = JSON.parse(localStorage.getItem('recipeBook') || '{}');
      if (saved.favorites) setFavorites(saved.favorites);
      if (saved.pantry) setPantry(window.PantryData.migratePantry(saved.pantry));
      if (saved.shopping) setShopping(saved.shopping);
      if (saved.plan) setPlan(saved.plan);
      if (saved.notes) setNotesObj(saved.notes);
      if (saved.customRecipes) setCustomRecipes(saved.customRecipes);
    } catch (e) {}
  }, []);

  useEffectApp(() => {
    localStorage.setItem('recipeBook', JSON.stringify({ favorites, pantry, shopping, plan, notes, customRecipes }));
  }, [favorites, pantry, shopping, plan, notes, customRecipes]);

  useEffectApp(() => {
    const r = document.documentElement;
    r.setAttribute('data-density', tweaks.density);
    r.setAttribute('data-theme', tweaks.theme);
    r.setAttribute('data-mode', tweaks.mode);
    r.setAttribute('data-fontpair', tweaks.fontPair);
    r.setAttribute('data-radius', tweaks.radius);
    r.setAttribute('data-sticker-rotate', tweaks.stickerRotate ? 'on' : 'off');
    r.setAttribute('data-dot-grid', tweaks.dotGrid ? 'on' : 'off');
  }, [tweaks]);

  const state = {
    favorites, pantry, shopping, plan, notes, customRecipes,
    openRecipeId: route.recipeId,
    imageMode: tweaks.cardStyle,
    units: tweaks.units,
  };

  const actions = {
    go: (page) => setRoute({ page, recipeId: null }),
    openRecipe: (id) => setRoute({ page: 'detail', recipeId: id }),
    toggleFav: (id) => setFavorites(favorites.includes(id) ? favorites.filter(x => x !== id) : [...favorites, id]),
    setPantry,
    addPantryItem: (name, opts) => setPantry(window.PantryData.addOrUpdate(pantry, name, opts || {})),
    removePantryItem: (name) => setPantry(window.PantryData.removeByKey(pantry, name)),
    togglePantryStock: (name) => setPantry(window.PantryData.toggleStock(pantry, name)),
    setPantryCategory: (name, category) => setPantry(window.PantryData.addOrUpdate(pantry, name, { category })),
    addOutOfStockToShopping: (name) => {
      const it = pantry.find(p => p.key === window.PantryData.normalizeName(name));
      if (!it) return;
      const exists = shopping.some(s => window.PantryData.normalizeName(s.name) === it.key);
      if (!exists) setShopping([...shopping, { name: it.name, qty: '', unit: '', source: 'pantry', checked: false }]);
    },
    moveCheckedToPantry: () => {
      const checked = shopping.filter(s => s.checked);
      if (checked.length === 0) return 0;
      let next = pantry;
      let added = 0;
      for (const item of checked) {
        const exists = window.PantryData.findIndex(next, item.name) >= 0;
        if (!exists) added += 1;
        next = window.PantryData.recordPurchase(next, item.name);
      }
      setPantry(next);
      setShopping(shopping.filter(s => !s.checked));
      return { added, moved: checked.length };
    },
    addAllToShopping: (recipe, scale = 1) => {
      const newItems = recipe.ingredients.map(ing => ({
        name: ing.name,
        qty: typeof ing.qty === 'number' ? +(ing.qty * scale).toFixed(2) : ing.qty,
        unit: ing.unit,
        source: recipe.title,
        checked: false,
      }));
      setShopping(prev => {
        const merged = [...prev];
        newItems.forEach(it => {
          if (!merged.some(m => m.name.toLowerCase() === it.name.toLowerCase() && m.source === it.source)) merged.push(it);
        });
        return merged;
      });
      // little flash
    },
    addShoppingItem: (item) => setShopping([...shopping, { ...item, checked: false }]),
    toggleShoppingItem: (i) => setShopping(shopping.map((it, j) => j === i ? { ...it, checked: !it.checked } : it)),
    removeShoppingItem: (i) => setShopping(shopping.filter((_, j) => j !== i)),
    clearShopping: () => setShopping([]),
    setPlanDay: (day, recipeId) => setPlan({ ...plan, [day]: recipeId }),
    clearPlan: () => setPlan(window.RECIPE_DATA.DEFAULT_MEAL_PLAN),
    setNotes: (id, text) => setNotesObj({ ...notes, [id]: text }),
    addCustom: (recipe) => setCustomRecipes([...customRecipes, recipe]),
  };

  const tabs = [
    { id: 'browse', label: 'Recipes', icon: '📖' },
    { id: 'pantry', label: 'Pantry', icon: '🥕' },
    { id: 'plan', label: 'Plan', icon: '📅' },
    { id: 'shopping', label: 'Shopping', icon: '🛒' },
    { id: 'favorites', label: 'Favorites', icon: '♥' },
    { id: 'print', label: 'Print', icon: '🖨️' },
    { id: 'add', label: 'Add', icon: '＋' },
  ];

  let body = null;
  switch (route.page) {
    case 'browse':    body = <window.PagesA.BrowsePage state={state} actions={actions} />; break;
    case 'detail':    body = <window.PagesA.DetailPage state={state} actions={actions} />; break;
    case 'pantry':    body = <window.PagesB.PantryPage state={state} actions={actions} />; break;
    case 'shopping':  body = <window.PagesB.ShoppingPage state={state} actions={actions} />; break;
    case 'plan':      body = <window.PagesB.PlanPage state={state} actions={actions} />; break;
    case 'favorites': body = <window.PagesB.FavoritesPage state={state} actions={actions} />; break;
    case 'add':       body = <window.PagesB.AddPage state={state} actions={actions} />; break;
    case 'print':     body = <window.PagesC.PrintHubPage state={state} actions={actions} />; break;
    default:          body = <window.PagesA.BrowsePage state={state} actions={actions} />;
  }

  const shoppingBadge = shopping.filter(s => !s.checked).length;
  const favBadge = favorites.length;

  return (
    <React.Fragment>
      <nav className="nav no-print">
        <div className="nav-brand" onClick={() => actions.go('browse')} style={{cursor: 'pointer'}}>
          <span className="nav-brand-glyph">🍳</span>
          <span>my little<br/>cookbook</span>
        </div>
        <div className="nav-tabs">
          {tabs.map(t => (
            <button
              key={t.id}
              className={'nav-tab' + (route.page === t.id || (route.page === 'detail' && t.id === 'browse') ? ' active' : '')}
              onClick={() => actions.go(t.id)}
            >
              {t.icon} {t.label}
              {t.id === 'shopping' && shoppingBadge > 0 && <span style={{marginLeft: 6, padding: '1px 6px', background: 'var(--coral)', borderRadius: 999, fontSize: 11, color: 'var(--ink)'}}>{shoppingBadge}</span>}
              {t.id === 'favorites' && favBadge > 0 && <span style={{marginLeft: 6, padding: '1px 6px', background: 'var(--blush)', borderRadius: 999, fontSize: 11, color: 'var(--ink)'}}>{favBadge}</span>}
            </button>
          ))}
        </div>
      </nav>
      {body}

      <TweaksPanel title="Tweaks">
        <TweakSection title="Theme">
          <TweakSelect
            label="Color palette"
            value={tweaks.theme}
            options={[
              { value: 'rainbow', label: '🌈 Pastel rainbow (default)' },
              { value: 'sunset',  label: '🌅 Sunset (warm)' },
              { value: 'ocean',   label: '🌊 Ocean (cool)' },
              { value: 'garden',  label: '🌿 Garden (earthy)' },
            ]}
            onChange={v => setTweak('theme', v)}
          />
          <TweakRadio
            label="Mode"
            value={tweaks.mode}
            options={[{ value: 'light', label: '☀ Light' }, { value: 'dark', label: '🌙 Dark' }]}
            onChange={v => setTweak('mode', v)}
          />
        </TweakSection>

        <TweakSection title="Typography">
          <TweakSelect
            label="Font pair"
            value={tweaks.fontPair}
            options={[
              { value: 'default',  label: 'Bricolage + DM Sans (default)' },
              { value: 'serif',    label: 'Fraunces + Inter (editorial)' },
              { value: 'mono',     label: 'Space Grotesk + JetBrains (zine)' },
              { value: 'rounded',  label: 'Nunito (friendly)' },
            ]}
            onChange={v => setTweak('fontPair', v)}
          />
        </TweakSection>

        <TweakSection title="Layout">
          <TweakRadio
            label="Density"
            value={tweaks.density}
            options={[
              { value: 'compact', label: 'Compact' },
              { value: 'cozy',    label: 'Cozy' },
              { value: 'airy',    label: 'Airy' },
            ]}
            onChange={v => setTweak('density', v)}
          />
          <TweakRadio
            label="Roundness"
            value={tweaks.radius}
            options={[
              { value: 'sharp',   label: 'Sharp' },
              { value: 'default', label: 'Default' },
              { value: 'round',   label: 'Round' },
            ]}
            onChange={v => setTweak('radius', v)}
          />
        </TweakSection>

        <TweakSection title="Cards">
          <TweakRadio
            label="Card image style"
            value={tweaks.cardStyle}
            options={[
              { value: 'mix',         label: 'Mix' },
              { value: 'photo-only',  label: 'Photos' },
              { value: 'glyph-only',  label: 'Glyphs' },
            ]}
            onChange={v => setTweak('cardStyle', v)}
          />
          <TweakToggle label="Sticker rotation (tilt cards on hover)" value={tweaks.stickerRotate} onChange={v => setTweak('stickerRotate', v)} />
          <TweakToggle label="Dot-grid background" value={tweaks.dotGrid} onChange={v => setTweak('dotGrid', v)} />
        </TweakSection>

        <TweakSection title="Recipes">
          <TweakRadio
            label="Units"
            value={tweaks.units}
            options={[{ value: 'metric', label: 'Metric (g, ml)' }, { value: 'imperial', label: 'Imperial (oz, cups)' }]}
            onChange={v => setTweak('units', v)}
          />
        </TweakSection>

        <TweakSection title="Data">
          <TweakButton onClick={() => { localStorage.removeItem('recipeBook'); window.location.reload(); }}>Reset all data</TweakButton>
        </TweakSection>
      </TweaksPanel>
    </React.Fragment>
  );
}

ReactDOM.createRoot(document.getElementById('app')).render(<App />);
