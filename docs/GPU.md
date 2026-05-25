# GPU Transcoding

ShareBox auto-detects GPU hardware and uses it for video transcoding when available. Hardware encoding cuts CPU usage by 80-90% on a typical transcode and lets a low-power host handle several simultaneous streams.

## Supported hardware

| GPU | Backend | Encoder | Docker flag |
|---|---|---|---|
| NVIDIA GTX/RTX | NVENC | h264_nvenc | `--gpus all` or `deploy.resources` |
| Intel iGPU / Arc | VAAPI | h264_vaapi | `--device /dev/dri:/dev/dri` |
| Raspberry Pi 4 | V4L2M2M | h264_v4l2m2m | `--device /dev/video10:/dev/video10` |

Detection order when `FFMPEG_HW_ACCEL=auto`: VAAPI → NVENC → V4L2M2M → software.

---

## NVIDIA (Windows or Linux)

### Prerequisites

**Windows (Docker Desktop + WSL2)**
- Docker Desktop installed with WSL2 backend enabled
- NVIDIA Game Ready or Studio drivers installed (no separate CUDA needed — Docker Desktop passes the host GPU through automatically)

**Linux**
- NVIDIA drivers: `sudo apt install nvidia-driver-XXX` (replace XXX with your driver version)
- nvidia-container-toolkit:
  ```bash
  sudo apt install nvidia-container-toolkit
  sudo systemctl restart docker
  ```

### Run

```bash
git clone https://github.com/ohugonnot/sharebox.git && cd sharebox
docker compose -f docker-compose.nvidia.yml up -d
```

Open `http://localhost:8080/dl/browse` — navigate to a video and click play.

### Verify GPU is in use

Check the startup log:
```bash
docker logs sharebox-sharebox-1 2>&1 | grep GPU
# Expected: "GPU: NVENC detected — hardware transcoding active"
```

While a video is streaming, confirm ffmpeg is using the GPU:
```bash
nvidia-smi
# Look for "ffmpeg" in the process list
```

---

## Intel VAAPI (Linux)

The standard `docker-compose.yml` already has the device line commented out. Uncomment it:

```yaml
services:
  sharebox:
    devices:
      - /dev/dri:/dev/dri
```

Verify detection inside the container:
```bash
docker exec <container> vainfo
docker exec <container> ffmpeg -encoders 2>/dev/null | grep vaapi
```

> VAAPI is Linux-only. It does not work on Docker Desktop for Windows/Mac.

---

## Raspberry Pi 4 (V4L2M2M)

V4L2M2M is auto-detected when the video devices are passed through. Add to `docker-compose.yml`:

```yaml
services:
  sharebox:
    devices:
      - /dev/video10:/dev/video10
      - /dev/video11:/dev/video11
      - /dev/video12:/dev/video12
```

---

## Configuration

Set `FFMPEG_HW_ACCEL` in `config.php` or as an environment variable:

```php
define('FFMPEG_HW_ACCEL', 'auto');  // 'auto' | 'vaapi' | 'nvenc' | 'v4l2m2m' | 'none'
```

Use `'none'` to force software encoding even when a GPU is available (useful for debugging).

---

## Troubleshooting

**"GPU: NVENC not detected" on startup**

Windows:
- Docker Desktop > Settings > Resources > WSL Integration — ensure your WSL distro is checked
- Confirm drivers are up to date: [nvidia.com/Download](https://www.nvidia.com/Download/index.aspx)

Linux:
- Check container toolkit: `sudo apt install nvidia-container-toolkit && sudo systemctl restart docker`
- Quick test: `docker run --rm --gpus all nvidia/cuda:12.6.0-base-ubuntu24.04 nvidia-smi`

**ffmpeg has NVENC in encoders list but transcoding still fails**

```bash
docker exec sharebox-sharebox-1 ffmpeg -encoders 2>/dev/null | grep nvenc
# Should show: V..... h264_nvenc
```

If listed but failing: the NVIDIA driver version inside WSL2 may be outdated. Update Windows NVIDIA drivers — they propagate to WSL2 automatically.

**Build fails with "nvidia/cuda image not found"**

The base image requires network access during `docker build`. If you're on an air-gapped machine, pull it first:
```bash
docker pull nvidia/cuda:12.6.0-runtime-ubuntu24.04
```
