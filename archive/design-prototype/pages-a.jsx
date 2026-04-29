// Pages: Browse, Detail, Pantry, Shopping, Plan, Favorites, Add
// All pages get { state, actions } props

const { useState, useMemo, useEffect, useRef } = React;

// ============ BROWSE PAGE ============
function BrowsePage({ state, actions }) {
  const { RECIPES } = window.RECIPE_DATA;
  const [search, setSearch] = useState('');
  const [activeCuisine, setActiveCuisine] = useState('All');
  const [activeTime, setActiveTime] = useState('All');
  const [activeTag, setActiveTag] = useState('All');

  const allRecipes = [...RECIPES, ...state.customRecipes];
  const cuisines = ['All', ...new Set(allRecipes.map(r => r.cuisine))];
  const tags = ['All', ...new Set(allRecipes.flatMap(r => r.tags || []))];

  const timeOptions = [
    { label: 'All', test: () => true },
    { label: '< 30 min', test: r => r.time < 30 },
    { label: '30–60 min', test: r => r.time >= 30 && r.time <= 60 },
    { label: '> 60 min', test: r => r.time > 60 },
  ];

  const filtered = allRecipes.filter(r => {
    if (search && !r.title.toLowerCase().includes(search.toLowerCase()) &&
        !r.cuisine.toLowerCase().includes(search.toLowerCase()) &&
        !(r.tags || []).some(t => t.toLowerCase().includes(search.toLowerCase()))) return false;
    if (activeCuisine !== 'All' && r.cuisine !== activeCuisine) return false;
    if (activeTag !== 'All' && !(r.tags || []).includes(activeTag)) return false;
    const tOpt = timeOptions.find(t => t.label === activeTime);
    if (tOpt && !tOpt.test(r)) return false;
    return true;
  });

  return (
    <div className="page">
      <div className="hero">
        <div className="hero-stickers">
          <div className="hero-sticker" style={{top: '20%', right: '8%', background: '#FFE9A8', transform: 'rotate(-12deg)'}}>🍋</div>
          <div className="hero-sticker" style={{top: '55%', right: '20%', background: '#FFB3B3', transform: 'rotate(15deg)'}}>🌶️</div>
          <div className="hero-sticker" style={{top: '15%', right: '28%', background: '#C8F0DC', transform: 'rotate(8deg)', width: 60, height: 60, fontSize: 28}}>🌿</div>
        </div>
        <div className="page-eyebrow" style={{color: 'rgba(251,247,240,0.6)', marginBottom: 8}}>YOUR LITTLE COOKBOOK</div>
        <h1>What are<br/>we cooking?</h1>
        <p style={{maxWidth: 480, marginTop: 14, opacity: 0.85}}>{allRecipes.length} recipes from {cuisines.length - 1} cuisines. Search, filter, save, plan your week — or hit pantry mode to see what you can make right now.</p>
        <div className="row" style={{marginTop: 20}}>
          <button className="btn btn-mint" onClick={() => actions.go('pantry')}>🥕 What's in my pantry?</button>
          <button className="btn btn-lilac" onClick={() => actions.go('add')}>＋ Add a recipe</button>
        </div>
      </div>

      <div className="filter-bar">
        <input
          className="search-input"
          placeholder="Search recipes, cuisines, tags…"
          value={search}
          onChange={e => setSearch(e.target.value)}
        />
        <div className="row" style={{gap: 6}}>
          {cuisines.slice(0, 7).map(c => (
            <button key={c} className={'filter-chip' + (activeCuisine === c ? ' active' : '')} onClick={() => setActiveCuisine(c)}>{c}</button>
          ))}
        </div>
        <div className="row" style={{gap: 6}}>
          {timeOptions.map(t => (
            <button key={t.label} className={'filter-chip' + (activeTime === t.label ? ' active' : '')} onClick={() => setActiveTime(t.label)}>{t.label}</button>
          ))}
        </div>
      </div>

      <div className="row" style={{marginBottom: 20, gap: 6, overflowX: 'auto'}}>
        {tags.slice(0, 12).map(t => (
          <button key={t} className={'pill' + (activeTag === t ? ' pill-coral' : '')} style={{cursor: 'pointer'}} onClick={() => setActiveTag(t)}>#{t}</button>
        ))}
      </div>

      <div className="page-header" style={{marginBottom: 16}}>
        <div className="page-title-wrap">
          <h2>All recipes</h2>
          <span className="page-count-pill">{filtered.length} found</span>
        </div>
      </div>

      {filtered.length === 0 ? (
        <div className="empty">
          <div className="empty-glyph">🤷</div>
          <div>Nothing matches. Try clearing filters?</div>
        </div>
      ) : (
        <div className="grid">
          {filtered.map(r => <RecipeCard key={r.id} recipe={r} state={state} actions={actions} />)}
        </div>
      )}
    </div>
  );
}

