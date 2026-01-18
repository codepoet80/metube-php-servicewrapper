#!/bin/bash
# Common environment configuration for metube shell scripts
# Ensures binaries are available when invoked via web server

# Add paths for yt-dlp, deno, ffmpeg, etc.
export PATH="/Users/jonwise/.deno/bin:/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin:$PATH"

# Uncomment to debug path issues
# echo "PATH: $PATH" >> /tmp/metube-debug.log
# which yt-dlp >> /tmp/metube-debug.log 2>&1
# which deno >> /tmp/metube-debug.log 2>&1
# which ffmpeg >> /tmp/metube-debug.log 2>&1
