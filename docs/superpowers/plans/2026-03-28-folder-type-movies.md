# Folder Type (Series/Movies) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow users to tag a folder as "movies" so video files inside render as Netflix-style grid cards with TMDB posters, while keeping "series" folders unchanged.

**Architecture:** Add a `folder_type` column to `folder_posters` table. Extend the `⋮` button into a dropdown menu with "Changer poster" + "Type: Série/Films". When browsing a folder tagged `movies`, generate grid cards for video files and fetch TMDB posters by filename. Reuse existing `extract_title_year()`, `selectPoster()`, `openPosterPicker()`, and the `?posters=1` endpoint.

**Tech Stack:** PHP 8.2, SQLite, vanilla JS (no framework), TMDB API v3

---

### Task 1: Database migration v4 — add `folder_type` column

**Files:**
- Modify: `db.php:104-111` (after v3 migration block)
- Modify: `tests/DatabaseTest.php:135-151` (update version + schema test)

- [ ] **Step 1: Write the failing test**

In `tests/DatabaseTest.php`, update the version test and add folder_type column check:

```php
// Replace the existing testUserVersionIsCurrent test
public function testUserVersionIsCurrent(): void
{
    $db = get_db();
    $version = (int) $db->query('PRAGMA user_version')->fetchColumn();
    $this->assertSame(4, $version);
}

// Add after testFolderPostersTableExists
public function testFolderPostersHasFolderTypeColumn(): void
{
    $db = get_db();
    $cols = $this->getColumnNames($db, 'folder_posters');
    $this->assertContains('folder_type', $cols);
}

public function testFolderTypeDefaultsToSeries(): void
{
    $db = get_db();
    $db->prepare("INSERT OR REPLACE INTO folder_posters (path, poster_url) VALUES (:p, :u)")
       ->execute([':p' => '/tmp/test_folder_type', ':u' => null]);
    $row = $db->query("SELECT folder_type FROM folder_posters WHERE path = '/tmp/test_folder_type'")->fetch();
    $this->assertSame('series', $row['folder_type']);
    $db->prepare("DELETE FROM folder_posters WHERE path = '/tmp/test_folder_type'")->execute();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/DatabaseTest.php --filter="testUserVersionIsCurrent|testFolderPostersHasFolderTypeColumn|testFolderTypeDefaultsToSeries" -v`
Expected: FAIL — version is 3, column `folder_type` missing

- [ ] **Step 3: Write migration v4 in db.php**

Add after the v3 block (after line 111):

