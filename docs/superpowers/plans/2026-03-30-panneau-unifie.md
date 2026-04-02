# Panneau unifié — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ouvrir `admin.php` à tous les utilisateurs connectés sous le nom "Panneau", avec un onglet "Mon compte" visible par tous et les onglets admin masqués pour les users ordinaires.

**Architecture:** Modifier `admin.php` pour remplacer le guard admin-only par un guard connected-only, ajouter un onglet "Mon compte" avec le formulaire de changement de mot de passe, conditionner le rendu des onglets admin sur `$isAdmin`. Modifier `header.php` pour supprimer le modal "Mon compte" et le remplacer par un lien "Panneau" universel.

**Tech Stack:** PHP 8.3, SQLite, JS vanilla (pas de framework)

---

### Task 1 : Ouvrir admin.php aux utilisateurs connectés

**Files:**
- Modify: `admin.php:13-16`

- [ ] **Step 1 : Remplacer le guard admin-only par un guard connected + ajouter `$isAdmin`**

Lignes 13–16 actuelles :
```php
if (($_SESSION['sharebox_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Accès réservé aux administrateurs.';
    exit;
}
```

Remplacer par :
```php
$isAdmin = ($_SESSION['sharebox_role'] ?? '') === 'admin';
```

`require_auth()` (ligne 11) gère déjà la redirection si non connecté — pas besoin d'autre vérification.

- [ ] **Step 2 : Protéger les actions AJAX admin-only**

Les actions AJAX (`list_users`, `create_user`, `update_user`, `delete_user`, `list_downloads`, `start_rtorrent`, `stop_rtorrent`, `disk_usage`) doivent retourner 403 si appelées par un non-admin. Ajouter ce bloc juste après `if ($action !== '') {` (ligne 32) :

```php
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    // Actions admin-only
    $adminOnlyActions = ['list_users','create_user','update_user','delete_user',
                         'list_downloads','start_rtorrent','stop_rtorrent','disk_usage'];
    if (in_array($action, $adminOnlyActions, true) && !$isAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Accès réservé aux administrateurs.']);
        exit;
    }
```

Retirer la ligne `header('Content-Type: application/json; charset=utf-8');` qui était juste après (elle est maintenant dans le bloc ci-dessus).

- [ ] **Step 3 : Vérifier manuellement**

```bash
# En tant que user non-admin, accéder à admin.php doit afficher la page (pas 403)
# En tant que user non-admin, appeler ?action=list_users doit retourner {"error":"..."}
```

- [ ] **Step 4 : Commit**

```bash
git add admin.php
git commit -m "feat: ouvrir admin.php aux users connectés, guard AJAX admin-only conservé"
```

---

### Task 2 : Ajouter l'onglet "Mon compte" dans admin.php

**Files:**
- Modify: `admin.php:699-703` (nav des onglets)
- Modify: `admin.php` après le dernier `</div>` de tab-systeme

- [ ] **Step 1 : Conditionner les onglets admin dans la nav**

Remplacer le bloc `<nav class="admin-tabs">` (lignes 699–703) par :

```php
    <nav class="admin-tabs">
        <?php if ($isAdmin): ?>
        <button class="tab-btn active" onclick="switchTab('utilisateurs')">Utilisateurs</button>
        <button class="tab-btn" onclick="switchTab('activite')">Activité</button>
        <button class="tab-btn" onclick="switchTab('systeme')">Système</button>
        <?php endif; ?>
        <button class="tab-btn <?= $isAdmin ? '' : 'active' ?>" onclick="switchTab('compte')">Mon compte</button>
    </nav>
```

- [ ] **Step 2 : Conditionner les panels admin et ajouter le panel "Mon compte"**

Les divs `tab-utilisateurs`, `tab-activite`, `tab-systeme` doivent être enveloppées dans `<?php if ($isAdmin): ?> ... <?php endif; ?>`.

Remplacer :
```php
    <div id="tab-utilisateurs" class="tab-panel active">
```
par :
```php
    <?php if ($isAdmin): ?>
    <div id="tab-utilisateurs" class="tab-panel active">
```

Après la fermeture `</div>` du tab-systeme (chercher la ligne `</div>` qui suit le dernier contenu de tab-systeme), ajouter :
```php
    <?php endif; ?>

    <div id="tab-compte" class="tab-panel <?= $isAdmin ? '' : 'active' ?>">
        <div class="card" style="max-width:420px">
            <div class="card-header">
                <div class="card-title">Mon compte</div>
            </div>
            <div style="padding:1.2rem 1.4rem">
                <div class="modal-compte-label">Mot de passe actuel</div>
                <input type="password" id="mdp-actuel" class="modal-compte-input" autocomplete="current-password">
                <div class="modal-compte-label">Nouveau mot de passe</div>
                <input type="password" id="mdp-nouveau" class="modal-compte-input" autocomplete="new-password">
                <div class="modal-compte-label">Confirmation</div>
                <input type="password" id="mdp-confirm" class="modal-compte-input" autocomplete="new-password">
                <div id="mdp-error" style="display:none;color:var(--red,#e8453c);font-size:.82rem;margin-top:.5rem"></div>
                <div style="display:flex;justify-content:flex-end;margin-top:1.2rem">
                    <button id="mdp-submit" onclick="soumettreChangementMdp()" style="padding:.4rem .8rem;background:var(--accent,#f0a030);color:#000;border:none;border-radius:6px;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:600">Enregistrer</button>
                </div>
            </div>
        </div>
    </div>
```

