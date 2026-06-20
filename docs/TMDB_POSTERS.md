# TMDB Posters — flux complet

Carte du système d'affiches : comment un nom de fichier/dossier devient une fiche TMDB
(affiche + note + synopsis) dans la grille. À lire avant d'investiguer, modifier ou
améliorer le matching — évite de tout redécouvrir à chaque fois.

## Vue d'ensemble (qui appelle quoi)

```
Navigation grille (browser)
        │  GET ?posters=1&path=…
        ▼
handlers/tmdb.php  ── découvre les items, lit le cache, INSÈRE des lignes NULL,
        │             NE FAIT AUCUN appel TMDB synchrone, puis auto-démarre le worker
        │  exec(tmdb-worker.php &)
        ▼
tools/tmdb-worker.php ── le MOTEUR : discover → match (word-removal) → verify →
        │                propagation parent→enfant → posters de saison → GC → refresh
        │  pour chaque ligne NULL :
        ▼
functions.php : extract_title_year → tmdb_match → tmdb_search_candidates →
                tmdb_score_candidate → tmdb_score_to_verified
        │  appels HTTP :
        ▼
tmdb_fetch_cached → tmdb_fetch (cURL, retry/backoff) → api.themoviedb.org
```

Principe clé : **le web n'appelle jamais TMDB en synchrone** (latence + rate-limit). Il
ne fait qu'inscrire des lignes `poster_url IS NULL` dans `folder_posters` et lancer le
worker, qui fait tout le travail réseau en tâche de fond. Le front **poll** ensuite.

## Points d'entrée

| Déclencheur | Fichier | Rôle |
|---|---|---|
| `GET ?posters=1` | `handlers/tmdb.php:43` | Découverte/enqueue des dossiers + fichiers vidéo, lecture cache, auto-start worker |
| Cron / auto-start | `tools/tmdb-worker.php` | Le moteur de matching (toutes les phases) |
| `POST ?folder_type_set` | `handlers/tmdb.php:478` | Admin bascule un dossier `series`↔`movies` ; **reset les enfants** pour re-match |
| `GET ?tmdb_search=` | `handlers/tmdb.php` | Recherche manuelle (picker admin) |
| `POST ?tmdb_set` | `handlers/tmdb.php` | Choix humain → `verified=100` (jamais écrasé) |
| Skill `/tmdb-scan` | (IA) | Re-vérifie les entrées `verified` faible (40–60) |
| `docker/demo-bootstrap.php` | Docker démo | Marque les dossiers films + enqueue, puis lance le **vrai** worker (zéro mapping en dur) |

## Modèle de données : table `folder_posters`

| Colonne | Sens |
|---|---|
| `path` | Chemin absolu (PK). Peut être un **dossier** OU un **fichier vidéo** (dossiers type `movies`) |
| `poster_url` | URL affiche TMDB (w300/w500) ; `NULL` = à traiter ; `__none__` = masqué par l'user |
| `tmdb_id`, `title`, `overview`, `tmdb_year`, `tmdb_type`, `tmdb_rating` | Métadonnées TMDB |
| `verified` | **Score de confiance** (voir ci-dessous) |
| `match_attempts` | Compteur de tentatives ratées (borne `< 3`). **N'incrémente que sur un vrai « rien trouvé »** |
| `ia_checked` | `1` = traité (évite le spinner « IA pending ») |
| `folder_type` | `series` (défaut) ou `movies` — décide l'affichage ET l'enqueue des fichiers vidéo |
| `updated_at` | Refresh TTL (7 j) |

### Niveaux `verified`

| Valeur | Signification |
|---|---|
| `0` | Cherché, rien trouvé (sous le seuil) |
| `40` / `60` | Auto-match (`tmdb_score_to_verified` : score ≥ 35 / ≥ 55) |
| `70` | Auto-vérifié en masse (≥ 3 entrées même `tmdb_id` dans un dossier) ou propagation parent |
| `80` | Auto-match fort (score ≥ 80) |
| `100` | **Choix humain** — jamais écrasé par le worker ni le refresh |
| `-1` | L'user a demandé une re-vérification IA |
| `poster_url='__none__'` | Affiche masquée par l'user (def. si `verified=100`) |

