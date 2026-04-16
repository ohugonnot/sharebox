#!/bin/sh
# Generate rich demo media for ShareBox.
# Called by entrypoint.sh when SHAREBOX_DEMO_DATA=true.
# Creates ~10s clips with colour video, 2 audio tracks (EN/FR), and embedded SRT subtitles.
set -e

MEDIA_DIR="${1:-/media}"
# Strip trailing slash to avoid double-slash paths
MEDIA_DIR="${MEDIA_DIR%/}"

# Skip if media dir already has real content (not just Alpine's default cdrom/floppy/usb)
REAL_CONTENT=$(find "$MEDIA_DIR" -mindepth 1 -maxdepth 1 \
    -not -name 'cdrom' -not -name 'floppy' -not -name 'usb' | head -1)
if [ -n "$REAL_CONTENT" ]; then
    echo "Demo-data: $MEDIA_DIR already has content, skipping."
    exit 0
fi
# Clean Alpine defaults
rm -rf "$MEDIA_DIR/cdrom" "$MEDIA_DIR/floppy" "$MEDIA_DIR/usb" 2>/dev/null || true

echo "Demo-data: creating rich sample media..."

# ── Build a reusable 10s demo clip with 2 audio tracks + 2 subtitle tracks ──
# Video: colour bars + title overlay
# Audio 1 (eng): sine 440 Hz  Audio 2 (fre): sine 660 Hz
# Sub 1 (eng): English demo   Sub 2 (fre): French demo

make_srt_en() {
    cat > /tmp/sub_en.srt <<'SRT'
1
00:00:01,000 --> 00:00:04,000
[English] Welcome to ShareBox Demo

2
00:00:05,000 --> 00:00:08,000
[English] This is a sample subtitle track

3
00:00:08,500 --> 00:00:10,000
[English] Enjoy the demo!
SRT
}

make_srt_fr() {
    cat > /tmp/sub_fr.srt <<'SRT'
1
00:00:01,000 --> 00:00:04,000
[Francais] Bienvenue sur ShareBox Demo

2
00:00:05,000 --> 00:00:08,000
[Francais] Ceci est une piste de sous-titres

3
00:00:08,500 --> 00:00:10,000
[Francais] Bonne demo !
SRT
}

make_srt_en
make_srt_fr

# Generate the base clip (once, then copy for all files)
# - testsrc2 gives a professional colour-bar pattern with a running timer
# - Two distinct sine tones so the user can hear the audio track switch
# - Two SRT subtitle files muxed in
CLIP="/tmp/demo_clip.mkv"
ffmpeg -y \
    -f lavfi -i "testsrc2=s=1280x720:d=10:r=24" \
    -f lavfi -i "sine=f=440:d=10:sample_rate=48000" \
    -f lavfi -i "sine=f=660:d=10:sample_rate=48000" \
    -i /tmp/sub_en.srt \
    -i /tmp/sub_fr.srt \
    -map 0:v -map 1:a -map 2:a -map 3 -map 4 \
    -c:v libx264 -preset ultrafast -crf 28 -g 48 -pix_fmt yuv420p \
    -c:a aac -b:a 64k -ac 2 \
    -c:s srt \
    -metadata:s:a:0 language=eng -metadata:s:a:0 title="English" \
    -metadata:s:a:1 language=fre -metadata:s:a:1 title="Francais" \
    -metadata:s:s:0 language=eng -metadata:s:s:0 title="English" \
    -metadata:s:s:1 language=fre -metadata:s:s:1 title="Francais" \
    "$CLIP" 2>/dev/null

CLIP_SIZE=$(wc -c < "$CLIP")
echo "Demo-data: base clip created (${CLIP_SIZE} bytes)"

# Helper: create episode files (copies of the demo clip)
make_episodes() {
    dir="$1"; prefix="$2"; start="$3"; count="$4"
    mkdir -p "$dir"
    i=$start
    end=$((start + count))
    while [ $i -lt $end ]; do
        ep=$(printf "%02d" $i)
        cp "$CLIP" "$dir/${prefix}${ep}.mkv"
        i=$((i + 1))
    done
}

# ── Series ──
make_episodes "$MEDIA_DIR/Series/Breaking Bad/Season 1"    "Breaking.Bad.S01E" 1 7
make_episodes "$MEDIA_DIR/Series/Breaking Bad/Season 2"    "Breaking.Bad.S02E" 1 4
make_episodes "$MEDIA_DIR/Series/Game of Thrones/Season 1" "Game.of.Thrones.S01E" 1 5
make_episodes "$MEDIA_DIR/Series/Game of Thrones/Season 2" "Game.of.Thrones.S02E" 1 3
make_episodes "$MEDIA_DIR/Series/Stranger Things/Season 1" "Stranger.Things.S01E" 1 4
make_episodes "$MEDIA_DIR/Series/Stranger Things/Season 2" "Stranger.Things.S02E" 1 3
make_episodes "$MEDIA_DIR/Series/The Mandalorian/Season 1" "The.Mandalorian.S01E" 1 3
make_episodes "$MEDIA_DIR/Series/The Mandalorian/Season 2" "The.Mandalorian.S02E" 1 3

# ── Films ──
mkdir -p "$MEDIA_DIR/Films"
for f in \
    "Inception.2010.1080p.BluRay.x264.mkv" \
    "The.Dark.Knight.2008.MULTI.1080p.mkv" \
    "Interstellar.2014.FRENCH.BDRip.mkv" \
    "Pulp.Fiction.1994.REMASTERED.720p.mkv" \
    "The.Matrix.1999.UHD.2160p.x265.mkv" \
    "Parasite.2019.KOREAN.VOSTFR.BluRay.mkv" \
    "Fight.Club.1999.MULTI.1080p.DTS.mkv" \
    "Gladiator.2000.FRENCH.720p.x264.mkv" \
    "The.Shawshank.Redemption.1994.1080p.mkv" \
    "Spirited.Away.2001.JAPANESE.BluRay.mkv" \
    "Blade.Runner.2049.2017.MULTI.2160p.HDR.mkv" \
    "Dune.2021.IMAX.1080p.WEB-DL.mkv"
do
    cp "$CLIP" "$MEDIA_DIR/Films/$f"
done

# ── Anime ──
make_episodes "$MEDIA_DIR/Anime/Attack on Titan/Season 1" "Attack.on.Titan.S01E" 1 4
make_episodes "$MEDIA_DIR/Anime/Attack on Titan/Season 2" "Attack.on.Titan.S02E" 1 3
make_episodes "$MEDIA_DIR/Anime/Death Note/Season 1"      "Death.Note.S01E" 1 4
make_episodes "$MEDIA_DIR/Anime/One Piece/Season 1"        "One.Piece.S01E" 1 5

# Clean up temp files
rm -f "$CLIP" /tmp/sub_en.srt /tmp/sub_fr.srt

# Fix permissions
chown -R www-data:www-data "$MEDIA_DIR" 2>/dev/null || true

echo "Demo-data: done. Rich clips with 2 audio + 2 subtitle tracks in $MEDIA_DIR"
