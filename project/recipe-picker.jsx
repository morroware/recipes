// Reusable RecipePicker — handles hundreds of recipes
// Modes: 'single' (radio-like) or 'multi' (checkbox-like)

const { useState: useStateRP, useMemo: useMemoRP } = React;

function RecipePicker({ recipes, selected, onChange, mode = 'single', height = 480, hideHeader }) {
  const [search, setSearch] = useStateRP('');
  const [cuisine, setCuisine] = useStateRP('All');
  const [sortBy, setSortBy] = useStateRP('title'); // title | time | recent

  const cuisines = useMemoRP(() => ['All', ...new Set(recipes.map(r => r.cuisine))], [recipes]);

  const filtered = useMemoRP(() => {
    let list = recipes.filter(r => {
      if (cuisine !== 'All' && r.cuisine !== cuisine) return false;
      if (!search) return true;
      const q = search.toLowerCase();
      return r.title.toLowerCase().includes(q)
        || r.cuisine.toLowerCase().includes(q)
        || (r.tags || []).some(t => t.toLowerCase().includes(q))
        || r.ingredients.some(i => i.name.toLowerCase().includes(q));
    });
    if (sortBy === 'title') list.sort((a, b) => a.title.localeCompare(b.title));
    else if (sortBy === 'time') list.sort((a, b) => a.time - b.time);
    return list;
  }, [recipes, search, cuisine, sortBy]);

  const isSelected = (id) =>
    mode === 'multi' ? selected.includes(id) : selected === id;

  const toggle = (id) => {
    if (mode === 'multi') {
      onChange(selected.includes(id) ? selected.filter(x => x !== id) : [...selected, id]);
    } else {
      onChange(id);
    }
  };

  return (
    <div className="recipe-picker">
      {!hideHeader && (
        <div className="recipe-picker-header">
          <input
            className="search-input"
            placeholder={`Search ${recipes.length} recipes…`}
            value={search}
            onChange={e => setSearch(e.target.value)}
            autoFocus
          />
          <div className="row" style={{gap: 6, marginTop: 10, flexWrap: 'wrap'}}>
            {cuisines.slice(0, 8).map(c => (
              <button
                key={c}
                className={'filter-chip' + (cuisine === c ? ' active' : '')}
                onClick={() => setCuisine(c)}
                style={{fontSize: 12, padding: '4px 10px'}}
              >{c}</button>
            ))}
            <span style={{flex: 1}} />
            <select
              className="form-input"
              style={{padding: '4px 10px', fontSize: 12, width: 'auto'}}
              value={sortBy}
              onChange={e => setSortBy(e.target.value)}
            >
              <option value="title">A→Z</option>
              <option value="time">Quickest first</option>
            </select>
          </div>
          <div className="mono" style={{fontSize: 11, color: 'var(--ink-soft)', marginTop: 8}}>
            {filtered.length} of {recipes.length}
            {mode === 'multi' && selected.length > 0 && (
              <span style={{marginLeft: 8, color: 'var(--ink)', fontWeight: 700}}>
                · {selected.length} selected
              </span>
            )}
          </div>
        </div>
      )}

      <div className="recipe-picker-list" style={{maxHeight: height}}>
        {filtered.length === 0 ? (
          <div className="muted" style={{padding: 20, textAlign: 'center', fontSize: 13}}>
            No matches.
          </div>
        ) : filtered.map(r => (
          <div
            key={r.id}
            className={'recipe-picker-row' + (isSelected(r.id) ? ' selected' : '')}
            onClick={() => toggle(r.id)}
          >
            <div className="recipe-picker-thumb" style={{
              background: r.photo ? `url(${r.photo})` : window.RECIPE_DATA.STICKER_COLORS[r.color]?.bg,
            }}>
              {!r.photo && <span>{r.glyph}</span>}
            </div>
            <div className="recipe-picker-body">
              <div className="recipe-picker-title">{r.title}</div>
              <div className="recipe-picker-meta">
                {r.cuisine} · {r.time}m · serves {r.servings}
              </div>
            </div>
            <div className={'recipe-picker-mark recipe-picker-mark-' + mode}>
              {isSelected(r.id) && (mode === 'multi' ? '✓' : '●')}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

window.RecipePicker = RecipePicker;
