# Spec — Logs d'activité système

**Date :** 2026-03-30
**Statut :** Approuvé

---

## Contexte

Seuls les téléchargements sont actuellement loggés (`download_logs`). Les connexions, créations/suppressions de liens et actions admin ne laissent aucune trace visible. L'objectif est d'ajouter une table `activity_logs` pour ces événements système, affichés dans une nouvelle card dans l'onglet Activité d'`admin.php`.

---

## Table `activity_logs`

Migration SQLite v14 :

```sql
CREATE TABLE IF NOT EXISTS activity_logs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type  TEXT NOT NULL,
    username    TEXT,
    ip          TEXT,
    details     TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
)
```

Index : `idx_activity_ts` sur `created_at DESC`.

**Rétention :** 90 jours. Nettoyage automatique `DELETE FROM activity_logs WHERE created_at < datetime('now', '-90 days')` à chaque `log_activity()`.

---

## Fonction helper `log_activity()`

Ajoutée dans `functions.php` :

```php
function log_activity(string $event_type, ?string $username, ?string $ip, ?string $details = null): void
```

- Insère une ligne dans `activity_logs`
- Déclenche le nettoyage des entrées > 90 jours
- Ne lève pas d'exception (silencieux en cas d'erreur DB)

---

## Événements loggés

| event_type | Fichier | Déclencheur | details |
|---|---|---|---|
| `login_ok` | `login.php` | Après `password_verify` réussi | — |
| `login_fail` | `login.php` | Après échec (`$error = ...`) | username tenté |
| `link_create` | `ctrl.php` | Après `INSERT INTO links` | nom + token du lien |
| `link_delete` | `ctrl.php` | Avant/après `DELETE FROM links` | nom du lien |
| `admin_create_user` | `admin.php` | Après `INSERT INTO users` | username créé |
| `admin_edit_user` | `admin.php` | Après `UPDATE users` | username modifié |
| `admin_delete_user` | `admin.php` | Après `DELETE FROM users` | username supprimé |

**IP :** `$_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''` (nginx proxyfie).

---

## API — action `activity_events`

Nouvelle action AJAX dans `admin.php` (admin-only, ajoutée à `$adminOnlyActions`) :

**Paramètres POST :** `type_filter` (string, optionnel), `offset` (int)

**Réponse :**
```json
{
  "logs": [{ "id", "event_type", "username", "ip", "details", "created_at" }],
  "total": 42,
  "limit": 15,
  "offset": 0
}
```

---

## Affichage — onglet Activité

L'onglet Activité (`tab-activite`) contient deux cards séparées :

**Card 1 — Téléchargements** (existante, inchangée)

**Card 2 — Événements système** (nouvelle) :
- Filtre dropdown par type (`Tous`, `Connexions`, `Liens`, `Admin`)
- Tableau : badge coloré par type · Utilisateur · IP · Détails · Date
- Pagination 15/page (même pattern que les téléchargements)
- Chargement lazy au clic sur l'onglet (même flag `activityLoaded`)

**Badges couleur :**
- `login_ok` → vert
- `login_fail` → rouge
- `link_create` / `link_delete` → bleu / orange
- `admin_*` → accent (jaune)

---

## Sécurité

- `activity_events` est dans `$adminOnlyActions` — non accessible aux non-admins
- `details` est échappé en HTML côté JS (`esc()`)
- Pas de données sensibles dans `details` (pas de mot de passe, pas de token complet)

---

## Hors périmètre

- Notifications temps réel
- Export CSV
- Logs de streaming / browse
- Logs de logout
