# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
