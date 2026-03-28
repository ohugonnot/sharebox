# ShareBox — Notes pour Claude

## Contexte serveur

- Debian, nginx + PHP-FPM 8.2, SQLite, ffmpeg 5.1
- rtorrent en seeding lourd (utilisateur `ropixv2`) sur RAID0 (md5, 4x HDD)
- SCGI socket rtorrent : `/var/run/ropixv2/.rtorrent.sock`
- OPcache actif (`validate_timestamps=On`, `revalidate_freq=240s`) → après un edit PHP, faire `systemctl reload php8.2-fpm`
- www-data est dans le groupe ropixv2 (accès aux fichiers en lecture)

### Hardware

| | |
|---|---|
| CPU | Intel Xeon E31230 — Sandy Bridge, 4c/8t @ 3.2 GHz |
| RAM | 15 GB (≈14.7 GB disponible, majoritairement page cache) |
| GPU | Matrox G200EH (BMC/IPMI serveur) — **pas d'iGPU Intel, VAAPI inutilisable** |
| Stockage | RAID0 md5, 4× HDD |

### PHP-FPM pool (`/etc/php/8.2/fpm/pool.d/www.conf`)

```
pm = dynamic
pm.max_children   = 25   # chaque stream tient un worker pour toute la durée du film
pm.start_servers  = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 10
```

Rationale : avec 3 streams long-running + retries + probes + UI admin, 5 (default) saturait.
25 workers × ~25 MB RSS = 625 MB — trivial sur 15 GB.

## Architecture fichiers clés

| Fichier | Rôle |
|---|---|
| `config.php` | Constantes : `BASE_PATH`, `DB_PATH`, `XACCEL_PREFIX`, `DL_BASE_URL`, `STREAM_MAX_CONCURRENT` |
| `db.php` | Connexion PDO SQLite, WAL mode, création tables, migrations via `PRAGMA user_version` |
| `functions.php` | Utilitaires purs + helpers ffmpeg (`buildFilterGraph`, `buildFfmpegInputArgs`, etc.) + `stream_log` + `computeEpisodeNav` |
| `download.php` | Routeur public (~746 lignes) : auth → path validation → dispatch vers handlers |
| `handlers/probe.php` | Probe ffprobe avec cache SQLite + pré-cache sous-titres en background |
| `handlers/subtitle.php` | Extraction WebVTT avec cache SQLite |
| `handlers/keyframe.php` | Keyframe PTS lookup pour correction seek |
| `handlers/stream_native.php` | Streaming natif via X-Accel-Redirect |
| `handlers/stream_remux.php` | Remux MKV→MP4 (video copy + audio AAC) |
| `handlers/stream_transcode.php` | Transcode complet x264+AAC |
| `handlers/stream_hls.php` | HLS segmenté pour iOS Safari |
| `player.js` | Player vidéo JS (~1050 lignes), chargé via `<script src>` avec cache-busting mtime |
| `player.css` | CSS du player (~168 lignes), chargé via `<link>` avec cache-busting mtime |
| `ctrl.php` | API JSON admin : browse, create, delete, email |
| `index.php` | UI panel admin |
| `app.js` | JS panel admin |
| `monitoring.php` | API JSON monitoring : CPU, RAM, disque, réseau, températures, torrents actifs |
| `cron_net_speed.php` | Cron 1 min : échantillonne RX/TX et insère dans `net_speed`, purge >7 jours |

## Tables SQLite

```sql
probe_cache (path TEXT PK, mtime INTEGER, result TEXT)  -- cache ffprobe par path+mtime
subtitle_cache (path TEXT, track INTEGER, mtime INTEGER, vtt TEXT, PK(path,track))  -- cache WebVTT extrait
links (id, token, path, type, name, password_hash, expires_at, created_at, download_count)
net_speed (id INTEGER PK, ts INTEGER, rx_bytes INTEGER, tx_bytes INTEGER)  -- samples 1 min, purge 7 jours
```

SQLite configuré dans `db.php` à chaque connexion :
```php
PRAGMA journal_mode=WAL;    -- lectures concurrentes sans bloquer les écritures
PRAGMA busy_timeout=3000;   -- attente 3s si DB verrouillée (probes concurrents)
```

