# Spec — Panneau utilisateur unifié

**Date :** 2026-03-30
**Statut :** Approuvé

---

## Contexte

Actuellement, `admin.php` est réservé aux admins. Les utilisateurs normaux n'ont accès qu'à un modal "Mon compte" dans `header.php` pour changer leur mot de passe. L'objectif est d'unifier l'expérience dans un seul panneau accessible à tous, extensible à l'avenir.

---

## Objectif

- Ouvrir `admin.php` à tous les utilisateurs connectés sous le nom **"Panneau"**
- Les admins voient tous les onglets + "Mon compte"
- Les users ne voient que "Mon compte"
- Supprimer le modal "Mon compte" de `header.php` (remplacé par l'onglet)
- Le bouton dans le header devient "Panneau" pour tout le monde

---

## Accès et sécurité

- **Avant :** redirect si `role !== 'admin'`
- **Après :** redirect si non connecté (session absente), sinon accès autorisé
- PHP expose une variable `$isAdmin` (bool) utilisée pour conditionner l'affichage des onglets admin
- Les actions AJAX sensibles (`create_user`, `update_user`, `delete_user`, etc.) conservent leur vérification `role === 'admin'` côté PHP — le masquage des onglets est cosmétique uniquement

---

## Onglets

| Onglet        | Admin | User |
|---------------|:-----:|:----:|
| Utilisateurs  | ✓     |      |
| Activité      | ✓     |      |
| Système       | ✓     |      |
| Mon compte    | ✓     | ✓    |

Les onglets non accessibles ne sont pas rendus dans le HTML (pas juste masqués en CSS).

---

## Onglet "Mon compte"

Contenu migré depuis le modal `header.php` :
- Formulaire changement de mot de passe (mot de passe actuel, nouveau, confirmation)
- Même logique JS/AJAX que le modal actuel
- L'onglet est actif par défaut pour les non-admins, secondaire pour les admins (dernier onglet)

---

## Header

- Suppression du modal `#modal-compte`, des fonctions `ouvrirModalCompte()` / `fermerModalCompte()`, et du bouton "Mon compte"
- Suppression du lien "Admin" conditionnel
- Remplacement par un unique lien **"Panneau"** → `/share/admin.php`, visible pour tout utilisateur connecté
- Le lien garde le même style visuel que l'actuel bouton "Admin"

---

## Nommage

- Fichier : `admin.php` (inchangé)
- Titre affiché dans la page : **"Panneau"**
- Titre de l'onglet navigateur : `<title>Panneau — ShareBox</title>`

---

## Comportement par défaut

- Admin → onglet "Utilisateurs" actif au chargement (comportement actuel conservé)
- User → onglet "Mon compte" actif au chargement

---

## Extensibilité

La structure d'onglets conditionnels (`$isAdmin`) peut être étendue avec un troisième niveau de rôle ou des onglets spécifiques à un utilisateur sans changer l'architecture.

---

## Hors périmètre

- Renommage du fichier `admin.php`
- Nouveaux onglets pour les users (au-delà de Mon compte)
- Gestion de rôles supplémentaires