```php
if ($version < 4) {
    // v4 : type de dossier (series ou movies) pour adapter le rendu grille
    $cols = array_column($db->query("PRAGMA table_info(folder_posters)")->fetchAll(), 'name');
    if (!in_array('folder_type', $cols, true)) {
        $db->query("ALTER TABLE folder_posters ADD COLUMN folder_type TEXT DEFAULT 'series'");
    }
    $db->query('PRAGMA user_version = 4');
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/DatabaseTest.php -v`
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add db.php tests/DatabaseTest.php
git commit -m "feat: migration v4 — add folder_type column to folder_posters"
```

---

### Task 2: Endpoint `?folder_type_set=1` — save folder type to DB

**Files:**
- Modify: `download.php:184-187` (add route for `folder_type_set`)
- Modify: `handlers/tmdb.php` (add handler at the end, before the final error response)

- [ ] **Step 1: Add route in download.php**

In `download.php`, modify line 185 to also route `folder_type_set`:

```php
// TMDB poster endpoints (search, batch, set) + folder type
if (isset($_GET['posters']) || isset($_GET['tmdb_search']) || isset($_GET['tmdb_set']) || isset($_GET['folder_type_set'])) {
    require __DIR__ . '/handlers/tmdb.php';
}
```

- [ ] **Step 2: Add handler in handlers/tmdb.php**

Insert before the final `echo json_encode(['error' => 'unknown tmdb action']);` line (before line 193):

```php
// ── Set folder type (series/movies) ──
if (isset($_GET['folder_type_set']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $folder = $input['folder'] ?? '';
    $type = $input['folder_type'] ?? 'series';

    if (!$folder) { http_response_code(400); echo json_encode(['error' => 'missing folder']); exit; }
    if (!in_array($type, ['series', 'movies'], true)) { http_response_code(400); echo json_encode(['error' => 'invalid type']); exit; }

    $fullPath = $resolvedPath . '/' . $folder;
    if (!is_dir($fullPath)) { http_response_code(404); echo json_encode(['error' => 'folder not found']); exit; }

    try {
        // Upsert: create row if not exists, update folder_type if exists
        $db->prepare("INSERT INTO folder_posters (path, folder_type) VALUES (:p, :t)
                      ON CONFLICT(path) DO UPDATE SET folder_type = :t, updated_at = datetime('now')")
           ->execute([':p' => $fullPath, ':t' => $type]);
        echo json_encode(['success' => true, 'folder_type' => $type]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'db error']);
    }
    exit;
}
```

- [ ] **Step 3: Test manually**

This endpoint is hard to unit-test (requires HTTP context with `$resolvedPath` and `$db`). Verify with:
Run: `vendor/bin/phpunit -v && vendor/bin/phpstan analyse`
Expected: All pass, no regressions

- [ ] **Step 4: Commit**

```bash
git add download.php handlers/tmdb.php
git commit -m "feat: add ?folder_type_set endpoint to toggle series/movies"
```

---

### Task 3: Dropdown menu on grid cards (replace `⋮` single-action)

**Files:**
- Modify: `download.php` — CSS (around line 392-394), PHP grid card HTML (around line 539), JS (new functions)

- [ ] **Step 1: Add CSS for the dropdown menu**

Find the existing `.grid-card-ctx` CSS block (line 392-394) and add dropdown styles after it:

```css
.grid-card-menu { position:absolute; top:calc(.5rem + 30px); right:.5rem; background:#1a1a2e; border:1px solid rgba(255,255,255,.15); border-radius:8px; padding:.3rem 0; min-width:160px; z-index:20; box-shadow:0 4px 12px rgba(0,0,0,.5); display:none; }
.grid-card-menu.open { display:block; }
.grid-card-menu-item { display:flex; align-items:center; gap:.5rem; padding:.45rem .75rem; color:rgba(255,255,255,.85); font-size:.78rem; cursor:pointer; white-space:nowrap; transition:background .1s; }
.grid-card-menu-item:hover { background:rgba(255,255,255,.08); }
.grid-card-menu-item svg { width:14px; height:14px; flex-shrink:0; }
```

- [ ] **Step 2: Modify PHP grid card HTML — replace inline onclick with dropdown**

Replace the current `grid-card-ctx` div (line 539) with a dropdown trigger:

```php
echo '<div class="grid-card-ctx" onclick="event.preventDefault();event.stopPropagation();toggleCardMenu(this,\'' . $escapedName . '\')" title="Options"><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg></div>';
```

Note: the HTML itself barely changes (just the onclick). The dropdown is created dynamically in JS.

- [ ] **Step 3: Add JS function `toggleCardMenu()`**

Add after the `selectPoster()` function (after line 969), inside the script block:

```javascript
// ── Card dropdown menu ──
function toggleCardMenu(btn, folderName) {
    // Close any existing menu
    var old = document.querySelector('.grid-card-menu');
    if (old) { old.remove(); }
    // If clicking same button that was open, just close
    if (btn.dataset.menuOpen === '1') { btn.dataset.menuOpen = ''; return; }
    document.querySelectorAll('.grid-card-ctx').forEach(function(b){ b.dataset.menuOpen = ''; });
    btn.dataset.menuOpen = '1';

    var menu = document.createElement('div');
    menu.className = 'grid-card-menu open';

    // Item 1: Changer le poster
    var item1 = document.createElement('div');
    item1.className = 'grid-card-menu-item';
    var svg1 = document.createElementNS('http://www.w3.org/2000/svg','svg');
    svg1.setAttribute('viewBox','0 0 24 24'); svg1.setAttribute('fill','none');
    svg1.setAttribute('stroke','currentColor'); svg1.setAttribute('stroke-width','2');
    svg1.insertAdjacentHTML('beforeend','<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>');
    item1.appendChild(svg1);
    var span1 = document.createElement('span');
    span1.textContent = 'Changer le poster';
    item1.appendChild(span1);
    item1.onclick = function(e) { e.preventDefault(); e.stopPropagation(); menu.remove(); btn.dataset.menuOpen = ''; openPosterPicker(folderName); };
    menu.appendChild(item1);

    // Item 2: Type série/films (only for folder cards, not file cards)
    var card = btn.closest('.grid-card');
    if (card && card.dataset.type === 'folder') {
        var currentType = card.dataset.folderType || 'series';
        var item2 = document.createElement('div');
        item2.className = 'grid-card-menu-item';
        var nextType = currentType === 'movies' ? 'series' : 'movies';
        var svg2 = document.createElementNS('http://www.w3.org/2000/svg','svg');
        svg2.setAttribute('viewBox','0 0 24 24'); svg2.setAttribute('fill','none');
        svg2.setAttribute('stroke','currentColor'); svg2.setAttribute('stroke-width','2');
        if (currentType === 'movies') {
            svg2.insertAdjacentHTML('beforeend','<rect x="2" y="7" width="20" height="15" rx="2"/><polyline points="17 2 12 7 7 2"/>');
        } else {
            svg2.insertAdjacentHTML('beforeend','<rect x="2" y="2" width="20" height="20" rx="2.18"/><line x1="7" y1="2" x2="7" y2="22"/><line x1="17" y1="2" x2="17" y2="22"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="2" y1="7" x2="7" y2="7"/><line x1="2" y1="17" x2="7" y2="17"/><line x1="17" y1="7" x2="22" y2="7"/><line x1="17" y1="17" x2="22" y2="17"/>');
        }
        item2.appendChild(svg2);
        var span2 = document.createElement('span');
        span2.textContent = currentType === 'movies' ? 'Type : Films \u2192 S\u00e9rie' : 'Type : S\u00e9rie \u2192 Films';
        item2.appendChild(span2);
        item2.onclick = function(e) {
            e.preventDefault(); e.stopPropagation();
            menu.remove(); btn.dataset.menuOpen = '';
            setFolderType(folderName, nextType, card);
        };
        menu.appendChild(item2);
    }

    btn.closest('.grid-card').appendChild(menu);
}

function setFolderType(folderName, type, card) {
    var url = BASE_URL + '?' + SUB_PATH + 'folder_type_set=1';
    fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({folder: folderName, folder_type: type})
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.success && card) {
            card.dataset.folderType = type;
        }
    }).catch(function(){});
}

