#!/bin/bash
# Cleanup script for metube-php-servicewrapper
# Removes old video files, status files, and job tracking files
# Run via cron, e.g.: */15 * * * * /var/www/metube/youtube-cleanup.sh

# Load common environment config (VIDEO_DIR, JOBS_DIR)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/config.sh"

# Enable nullglob so globs that match nothing expand to nothing (not literal)
shopt -s nullglob

# Remove video and status files older than 30 minutes
find "$VIDEO_DIR" -maxdepth 1 -name "*.mp4" -amin +30 -exec rm -f {} \; >/dev/null 2>&1
find "$VIDEO_DIR" -maxdepth 1 -name "*.lock" -amin +30 -exec rm -f {} \; >/dev/null 2>&1
find "$VIDEO_DIR" -maxdepth 1 -name "*.status" -amin +30 -exec rm -f {} \; >/dev/null 2>&1
find "$VIDEO_DIR" -maxdepth 1 -name "*.progress" -amin +30 -exec rm -f {} \; >/dev/null 2>&1
find "$VIDEO_DIR" -maxdepth 1 -name "*.error" -amin +30 -exec rm -f {} \; >/dev/null 2>&1

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

# Remove job tracking files older than 30 minutes
if [ -d "$JOBS_DIR" ]; then
    find "$JOBS_DIR" -maxdepth 1 -name "job_*.json" -amin +30 -exec rm -f {} \; >/dev/null 2>&1
fi
