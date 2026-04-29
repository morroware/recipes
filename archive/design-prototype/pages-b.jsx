// Pages B: Pantry, Shopping, Plan, Favorites, Add

const { useState: useStateB, useMemo: useMemoB, useEffect: useEffectB } = React;

// ============ PANTRY / SEARCH BY INGREDIENT ============
function PantryPage({ state, actions }) {
  const { RECIPES } = window.RECIPE_DATA;
  const PD = window.PantryData;
  const [mode, setMode] = useStateB('pantry'); // 'pantry' or 'tag'
  const [draft, setDraft] = useStateB('');
  const [tagSearch, setTagSearch] = useStateB([]);
  const [tagDraft, setTagDraft] = useStateB('');
  const [showOOS, setShowOOS] = useStateB(false);
  const [editingCat, setEditingCat] = useStateB(null);

  const allRecipes = [...RECIPES, ...state.customRecipes];
  const pantry = state.pantry;
  const inStock = pantry.filter(p => p.inStock);
  const outOfStock = pantry.filter(p => !p.inStock);

  const addItem = () => {
    const name = draft.trim();
    if (!name) return;
    actions.addPantryItem(name, { inStock: true });
    setDraft('');
  };

  const addTag = () => {
    if (!tagDraft.trim()) return;
    setTagSearch([...tagSearch, tagDraft.trim().toLowerCase()]);
    setTagDraft('');
  };

  // Group in-stock items by category, ordered
  const grouped = useMemoB(() => {
    const buckets = {};
    PD.CATEGORIES.forEach(c => { buckets[c] = []; });
    inStock.forEach(item => {
      const cat = item.category || 'Other';
      (buckets[cat] = buckets[cat] || []).push(item);
    });
    Object.values(buckets).forEach(arr => arr.sort((a, b) => a.name.localeCompare(b.name)));
    return buckets;
  }, [pantry]);

  // Most used: top 6 by purchaseCount (where >= 1)
  const mostUsed = useMemoB(() => {
    return [...pantry].filter(p => (p.purchaseCount || 0) >= 1).sort((a, b) => (b.purchaseCount || 0) - (a.purchaseCount || 0)).slice(0, 6);
  }, [pantry]);

  const pantrySuggestions = useMemoB(() => {
    if (mode !== 'pantry') return [];
    return allRecipes.map(r => {
      const total = r.ingredients.length;
      const haveCount = r.ingredients.filter(ing => PD.hasIngredient(pantry, ing.name)).length;
      const pct = total ? haveCount / total : 0;
      const missing = r.ingredients.filter(ing => !PD.hasIngredient(pantry, ing.name));
      return { recipe: r, haveCount, total, pct, missing };
    }).sort((a, b) => b.pct - a.pct);
  }, [pantry, state.customRecipes, mode]);

  const tagSuggestions = useMemoB(() => {
    if (mode !== 'tag' || tagSearch.length === 0) return [];
    return allRecipes.filter(r =>
      tagSearch.every(t => r.ingredients.some(ing => ing.name.toLowerCase().includes(t)))
    );
  }, [tagSearch, state.customRecipes, mode]);

  return (
    <div className="page">
      <div className="page-header">
        <div className="page-title-wrap">
          <div>
            <div className="page-eyebrow">YOUR KITCHEN</div>
            <h1>Pantry 🥕</h1>
          </div>
          <span className="page-count-pill">{inStock.length} in stock{outOfStock.length > 0 ? ` · ${outOfStock.length} out` : ''}</span>
        </div>
      </div>

      <div className="row" style={{marginBottom: 24, gap: 6}}>
        <button className={'filter-chip' + (mode === 'pantry' ? ' active' : '')} onClick={() => setMode('pantry')}>📦 Inventory & suggestions</button>
        <button className={'filter-chip' + (mode === 'tag' ? ' active' : '')} onClick={() => setMode('tag')}>🔎 Find by ingredient</button>
      </div>

      {mode === 'pantry' && (
        <div className="pantry-grid">
          <div className="pantry-panel">
            <h3 style={{marginBottom: 12}}>Inventory</h3>
            <p className="muted" style={{fontSize: 13, marginBottom: 14}}>Tap the checkbox to mark in stock / out. Items stay in your kitchen list either way.</p>
            <div className="row" style={{marginBottom: 14}}>
              <input
                className="search-input"
                placeholder="add an ingredient + Enter…"
                value={draft}
                onChange={e => setDraft(e.target.value)}
                onKeyDown={e => e.key === 'Enter' && addItem()}
              />
              <button className="btn btn-primary" onClick={addItem}>Add</button>
            </div>

            {mostUsed.length > 0 && (
              <div style={{marginBottom: 18, paddingBottom: 14, borderBottom: '1.5px dashed rgba(31,27,22,0.18)'}}>
                <div className="page-eyebrow" style={{marginBottom: 8}}>⭐ MOST USED</div>
                <div className="row" style={{gap: 6, flexWrap: 'wrap'}}>
                  {mostUsed.map(item => (
                    <span key={item.key} className="pill" style={{background: item.inStock ? 'var(--mint)' : 'transparent', opacity: item.inStock ? 1 : 0.6}}>
                      {item.name} <span className="muted" style={{fontSize: 11, marginLeft: 4}}>×{item.purchaseCount}</span>
                    </span>
                  ))}
                </div>
              </div>
            )}

            {inStock.length === 0 ? (
              <div className="muted" style={{fontSize: 13, padding: '18px 0'}}>Nothing in stock. Add some ingredients above.</div>
            ) : (
              <div>
                {PD.CATEGORIES.map(cat => {
                  const items = grouped[cat] || [];
                  if (items.length === 0) return null;
                  return (
                    <div key={cat} className="pantry-cat-group">
                      <div className="pantry-cat-header">
                        <span className="pantry-cat-glyph">{PD.CATEGORY_GLYPHS[cat]}</span>
                        <span className="pantry-cat-name">{cat}</span>
                        <span className="pantry-cat-count">{items.length}</span>
                      </div>
                      {items.map(item => (
                        <PantryRow key={item.key} item={item} actions={actions} editingCat={editingCat} setEditingCat={setEditingCat} />
                      ))}
                    </div>
                  );
                })}
              </div>
            )}

            {outOfStock.length > 0 && (
              <div style={{marginTop: 18, paddingTop: 14, borderTop: '2.5px dashed rgba(31,27,22,0.25)'}}>
                <button
                  className="pantry-oos-toggle"
                  onClick={() => setShowOOS(!showOOS)}
                >
                  <span style={{flex: 1, textAlign: 'left'}}>
                    {showOOS ? '▾' : '▸'} Out of stock <span className="muted" style={{fontWeight: 500}}>({outOfStock.length})</span>
                  </span>
                </button>
                {showOOS && (
                  <div style={{marginTop: 10}}>
                    {outOfStock.map(item => (
                      <PantryRow
                        key={item.key}
                        item={item}
                        actions={actions}
                        editingCat={editingCat}
                        setEditingCat={setEditingCat}
                        showRestock
                        onShop={() => actions.addOutOfStockToShopping(item.name)}
                      />
                    ))}
                  </div>
                )}
              </div>
            )}
          </div>

          <div className="pantry-panel">
            <h3 style={{marginBottom: 12}}>You can make…</h3>
            <p className="muted" style={{fontSize: 13, marginBottom: 14}}>Sorted by % match using your <strong>in-stock</strong> items only.</p>
            {inStock.length === 0 ? (
              <div className="empty" style={{padding: 30}}><div className="empty-glyph">🤔</div><div>Mark some items in stock to see suggestions.</div></div>
            ) : pantrySuggestions.slice(0, 8).map(s => (
              <div key={s.recipe.id} className="suggest-card" onClick={() => actions.openRecipe(s.recipe.id)}>
                <div className="suggest-card-glyph" style={{
                  background: s.recipe.photo ? `url(${s.recipe.photo})` : window.RECIPE_DATA.STICKER_COLORS[s.recipe.color]?.bg,
                }}>{!s.recipe.photo && s.recipe.glyph}</div>
                <div className="suggest-card-info">
                  <div className="suggest-card-title">{s.recipe.title}</div>
                  <div className="suggest-card-meta">{s.recipe.cuisine} · {s.recipe.time}m · need {s.missing.length} more</div>
                  <div className="match-bar"><div className="match-bar-fill" style={{width: (s.pct * 100) + '%'}} /></div>
                </div>
                <div className="mono" style={{fontWeight: 800, fontSize: 18}}>{Math.round(s.pct * 100)}%</div>
              </div>
            ))}
          </div>
        </div>
      )}

      {mode === 'tag' && (
        <div className="pantry-panel">
          <h3 style={{marginBottom: 12}}>Find recipes containing all of:</h3>
          <div className="row" style={{marginBottom: 14}}>
            <input
              className="search-input"
              placeholder="ingredient + Enter…"
              value={tagDraft}
              onChange={e => setTagDraft(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && addTag()}
            />
            <button className="btn btn-primary" onClick={addTag}>Add</button>
          </div>
          <div style={{marginBottom: 18}}>
            {tagSearch.map(t => (
              <span key={t} className="pantry-tag" style={{background: 'var(--butter)'}} onClick={() => setTagSearch(tagSearch.filter(x => x !== t))}>
                {t} <span className="pantry-tag-x">✕</span>
              </span>
            ))}
          </div>
          <h3 style={{marginBottom: 12, marginTop: 20}}>{tagSearch.length === 0 ? 'Add ingredients to search.' : `${tagSuggestions.length} matching recipes`}</h3>
          <div className="grid" style={{marginTop: 10}}>
            {tagSuggestions.map(r => <window.PagesA.RecipeCard key={r.id} recipe={r} state={state} actions={actions} />)}
          </div>
        </div>
      )}
    </div>
  );
}

// One row in the pantry checklist
function PantryRow({ item, actions, editingCat, setEditingCat, showRestock, onShop }) {
  const PD = window.PantryData;
  const isEditing = editingCat === item.key;
  const ago = PD.timeAgo(item.lastBought);
  return (
    <div className={'pantry-row' + (item.inStock ? '' : ' oos')}>
      <button
        className={'pantry-check' + (item.inStock ? ' checked' : '')}
        onClick={() => actions.togglePantryStock(item.name)}
        title={item.inStock ? 'Mark out of stock' : 'Mark in stock'}
        aria-label={item.inStock ? 'Mark out of stock' : 'Mark in stock'}
      />
      <div className="pantry-row-main">
        <div className="pantry-row-name">{item.name}</div>
        <div className="pantry-row-meta">
          {isEditing ? (
            <select
              className="pantry-cat-select"
              value={item.category}
              autoFocus
              onChange={(e) => { actions.setPantryCategory(item.name, e.target.value); setEditingCat(null); }}
              onBlur={() => setEditingCat(null)}
            >
              {PD.CATEGORIES.map(c => <option key={c} value={c}>{c}</option>)}
            </select>
          ) : (
            <span className="pantry-row-cat" onClick={() => setEditingCat(item.key)} title="Change category">{PD.CATEGORY_GLYPHS[item.category]} {item.category}</span>
          )}
          {item.purchaseCount > 0 && <span className="pantry-row-stat">×{item.purchaseCount} bought</span>}
          {ago && <span className="pantry-row-stat">last: {ago}</span>}
        </div>
      </div>
      {showRestock && (
        <button className="btn btn-sm btn-mint" onClick={onShop} title="Add to shopping list">+ Shop</button>
      )}
      <button className="btn btn-sm btn-ghost" onClick={() => actions.removePantryItem(item.name)} title="Remove from pantry">✕</button>
    </div>
  );
}

// ============ SHOPPING LIST ============
function ShoppingPage({ state, actions }) {
  const [draftName, setDraftName] = useStateB('');
  const [toast, setToast] = useStateB(null);

  const addManual = () => {
    if (!draftName.trim()) return;
    actions.addShoppingItem({ name: draftName.trim(), qty: '', unit: '', source: 'manual' });
    setDraftName('');
  };

  const stockUp = () => {
    const result = actions.moveCheckedToPantry();
    if (!result) return;
    const { added, moved } = result;
    let msg;
    if (added === 0) msg = `Moved ${moved} item${moved === 1 ? '' : 's'} off your list — already in pantry`;
    else if (added === moved) msg = `🥕 ${added} item${added === 1 ? '' : 's'} added to pantry`;
    else msg = `🥕 ${added} new to pantry, ${moved - added} already had`;
    setToast(msg);
    setTimeout(() => setToast(null), 2800);
  };

  const checkedCount = state.shopping.filter(s => s.checked).length;
  const allChecked = checkedCount > 0 && checkedCount === state.shopping.length;

  return (
    <div className="page">
      <div className="page-header">
        <div className="page-title-wrap">
          <div>
            <div className="page-eyebrow">GROCERIES</div>
            <h1>Shopping list 🛒</h1>
          </div>
          {state.shopping.length > 0 && <span className="page-count-pill">{checkedCount}/{state.shopping.length} ✓</span>}
        </div>
        <div className="row">
          {checkedCount > 0 && (
            <button
              className={'btn btn-sm ' + (allChecked ? 'btn-primary' : 'btn-mint')}
              onClick={stockUp}
              title="Remove checked items from list and add them to your pantry"
            >
              🥕 Stock pantry ({checkedCount})
            </button>
          )}
          <button className="btn btn-sm" onClick={() => window.print()}>🖨️ Print</button>
          {state.shopping.length > 0 && <button className="btn btn-sm" onClick={() => actions.clearShopping()}>Clear all</button>}
        </div>
      </div>
      {toast && <div className="shop-toast">{toast}</div>}

      <div className="pantry-panel" style={{marginBottom: 20}}>
        <div className="row">
          <input
            className="search-input"
            placeholder="add an item…"
            value={draftName}
            onChange={e => setDraftName(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && addManual()}
          />
          <button className="btn btn-primary" onClick={addManual}>Add</button>
        </div>
      </div>

      {state.shopping.length === 0 ? (
        <div className="empty">
          <div className="empty-glyph">🛒</div>
          <div>List is empty. Add items here, or add ingredients from a recipe.</div>
        </div>
      ) : (
        <div className="pantry-panel">
          {state.shopping.map((item, i) => (
            <div key={i} className="shop-row">
              <div className={'shop-check' + (item.checked ? ' checked' : '')} onClick={() => actions.toggleShoppingItem(i)} />
              <span className={'shop-name' + (item.checked ? ' checked' : '')} style={{flex: 1, fontWeight: 500}}>{item.name}</span>
              {item.qty && <span className="shop-qty">{item.qty} {item.unit}</span>}
              {item.source && item.source !== 'manual' && <span className="shop-source">from {item.source}</span>}
              <button className="btn btn-sm btn-ghost" onClick={() => actions.removeShoppingItem(i)}>✕</button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ============ MEAL PLAN ============
function PlanPage({ state, actions }) {
  const { RECIPES } = window.RECIPE_DATA;
  const [pickerDay, setPickerDay] = useStateB(null);
  const allRecipes = [...RECIPES, ...state.customRecipes];

  const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const today = new Date();
  const monday = new Date(today);
  monday.setDate(today.getDate() - ((today.getDay() + 6) % 7));
  const dayNum = today.getDay();
  const todayKey = days[(dayNum + 6) % 7];

  const buildShoppingFromPlan = () => {
    Object.values(state.plan).filter(Boolean).forEach(rid => {
      const r = allRecipes.find(x => x.id === rid);
      if (r) actions.addAllToShopping(r, 1);
    });
    actions.go('shopping');
  };

  return (
    <div className="page">
      <div className="page-header">
        <div className="page-title-wrap">
          <div>
            <div className="page-eyebrow">THIS WEEK</div>
            <h1>Meal plan 📅</h1>
          </div>
        </div>
        <div className="row">
          <button className="btn btn-sm btn-mint" onClick={buildShoppingFromPlan}>🛒 Build shopping list</button>
          <button className="btn btn-sm" onClick={() => actions.clearPlan()}>Clear week</button>
        </div>
      </div>

      <div className="week-grid">
        {days.map((d, i) => {
          const date = new Date(monday);
          date.setDate(monday.getDate() + i);
          const planned = state.plan[d];
          const recipe = planned ? allRecipes.find(r => r.id === planned) : null;
          const isToday = d === todayKey;
          return (
            <div key={d} className={'day-col' + (isToday ? ' today' : '')}>
              <div className="day-name">{d}</div>
              <div className="day-date">{date.toLocaleString('en', { month: 'short', day: 'numeric' })}</div>
              {recipe ? (
                <div className="day-slot-filled" onClick={() => actions.openRecipe(recipe.id)}>
                  <div style={{fontSize: 28, marginBottom: 4}}>{recipe.glyph}</div>
                  <div style={{fontFamily: 'Bricolage Grotesque', fontWeight: 800, fontSize: 14, lineHeight: 1.1}}>{recipe.title}</div>
                  <div className="mono" style={{fontSize: 10, color: 'var(--ink-soft)', marginTop: 4}}>{recipe.time}m · {recipe.cuisine}</div>
                  <button className="x-btn" onClick={(e) => { e.stopPropagation(); actions.setPlanDay(d, null); }}>✕</button>
                </div>
              ) : (
                <div className="day-slot" onClick={() => setPickerDay(d)}>
                  <div>＋ pick a recipe</div>
                </div>
              )}
            </div>
          );
        })}
      </div>

      {pickerDay && (
        <div className="modal-overlay" onClick={() => setPickerDay(null)}>
          <div className="modal" onClick={e => e.stopPropagation()}>
            <div className="row" style={{justifyContent: 'space-between', marginBottom: 16}}>
              <h2>Plan {pickerDay}</h2>
              <button className="btn btn-sm" onClick={() => setPickerDay(null)}>✕</button>
            </div>
            <window.RecipePicker
              recipes={allRecipes}
              selected={null}
              onChange={(id) => { actions.setPlanDay(pickerDay, id); setPickerDay(null); }}
              mode="single"
              height={500}
            />
          </div>
        </div>
      )}
    </div>
  );
}

// ============ FAVORITES ============
function FavoritesPage({ state, actions }) {
  const { RECIPES } = window.RECIPE_DATA;
  const all = [...RECIPES, ...state.customRecipes];
  const favs = all.filter(r => state.favorites.includes(r.id));
  return (
    <div className="page">
      <div className="page-header">
        <div className="page-title-wrap">
          <div>
            <div className="page-eyebrow">SAVED</div>
            <h1>Favorites ♥</h1>
          </div>
          <span className="page-count-pill">{favs.length}</span>
        </div>
      </div>
      {favs.length === 0 ? (
        <div className="empty">
          <div className="empty-glyph">♡</div>
          <div>No favorites yet. Tap the heart on any recipe.</div>
        </div>
      ) : (
        <div className="grid">
          {favs.map(r => <window.PagesA.RecipeCard key={r.id} recipe={r} state={state} actions={actions} />)}
        </div>
      )}
    </div>
  );
}

// ============ ADD RECIPE ============
function AddPage({ state, actions }) {
  const [title, setTitle] = useStateB('');
  const [cuisine, setCuisine] = useStateB('');
  const [time, setTime] = useStateB(30);
  const [servings, setServings] = useStateB(2);
  const [difficulty, setDifficulty] = useStateB('Easy');
  const [glyph, setGlyph] = useStateB('🍽️');
  const [color, setColor] = useStateB('mint');
  const [summary, setSummary] = useStateB('');
  const [tags, setTags] = useStateB('');
  const [ingredients, setIngredients] = useStateB([{ qty: '', unit: '', name: '', aisle: 'Pantry' }]);
  const [steps, setSteps] = useStateB(['']);
  const [saved, setSaved] = useStateB(false);

  const updateIng = (i, field, val) => {
    const next = [...ingredients]; next[i] = { ...next[i], [field]: val }; setIngredients(next);
  };
  const updateStep = (i, val) => { const n = [...steps]; n[i] = val; setSteps(n); };

  const submit = () => {
    if (!title) return;
    const recipe = {
      id: 'custom-' + Date.now(),
      title,
      cuisine: cuisine || 'Custom',
      time: parseInt(time, 10) || 30,
      servings: parseInt(servings, 10) || 2,
      difficulty,
      glyph,
      color,
      summary,
      tags: tags.split(',').map(t => t.trim()).filter(Boolean),
      ingredients: ingredients.filter(i => i.name).map(i => ({ ...i, qty: parseFloat(i.qty) || 1 })),
      steps: steps.filter(Boolean),
    };
    actions.addCustom(recipe);
    setSaved(true);
    setTimeout(() => actions.openRecipe(recipe.id), 600);
  };

  const colorOpts = ['mint','butter','peach','lilac','sky','blush','lime','coral'];

  return (
    <div className="page">
      <div className="row no-print" style={{marginBottom: 20}}>
        <button className="btn btn-ghost" onClick={() => actions.go('browse')}>← All recipes</button>
      </div>
      <div className="page-header">
        <div className="page-title-wrap">
          <div>
            <div className="page-eyebrow">NEW RECIPE</div>
            <h1>Add to your book ✏️</h1>
          </div>
        </div>
      </div>

      <div className="detail-grid">
        <div className="pantry-panel">
          <h3 style={{marginBottom: 16}}>Basics</h3>
          <div className="form-field">
            <label className="form-label">Title</label>
            <input className="form-input" value={title} onChange={e => setTitle(e.target.value)} placeholder="Grandma's lasagna" />
          </div>
          <div className="form-field">
            <label className="form-label">One-line summary</label>
            <input className="form-input" value={summary} onChange={e => setSummary(e.target.value)} placeholder="Three layers of bechamel and bolognese." />
          </div>
          <div className="row" style={{gap: 12}}>
            <div className="form-field" style={{flex: 1}}>
              <label className="form-label">Cuisine</label>
              <input className="form-input" value={cuisine} onChange={e => setCuisine(e.target.value)} />
            </div>
            <div className="form-field" style={{width: 100}}>
              <label className="form-label">Time (m)</label>
              <input className="form-input" type="number" value={time} onChange={e => setTime(e.target.value)} />
            </div>
            <div className="form-field" style={{width: 100}}>
              <label className="form-label">Serves</label>
              <input className="form-input" type="number" value={servings} onChange={e => setServings(e.target.value)} />
            </div>
          </div>
          <div className="form-field">
            <label className="form-label">Difficulty</label>
            <div className="row">
              {['Easy','Medium','Hard'].map(d => (
                <button key={d} className={'filter-chip' + (difficulty === d ? ' active' : '')} onClick={() => setDifficulty(d)}>{d}</button>
              ))}
            </div>
          </div>
          <div className="form-field">
            <label className="form-label">Tags (comma separated)</label>
            <input className="form-input" value={tags} onChange={e => setTags(e.target.value)} placeholder="weeknight, pasta, comfort" />
          </div>
          <div className="form-field">
            <label className="form-label">Card glyph (1 emoji)</label>
            <input className="form-input" value={glyph} onChange={e => setGlyph(e.target.value)} maxLength={4} style={{fontSize: 24, width: 90, textAlign: 'center'}} />
          </div>
          <div className="form-field">
            <label className="form-label">Card color</label>
            <div className="row">
              {colorOpts.map(c => (
                <button key={c} onClick={() => setColor(c)} style={{
                  width: 36, height: 36, borderRadius: 10, cursor: 'pointer',
                  border: color === c ? '3px solid var(--ink)' : '2px solid var(--ink)',
                  background: window.RECIPE_DATA.STICKER_COLORS[c].bg,
                  boxShadow: color === c ? '3px 3px 0 var(--ink)' : 'none',
                }} />
              ))}
            </div>
          </div>
        </div>

        <div className="pantry-panel">
          <h3 style={{marginBottom: 16}}>Ingredients</h3>
          {ingredients.map((ing, i) => (
            <div key={i} className="form-row-mini">
              <input className="form-input" placeholder="qty" value={ing.qty} onChange={e => updateIng(i, 'qty', e.target.value)} />
              <input className="form-input" placeholder="unit" value={ing.unit} onChange={e => updateIng(i, 'unit', e.target.value)} />
              <input className="form-input" placeholder="ingredient" value={ing.name} onChange={e => updateIng(i, 'name', e.target.value)} />
              <select className="form-input" value={ing.aisle} onChange={e => updateIng(i, 'aisle', e.target.value)}>
                {window.RECIPE_DATA.AISLES.map(a => <option key={a}>{a}</option>)}
              </select>
              <button className="btn btn-sm btn-ghost" onClick={() => setIngredients(ingredients.filter((_, j) => j !== i))}>✕</button>
            </div>
          ))}
          <button className="btn btn-sm" onClick={() => setIngredients([...ingredients, { qty: '', unit: '', name: '', aisle: 'Pantry' }])}>＋ Add ingredient</button>

          <h3 style={{marginBottom: 16, marginTop: 24}}>Steps</h3>
          {steps.map((s, i) => (
            <div key={i} className="form-field">
              <div className="row" style={{alignItems: 'flex-start'}}>
                <span className="step-num" style={{flexShrink: 0}}>{i + 1}</span>
                <textarea className="form-textarea" style={{minHeight: 60}} value={s} onChange={e => updateStep(i, e.target.value)} />
                <button className="btn btn-sm btn-ghost" onClick={() => setSteps(steps.filter((_, j) => j !== i))}>✕</button>
              </div>
            </div>
          ))}
          <button className="btn btn-sm" onClick={() => setSteps([...steps, ''])}>＋ Add step</button>

          <div style={{marginTop: 30, borderTop: '2px dashed var(--ink)', paddingTop: 20}}>
            <button className="btn btn-primary" onClick={submit} disabled={!title || saved}>
              {saved ? '✓ Saved!' : '💾 Save recipe'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

window.PagesB = { PantryPage, ShoppingPage, PlanPage, FavoritesPage, AddPage };
