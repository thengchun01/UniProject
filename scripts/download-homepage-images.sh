#!/usr/bin/env bash
# Download the homepage images into the local project.
# Run this script from the repository root using Git Bash.

set -euo pipefail

IMAGE_DIR="assets/images/homepage"
mkdir -p "$IMAGE_DIR"

download_image() {
    local filename="$1"
    local url="$2"
    local target="$IMAGE_DIR/$filename"

    printf 'Downloading %s...\n' "$filename"
    curl --fail --location --silent --show-error \
        --retry 3 --retry-delay 2 \
        --user-agent "Mozilla/5.0" \
        "$url" --output "$target"

    if [[ ! -s "$target" ]]; then
        printf 'ERROR: %s was downloaded as an empty file.\n' "$filename" >&2
        return 1
    fi

    printf 'Saved %s (%s bytes)\n' "$target" "$(wc -c < "$target" | tr -d ' ')"
}

# Keep the existing hero image.
download_image "hero-piano.jpg" \
    "https://images.unsplash.com/photo-1552422535-c45813c61732?fit=crop&fm=jpg&q=85&w=1600"

# Use the previous Tutorials image for the Piano card.
download_image "virtual-piano.jpg" \
    "https://images.unsplash.com/photo-1520523839897-bd0b52f945a0?fit=crop&fm=jpg&q=85&w=900"

# A real student learning piano with a teacher and sheet music.
download_image "tutorials.jpg" \
    "https://images.pexels.com/photos/7521177/pexels-photo-7521177.jpeg?cs=srgb&dl=pexels-pavel-danilyuk-7521177.jpg&fm=jpg"

# Sheet music for the Song Library card.
download_image "song-library.jpg" \
    "https://images.pexels.com/photos/7521296/pexels-photo-7521296.jpeg?cs=srgb&dl=pexels-pavel-danilyuk-7521296.jpg&fm=jpg"

# A real flat-lay arrangement of instruments for Learning Games.
download_image "learning-games.jpg" \
    "https://muzikercdn.com/uploads/sitemap_nodes/20846/2084666/d97d7fe9.jpg"

printf '\nAll homepage images downloaded successfully.\n'