// ============ RECIPE CARD ============
function RecipeCard({ recipe, state, actions }) {
  const { STICKER_COLORS } = window.RECIPE_DATA;
  const colorObj = STICKER_COLORS[recipe.color] || STICKER_COLORS.mint;
  const isFav = state.favorites.includes(recipe.id);
  const showPhoto = recipe.photo && state.imageMode !== 'glyph-only' &&
    (state.imageMode === 'photo-only' || state.imageMode === 'mix');

  return (
    <div className="recipe-card" onClick={() => actions.openRecipe(recipe.id)}>
      <div
        className={'recipe-card-img' + (showPhoto ? ' has-photo' : '')}
        style={{
          background: showPhoto ? `url(${recipe.photo})` : colorObj.bg,
          backgroundSize: 'cover',
          backgroundPosition: 'center',
        }}
      >
        {!showPhoto && <span style={{filter: 'drop-shadow(2px 2px 0 rgba(0,0,0,0.1))'}}>{recipe.glyph}</span>}
        <button
          className={'recipe-card-fav' + (isFav ? ' active' : '')}
          onClick={(e) => { e.stopPropagation(); actions.toggleFav(recipe.id); }}
          aria-label="favorite"
        >{isFav ? '♥' : '♡'}</button>
        <span className="recipe-card-time-pill">⏱ {recipe.time}m</span>
      </div>
      <div className="recipe-card-body">
        <div className="recipe-card-cuisine">{recipe.cuisine} · {recipe.difficulty}</div>
        <div className="recipe-card-title">{recipe.title}</div>
        <p className="recipe-card-summary">{recipe.summary}</p>
        <div className="recipe-card-tags">
          {(recipe.tags || []).slice(0, 3).map((t, i) => {
            const colors = ['pill-mint','pill-butter','pill-peach','pill-lilac','pill-sky','pill-blush'];
            return <span key={t} className={'pill ' + colors[i % colors.length]}>#{t}</span>;
          })}
        </div>
      </div>
    </div>
  );
}

