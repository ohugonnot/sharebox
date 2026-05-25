# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [5.0.0] - 2026-05-25

### Added
- **GPU hardware transcoding** — auto-detected, zero configuration
  - Intel VAAPI (NAS, mini-PC, Synology)
  - NVIDIA NVENC (desktop/laptop GPU via Docker)
  - Raspberry Pi V4L2M2M
  - Graceful fallback: if GPU fails, retries in software automatically
- **Continue Watching** — horizontal "Reprendre" row on browse page, shows last 8 videos with progress bars (localStorage-based)
- **Toast notifications** — inline toasts replace all browser `alert()` dialogs
- **Loading spinner** on file browser during navigation
- **E2E test suite** — 52 Playwright tests covering browse, posters, admin, player, ZIP, search, security
- **GitHub Actions E2E workflow** — Playwright tests run on every push/PR against Docker demo
- **Installation guides** — `docs/INSTALL.md` for Docker, Raspberry Pi, Synology, Unraid, bare metal
- **GPU documentation** — `docs/GPU.md` with setup guides for NVIDIA/Intel/Pi
- **NVIDIA Docker** — `Dockerfile.nvidia` + `docker-compose.nvidia.yml` for plug-and-play GPU testing
- Docker demo ships with `SHAREBOX_DEMO_DATA=true` by default (zero-config first launch)

### Fixed
- **Security (9 fixes):**
  - `ctrl.php`: path oracle via `mark_watched` — validate within BASE_PATH
  - `ctrl.php`: IDOR — non-admin can no longer delete system links
  - `admin.php`: `tmdb_scan_log` added to admin-only actions
  - `download.php`: atomic `max_downloads` check (race condition eliminated)
  - `stream_remux.php`: `escapeshellarg` on audio filter constant
  - `app.js`: XSS via `data.error` and search query — proper HTML escaping
  - `db.php`: default user role changed from 'admin' to 'user' (migration v20)
  - `header.php`: `htmlspecialchars` on `HTTP_HOST` (host header injection)
  - `docker/entrypoint.sh`: sanitize env vars before PHP config injection
- **Auth:** rate-limit file locking (flock) eliminates TOCTOU race condition
- **Streaming:** `CURLOPT_HEADER` set before `curl_exec` — TMDB Retry-After now works
- **Streaming:** HLS key hash includes `filemtime` (synced with handler)
- **Player:** seek while paused no longer restarts the stream
- **Player:** subtitle `_emptyRetries` reset on track switch
- **Player:** subtitle Blob URL revoked on destroy (memory leak fix)
- **Player:** native video confirm timeout 2s→4s (fewer false transcode cascades on slow networks)
- **Dashboard:** torrent poll interval uses correct open/closed accordion state
- **Dashboard:** null-safe pill updates (no crash if DOM element missing)
- **App:** `resp.ok` check before `.json()` on all fetch calls
- **CSS:** `--text-muted` contrast improved to WCAG AA (4.6:1)
- **CSS:** stray `CSS;` token removed from player.css

### Changed
- README: expanded comparison table (vs Plex, Jellyfin, Emby)
- README: SEO keywords for Google discoverability
- GitHub topics: +raspberry-pi, +nas, +emby-alternative, +homelab
- Demo link points to Films grid (12 posters visible immediately)

## [2.0.0] - 2026-03-18

### Added
- System monitoring dashboard in the admin panel
  - 4 metric cards: CPU (%), RAM, Disk (usage + I/O busy%), Network (MB/s)
  - Color-coded status pills visible even when accordion is collapsed
  - 7-day bandwidth history chart (Chart.js, gradient area fills, dark tooltips)
  - Active torrent list via rtorrent SCGI XML-RPC (≥ 50 KB/s filter, sorted by speed)
  - CPU & HDD temperature monitoring (coretemp + drivetemp kernel modules)
  - Adaptive polling: 10 s when expanded, 60 s pill-only when collapsed
  - Accordion and graph state persisted in `localStorage`