## Pipeline de matching (`functions.php`)

Du nom brut au candidat retenu :

1. **`extract_title_year($name)` — `functions.php:178`**
   Nettoie le nom : retire crochets, normalise séparateurs, extrait l'année (gère les
   ranges type `1997-2003` → première année), retire marqueurs saison + mots-bruit
   (`integrale`, `collection`…), **coupe au 1ᵉʳ tag technique** (`1080p|x264|MULTI|…`).
   Retour `['title' => …, 'year' => int|null]`. Guards : un code `S01`/`Films` nu → titre vide.

2. **`tmdb_match($title, $year, $preferTv, $apiKey, $ctx, &$responded)` — `functions.php:619`**
   **Boucle word-removal** : retire les mots **depuis la fin**, un par un (≤ 5 essais),
   re-cherche, **garde la requête la plus LONGUE qui passe le seuil 55**. Score contre la
   requête réellement utilisée (pas le titre brut). Si rien ≥ 35 **et** année fournie →
   **2ᵉ passe sans filtre année** (le scoring garde le bonus année). Retourne
   `['candidate','score']` ou `null`. `$responded` (out) = TMDB a-t-il répondu au moins une fois.

3. **`tmdb_search_candidates($title, $year, $apiKey, $ctx, $limit, $preferTv, &$responded)` — `functions.php:561`**
   Interroge **TOUJOURS les deux types** `movie` ET `tv` (pas seulement le type probable) ×
   langues **`fr` + `en-US`** : c'est le SCORING qui tranche. Se limiter à un type matchait un
   film homonyme pour toute série (Breaking Bad → un film « Breaking Bad Wolf »). Année en VRAI
   paramètre TMDB (`&year=` / `&first_air_date_year=`). **Fusion par `id`** : un même titre vu dans
   2 langues agrège ses variantes dans `titles[]` (clé pour l'anime : on cherche en anglais, TMDB
   renvoie le titre fr/jp). Via `tmdb_fetch_cached` ; sleep 300 ms uniquement sur appel réseau réel.

4. **`tmdb_score_candidate($title, $year, $candidate, $preferTv)` — `functions.php` (≈ 660)**
   Score 0–100 : similarité `similar_text` ×0.65 sur **toutes les variantes de titre** (`titles[]`
   fr/en/original), pénalité longueur anti « One »/« One Piece », bonus substring (≤8),
   année exacte +15 / ±1 +10, **cohérence type : +18 pour la TV quand `preferTv`** (dossier à
   saisons = série quasi-sûre) sinon film +10 / tv +3, popularité `vote_count` +2…+12.

5. **`tmdb_score_to_verified($score)`** : `≥80→80`, `≥55→60`, `≥35→40`, sinon `0`.

## Robustesse réseau

- **`tmdb_fetch` — `functions.php:420`** : cURL (share handle DNS/SSL), `connect=3s`/`timeout=8s`,
  **retry + backoff exponentiel** (500ms→1s→2s) sur 5xx/réseau, respecte `Retry-After` sur 429,
  abandon immédiat sur 401/404/4xx.
- **`tmdb_fetch_cached` — `functions.php:508`** : cache SQLite (`tmdb_cache`), TTL 7 j, GC
  probabiliste. **Ne cache QUE les succès** → un échec transitoire est réessayé.
- **Espacement** : `usleep(300000)` entre appels dans `tmdb_search_candidates`.

### Transient vs no-match (important)

Un **502 TMDB** (Bad Gateway) est un échec d'infra **transitoire**, pas un « pas de match ».
Le flag `$responded` permet au worker de **ne PAS consommer `match_attempts`** quand TMDB
était injoignable (`tmdb-worker.php`, branche `elseif ($responded)`). Sans ça, une rafale de
502 épuisait les 3 tentatives et laissait un dossier sans affiche **pour toujours**.
Désormais : transitoire → on n'incrémente pas → le prochain passage réessaie.

## Phases du worker (`tools/tmdb-worker.php`)

1. **Phase 0 — `discover_folders` (`:42`)** : crawle `BASE_PATH`, insère les **DOSSIERS** seulement
   (pas les fichiers — les fichiers vidéo sont enqueués par `?posters` côté web, branche `movies`).
