# Design : Unification du header admin.php

**Date :** 2026-03-30

## Contexte

`admin.php` utilise un `<nav class="nav">` avec CSS inline, visuellement différent du `<header class="app-header">` de `index.php`. L'objectif est d'harmoniser les deux pages avec le même style.

## Approche retenue

Lier `style.css` dans `admin.php` et remplacer le `<nav>` par un `<header class="app-header">` identique à celui de `index.php`.

## Changements

### 1. `<head>` — ajouter style.css

```html
<link rel="stylesheet" href="/share/style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
```

### 2. Header HTML — remplacer `<nav class="nav">...</nav>`

```html
<header class="app-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem">
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

### 3. CSS inline — supprimer le bloc `.nav`

Supprimer les règles `.nav`, `.nav-left`, `.nav-brand`, `.nav-links`, `.nav-link`, `.nav-right`, `.nav-user`, `.nav-logout` du `<style>` inline d'`admin.php`.

## Ce qui ne change pas

- Tout le reste du contenu d'`admin.php` (cartes, modals, JS)
- Le CSS inline restant (styles spécifiques admin : `.card`, `.btn`, `.modal`, etc.)
- La logique PHP