// Close menu on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('.grid-card-ctx') && !e.target.closest('.grid-card-menu')) {
        var m = document.querySelector('.grid-card-menu');
        if (m) m.remove();
        document.querySelectorAll('.grid-card-ctx').forEach(function(b){ b.dataset.menuOpen = ''; });
    }
});
```

- [ ] **Step 4: Add `data-folder-type` attribute to grid cards in PHP**

In the PHP grid card rendering (around line 534), we need to look up the folder_type from DB and add it. Before the `foreach ($folders as $idx => $folder)` loop (line 528), add a batch query:

```php
// Look up folder types for all subfolders
$folderTypes = [];
if (!empty($folders)) {
    $paths = array_map(fn($f) => $dirPath . '/' . $f['name'], $folders);
    $placeholders = implode(',', array_fill(0, count($paths), '?'));
    $stmt = $db->prepare("SELECT path, folder_type FROM folder_posters WHERE path IN ($placeholders)");
    $stmt->execute($paths);
    foreach ($stmt->fetchAll() as $row) {
        $folderTypes[$row['path']] = $row['folder_type'] ?? 'series';
    }
}
```

Then in the grid card `<a>` tag (line 534), add the data attribute:

```php
$folderFullPath = $dirPath . '/' . $folder['name'];
$folderType = $folderTypes[$folderFullPath] ?? 'series';
echo '<a class="grid-card" href="' . $folderUrl . '" style="background:' . $color . '" data-type="folder" data-name="' . $folderHtml . '" data-folder="' . $folderHtml . '" data-folder-type="' . $folderType . '">';
```

- [ ] **Step 5: Verify no regressions**

Run: `vendor/bin/phpunit -v && vendor/bin/phpstan analyse`
Expected: All pass

- [ ] **Step 6: Commit**

```bash
git add download.php
git commit -m "feat: dropdown menu on grid cards with poster picker + folder type toggle"
```

---

### Task 4: Render video files as grid cards in "movies" folders

**Files:**
- Modify: `download.php` — PHP rendering section (around lines 276-544) and JS

This is the core feature. When the current directory is tagged `movies`, video files get grid cards.

- [ ] **Step 1: Detect if current folder is tagged "movies"**

In the `afficher_listing()` function, after the `$hasFolders` variable (line 297), add folder type detection. First, get `$db`:

```php
$db = get_db();

