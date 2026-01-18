#!/bin/bash
# Common environment configuration for metube shell scripts
# Copy this file to config.sh and customize paths for your system

# ============================================================================
# Directory paths - must match your config.php settings
# ============================================================================

# Video download directory (same as file_dir in config.php)
export VIDEO_DIR="/path/to/your/video/directory"

# Job tracking directory
export JOBS_DIR="/tmp/metube-jobs"

# ============================================================================
# Binary paths - needed when scripts run via web server
# ============================================================================

# The web server runs with a minimal PATH, so binaries like yt-dlp, deno,
# and ffmpeg may not be found. Add their paths here.
# Find paths on your system with: which yt-dlp && which deno && which ffmpeg

export PATH="/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin:$HOME/.deno/bin:$PATH"

# ============================================================================
# Debug options - uncomment to troubleshoot (check /tmp/metube-debug.log)
# ============================================================================

# echo "PATH: $PATH" >> /tmp/metube-debug.log
# which yt-dlp >> /tmp/metube-debug.log 2>&1
# which deno >> /tmp/metube-debug.log 2>&1
# which ffmpeg >> /tmp/metube-debug.log 2>&1
