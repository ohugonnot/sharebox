# ShareBox — Notes pour Claude

## Contexte serveur

- Debian, nginx + PHP-FPM 8.2, SQLite, ffmpeg
- rtorrent en seeding lourd (utilisateur `ropixv2`) sur RAID0 (md5, 4x HDD)
- SCGI socket rtorrent : `/var/run/ropixv2/.rtorrent.sock`
- OPcache actif (`validate_timestamps=On`, `revalidate_freq=2s`) → après un edit, faire `systemctl reload php8.2-fpm` si besoin immédiat
- www-data est dans le groupe ropixv2 (accès aux fichiers en lecture)

## Architecture fichiers clés

| Fichier | Rôle |
|---|---|
| `config.php` | Constantes : `BASE_PATH`, `DB_PATH`, `XACCEL_PREFIX`, `DL_BASE_URL`, `STREAM_MAX_CONCURRENT` |
| `db.php` | Connexion PDO SQLite, création tables `links` + `probe_cache`, migration `password_plain` |
| `functions.php` | Utilitaires purs : `format_taille`, `get_stream_mime`, `get_media_type`, `generate_slug`, `dir_size`, `is_path_within`, `acquireStreamSlot`/`releaseStreamSlot` |
| `download.php` | Handler public : probe ffprobe (avec cache SQLite), streaming ffmpeg, player HTML/JS embarqué |
| `ctrl.php` | API JSON admin : browse, create, delete, email |
| `index.php` | UI panel admin |
| `app.js` | JS panel admin |

## Tables SQLite

```sql
probe_cache (path TEXT PK, mtime INTEGER, result TEXT)  -- cache ffprobe par path+mtime
links (id, token, path, type, name, password_hash, password_plain, expires_at, created_at, download_count)
```

## Streaming ffmpeg

Deux modes dans `download.php` :

**Remux** (codec vidéo déjà compatible, ex. H.264) :
```
ffmpeg -thread_queue_size 512 -fflags +genpts+discardcorrupt -i <file>
  -c:v copy -c:a aac -ac 2 -b:a 128k
  -af "aresample=async=2000:first_pts=0"
  -avoid_negative_ts make_zero -start_at_zero
  -max_muxing_queue_size 1024 -min_frag_duration 2000000
  -movflags frag_keyframe+empty_moov+default_base_moof
  -f mp4 pipe:1
```

**Transcode** (HEVC/x265/incompatible) :
```
ffmpeg -thread_queue_size 512 -fflags +genpts+discardcorrupt -i <file>
  -c:v libx264 -preset ultrafast -crf 23 -g 50
  -vf "scale=-2:'min(<quality>,ih)'" -pix_fmt yuv420p
  -c:a aac -ac 2 -b:a 128k
  -af "aresample=async=2000:first_pts=0"
  -avoid_negative_ts make_zero -start_at_zero
  -max_muxing_queue_size 1024 -min_frag_duration 2000000
  -movflags frag_keyframe+empty_moov+default_base_moof
  -f mp4 pipe:1
```

- `-thread_queue_size` est une **option d'entrée** (avant `-i`), pas de sortie
- vmtouch warm uniquement si filesize < 2 GB (sinon OOM sur gros fichiers)
- `acquireStreamSlot()` limite les ffmpeg concurrents (`STREAM_MAX_CONCURRENT`, défaut 3)

## Optimisations système appliquées

- `/etc/sysctl.d/99-disk-perf.conf` : swappiness=10, dirty_ratio=15, vfs_cache_pressure=50
- `/etc/udev/rules.d/60-disk-perf.rules` : read_ahead md5=16384, HDD=4096
- `/etc/systemd/system/rtorrent-ionice.service` : ionice -c 3 sur rtorrent (idle I/O)

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
        chunk = s.recv(4096);
        if not chunk: break
        resp += chunk
    s.close()
    return x.loads(resp.split(b'\r\n\r\n', 1)[-1])[0][0]

sock = '/var/run/ropixv2/.rtorrent.sock'
# Lire : scgi_call(sock, 'pieces.memory.max', '')
# Écrire : scgi_call(sock, 'pieces.memory.max.set', '', 5*1024**3)
```

Nécessite d'être exécuté en `sudo` (ou utilisateur ropixv2) pour accéder au socket.

## Player vidéo (JS dans download.php)

- Bouton **Resync** : appelle `startStream(realTime())` — recharge le flux à la position courante
- Bouton toujours dans `trackBar` (même si probe échoue), ajouté dans `setupAndStart()`
- Modes : `native` → `remux` → `transcode-480p/720p/1080p`
- `confirmedStep` mémorise le mode qui a fonctionné pour éviter de re-tester

## Tests

```bash
composer install
vendor/bin/phpunit
```

CI GitHub Actions sur push/PR → badge dans README.