- [ ] **Step 3 : Ajouter les styles manquants pour `.modal-compte-label` et `.modal-compte-input`**

Ces classes sont définies dans `header.php` via son `<style>`. Comme `header.php` est inclus dans `admin.php`, elles seront disponibles — pas de changement CSS nécessaire.

Vérifier que `header.php` contient bien ces classes :
```bash
grep -n "modal-compte-label\|modal-compte-input" /var/www/sharebox/style.css /var/www/sharebox/header.php
```

Si elles sont uniquement dans `header.php` inline, c'est bon. Si elles n'existent pas du tout, les ajouter dans le bloc `<style>` de `admin.php` (chercher `.badge-admin` vers ligne 452 pour repère) :

```css
.modal-compte-label { font-size:.82rem; color:var(--text-secondary); margin-bottom:.3rem; margin-top:.8rem; }
.modal-compte-input { width:100%; box-sizing:border-box; background:var(--bg-input,#1a1e2e); border:1px solid var(--border-strong,rgba(255,255,255,.08)); border-radius:6px; color:var(--text); padding:.45rem .6rem; font-size:.85rem; font-family:inherit; outline:none; }
.modal-compte-input:focus { border-color:var(--accent,#f0a030); }
```

- [ ] **Step 4 : Ajouter la fonction JS `soumettreChangementMdp` dans admin.php**

Juste avant la fonction `switchTab` (ligne ~1229), ajouter :

```javascript
// ── Mon compte ───────────────────────────────────────────────────────────────
async function soumettreChangementMdp() {
    const btn = document.getElementById('mdp-submit');
    const errDiv = document.getElementById('mdp-error');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    btn.disabled = true;
    errDiv.style.display = 'none';
    try {
        const resp = await fetch('/share/ctrl.php?cmd=change_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                current_password: document.getElementById('mdp-actuel').value,
                new_password: document.getElementById('mdp-nouveau').value,
                confirm_password: document.getElementById('mdp-confirm').value,
                csrf_token: csrfToken
            })
        });
        const data = await resp.json();
        if (data.error) {
            errDiv.textContent = data.error;
            errDiv.style.color = 'var(--red, #e8453c)';
            errDiv.style.display = 'block';
        } else {
            errDiv.style.color = 'var(--green, #3ddc84)';
            errDiv.textContent = 'Mot de passe modifié.';
            errDiv.style.display = 'block';
            document.getElementById('mdp-actuel').value = '';
            document.getElementById('mdp-nouveau').value = '';
            document.getElementById('mdp-confirm').value = '';
            setTimeout(() => {
                errDiv.style.display = 'none';
                errDiv.style.color = 'var(--red, #e8453c)';
            }, 1800);
        }
    } catch (_) {
        errDiv.textContent = 'Erreur de connexion';
        errDiv.style.color = 'var(--red, #e8453c)';
        errDiv.style.display = 'block';
    } finally {
        btn.disabled = false;
    }
}
```

- [ ] **Step 5 : Conditionner les appels JS d'init pour les admins seulement**

Lignes ~1241–1242 :
```javascript
loadUsers();
loadTmdbStatus();
```

Remplacer par :
```php
<?php if ($isAdmin): ?>
loadUsers();
loadTmdbStatus();
<?php endif; ?>
```

- [ ] **Step 6 : Commit**

```bash
git add admin.php
git commit -m "feat: ajouter onglet Mon compte dans le panneau, conditionner onglets admin"
```

---

### Task 3 : Mettre à jour header.php

**Files:**
- Modify: `header.php`

- [ ] **Step 1 : Remplacer les boutons Admin/Mon compte par un lien "Panneau" unique**

Remplacer le bloc (lignes 26–32) :
```php
        <?php if ($header_back): ?>
            <a href="/share/" style="color:var(--text-secondary);font-size:.8rem;text-decoration:none;padding:.3rem .6rem;border:1px solid var(--border);border-radius:var(--radius-sm)">← Fichiers</a>
        <?php elseif (($_SESSION['sharebox_role'] ?? '') === 'admin'): ?>
            <a href="/share/admin.php" style="color:var(--accent);font-size:.8rem;text-decoration:none;padding:.3rem .6rem;border:1px solid rgba(240,160,48,.2);border-radius:var(--radius-sm)">Admin</a>
        <?php endif; ?>
        <span style="color:var(--text-secondary);font-size:.85rem"><?= htmlspecialchars(get_current_user_name() ?? '') ?></span>
        <button onclick="ouvrirModalCompte()" style="color:var(--text-secondary,#8892a4);font-size:.8rem;background:none;border:1px solid var(--border,rgba(255,255,255,.04));border-radius:var(--radius-sm,6px);padding:.3rem .6rem;cursor:pointer">Mon compte</button>
```