Purge probe_cache (bug corrigé) :
```sql
-- AVANT (bug) : supprimait les probes de fichiers dans un dossier partagé
DELETE FROM probe_cache WHERE path NOT IN (SELECT path FROM links)

-- APRÈS (fix) : matching exact pour fichiers, préfixe pour dossiers
DELETE FROM probe_cache WHERE NOT EXISTS (
    SELECT 1 FROM links
    WHERE probe_cache.path = links.path
       OR probe_cache.path LIKE rtrim(links.path, '/') || '/%'
)
```

## Streaming ffmpeg — 3 modes

La sélection du mode se fait côté JS via **probe-first** : JS fetch `?probe=1` avant de
démarrer le stream, `chooseModeFromProbe()` choisit remux (H.264) ou transcode (HEVC/AV1).

### Mode remux — H.264, zéro ré-encode vidéo

```
ffmpeg -ss <seek> -thread_queue_size 512 -fflags +genpts+discardcorrupt -i <file>
  -map 0:v:0 -map 0:a:N
  -c:v copy -c:a aac -ac 2 -b:a 192k
  -af "aresample=async=3000"
  -avoid_negative_ts make_zero -start_at_zero
  -max_muxing_queue_size 1024 -min_frag_duration 300000
  -movflags frag_keyframe+empty_moov+default_base_moof
  -f mp4 pipe:1
```

### Mode transcode — HEVC/AV1/incompatible

```
ffmpeg -ss <seek> -thread_queue_size 512 -fflags +genpts+discardcorrupt -i <file>
  -map 0:v:0 -map 0:a:N
  -c:v libx264 -preset ultrafast -crf 23 -g 50 -threads 4
  -vf "scale=-2:'min(<quality>,ih)'" -pix_fmt yuv420p
  -c:a aac -ac 2 -b:a 192k
  -af "aresample=async=3000:first_pts=0"
  -avoid_negative_ts make_zero -start_at_zero
  -max_muxing_queue_size 1024 -min_frag_duration 300000
  -movflags frag_keyframe+empty_moov+default_base_moof
  -f mp4 pipe:1
```

### Mode transcode + burn-in PGS/VOBSUB — `?burnSub=N`

```
ffmpeg -ss <seek> -thread_queue_size 512 -fflags +genpts+discardcorrupt -i <file>
  -filter_complex "[0:s:N][0:v]scale2ref[ss][sv];[sv][ss]overlay=eof_action=pass[ov];
                   [ov]scale=-2:'min(<quality>,ih)',format=yuv420p[v]"
  -map "[v]" -map 0:a:N
  -c:v libx264 -preset ultrafast -crf 23 -g 50 -threads 4
  -c:a aac -ac 2 -b:a 192k
  -af "aresample=async=3000:first_pts=0"
  -avoid_negative_ts make_zero -start_at_zero
  -max_muxing_queue_size 1024 -min_frag_duration 300000
  -movflags frag_keyframe+empty_moov+default_base_moof
  -f mp4 pipe:1
```

### Notes ffmpeg critiques

