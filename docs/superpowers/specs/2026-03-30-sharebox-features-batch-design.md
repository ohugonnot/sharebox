# Design — ShareBox features batch

Date: 2026-03-30

## Scope

7 features groupées en 2 phases d'implémentation :

**Groupe 1 — sans migration DB**
1. Filtre JS dans le navigateur de fichiers
2. Changement de mot de passe (users)
3. Badge "créé par" sur les liens (vue admin)
4. Purge des liens expirés (admin)
5. Quota disque par user (admin)

**Groupe 2 — avec migrations DB**
6. Limite de téléchargements (`max_downloads`) sur les liens
7. Logs de téléchargement + section "Activité récente" (admin)

---

## Groupe 1 — Features sans migration DB

### 1. Filtre JS dans le navigateur de fichiers

**Où :** `index.php` + `app.js`

- Input `<input type="search" id="file-filter" placeholder="Filtrer…">` inséré au-dessus de `#file-list`
- Event `input` dans app.js : filtre les `<li>` du file-list en comparant le nom de fichier (case-insensitive, `textContent`)
- Reset automatique à chaque navigation (changement de dossier)
- Pas de requête serveur

### 2. Changement de mot de passe

**Où :** `header.php` + `ctrl.php`

- Bouton "Mon compte" dans `header.php` (inclus dans index.php et admin.php — visible pour tous les users connectés, admin inclus)
- Modal avec 3 champs : mot de passe actuel, nouveau, confirmation
- Nouvelle action `change_password` dans `ctrl.php` (POST + CSRF) :
  - Récupère l'user depuis `$_SESSION['sharebox_user']`
  - Vérifie l'ancien password avec `password_verify()`
  - Valide le nouveau (≥ 4 caractères, confirmation identique)
  - `UPDATE users SET password_hash = ? WHERE username = ?`
  - Retourne `{ok: true}` ou `{error: "..."}`
- Pas de re-login forcé après changement

### 3. Badge "créé par" sur les cartes de liens

**Où :** `index.php` — fonction `afficher_liens()`

- Quand `$currentRole === 'admin'`, afficher un badge `créé par {username}` en bas de chaque carte de lien
- Utilise `$link['created_by']` (colonne déjà présente)
- Affiché uniquement si `created_by` n'est pas null

### 4. Purge des liens expirés

**Où :** `admin.php`

- Bouton "Purger les expirés" dans la section de stats liens (admin.php, action `tmdb_status` area)
- Nouvelle action `purge_expired` dans admin.php (POST + CSRF) :
  - `DELETE FROM links WHERE expires_at IS NOT NULL AND expires_at < datetime('now')`
  - Retourne `{ok: true, deleted: N}`
- Confirmation JS avant exécution : "X liens expirés seront supprimés définitivement."
- Recharge la section liens après succès

### 5. Quota disque par user

**Où :** `admin.php`

- Nouvelle colonne "Disque" dans le tableau users
- Chargée dans l'action `list_users` existante :
  - Pour chaque user, si `BASE_PATH . $username` existe : exécuter `du -sb` avec timeout 5s via `proc_open`
  - Si timeout ou erreur : valeur `null` → affiché "—"
  - Formater en KB/MB/GB côté JS
- Le timeout évite de bloquer sur les gros dossiers

---

## Groupe 2 — Features avec migrations DB

### 6. Limite de téléchargements (max_downloads)

**Migration :** v12 — `ALTER TABLE links ADD COLUMN max_downloads INTEGER NULL`

**Création de lien (`ctrl.php`, action `create`) :**
- Nouveau champ optionnel `max_downloads` dans la payload JSON (null si vide ou 0)
- Inséré en DB à la création

**UI création (`app.js` / formulaire de création) :**
- Champ optionnel "Max téléch." (input number, min=1, placeholder="Illimité")

**Affichage carte lien (`index.php`) :**
- Si `max_downloads` défini : afficher `{download_count}/{max_downloads}` à la place du simple compteur
- Carte stylée différemment si `download_count >= max_downloads` (lien épuisé)

**Enforcement (`download.php`) :**
- Après validation du token, vérifier : `max_downloads IS NOT NULL AND download_count >= max_downloads`
- Si atteint : HTTP 410 Gone avec message "Ce lien a atteint sa limite de téléchargements."

### 7. Logs de téléchargement + Activité récente

**Migration :** v13 — nouvelle table

```sql
CREATE TABLE IF NOT EXISTS download_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id INTEGER NOT NULL,
    ip TEXT NOT NULL,
    downloaded_at TEXT NOT NULL DEFAULT (datetime('now'))
)
```

**Enregistrement (`download.php`) :**
- À chaque téléchargement valide (après vérifications token/password/expiry/max_downloads) :
  - `INSERT INTO download_logs (link_id, ip) VALUES (?, ?)`
  - IP depuis `$_SERVER['REMOTE_ADDR']`
- Nettoyage auto probabiliste dans `download.php` après l'insert : 1% de chance d'exécuter `DELETE FROM download_logs WHERE downloaded_at < datetime('now', '-30 days')` — évite de ralentir `get_db()` appelé partout

**Section "Activité récente" (admin.php) :**
- Nouvelle section sous la section TMDB
- Action `recent_activity` : retourne les 50 derniers logs avec JOIN sur `links` (nom, token)
- Dropdown "Filtrer par user" (liste les users, filtre via `links.created_by`)
- Tableau : nom fichier | token | IP | date

---

## Fichiers modifiés

| Fichier | Changements |
|---------|-------------|
| `index.php` | Badge "créé par" sur cartes liens (groupe 1) |
| `header.php` | Bouton "Mon compte" + modal changement mdp |
| `app.js` | Filtre file browser, modal changement mdp, champ max_downloads |
| `ctrl.php` | Action `change_password`, champ `max_downloads` dans `create` |
| `admin.php` | Purge expirés, quota disque dans list_users, section activité récente |
| `download.php` | Vérif max_downloads, insert download_logs |
| `db.php` | Migrations v12 + v13 |
| `style.css` | Styles : input filtre, modal compte, badge créé-par, état lien épuisé |

---

## Tests

- `ChangePasswordTest` : changement valide, mauvais ancien mdp, confirmation incorrecte
- `MaxDownloadsTest` : création avec max, enforcement dans download.php, affichage carte
- `DownloadLogsTest` : insert log à chaque dl, cleanup > 30j, action recent_activity
- `PurgeExpiredTest` : purge supprime uniquement les expirés
- Mise à jour `AdminTest` : quota disque (mock `du`), purge
