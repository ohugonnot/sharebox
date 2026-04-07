# Seedbox Tuning Log

Historique des changements de configuration pour suivi et comparaison.

---

## 2026-04-05 — Migration rtorrent → qBittorrent + tuning complet

### Contexte
- Serveur : Seedhost dn40904, Debian, 48 CPU, 62 GB RAM, 10 Gbps
- Disques : 3x Toshiba MG06ACA 10TB HDD en RAID0 (md0) + 1x NVMe 1.8TB
- qBittorrent 4.5.2, libtorrent 2.x
- 59 torrents principalement contenu FR niche

### Problemes constates
- Upload oscillant (accelere/freine en boucle)
- qBittorrent RSS : **49 GB** (mmap libtorrent 2.x + DiskCacheSize 16GB)
- Seulement 14 connexions TCP sur 3000 max
- 52/59 torrents stalledUP
- DISKEFF : ~10-15x (thrashing HDD)
- Chiffrement force → moins de peers

### Changements qBittorrent

| Parametre | Avant | Apres | Raison |
|---|---|---|---|
| `encryption` | 1 (force) | 0 (prefer) | Plus de peers potentiels |
| `disk_cache` | 16384 MB | -1 (auto) | Liberee 40+ GB de RAM |
| `disk_cache_ttl` | 600s | 120s | Eviter cache stale |
| `disk_io_type` | 0 (mmap) | 1 (POSIX) | Fix RSS 49GB, mieux pour HDD |
| `disk_io_read_mode` | 1 (OS cache) | 1 | Inchange |
| `disk_io_write_mode` | 1 (OS cache) | 1 | Inchange |
| `async_io_threads` | 32 | 4 | 1 par disque, stop thrashing random I/O |
| `send_buffer_watermark` | 500 KB | 10240 KB | Adapte 10Gbps |
| `send_buffer_low_watermark` | 10 KB | 1024 KB | Plancher 1MB |
| `send_buffer_watermark_factor` | 200% | 250% | Plus agressif |
| `connection_speed` | 1000 | 500 | Moins de flood connexions |
| `socket_backlog_size` | 1024 | 4096 | Plus de connexions entrantes |
| `memory_working_set_limit` | 512 MB | 4096 MB | libtorrent peut travailler |
| `max_connec` | 3000 | 5000 | Plus de peers totaux |
| `max_connec_per_torrent` | 200 | 500 | Plus de peers par torrent |
| `max_uploads` | 500 | 2000 | 4x plus de slots upload |
| `max_uploads_per_torrent` | 50 | 150 | 3x plus par torrent |
| `upload_choking_algorithm` | 1 (fastest) | 0 (round-robin) | Distribue mieux l'upload |
| `utp_tcp_mixed_mode` | 0 (prefer TCP) | 1 (proportional) | uTP ne bride plus TCP |
| `peer_turnover` | 4% | 2% | Moins de churn |
| `peer_turnover_cutoff` | 90% | 95% | Garde peers plus longtemps |
| `peer_turnover_interval` | 300s | 120s | Rotation plus frequente |
| `upload_slots_behavior` | 0 (fixe) | 1 (rate-based) | Slots adaptatifs a la bande passante |
| `enable_embedded_tracker` | false | true (:9000) | Serveur = aussi tracker |
| `preallocate_all` | true | false | Moins d'I/O inutile HDD |
| `disk_queue_size` | 64 MB | 128 MB | Plus de buffer lecture |
| `enable_piece_extent_affinity` | false | true | Lectures sequentielles HDD |
| `peer_tos` | 4 | 128 (AF41) | QoS priorite reseau |

### Changements systeme

| Parametre | Avant | Apres | Raison |
|---|---|---|---|
| HDD `read_ahead_kb` | 4096 | 256 | Optimal pour seeding random I/O |
| md0 `read_ahead_kb` | 8192 | 256 | Idem |

Persiste dans `/etc/udev/rules.d/60-hdd-io-tuning.rules`.

### Changements reseau/firewall

| Action | Detail |
|---|---|
| UFW 8443/tcp | Reverse proxy HTTPS → qBittorrent WebUI |
| UFW 9000/tcp | Embedded tracker qBittorrent |
| nginx reverse proxy | `/etc/nginx/sites-available/qbittorrent` sur port 8443 |

### Crons ajoutes

| Cron | Frequence | Script | Role |
|---|---|---|---|
| kick-slow-peers | */5 min | `kick-slow-peers-qbt` | Ban peers <50KB/s et idle 0KB/s (30 min), reannonce trackers |
| warm-cache | */30 min | `warm-cache-qbt` | Pre-charge fichiers populaires <10GB en page cache RAM |

### Resultats

| Metrique | Avant | Apres |
|---|---|---|
| RAM qBittorrent | 49 GB | 5-20 GB |
| Connexions TCP | 14 | 70-100+ |
| Torrents actifs | 5-6 | 8-15 |
| Upload moyen | 9 MB/s | 15-35 MB/s |
| Upload pic | 24 MB/s | 63 MB/s |
| DISKEFF | ~10-15x | ~0.7-1.3x |
| Disk I/O utilisation | 54% | 18-26% |
| Oscillation vitesse | 0.1-24 MB/s (240x) | 2-7 MB/s (3.5x) |

### Settings systeme complets (snapshot)

```
tcp_congestion: bbr
rmem_max: 268435456 (256MB)
wmem_max: 268435456 (256MB)
somaxconn: 65535
netdev_max_backlog: 250000
tcp_fastopen: 3
tcp_notsent_lowat: 16384
tcp_slow_start_after_idle: 0
swappiness: 1
vfs_cache_pressure: 10
dirty_bytes: 4294967296 (4GB)
dirty_background_bytes: 1073741824 (1GB)
mtu: 1500
nic_speed: 10000 Mbps
hdd_read_ahead: 256 KB
hdd_scheduler: mq-deadline
hdd_nr_requests: 256
```

### Limites identifiees

- Contenu niche FR avec ratio seeds/leechers 10:1 a 100:1 → peu de demande
- 15/60 torrents jamais uploades (0 leechers)
- MTU 1500 (jumbo frames impossible chez Seedhost)
- La vitesse d'upload est limitee par la demande, pas par le serveur

---

## Template pour futur changement

```
## YYYY-MM-DD — Description

### Changement
| Parametre | Avant | Apres | Raison |

### Resultats
| Metrique | Avant | Apres |

### Notes
```
