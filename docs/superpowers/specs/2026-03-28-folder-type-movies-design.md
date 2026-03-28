# Design : Tag "Série / Films" sur les dossiers

## Contexte

Le grid Netflix fonctionne bien pour les séries (dossiers → cartes avec posters TMDB) mais pas pour un répertoire de films où les fichiers vidéo sont directement dans le dossier. On ajoute un tag `folder_type` sur chaque dossier pour adapter le rendu.

## 1. Base de données

- Migration v4 : `ALTER TABLE folder_posters ADD COLUMN folder_type TEXT DEFAULT 'series'`
- Valeurs : `series` (défaut, comportement actuel inchangé) / `movies`
- Le type est sur le **dossier parent** — quand on entre dans un dossier tagué `movies`, les fichiers vidéo deviennent des cartes grid

## 2. Menu dropdown sur les cartes

- Le bouton `⋮` existant devient un dropdown avec 2 entrées :
  - **Changer le poster** (comportement actuel de `openPosterPicker`)
  - **Type : Série / Films** (toggle)
- Clic sur "Type" → POST `?folder_type_set=1` avec `folder` + `type` → update DB
- Le dropdown se ferme au clic extérieur
- L'entrée "Type" affiche l'état actuel (ex: "Type : Série" avec possibilité de basculer)

## 3. Rendu des fichiers vidéo en mode "movies"

- Le PHP vérifie le `folder_type` du dossier courant dans `folder_posters`
- Si `movies` : les fichiers vidéo génèrent des grid cards (même style que les cartes dossier)
- Le clic sur une carte film suit `pref_click` (play ou download)
- Chaque carte fichier a aussi un menu `⋮` dropdown pour changer son poster manuellement
- Couleurs de fallback, format 2/3, overview au hover : identiques aux cartes dossier

## 4. TMDB pour les fichiers

- Extension de `?posters=1` : détecte le `folder_type` du dossier courant
- Si `movies` : scanne les fichiers vidéo, extrait titre+année du filename
- Réutilise `extract_title_year()` adapté : virer extension + tags codec/résolution/source (BluRay, 1080p, x264, DTS, etc.)
- Cache dans `folder_posters` avec le path du fichier comme clé (même table, même structure)
- Même logique de batch (max 10 par requête, polling JS)
- Même popup de recherche manuelle pour corriger les résultats auto

## 5. Ce qui ne change pas

- Dossiers tagués `series` ou non tagués : comportement identique à aujourd'hui
- La grille CSS, le format 2/3, les couleurs de fallback, l'overview au hover
- Le poster picker modal (même code, accessible depuis le dropdown)
- Le endpoint `?tmdb_search=` et `?tmdb_set=` : inchangés
- Les settings existants (pref_view, pref_cardsize, pref_quality, etc.)

## Fichiers impactés

- `db.php` — migration v4 (ajout colonne `folder_type`)
- `handlers/tmdb.php` — gestion `?folder_type_set=1`, extension `?posters=1` pour fichiers vidéo
- `functions.php` — adaptation `extract_title_year()` pour filenames
- `download.php` — dropdown menu, cartes fichiers vidéo en mode movies, passage folder_type au JS
