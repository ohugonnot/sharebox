# Design — Mode privé par utilisateur

**Date** : 2026-03-30
**Statut** : Approuvé

## Contexte

ShareBox supporte plusieurs utilisateurs. Actuellement, tous les users authentifiés voient tous les liens de partage et peuvent naviguer dans le même BASE_PATH. Il n'y a aucune isolation entre users.

Besoin : permettre à un admin de marquer un user comme "privé". Un user privé ne voit que son propre contenu et reste invisible aux autres users non-admin.

## Règles de visibilité

| Acteur | File browser | Liens visibles |
|--------|-------------|----------------|
| Admin | Tout BASE_PATH | Tous les liens |
| User non-privé | Tout BASE_PATH | Liens de tous les users non-privés + liens sans owner |
| User privé | `BASE_PATH/{username}/` uniquement | Ses propres liens uniquement |

- Les liens d'un user privé sont invisibles aux autres users (sauf admin)
- Le dashboard système (CPU, RAM, disk, réseau) reste visible pour tous
- Un admin est toujours non-filtré, indépendamment de son propre flag `private`

## Changements base de données

```sql
-- Migration 1 : flag private sur users
ALTER TABLE users ADD COLUMN private INTEGER NOT NULL DEFAULT 0;

-- Migration 2 : attribution des liens à leur créateur
ALTER TABLE links ADD COLUMN created_by TEXT REFERENCES users(username);
```

Les liens existants (`created_by = NULL`) sont traités comme publics — comportement actuel préservé.

## Admin panel (`admin.php`)

- Formulaire **create_user** : ajout checkbox "Mode privé" → champ `private`
- Formulaire **update_user** : même checkbox, pré-cochée selon valeur courante
- Lecture via `list_users` : inclure le champ `private` dans le retour

## Filtrage serveur (`ctrl.php`)

### `cmd=ls` (file browser)
Si `role !== 'admin'` ET `private = 1` : la racine de navigation est forcée à `BASE_PATH . $username . '/'`. Toute tentative de path traversal au-dessus est bloquée (logique `is_path_within` déjà en place).

### `cmd=list_links`
```sql
-- Admin
SELECT * FROM links ORDER BY created_at DESC;

-- User non-privé : liens publics uniquement (owner absent ou owner non-privé)
SELECT l.* FROM links l
LEFT JOIN users u ON l.created_by = u.username
WHERE l.created_by IS NULL OR u.private = 0
ORDER BY l.created_at DESC;

-- User privé : ses liens uniquement
SELECT * FROM links WHERE created_by = ? ORDER BY created_at DESC;
```

### `cmd=create_link`
Remplir automatiquement `created_by` avec `$_SESSION['sharebox_user']`.

### `cmd=delete_link`
Un user non-admin ne peut supprimer que ses propres liens (`created_by = username`). Admin peut tout supprimer.

## Frontend

- Checkbox "Mode privé" dans le formulaire user de l'admin panel
- Implémenté avec `/frontend-design` skill pour cohérence UI
- Aucun changement côté file browser JS — le filtrage est 100% serveur

## Vérification

1. Créer user `alice` (non-privé) et user `bob` (privé)
2. Se connecter en `alice` → créer un lien → visible en `alice`
3. Se connecter en `bob` → créer un lien → visible en `bob`, invisible en `alice`
4. En `alice` → les liens de `bob` n'apparaissent pas
5. En `bob` → les liens de `alice` n'apparaissent pas
6. En admin → tous les liens visibles
7. En `bob` → file browser limité à `/home/storage/users/bob/`
8. En `alice` → file browser accès complet à `/home/storage/users/`