- `-thread_queue_size` est une **option d'entrée** (avant `-i`), pas de sortie
- `-threads 4` cap x264 : 2 transcodes simultanées se partagent les 8 cores sans contention
- **scale2ref** pour PGS : canvas PGS peut déclarer une résolution différente de la vidéo (ex. vidéo 1440×1080 SAR 4:3, PGS 1920×1080). `scale2ref` redimensionne le canvas PGS aux dimensions exactes de la vidéo → X/Y corrects. Sans ça, subs hors cadre ou décalés.
- **Pas de fine seek** (option après `-i`) : sur 4K HEVC, décode N sec avant le 1er frame → timeout navigateur. Seek coarse-only partout (keyframe, ±2 s d'imprécision acceptable).
- `min_frag_duration 300000` (300 ms) : compromis seek accuracy / overhead muxer
- vmtouch warm uniquement si filesize < 2 GB (sinon OOM sur gros fichiers RAID0)
- `aresample=async=3000` : tolère gaps PTS audio jusqu'à ~62 ms — nécessaire pour sources BluRay DTS/AC3
- `acquireStreamSlot()` limite les ffmpeg concurrents (`STREAM_MAX_CONCURRENT`, défaut 3)

## Player vidéo (JS dans download.php)

### Machine d'état stream

```
native → remux → transcode-480p / transcode-720p / transcode-1080p
```

`S.confirmed` mémorise le mode fonctionnel — pas de re-test sur les seeks.
`onFail` cascade : native/remux → transcode (remux peut échouer sur conteneur exotique).

### Probe-first startup

```js
// JS fetch ?probe=1 avant de démarrer
chooseModeFromProbe(d):
  h264 + isMP4 + audio aac/mp3 → 'native'  (navigateur lit directement)
  h264 + MKV ou audio incompatible (AC3/DTS) → 'remux'  ✓ testé, écart PTS < 100ms constant
  HEVC/AV1/autre → 'transcode'
  fallback 2s si probe lent → startStream() sans attendre
```

Probe JSON inclut `isMP4` (format_name contient mp4/mov). Entrées cache sans ce champ sont
invalidées automatiquement à la prochaine requête probe.

**Restart proactif post-fallback** : si le probe arrive dans les 5 s après le déclenchement du
fallback natif (`fallbackAt`) et que `chooseModeFromProbe` retourne un mode ≠ `native`, le stream
est redémarré immédiatement dans le bon mode — évite le cycle erreur → cascade transcode (~3-5 s
de latence supplémentaire sur cache froid avec fichiers HEVC).

### Stall watchdog exponentiel

```js
stallTimeout() = Math.min(base * Math.pow(2, S.stallCount), 120000)
// base : remux=10s | transcode=20s | burnSub=30s  — cap 2 min
```

`S.stallCount` est reset à 0 après 30 s de lecture stable ininterrompue (`stableTimer` lancé sur `playing`, annulé sur `waiting`/`pause`). Sans ce reset, après plusieurs stalls le watchdog attend 2 min avant chaque retry même quand le réseau est revenu.

### Module Subs (objet JS)

- `types[]` / `urls[]` / `cues[]` indexés par piste sous-titre
- `_find(t)` : binary search O(log n) pour le seek
- `_idx` : forward pointer O(1) amortized pendant lecture, reset sur seek
- Type `text` → extraction WebVTT + overlay JS (div `.sub-overlay`)
- Type `image` → burn-in : restart stream en transcode avec `burnSub=N`

### État global S

```js
S = {
  step, confirmed,           // machine d'état stream
  offset, duration,          // position courante et durée totale
  audioIdx, quality, burnSub,
  speed,
  dragging, seekPending, rafPending,
  hasFailed, stallCount,
  fsHideTimer, videoWidthTimer, seekDebounce, stallTimer
}
```

### Features player

- **rAF throttle** : `timeupdate` → flag `S.rafPending` → `requestAnimationFrame` pour tous les DOM writes
- **Raccourcis clavier** : Space/K play-pause, ←/→ ±10 s seek + OSD ⏪/⏩, ↑/↓ volume ±5%, F fullscreen, M mute, 0–9 jump à N×10%, `?` overlay aide — guard `e.ctrlKey || e.metaKey || e.altKey` pour ne pas intercepter les raccourcis browser (Ctrl+F, Cmd+F, etc.)
- **Overlay raccourcis** : touche `?` ou `Escape` — liste des raccourcis dans un overlay centré, construit en DOM pur (createElement/textContent, pas d'innerHTML)
- **Volume slider** : CSS custom property `--vol-pct` sur `-webkit-slider-runnable-track`, `style.setProperty('--vol-pct', pct+'%')` dans `updateVolUI()` — écriture localStorage debouncée 500 ms (slider et molette partagent le même `volSaveTimer`)
- **localStorage** : `player_volume`, `player_muted`, `player_speed`, `player_sub_<base><pp>` (piste sous-titre mémorisée par fichier) — restaurés à l'init
- **Seekbar tooltip** : div `.seek-tooltip`, timecode au survol
- **Resync button** : `startStream(realTime())` — recharge à la position courante
- **Badge mode courant** : affiche `NATIF` / `REMUX` / `TRANSCODE 720p` dans la barre de contrôle, cliquable pour forcer le mode manuellement
- **iOS fullscreen** : `isIOS` (UA detection), `player.webkitEnterFullscreen()` / `player.webkitExitFullscreen()` — `requestFullscreen` sur `<div>` non supporté sur Safari iOS ; listeners `webkitbeginfullscreen` / `webkitendfullscreen` sur `<video>` pour sync du bouton
- **Double-tap sans délai** : single tap → play/pause immédiat (pas de timer 250 ms) ; double tap (< 300 ms) → annule le premier tap + toggle fullscreen via `lastTapTime` timestamp
- **Vitesses lecture** : `[0.5, 0.75, 1, 1.5, 2]` — cycle via le bouton speed

## Décisions techniques clés

| Décision | Raison |
|---|---|
| Seek coarse-only (avant `-i`) | Fine seek décode N sec de 4K HEVC avant le 1er frame → timeout |
| `scale2ref` pour PGS | Canvas PGS peut avoir dims ≠ vidéo → subs hors cadre sans ça |
| `-threads 4` x264 | 2 transcodes simultanées partagent 8 cores sans contention |
| `min_frag_duration 300000` | 300 ms = bon compromis seek interactif / overhead muxer |
| vmtouch < 2 GB uniquement | Fichiers 4K provoquent OOM si warmés en entier |
| Audio 192k (vs 128k) | Meilleur downmix 5.1 → stéréo BluRay |
| `pm.max_children=25` | Workers FPM monopolisés par streams long-running (films 2h+) |
| Pas de VAAPI | GPU = Matrox G200EH BMC, pas d'iGPU Intel sur ce Xeon serveur |
| probe_cache purge LIKE | `NOT IN (links.path)` supprimait les probes de fichiers dans dossier partagé |
| Rate limiting password | `sleep(1)` + compteur session par token → protection brute-force sans dépendance externe |
| Probe fallback restart proactif | Si probe arrive < 5 s après fallback natif et mode ≠ native → restart immédiat au lieu d'attendre l'erreur cascade |
| Double-tap lastTapTime | Supprime le timer 250 ms de l'ancien pattern : play/pause immédiat, double-tap détecté par timestamp |
| PHPStan level 5 | Analyse statique en CI — détecte les types incorrects et dead code avant merge |
| CI matrix PHP 8.1/8.2/8.3 | Garantit la compatibilité sur les 3 versions actives simultanément |

## Optimisations système appliquées

- `/etc/sysctl.d/99-disk-perf.conf` : swappiness=10, dirty_ratio=15, vfs_cache_pressure=50
- `/etc/sysctl.d/99-seedbox.conf` : tcp_wmem/rmem max=128 MB, wmem_max=128 MB
- `/etc/udev/rules.d/60-rtorrent-readahead.rules` : read_ahead md5=128 KB, HDD=1024 KB (aligné chunk RAID0 512 KB×2)
- `/etc/udev/rules.d/61-hdd-queue-depth.rules` : nr_requests HDD=512 (meilleur tri mq-deadline)
- `/etc/systemd/system/rtorrent-ionice.service` : ionice **-c 2 -n 4** sur rtorrent (best-effort priority 4, pas idle)

## rtorrent tuning (`/home/ropixv2/.rtorrent.rc`)

| Paramètre | Valeur | Raison |
|---|---|---|
| `pieces.memory.max` | 1.5 GB | Évite double-cache avec page cache OS (≈13 GB) — ancienne valeur 12 GB provoquait pression RAM |
| `pieces.preload.type` | 2 (madvise) | Laisse le kernel optimiser l'accès aléatoire multi-torrents (vs linear=1) |
| `network.send_buffer.size` | 16 MB | Meilleur débit/peer sur 1 Gbps (vs 8 MB) |
| `network.max_open_files` | 4096 | 17K torrents actifs — plus de FD disponibles (vs 2048) |

Note : `pieces.memory.max` n'est **pas** rechargeable à chaud via SCGI — nécessite restart rtorrent.

## rtorrent hot reload via SCGI

```python
import socket, xmlrpc.client as x

def scgi_call(sock_path, method, *args):
    body = x.dumps(args, method).encode()
    headers = b'CONTENT_LENGTH\x00' + str(len(body)).encode() + b'\x00SCGI\x001\x00'
    ns = str(len(headers)).encode() + b':' + headers + b','
    s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    s.connect(sock_path)
    s.sendall(ns + body)
    resp = b''
    while True:
        chunk = s.recv(4096)
        if not chunk: break
        resp += chunk
    s.close()
    return x.loads(resp.split(b'\r\n\r\n', 1)[-1])[0][0]

sock = '/var/run/ropixv2/.rtorrent.sock'
# Lire : scgi_call(sock, 'pieces.memory.max', '')
# Écrire : scgi_call(sock, 'pieces.memory.max.set', '', 5*1024**3)
```

Nécessite d'être exécuté en `sudo` (ou utilisateur ropixv2) pour accéder au socket.

## Tests

```bash
composer install
vendor/bin/phpunit
```

CI GitHub Actions sur push/PR → badge dans README.
Matrix PHP 8.1/8.2/8.3 en parallèle + PHPStan level 5.
