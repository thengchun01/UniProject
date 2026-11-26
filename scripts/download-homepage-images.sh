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

download_image "hero-piano.jpg" \
    "https://images.unsplash.com/photo-1552422535-c45813c61732?fit=crop&fm=jpg&q=85&w=1600"
download_image "tutorials.jpg" \
    "https://images.unsplash.com/photo-1520523839897-bd0b52f945a0?fit=crop&fm=jpg&q=85&w=700"
download_image "song-library.jpg" \
    "https://images.unsplash.com/photo-1684086974736-85b4bce4345a?fit=crop&fm=jpg&q=85&w=700"
download_image "virtual-piano.jpg" \
    "https://images.unsplash.com/photo-1550684848-fac1c5b4e853?fit=crop&fm=jpg&q=85&w=700"
download_image "learning-games.jpg" \
    "https://images.unsplash.com/photo-1611339555312-e607c8352fd7?fit=crop&fm=jpg&q=85&w=700"

printf '\nAll homepage images downloaded successfully.\n'