- `net_speed` SQLite table — 1-min cron samples, 7-day rolling purge
- 25 new PHPUnit tests (69 total): dashboard helpers, speed API, torrent SCGI parser
- CI matrix: PHP 8.1 / 8.2 / 8.3 tested in parallel
- PHPStan static analysis at level 5 in CI

## [1.9.3] - 2026-02-XX

### Added
- Player UX: overlay pause/play button, fullscreen cursor, stream mode badge (NATIF/REMUX/TRANSCODE)
- Clickable mode badge to force stream mode manually

## [1.9.2] - 2026-02-XX

### Fixed
- Stall watchdog: exponential backoff capped at 2 min (base × 2^n)

## [1.9.1] - 2026-02-XX

### Fixed
- Stall watchdog: differentiated base timeout per mode (remux 10 s, transcode 20 s, burn-in 30 s)

## [1.9.0] - 2026-02-XX

### Added
- Probe-first stream selection: ffprobe before playback, H.264 → remux (zero re-encode), HEVC/AV1 → transcode
- `chooseModeFromProbe()` with `isMP4` detection; probe cache entries without `isMP4` auto-invalidated

## [1.8.0] - 2026-01-XX

### Added
- Keyboard shortcuts: Space/K play-pause, ←/→ ±10 s, F fullscreen, M mute
- Volume slider with orange fill; volume, mute, and playback speed persisted in `localStorage`
- Seekbar hover tooltip with timecode preview
- rAF throttle on `timeupdate` — all seek-bar DOM writes via `requestAnimationFrame`
- Resync button: one-click A/V resync at current position

## [1.7.0] - 2026-01-XX

### Added
- Fullscreen overlay controls with auto-hide timer
- Tap gestures on mobile (tap to show/hide controls)
- Compact player bar below the video

## [1.6.0] - 2026-01-XX

### Added
- Subtitle track selection: text subtitles extracted to WebVTT (JS overlay), image subtitles burned in
- Binary search cue lookup (O(log n) on seek, O(1) amortized forward)
- Stream reliability improvements (stall watchdog, confirmed-mode state machine)

## [1.5.0] - 2025-12-XX

### Added
- ffprobe results cached in SQLite `probe_cache` table (path + mtime key)
- vmtouch page-cache warming for files < 2 GB
- Resync button (reloads stream at current position)
- A/V sync hardening: `aresample async=3000`, `-g 50`, `-thread_queue_size 512`

## [1.4.0] - 2025-12-XX

### Fixed
- iOS streaming: `Accept-Ranges`, `playsinline`, tap-to-play, probe timeout, race condition on load

## [1.3.0] - 2025-12-XX

### Added
- PGS/VOBSUB burn-in via `filter_complex` with `scale2ref` for correct positioning on anamorphic sources
- Adaptive quality levels: 480p, 720p, 1080p

## [1.2.0] - 2025-12-XX

### Added
- CSRF token protection on all admin POST actions
- Path traversal hardening with `realpath()` validation against `BASE_PATH`
- Mail header injection sanitisation
- ZIP download size cap (`MAX_ZIP_SIZE`)
- `session_regenerate_id(true)` after password authentication

## [1.1.0] - 2025-11-XX

### Added
- PHPUnit test suite: security, slug generation, file utilities, concurrency (44 tests)
- GitHub Actions CI with dynamic badge

## [1.0.0] - 2025-11-XX

### Added
- File and folder sharing with human-readable slug URLs
- Password protection (bcrypt) and link expiration
- Built-in video player with ffmpeg transcoding (remux H.264, transcode HEVC/AV1)
- Audio track selection, seek support
- QR code generation (pure JS)
- Email sharing
- ZIP folder download
- Dark theme admin panel
- nginx X-Accel-Redirect support
- SQLite database (auto-created)