Par :
```php
        <?php if ($header_back): ?>
            <a href="/share/" style="color:var(--text-secondary);font-size:.8rem;text-decoration:none;padding:.3rem .6rem;border:1px solid var(--border);border-radius:var(--radius-sm)">← Fichiers</a>
        <?php else: ?>
            <a href="/share/admin.php" style="color:var(--accent);font-size:.8rem;text-decoration:none;padding:.3rem .6rem;border:1px solid rgba(240,160,48,.2);border-radius:var(--radius-sm)">Panneau</a>
        <?php endif; ?>
        <span style="color:var(--text-secondary);font-size:.85rem"><?= htmlspecialchars(get_current_user_name() ?? '') ?></span>
```

- [ ] **Step 2 : Supprimer le modal et les fonctions JS**

Supprimer entièrement le bloc HTML du modal (lignes 36–51) :
```html
<div id="modal-compte" style="display:none;position:fixed;inset:0;...">
    ...
</div>
```

Supprimer le bloc `<script>` entier (lignes 52–106) contenant `ouvrirModalCompte`, `fermerModalCompte`, `soumettreChangementMdp`.

- [ ] **Step 3 : Mettre à jour le commentaire en haut de header.php**

Ligne 4 :
```php
 *  $header_back     : bool    — show '← Fichiers' instead of 'Admin' link (default: false)
```
Remplacer par :
```php
 *  $header_back     : bool    — show '← Fichiers' instead of 'Panneau' link (default: false)
```

- [ ] **Step 4 : Vérifier qu'aucune autre page n'appelle `ouvrirModalCompte`**

```bash
grep -rn "ouvrirModalCompte\|fermerModalCompte\|modal-compte" /var/www/sharebox/*.php /var/www/sharebox/*.js 2>/dev/null
```

Si un résultat remonte autre que `header.php` déjà modifié, traiter avant de committer.

- [ ] **Step 5 : Commit**

```bash
git add header.php
git commit -m "feat: remplacer boutons Admin+Mon compte par lien Panneau unique dans le header"
```

---

### Task 4 : Mettre à jour le titre de la page admin.php

**Files:**
- Modify: `admin.php:329` (title)
- Modify: `admin.php:697` (header_subtitle)
- Modify: `admin.php:1-5` (commentaire)

- [ ] **Step 1 : Changer le `<title>`**

Ligne 329 :
```html
    <title>ShareBox — Admin</title>
```
→
```html
    <title>ShareBox — Panneau</title>
```

- [ ] **Step 2 : Changer le subtitle du header inclus**

Ligne 697 :
```php
    <?php $header_subtitle = 'Administration'; $header_back = true; include __DIR__ . '/header.php'; ?>
```
→
```php
    <?php $header_subtitle = 'Panneau'; $header_back = true; include __DIR__ . '/header.php'; ?>
```

- [ ] **Step 3 : Mettre à jour le commentaire en haut du fichier**

Lignes 2–5 :
```php
/**
 * ShareBox - Admin panel (user management + seedbox control)
 * Requires admin role.
 */
```
→
```php
/**
 * ShareBox — Panneau utilisateur (gestion compte + administration)
 * Accessible à tous les utilisateurs connectés. Onglets admin réservés aux admins.
 */
```

- [ ] **Step 4 : Commit**

```bash
git add admin.php
git commit -m "feat: renommer Admin → Panneau dans les titres"
```

---

### Task 5 : Vérification manuelle end-to-end

- [ ] **Step 1 : Recharger PHP-FPM**

```bash
systemctl reload php8.3-fpm
```

- [ ] **Step 2 : Tests en tant qu'admin**

- Accéder à `/share/admin.php` → page "Panneau" avec 4 onglets (Utilisateurs, Activité, Système, Mon compte)
- Onglet "Utilisateurs" actif par défaut, liste des users s'affiche
- Onglet "Mon compte" accessible, changement de mot de passe fonctionne
- Header affiche "Panneau" (lien vers admin.php)

- [ ] **Step 3 : Tests en tant qu'user non-admin**

- Accéder à `/share/admin.php` → page "Panneau" avec seulement l'onglet "Mon compte"
- Onglet "Mon compte" actif par défaut
- Changement de mot de passe fonctionne
- Appel direct à `?action=list_users` retourne `{"error":"Accès réservé aux administrateurs."}`
- Header affiche "Panneau" (lien vers admin.php)

- [ ] **Step 4 : Tests sur les autres pages**

- `/share/` (index) → header affiche "Panneau" sans modal "Mon compte"
- Pas de référence cassée à `ouvrirModalCompte`

- [ ] **Step 5 : Lancer les tests PHPUnit**

```bash
cd /var/www/sharebox && vendor/bin/phpunit
```

Attendu : tous les tests passent (aucun test existant ne couvre admin.php ou header.php).
