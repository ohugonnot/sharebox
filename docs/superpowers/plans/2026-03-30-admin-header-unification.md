# Admin Header Unification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer le header `<nav class="nav">` d'`admin.php` par le style `<header class="app-header">` de `index.php`.

**Architecture:** Ajouter `style.css` dans le `<head>` d'`admin.php`, remplacer le bloc `<nav>` par un `<header class="app-header">` avec logo SVG + titre + subtitle "Administration" à gauche, et liens "← Fichiers" / ruTorrent / username / Logout à droite. Supprimer le CSS inline `.nav`.

**Tech Stack:** PHP 8.3, HTML/CSS (pas de JS modifié)

---

### Task 1 : Ajouter style.css dans le `<head>` d'admin.php

**Files:**
- Modify: `admin.php:275`

- [ ] **Step 1 : Ajouter le lien vers style.css**

Dans `admin.php`, après la ligne :
```html
    <link rel="icon" type="image/svg+xml" href="/share/favicon.svg">
```
Ajouter :
```html
    <link rel="stylesheet" href="/share/style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
```

- [ ] **Step 2 : Vérifier en navigateur**

Ouvrir `https://dn40904.seedhost.eu/share/admin.php` — la page ne doit pas être cassée visuellement (les variables CSS de `style.css` ne conflictuent pas avec le CSS inline existant).

---

### Task 2 : Supprimer le CSS `.nav` inline

**Files:**
- Modify: `admin.php:314-380`

- [ ] **Step 1 : Supprimer le bloc CSS `.nav`**

Dans `admin.php`, supprimer entièrement le bloc commenté `/* ── Nav ── */` et toutes ses règles, soit les lignes :
```css
        /* ── Nav ── */
        .nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 2rem;
            border-bottom: 1px solid var(--border);
            background: var(--bg-deep);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-left {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .nav-brand {
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: -.02em;
        }

        .nav-brand span { color: var(--accent); }

        .nav-links {
            display: flex;
            gap: .5rem;
        }

        .nav-link {
            padding: .4rem .8rem;
            font-size: .78rem;
            font-weight: 500;
            color: var(--text-dim);
            text-decoration: none;
            border-radius: 6px;
            transition: all .2s;
        }

        .nav-link:hover { color: var(--text); background: var(--accent-dim); }
        .nav-link.active { color: var(--accent); background: var(--accent-dim); }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .nav-user {
            font-size: .78rem;
            color: var(--text-dim);
        }

        .nav-logout {
            font-size: .72rem;
            color: var(--text-muted);
            text-decoration: none;
            padding: .3rem .6rem;
            border: 1px solid var(--border);
            border-radius: 5px;
            transition: all .2s;
        }

        .nav-logout:hover { color: var(--text-dim); border-color: var(--border-strong); }
```

---

### Task 3 : Remplacer le `<nav>` par `<header class="app-header">`

**Files:**
- Modify: `admin.php:640-653`

- [ ] **Step 1 : Remplacer le bloc `<nav>` dans le HTML**

Remplacer :
```html
<nav class="nav">
    <div class="nav-left">
        <div class="nav-brand">Share<span>Box</span></div>
        <div class="nav-links">
            <a href="/share/" class="nav-link">Fichiers</a>
            <a href="/share/admin.php" class="nav-link active">Admin</a>
            <?php if ($seedboxMode): ?><a href="/" class="nav-link" target="_blank">ruTorrent</a><?php endif; ?>
        </div>
    </div>
    <div class="nav-right">
        <span class="nav-user"><?= htmlspecialchars($_SESSION['sharebox_user'] ?? '') ?></span>
        <a href="/share/logout.php" class="nav-logout">Logout</a>
    </div>
</nav>
```

Par :
```html
<div class="app">
<header class="app-header" style="display:flex;justify-content:space-between;align-items:center">
    <div style="display:flex;align-items:center;gap:.7rem">
        <div class="app-logo">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M21 3h-8v2h5.59L11 12.59 12.41 14 20 6.41V12h2V3z" fill="#0c0e14"/>
                <path d="M3 5v16h16v-7h-2v5H5V7h5V5H3z" fill="#0c0e14"/>
            </svg>
        </div>
        <div>
            <div class="app-title">Share<span style="color:var(--accent)">Box</span></div>
            <div class="app-subtitle">Administration</div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:.8rem">
        <a href="/share/" style="color:var(--text-secondary);font-size:.8rem;text-decoration:none;padding:.3rem .6rem;border:1px solid var(--border);border-radius:var(--radius-sm)">← Fichiers</a>
        <?php if ($seedboxMode): ?>
            <a href="/" target="_blank" style="color:var(--text-secondary);font-size:.8rem;text-decoration:none;padding:.3rem .6rem;border:1px solid var(--border);border-radius:var(--radius-sm)">ruTorrent</a>
        <?php endif; ?>
        <span style="color:var(--text-secondary);font-size:.85rem"><?= htmlspecialchars($_SESSION['sharebox_user'] ?? '') ?></span>
        <a href="/share/logout.php" style="color:var(--text-muted);font-size:.8rem;text-decoration:none;padding:.3rem .6rem;border:1px solid var(--border);border-radius:var(--radius-sm)">Logout</a>
    </div>
</header>
```

> **Note :** Le `<div class="main">` qui suit dans admin.php doit être remplacé par `<div class="main">` — il reste inchangé. Vérifier que la balise `<div class="app">` est bien fermée en fin de page (chercher `</div>` final).

- [ ] **Step 2 : Vérifier la fermeture des balises**

S'assurer qu'il y a bien un `</div>` correspondant au `<div class="app">` ajouté, avant la fermeture `</body>`. Si le `</div>` final de `.main` est déjà présent, ajouter `</div>` (pour `.app`) juste avant `</body>`.

- [ ] **Step 3 : Vérifier en navigateur**

Ouvrir `https://dn40904.seedhost.eu/share/admin.php` — le header doit afficher :
- Logo SVG orange à gauche + "ShareBox" en grand + "Administration" en sous-titre
- Liens "← Fichiers", ruTorrent (si seedbox), username, Logout à droite
- Même apparence que `https://dn40904.seedhost.eu/share/`

- [ ] **Step 4 : Commit**

```bash
git add admin.php
git commit -m "feat: unify admin header with index.php app-header style"
```