2. **Match** (`:151`) : `SELECT … WHERE poster_url IS NULL AND match_attempts < 3 LIMIT 200`.
   Groupe les fichiers par titre extrait (dédoublonne les appels), appelle `tmdb_match`.
   **Détection série vs film (`preferTv`)** : signal **structurel** d'abord — un dossier est une
   série s'il contient ≥ 2 vidéos OU des sous-dossiers saison/numérotés (`Season 1`, `01`, `02`),
   un film n'a qu'une vidéo. Plus fiable que de parser le nom (`SxxExx`). `preferTv` ordonne la
   recherche et **bonifie** le score TV (+18) — il ne RESTREINT plus à un seul type.
3. **Auto-verify** (`:432`) : ≥ 3 entrées même `tmdb_id` dans un dossier (verified 50–60) → 70.
4. **Propagation parent→enfant** (`:459`, `:502`) : un parent `verified≥60` donne son affiche aux
   enfants sans poster (saisons, etc.).
5. **Posters de saison** (`:526`) : via `tmdb_id` parent + `/tv/{id}/season/{n}`.
6. **GC** (`:640`) : supprime les lignes dont le `path` n'existe plus.
7. **Refresh TTL** (`:670`) : rafraîchit `overview`/`rating` des entrées vieilles de > 7 j.

Boucle externe `do/while` : relance des passes tant qu'il y a du progrès ; s'arrête sur
stagnation (`pendingCount:attemptsSum` inchangé) — donc si TMDB est down, le worker s'arrête
au lieu de boucler, et reprend au prochain cron.

## Dépendance clé : `folder_type = 'movies'`

Les **fichiers vidéo** (dossier de films à plat) ne reçoivent une ligne `folder_posters`
**que si** le dossier parent est marqué `folder_type='movies'` (`handlers/tmdb.php:214`).
Posé par l'admin via `POST ?folder_type_set` (`:478`), qui **reset les enfants** pour re-match.
Sans ce flag, un dossier est traité en `series` (seuls les sous-dossiers reçoivent des affiches).

## Démo Docker = vrai install

La démo doit utiliser les **mêmes chemins de code** qu'une install réelle (pas un seed naïf) :
1. `docker/demo-data.sh` crée les médias d'exemple.
2. Les dossiers de films sont marqués `movies` via le vrai endpoint `?folder_type_set`.
3. On `curl ?posters=1` sur les dossiers de la grille (= 1ᵉʳ visiteur) → enqueue + auto-start worker.
4. Le worker matche tout via `tmdb_match` (retry/cache/scoring identiques à la prod).

→ La démo devient un **smoke test réel** du matching. Toute amélioration du pipeline profite
automatiquement à la démo. Cron Docker : le worker est planifié (toutes les ~10 min) si
`SHAREBOX_TMDB_API_KEY` est défini.

## Où changer quoi (aide-mémoire)

| Je veux… | Fichier:fonction |
|---|---|
| Améliorer le nettoyage des noms de release | `functions.php:extract_title_year` |
| Changer la stratégie de recherche (endpoints, langues, année) | `functions.php:tmdb_search_candidates` |
| Régler la boucle word-removal / fallback | `functions.php:tmdb_match` |
| Ajuster les poids du score / seuils | `functions.php:tmdb_score_candidate`, `tmdb_score_to_verified` |
| Toucher au retry/backoff/cache réseau | `functions.php:tmdb_fetch`, `tmdb_fetch_cached` |
| Changer l'enqueue web / l'auto-start worker | `handlers/tmdb.php` (bloc `?posters`) |
| Modifier les phases batch (verify, propagation, saisons, GC) | `tools/tmdb-worker.php` |
| Logs | canal `poster` → `dirname(STREAM_LOG)/poster.log` (`poster_log()`) |

## Tests

- `tests/TmdbMatchTest.php` — `extract_title_year` sur noms crades, sémantique word-removal,
  bonus année, garde-fous source (year-param typé, usage du cache).
- Lancer : `php8.2 vendor/bin/phpunit` (le `php` par défaut peut manquer `pdo_sqlite`).