// ============ DETAIL PAGE ============
function DetailPage({ state, actions }) {
  const { RECIPES, STICKER_COLORS } = window.RECIPE_DATA;
  const all = [...RECIPES, ...state.customRecipes];
  const recipe = all.find(r => r.id === state.openRecipeId);
  const [scaledServings, setScaledServings] = useState(recipe?.servings || 2);
  const [haveChecked, setHaveChecked] = useState({});
  const [cookMode, setCookMode] = useState(false);
  const [notes, setNotes] = useState(state.notes[recipe?.id] || '');

  useEffect(() => { setNotes(state.notes[recipe?.id] || ''); }, [recipe?.id]);

  if (!recipe) return null;
  const colorObj = STICKER_COLORS[recipe.color] || STICKER_COLORS.mint;
  const isFav = state.favorites.includes(recipe.id);
  const scale = scaledServings / recipe.servings;

  const fmtQty = (q, unit) => {
    let v = q * scale;
    let u = unit;
    // Imperial conversion
    if (state.units === 'imperial') {
      if (unit === 'g')  { v = v / 28.35; u = 'oz'; }
      else if (unit === 'kg') { v = v * 2.205; u = 'lb'; }
      else if (unit === 'ml') { v = v / 29.57; u = 'fl oz'; }
      else if (unit === 'l')  { v = v * 4.227; u = 'cup'; }
    }
    if (v < 1 && v > 0) {
      const fracs = [[0.125, '⅛'], [0.25, '¼'], [0.33, '⅓'], [0.5, '½'], [0.66, '⅔'], [0.75, '¾']];
      const closest = fracs.reduce((p, c) => Math.abs(c[0] - v) < Math.abs(p[0] - v) ? c : p);
      return closest[1] + (u ? ' ' + u : '');
    }
    const n = Number.isInteger(v) ? v : v.toFixed(1).replace(/\.0$/, '');
    return n + (u ? ' ' + u : '');
  };

  const printRecipe = () => window.print();

  return (
    <div className="page recipe-print">
      <div className="row no-print" style={{marginBottom: 20}}>
        <button className="btn btn-ghost" onClick={() => actions.go('browse')}>← All recipes</button>
        <div style={{flex: 1}} />
        <button className="btn btn-sm" onClick={printRecipe}>🖨️ Print</button>
        <button className={'btn btn-sm' + (isFav ? ' btn-coral' : '')} onClick={() => actions.toggleFav(recipe.id)}>{isFav ? '♥ Saved' : '♡ Save'}</button>
        <button className="btn btn-sm btn-mint" onClick={() => actions.addAllToShopping(recipe, scale)}>🛒 Add ingredients</button>
        <button className="btn btn-sm btn-primary" onClick={() => setCookMode(true)}>▶ Cook mode</button>
      </div>

      <div className="detail-grid">
        <div>
          <div
            className={'detail-hero' + (recipe.photo ? '' : ' no-photo')}
            style={{
              background: recipe.photo ? `url(${recipe.photo})` : colorObj.bg,
              backgroundSize: 'cover',
              backgroundPosition: 'center',
            }}
          >
            {!recipe.photo && <span>{recipe.glyph}</span>}
          </div>
          <div className="detail-section">
            <h2>Ingredients</h2>
            <div className="row no-print" style={{marginBottom: 14}}>
              <span className="mono" style={{fontSize: 12, color: 'var(--ink-soft)'}}>SCALE:</span>
              <button className="btn btn-sm" onClick={() => setScaledServings(Math.max(1, scaledServings - 1))}>−</button>
              <span className="mono" style={{fontWeight: 700}}>{scaledServings} servings</span>
              <button className="btn btn-sm" onClick={() => setScaledServings(scaledServings + 1)}>+</button>
            </div>
            <ul className="ingredient-list">
              {recipe.ingredients.map((ing, i) => {
                const inPantry = window.PantryData.hasIngredient(state.pantry, ing.name);
                return (
                  <li key={i} className="ingredient-row">
                    <span className="ingredient-qty">{fmtQty(ing.qty, ing.unit)}</span>
                    <span className="ingredient-name">{ing.name}</span>
                    {inPantry && <span className="ingredient-have">have ✓</span>}
                  </li>
                );
              })}
            </ul>
          </div>
        </div>

        <div>
          <div className="page-eyebrow">{recipe.cuisine}</div>
          <h1 style={{marginTop: 4}}>{recipe.title}</h1>
          <p style={{fontSize: 18, color: 'var(--ink-2)', marginTop: 10}}>{recipe.summary}</p>

          <div className="detail-meta-row">
            <div className="detail-stat" style={{background: 'var(--butter)'}}>
              <span className="detail-stat-label">TIME</span>
              <span className="detail-stat-value">{recipe.time} min</span>
            </div>
            <div className="detail-stat" style={{background: 'var(--mint)'}}>
              <span className="detail-stat-label">SERVES</span>
              <span className="detail-stat-value">{recipe.servings}</span>
            </div>
            <div className="detail-stat" style={{background: 'var(--lilac)'}}>
              <span className="detail-stat-label">DIFFICULTY</span>
              <span className="detail-stat-value">{recipe.difficulty}</span>
            </div>
            <div className="detail-stat" style={{background: 'var(--peach)'}}>
              <span className="detail-stat-label">INGREDIENTS</span>
              <span className="detail-stat-value">{recipe.ingredients.length}</span>
            </div>
          </div>

          <div className="row" style={{marginTop: 6}}>
            {(recipe.tags || []).map((t, i) => {
              const colors = ['pill-mint','pill-butter','pill-peach','pill-lilac','pill-sky','pill-blush'];
              return <span key={t} className={'pill ' + colors[i % colors.length]}>#{t}</span>;
            })}
          </div>

          <div className="detail-section">
            <h2>Method</h2>
            <ol className="steps-list">
              {recipe.steps.map((s, i) => (
                <li key={i} className="step-row">
                  <span className="step-num">{i + 1}</span>
                  <span className="step-text">{s}</span>
                </li>
              ))}
            </ol>
          </div>

          <div className="detail-section no-print">
            <h2>📝 Notes to self</h2>
            <textarea
              className="notes-area"
              placeholder="Made this Tuesday — needed more salt. Use less coconut milk next time…"
              value={notes}
              onChange={e => { setNotes(e.target.value); actions.setNotes(recipe.id, e.target.value); }}
            />
          </div>
        </div>
      </div>

      {cookMode && <CookMode recipe={recipe} onClose={() => setCookMode(false)} />}
    </div>
  );
}

// ============ COOK MODE ============
function CookMode({ recipe, onClose }) {
  const [step, setStep] = useState(0);
  const total = recipe.steps.length;
  const pct = ((step + 1) / total) * 100;

  useEffect(() => {
    const onKey = e => {
      if (e.key === 'ArrowRight') setStep(s => Math.min(total - 1, s + 1));
      if (e.key === 'ArrowLeft') setStep(s => Math.max(0, s - 1));
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [total, onClose]);

  return (
    <div className="cook-overlay">
      <div className="cook-header">
        <div>
          <div className="page-eyebrow">COOKING</div>
          <h2 style={{marginTop: 4}}>{recipe.title} {recipe.glyph}</h2>
        </div>
        <button className="btn" onClick={onClose}>✕ Exit</button>
      </div>
      <div className="cook-progress"><div className="cook-progress-fill" style={{width: pct + '%'}} /></div>
      <div className="cook-step-num">STEP {step + 1} OF {total}</div>
      <div className="cook-step-text">{recipe.steps[step]}</div>
      <div className="cook-controls">
        <button className="btn" disabled={step === 0} onClick={() => setStep(step - 1)}>← Back</button>
        <span className="mono muted">use ← / → keys</span>
        {step < total - 1 ? (
          <button className="btn btn-primary" onClick={() => setStep(step + 1)}>Next →</button>
        ) : (
          <button className="btn btn-mint" onClick={onClose}>🎉 Done!</button>
        )}
      </div>
    </div>
  );
}

window.PagesA = { BrowsePage, RecipeCard, DetailPage, CookMode };
