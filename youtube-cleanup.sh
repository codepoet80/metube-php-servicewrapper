#!/bin/bash
# Cleanup script for metube-php-servicewrapper
# Removes old video files, status files, and job tracking files
# Run via cron, e.g.: */15 * * * * /var/www/metube/youtube-cleanup.sh

# IMPORTANT: Update this path to match your file_dir configuration
VIDEO_DIR="/home/pi/youtube-dl"
JOBS_DIR="/tmp/metube-jobs"

# Remove video files older than 30 minutes
find "$VIDEO_DIR"/*.mp4 -amin +30 -exec rm -f {} \; 2>/dev/null

# Remove associated status files older than 30 minutes
find "$VIDEO_DIR"/*.lock -amin +30 -exec rm -f {} \; 2>/dev/null
find "$VIDEO_DIR"/*.status -amin +30 -exec rm -f {} \; 2>/dev/null
find "$VIDEO_DIR"/*.progress -amin +30 -exec rm -f {} \; 2>/dev/null
find "$VIDEO_DIR"/*.error -amin +30 -exec rm -f {} \; 2>/dev/null

# Remove orphaned status files (status files without corresponding video or lock file)
for status_file in "$VIDEO_DIR"/*.status; do
    if [ -f "$status_file" ]; then
        base_name=$(basename "$status_file" .status)
        video_file="$VIDEO_DIR/${base_name}.mp4"
        lock_file="$VIDEO_DIR/${base_name}.lock"
        # If no video and no lock, remove all associated files
        if [ ! -f "$video_file" ] && [ ! -f "$lock_file" ]; then
            rm -f "$VIDEO_DIR/${base_name}".{status,progress,error,lock} 2>/dev/null
        fi
    fi
done

# Remove job tracking files older than 30 minutes
if [ -d "$JOBS_DIR" ]; then
    find "$JOBS_DIR"/job_*.json -amin +30 -exec rm -f {} \; 2>/dev/null
fi
