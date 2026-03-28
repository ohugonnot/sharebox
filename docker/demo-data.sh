#!/bin/sh
# Generate fake media structure for the ShareBox demo.
# Called by entrypoint.sh when SHAREBOX_DEMO_DATA=true.
# Creates tiny placeholder videos (~2KB each) with realistic filenames.
set -e

MEDIA_DIR="${1:-/media}"

# Skip if media dir already has content (real media mounted)
EXISTING=$(find "$MEDIA_DIR" -maxdepth 1 -not -name '.' -not -name '..' | head -1)
if [ -n "$EXISTING" ]; then
    echo "Demo-data: /media/ already has content, skipping."
    exit 0
fi

echo "Demo-data: creating sample media structure..."

# Create a single tiny placeholder video (1s black, ~2KB)
PLACEHOLDER="/tmp/placeholder.mkv"
ffmpeg -f lavfi -i color=black:s=320x240:d=1 -c:v libx264 -preset ultrafast -crf 51 -y "$PLACEHOLDER" 2>/dev/null

# Helper: create episode files
# Usage: make_episodes <dir> <prefix> <start> <count>
make_episodes() {
    dir="$1"; prefix="$2"; start="$3"; count="$4"
    mkdir -p "$dir"
    i=$start
    end=$((start + count))
    while [ $i -lt $end ]; do
        ep=$(printf "%02d" $i)
        cp "$PLACEHOLDER" "$dir/${prefix}${ep}.mkv"
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

# ── Films (individual files, folder tagged as movies) ──
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
    cp "$PLACEHOLDER" "$MEDIA_DIR/Films/$f"
done

# ── Anime ──
make_episodes "$MEDIA_DIR/Anime/Attack on Titan/Season 1" "Attack.on.Titan.S01E" 1 4
make_episodes "$MEDIA_DIR/Anime/Attack on Titan/Season 2" "Attack.on.Titan.S02E" 1 3
make_episodes "$MEDIA_DIR/Anime/Death Note/Season 1"      "Death.Note.S01E" 1 4
make_episodes "$MEDIA_DIR/Anime/One Piece/Season 1"        "One.Piece.S01E" 1 5

# Clean up placeholder
rm -f "$PLACEHOLDER"

# Fix permissions
chown -R www-data:www-data "$MEDIA_DIR" 2>/dev/null || true

# Tag Films folder as movies in DB
php -r '
    require "/app/db.php";
    $db = get_db();
    $path = realpath("'"$MEDIA_DIR"'/Films");
    if ($path) {
        $db->prepare("INSERT INTO folder_posters (path, folder_type) VALUES (:p, :t)
                      ON CONFLICT(path) DO UPDATE SET folder_type = :t")
           ->execute([":p" => $path, ":t" => "movies"]);
        echo "Demo-data: tagged Films/ as movies\n";
    }
' 2>/dev/null || true

echo "Demo-data: done. Structure created in $MEDIA_DIR"
