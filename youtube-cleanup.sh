#!/bin/bash
# Cleanup script for metube-php-servicewrapper
# Removes old video files, status files, and job tracking files
# Run via cron, e.g.: */15 * * * * /var/www/metube/youtube-cleanup.sh
# Pass -clear (or --clear) to remove everything immediately, regardless of age.

# Load common environment config (VIDEO_DIR, JOBS_DIR)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/config.sh"

# Enable nullglob so globs that match nothing expand to nothing (not literal)
shopt -s nullglob

AGE_FILTER=(-amin +30)
if [ "$1" == "-clear" ] || [ "$1" == "--clear" ]; then
    echo "Clearing all files in $VIDEO_DIR and $JOBS_DIR, regardless of age..."
    AGE_FILTER=()
fi

# Remove video and status files (older than 30 minutes, unless -clear was passed)
find "$VIDEO_DIR" -maxdepth 1 -name "*.mp4" "${AGE_FILTER[@]}" -exec rm -f {} \; >/dev/null 2>&1
find "$VIDEO_DIR" -maxdepth 1 -name "*.lock" "${AGE_FILTER[@]}" -exec rm -f {} \; >/dev/null 2>&1
find "$VIDEO_DIR" -maxdepth 1 -name "*.status" "${AGE_FILTER[@]}" -exec rm -f {} \; >/dev/null 2>&1
find "$VIDEO_DIR" -maxdepth 1 -name "*.progress" "${AGE_FILTER[@]}" -exec rm -f {} \; >/dev/null 2>&1
find "$VIDEO_DIR" -maxdepth 1 -name "*.error" "${AGE_FILTER[@]}" -exec rm -f {} \; >/dev/null 2>&1

# Remove orphaned status files (status files without corresponding video or lock file)
for status_file in "$VIDEO_DIR"/*.status; do
    [ -f "$status_file" ] || continue
    base_name=$(basename "$status_file" .status)
    video_file="$VIDEO_DIR/${base_name}.mp4"
    lock_file="$VIDEO_DIR/${base_name}.lock"
    # If no video and no lock, remove all associated files
    if [ ! -f "$video_file" ] && [ ! -f "$lock_file" ]; then
        rm -f "$VIDEO_DIR/${base_name}".{status,progress,error,lock} >/dev/null 2>&1
    fi
done

# Remove job tracking files (older than 30 minutes, unless -clear was passed)
if [ -d "$JOBS_DIR" ]; then
    find "$JOBS_DIR" -maxdepth 1 -name "job_*.json" "${AGE_FILTER[@]}" -exec rm -f {} \; >/dev/null 2>&1
fi
