// Print Hub — searchable, multi-select pickers built for hundreds of recipes

const { useState: useStateP, useMemo: useMemoP } = React;

function PrintHubPage({ state, actions }) {
  const { RECIPES, AISLES } = window.RECIPE_DATA;
  const allRecipes = [...RECIPES, ...state.customRecipes];
  const [mode, setMode] = useStateP('shopping'); // shopping | card | booklet | week

  // single-pick for card; multi-pick for booklet
  const [singleId, setSingleId] = useStateP(state.openRecipeId || allRecipes[0]?.id || '');
  const [multiIds, setMultiIds] = useStateP([]);

  const printNow = () => window.print();

  const groupedShopping = useMemoP(() => {
    const groups = {};
    state.shopping.forEach(item => {
      let aisle = 'Other';
      for (const r of allRecipes) {
        const found = r.ingredients.find(ing => ing.name.toLowerCase() === item.name.toLowerCase());
        if (found?.aisle) { aisle = found.aisle; break; }
      }
      if (!groups[aisle]) groups[aisle] = [];
      groups[aisle].push(item);
    });
    return groups;
  }, [state.shopping, state.customRecipes]);

  const singleRecipe = allRecipes.find(r => r.id === singleId);
  const bookletRecipes = multiIds.map(id => allRecipes.find(r => r.id === id)).filter(Boolean);
  const weekRecipes = Object.entries(state.plan)
    .filter(([_, id]) => id)
    .map(([day, id]) => ({ day, recipe: allRecipes.find(r => r.id === id) }))
    .filter(x => x.recipe);

  const addAllFavs = () => {
    const favIds = state.favorites.filter(id => !multiIds.includes(id));
    setMultiIds([...multiIds, ...favIds]);
  };
  const addWeek = () => {
    const weekIds = Object.values(state.plan).filter(Boolean).filter(id => !multiIds.includes(id));
    setMultiIds([...multiIds, ...weekIds]);
  };

  return (
    <div className="page">
      <div className="page-header no-print">
        <div className="page-title-wrap">
          <div>
            <div className="page-eyebrow">PRINT &amp; EXPORT</div>
            <h1>Print shop 🖨️</h1>
          </div>
        </div>
        <button className="btn btn-primary" onClick={printNow}>🖨️ Print this sheet</button>
      </div>

      <div className="row no-print" style={{marginBottom: 24, gap: 6}}>
        <button className={'filter-chip' + (mode === 'shopping' ? ' active' : '')} onClick={() => setMode('shopping')}>🛒 Shopping list</button>
        <button className={'filter-chip' + (mode === 'card' ? ' active' : '')} onClick={() => setMode('card')}>🗂️ Recipe card (4×6)</button>
        <button className={'filter-chip' + (mode === 'booklet' ? ' active' : '')} onClick={() => setMode('booklet')}>📚 Recipe booklet</button>
        <button className={'filter-chip' + (mode === 'week' ? ' active' : '')} onClick={() => setMode('week')}>{"📅 Week's plan"}</button>
      </div>

      {/* SHOPPING */}
      {mode === 'shopping' && (
        <div className="print-sheet">
          <div className="print-sheet-eyebrow">
            <span>SHOPPING LIST</span>
            <span>{new Date().toLocaleDateString('en', { weekday: 'long', month: 'long', day: 'numeric' })}</span>
          </div>
          <h1 className="print-sheet-h1">Groceries 🛒</h1>
          <p style={{marginTop: 4, color: '#555', fontSize: 13}}>
            {state.shopping.length} item{state.shopping.length === 1 ? '' : 's'} · grouped by aisle
          </p>
          <hr/>
          {state.shopping.length === 0 ? (
            <p style={{fontStyle: 'italic', color: '#888'}}>No items in your shopping list yet.</p>
          ) : (
            <div className="print-shop-grid">
              {[...AISLES, 'Other'].filter(a => groupedShopping[a]).map(aisle => (
                <div key={aisle} className="print-shop-aisle">
                  <div className="print-shop-aisle-title">{aisle}</div>
                  {groupedShopping[aisle].map((it, i) => (
                    <div key={i} className="print-shop-row">
                      <span className="print-shop-box" />
                      <span className="print-shop-name">{it.name}</span>
                      {it.qty && <span className="print-shop-qty">{it.qty} {it.unit}</span>}
                      {it.source && it.source !== 'manual' && <span className="print-shop-source">({it.source})</span>}
                    </div>
                  ))}
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* RECIPE CARD — single searchable picker */}
      {mode === 'card' && (
        <div className="split-2 no-print">
          <div>
            <div className="page-eyebrow" style={{marginBottom: 10}}>PICK A RECIPE</div>
            <window.RecipePicker
              recipes={allRecipes}
              selected={singleId}
              onChange={setSingleId}
              mode="single"
              height={520}
            />
          </div>
          <div>
            <div className="page-eyebrow" style={{marginBottom: 10}}>PREVIEW</div>
            {singleRecipe ? (
              <window.PagesC.RecipeIndexCard recipe={singleRecipe} />
            ) : (
              <div className="print-preview-empty">Pick a recipe →</div>
            )}
          </div>
        </div>
      )}
      {mode === 'card' && singleRecipe && (
        <div className="print-only-block" style={{display: 'none'}}>
          <window.PagesC.RecipeIndexCard recipe={singleRecipe} />
        </div>
      )}

      {/* BOOKLET — multi-select with smart shortcuts */}
      {mode === 'booklet' && (
        <React.Fragment>
          <div className="split-2 no-print">
            <div>
              <div className="row" style={{justifyContent: 'space-between', marginBottom: 10}}>
                <div className="page-eyebrow">BUILD A BOOKLET</div>
                {multiIds.length > 0 && (
                  <button className="btn btn-sm btn-ghost" onClick={() => setMultiIds([])}>Clear</button>
                )}
              </div>
              <div className="row" style={{marginBottom: 10, gap: 6}}>
                <button className="btn btn-sm btn-blush" onClick={addAllFavs} disabled={state.favorites.length === 0}>
                  ＋ All favorites ({state.favorites.length})
                </button>
                <button className="btn btn-sm btn-mint" onClick={addWeek} disabled={Object.values(state.plan).filter(Boolean).length === 0}>
                  ＋ This week's plan
                </button>
              </div>
              <window.RecipePicker
                recipes={allRecipes}
                selected={multiIds}
                onChange={setMultiIds}
                mode="multi"
                height={480}
              />
            </div>
            <div>
              <div className="page-eyebrow" style={{marginBottom: 10}}>
                PREVIEW {bookletRecipes.length > 0 && `· ~${bookletRecipes.length} pages`}
              </div>
              {bookletRecipes.length > 8 && (
                <div className="print-warning">
                  ⚠️ {bookletRecipes.length} recipes selected — that's a long print job. Sure?
                </div>
              )}
              {bookletRecipes.length === 0 ? (
                <div className="print-preview-empty">
                  <div style={{fontSize: 36, marginBottom: 8}}>📚</div>
                  <div>Pick recipes from the left to build a booklet.</div>
                </div>
              ) : (
                <div style={{maxHeight: 600, overflow: 'auto', padding: 10, background: 'var(--cream-2)', borderRadius: 'var(--r-md)', border: '2px solid var(--ink)'}}>
                  {bookletRecipes.slice(0, 3).map(r => (
                    <div key={r.id} style={{transform: 'scale(0.8)', transformOrigin: 'top left', marginBottom: -50}}>
                      <window.PagesC.RecipeIndexCard recipe={r} />
                    </div>
                  ))}
                  {bookletRecipes.length > 3 && (
                    <div className="muted center" style={{padding: 16, fontSize: 13}}>
                      …and {bookletRecipes.length - 3} more.
                    </div>
                  )}
                </div>
              )}
            </div>
          </div>
          <div className="print-only-block" style={{display: 'none'}}>
            {bookletRecipes.map(r => <window.PagesC.RecipeIndexCard key={r.id} recipe={r} />)}
          </div>
        </React.Fragment>
      )}

      {/* WEEK */}
      {mode === 'week' && (
        <React.Fragment>
          <div className="no-print">
            {weekRecipes.length === 0 ? (
              <div className="empty"><div className="empty-glyph">📅</div><div>Nothing planned this week.</div></div>
            ) : (
              <React.Fragment>
                <p className="muted no-print" style={{marginBottom: 16}}>
                  {weekRecipes.length} planned recipe{weekRecipes.length === 1 ? '' : 's'} this week. Each prints as a 4×6 card.
                </p>
                <div className="print-card-grid">
                  {weekRecipes.map(({ day, recipe }) => (
                    <window.PagesC.RecipeIndexCard key={day} recipe={recipe} dayBadge={day} />
                  ))}
                </div>
              </React.Fragment>
            )}
          </div>
        </React.Fragment>
      )}
    </div>
  );
}

function RecipeIndexCard({ recipe, dayBadge }) {
  return (
    <div className="print-card">
      <div className="print-card-header">
        <div>
          <div className="print-card-meta">
            {recipe.cuisine} · {recipe.time} min · serves {recipe.servings} · {recipe.difficulty}
            {dayBadge && ` · ${dayBadge.toUpperCase()}`}
          </div>
          <div className="print-card-title">{recipe.glyph} {recipe.title}</div>
        </div>
        <div style={{
          fontFamily: 'var(--font-mono)', fontSize: 10, color: '#888',
          textAlign: 'right', textTransform: 'uppercase', letterSpacing: '0.1em',
        }}>
          recipe<br/>card
        </div>
      </div>
      <div>
        <div className="print-card-section-title">Ingredients</div>
        <ul className="print-card-ing">
          {recipe.ingredients.map((ing, i) => (
            <li key={i}>
              <span className="qty">{ing.qty}{ing.unit ? ' ' + ing.unit : ''}</span> {ing.name}
            </li>
          ))}
        </ul>
      </div>
      <div>
        <div className="print-card-section-title">Method</div>
        <ol className="print-card-steps">
          {recipe.steps.map((s, i) => <li key={i}>{s}</li>)}
        </ol>
      </div>
    </div>
  );
}

window.PagesC = { PrintHubPage, RecipeIndexCard };