// Check if this folder is tagged as "movies" (its own path in folder_posters)
$currentFolderType = 'series';
$stmtType = $db->prepare("SELECT folder_type FROM folder_posters WHERE path = :p");
$stmtType->execute([':p' => $dirPath]);
$typeRow = $stmtType->fetch();
if ($typeRow && $typeRow['folder_type']) {
    $currentFolderType = $typeRow['folder_type'];
}
$isMoviesFolder = ($currentFolderType === 'movies');
$videoFiles = [];
if ($isMoviesFolder) {
    foreach ($files as $f) {
        if (get_media_type($f['name']) === 'video') {
            $videoFiles[] = $f;
        }
    }
}
$hasGridItems = $hasFolders || !empty($videoFiles);
```

- [ ] **Step 2: Replace `$hasFolders` with `$hasGridItems` for grid toggle visibility**

On the line that shows the grid/list toggle button (line 469):

```php
if ($hasGridItems) {
```

And on the line that opens the grid-wrap div (line 517):

```php
if ($hasGridItems) {
```

- [ ] **Step 3: Render video file grid cards after folder cards**

After the folder grid cards loop ends (after line 542, before the `echo '</div>';` that closes grid-wrap), add:

```php
// Video file cards (movies mode only)
if ($isMoviesFolder) {
    foreach ($videoFiles as $idx => $vf) {
        $vfHtml = htmlspecialchars($vf['name']);
        $vfPath = $subPath ? $subPath . '/' . $vf['name'] : $vf['name'];
        $vfPlayUrl = $baseUrl . '?p=' . rawurlencode($vfPath) . '&play=1';
        $vfDownloadUrl = $baseUrl . '?p=' . rawurlencode($vfPath);
        $color = $cardColors[($idx + count($folders)) % count($cardColors)];
        $letter = mb_strtoupper(mb_substr($vf['name'], 0, 1));
        $escapedVfName = htmlspecialchars(addcslashes($vf['name'], "'\\"), ENT_QUOTES);
        // data-folder is reused for poster matching (same key as ?posters=1 response)
        echo '<a class="grid-card grid-card-file" href="' . $vfDownloadUrl . '" data-play="' . htmlspecialchars($vfPlayUrl, ENT_QUOTES) . '" style="background:' . $color . '" data-type="file" data-name="' . $vfHtml . '" data-folder="' . $vfHtml . '">';
        echo '<div class="grid-card-bg"><div class="grid-card-letter">' . htmlspecialchars($letter) . '</div></div>';
        echo '<div class="grid-card-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--green)"><rect x="2" y="4" width="20" height="16" rx="2"/><polygon points="10 9 15 12 10 15 10 9" fill="currentColor" stroke="none"/></svg></div>';
        echo '<div class="grid-card-toggle" onclick="event.preventDefault();event.stopPropagation();togglePoster(this,\'' . $escapedVfName . '\')" title="Afficher/masquer l\'image"><svg class="eye-on" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-off" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg></div>';
        echo '<div class="grid-card-ctx" onclick="event.preventDefault();event.stopPropagation();openPosterPicker(\'' . $escapedVfName . '\')" title="Changer le poster"><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg></div>';
        echo '<div class="grid-card-label"><div class="grid-card-title">' . $vfHtml . '</div></div>';
        echo '</a>';
    }
}
```

- [ ] **Step 4: Apply `pref_click` to video file grid cards in JS**

In the JS preferences init IIFE (around line 642-646), extend the click behavior to also handle grid card files:

```javascript
if(c==='play'){
    document.querySelectorAll('.row[data-play]').forEach(function(r){
        r.href=r.dataset.play;
    });
    document.querySelectorAll('.grid-card-file[data-play]').forEach(function(r){
        r.href=r.dataset.play;
    });
}
```

Also update the `setPref` function (around line 664-676) to handle grid card files:

```javascript
if(key==='click'){
    document.querySelectorAll('.row[data-play], .grid-card-file[data-play]').forEach(function(r){
        r.href = val==='play' ? r.dataset.play : r.href.split('&play=')[0].split('?play=')[0];
    });
    if(val==='download'){
        document.querySelectorAll('.row[data-play], .grid-card-file[data-play]').forEach(function(r){
            r.href=r.href.replace(/[&?]play=1/,'');
        });
    }
}
```

- [ ] **Step 5: Update `applyView()` to handle file cards in grid mode**

In `applyView()` (line 687-707), when switching to grid mode, also hide file rows that have grid card equivalents:

```javascript
function applyView(mode){
    var grid=document.getElementById('grid-folders');
    var panel=document.getElementById('list-panel');
    var toggle=document.getElementById('view-toggle');
    if(!grid) return;
    if(mode==='grid'){
        grid.classList.remove('hidden');
        panel.querySelectorAll('.row-folder').forEach(function(r){r.style.display='none'});
        // Hide video file rows that have grid cards
        document.querySelectorAll('.grid-card-file').forEach(function(gc){
            var name = gc.dataset.name;
            panel.querySelectorAll('.row[data-type="file"]').forEach(function(r){
                if(r.dataset.name === name) r.style.display='none';
            });
        });
        var upRow=panel.querySelector('.row:not([data-type])');
        if(upRow) upRow.style.display='none';
        if(toggle) toggle.classList.add('active');
    } else {
        grid.classList.add('hidden');
        panel.querySelectorAll('.row-folder').forEach(function(r){r.style.display=''});
        panel.querySelectorAll('.row[data-type="file"]').forEach(function(r){r.style.display=''});
        var upRow=panel.querySelector('.row:not([data-type])');
        if(upRow) upRow.style.display='';
        if(toggle) toggle.classList.remove('active');
    }
}
```

- [ ] **Step 6: Update filter to include file grid cards**

In the `filtrer()` function (line 732-743), add filtering for file grid cards after the existing grid card filter:

```javascript
// Also filter grid file cards
document.querySelectorAll('.grid-card[data-type="file"]').forEach(r => {
    r.classList.toggle('hidden', q && !r.dataset.name.toLowerCase().includes(q));
});
```

- [ ] **Step 7: Verify no regressions**

Run: `vendor/bin/phpunit -v && vendor/bin/phpstan analyse`
Expected: All pass

- [ ] **Step 8: Commit**

```bash
git add download.php
git commit -m "feat: render video files as grid cards in movies-type folders"
```

---

### Task 5: Extend `?posters=1` to fetch TMDB posters for video files

**Files:**
- Modify: `handlers/tmdb.php` (extend the `?posters=1` block)

- [ ] **Step 1: Add video file poster fetching to the posters endpoint**

In `handlers/tmdb.php`, replace the final lines of the `?posters=1` block (lines 126-128) with an extended version that also handles video files:

```php
    // Signal if there are more folders to fetch (JS will re-request)
    $remaining = count($uncached) - count($toFetch);

    // ── Video file posters (movies-type folders) ──
    $stmtType = $db->prepare("SELECT folder_type FROM folder_posters WHERE path = :p");
    $stmtType->execute([':p' => $resolvedPath]);
    $typeRow = $stmtType->fetch();
    $isMovies = ($typeRow && ($typeRow['folder_type'] ?? 'series') === 'movies');

    $videoRemaining = 0;
    if ($isMovies) {
        $videoExts = ['mp4','mkv','avi','m4v','mov','wmv','flv','webm','ts','m2ts','mpg','mpeg'];
        $videoFiles = [];
        foreach ($items as $item) {
            if ($item[0] === '.' || is_dir($resolvedPath . '/' . $item)) continue;
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, $videoExts, true)) {
                $videoFiles[] = $item;
            }
        }

        $videoCached = [];
        $videoUncached = [];
        foreach ($videoFiles as $vf) {
            $fullPath = $resolvedPath . '/' . $vf;
            $stmt = $db->prepare("SELECT poster_url, overview FROM folder_posters WHERE path = :p");
            $stmt->execute([':p' => $fullPath]);
            $row = $stmt->fetch();
            if ($row) {
                if ($row['poster_url'] === '__none__') {
                    $videoCached[$vf] = ['hidden' => true];
                } elseif ($row['poster_url']) {
                    $videoCached[$vf] = ['poster' => $row['poster_url']];
                    if ($row['overview']) $videoCached[$vf]['overview'] = $row['overview'];
                }
            } else {
                $videoUncached[] = $vf;
            }
        }

        // Merge cached video posters into result
        $result = array_merge($result, $videoCached);

        // Fetch from TMDB for uncached video files (max 10 per request)
        $videoToFetch = array_slice($videoUncached, 0, 10);
        foreach ($videoToFetch as $fileName) {
            $meta = extract_title_year($fileName);
            $title = $meta['title'];

            $queries = [$title];
            $shorter = preg_replace('/\b(hd|remasted|remastered|complete|integrale|intégrale|collection|pack|coffret)\b.*/i', '', $title);
            $shorter = trim($shorter);
            if ($shorter !== '' && $shorter !== $title) $queries[] = $shorter;
            $words = explode(' ', $title);
            if (count($words) > 3) {
                $half = implode(' ', array_slice($words, 0, (int)ceil(count($words) / 2)));
                if ($half !== $title && $half !== $shorter) $queries[] = $half;
            }

            $posterUrl = null;
            $tmdbId = null;
            $tmdbTitle = null;
            $tmdbOverview = null;

            foreach ($queries as $q) {
                $encoded = urlencode($q);
                // For movies, search multi then movie (instead of TV)
                $urls = [
                    "https://api.themoviedb.org/3/search/multi?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
                    "https://api.themoviedb.org/3/search/movie?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
                ];
                foreach ($urls as $searchUrl) {
                    $resp = @file_get_contents($searchUrl, false, $ctx);
                    $data = $resp ? json_decode($resp, true) : null;
                    if ($data && !empty($data['results'])) {
                        foreach ($data['results'] as $r) {
                            if (!empty($r['poster_path'])) {
                                $posterUrl = 'https://image.tmdb.org/t/p/w300' . $r['poster_path'];
                                $tmdbId = $r['id'] ?? null;
                                $tmdbTitle = $r['title'] ?? $r['name'] ?? null;
                                $tmdbOverview = $r['overview'] ?? null;
                                break 3;
                            }
                        }
                    }
                }
            }

            $fullPath = $resolvedPath . '/' . $fileName;
            try {
                $db->prepare("INSERT OR REPLACE INTO folder_posters (path, poster_url, tmdb_id, title, overview) VALUES (:p, :u, :i, :t, :o)")
                   ->execute([':p' => $fullPath, ':u' => $posterUrl, ':i' => $tmdbId, ':t' => $tmdbTitle, ':o' => $tmdbOverview]);
            } catch (PDOException $e) { /* ignore lock */ }

            if ($posterUrl) {
                $result[$fileName] = ['poster' => $posterUrl];
                if ($tmdbOverview) $result[$fileName]['overview'] = $tmdbOverview;
            }
        }

        $videoRemaining = count($videoUncached) - count($videoToFetch);
    }

    echo json_encode(['posters' => $result, 'remaining' => $remaining + $videoRemaining]);
    exit;
}
```

- [ ] **Step 2: Update `?tmdb_set` handler to accept files too**

In the `?tmdb_set` handler (line 175), the `is_dir($fullPath)` check rejects files. Change it to accept both:

```php
if (!is_dir($fullPath) && !is_file($fullPath)) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
```

- [ ] **Step 3: Verify no regressions**

Run: `vendor/bin/phpunit -v && vendor/bin/phpstan analyse`
Expected: All pass

- [ ] **Step 4: Commit**

```bash
git add handlers/tmdb.php
git commit -m "feat: extend ?posters=1 to fetch TMDB posters for video files in movies folders"
```

---

### Task 6: Final integration testing and cleanup

**Files:**
- Verify all modified files work together

- [ ] **Step 1: Run full test suite**

Run: `vendor/bin/phpunit -v`
Expected: All 157+ tests pass

- [ ] **Step 2: Run static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: Level 5 pass, no new errors

- [ ] **Step 3: Reload PHP-FPM (OPcache)**

Run: `sudo systemctl reload php8.2-fpm`

- [ ] **Step 4: Manual smoke test**

Test the following scenarios in browser:
1. Browse a series folder — grid works as before (no regression)
2. On a folder card, click `⋮` — dropdown appears with "Changer le poster" and "Type: Série → Films"
3. Click "Type: Série → Films" — DB updated, `data-folder-type` changes
4. Navigate into the folder tagged "movies" — video files render as grid cards
5. Poster auto-fetch finds movie posters by filename
6. Click `⋮` on a movie card — "Changer le poster" opens the poster picker modal
7. Toggle view between grid/list — video file rows hide/show correctly
8. `pref_click` (play/download) applies to movie cards
