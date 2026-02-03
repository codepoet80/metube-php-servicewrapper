#!/bin/bash
# Downloads and converts Reddit video via FFmpeg
# Args: $1=HLS_URL, $2=output_dir, $3=filename (no ext), $4=job_id

# Load common environment config
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/config.sh"

URL="$1"
OUTPUT_DIR="$2"
FILENAME="$3"
JOB_ID="$4"

# Status file paths (in output dir, using filename as base)
STATUS_FILE="${OUTPUT_DIR}${FILENAME}.status"
PROGRESS_FILE="${OUTPUT_DIR}${FILENAME}.progress"
ERROR_FILE="${OUTPUT_DIR}${FILENAME}.error"
LOCK_FILE="${OUTPUT_DIR}${FILENAME}.lock"
SAVEPATH="${OUTPUT_DIR}${FILENAME}.mp4"

# Update status
echo "converting" > "$STATUS_FILE"
echo "0" > "$PROGRESS_FILE"

# Get duration for progress calculation (may not work for all HLS streams)
DURATION=$(ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "$URL" 2>/dev/null)

# Convert with progress (settings for webOS compatibility + speed)
ffmpeg -i "$URL" -threads 0 -c:v libx264 -preset fast -crf 20 \
    -profile:v baseline -movflags +faststart \
    -c:a aac -b:a 128k \
    -progress pipe:1 "$SAVEPATH" 2>"$ERROR_FILE" | while read -r line; do
    if [[ "$line" =~ ^out_time_ms=([0-9]+) ]]; then
        TIME_MS="${BASH_REMATCH[1]}"
        if [ -n "$DURATION" ] && [ "$DURATION" != "N/A" ] && [ "$DURATION" != "0" ]; then
            DURATION_MS=$(echo "$DURATION * 1000000" | bc 2>/dev/null || echo "0")
            if [ "$DURATION_MS" != "0" ]; then
                PCT=$(echo "scale=0; $TIME_MS * 100 / $DURATION_MS" | bc 2>/dev/null || echo "0")
                echo "$PCT" > "$PROGRESS_FILE"
            fi
        fi
    fi
done

# Check if conversion succeeded
if [ ! -f "$SAVEPATH" ]; then
    echo "failed" > "$STATUS_FILE"
    exit 1
fi

# Success - update status and clean up
echo "ready" > "$STATUS_FILE"
rm -f "$LOCK_FILE" "$PROGRESS_FILE"

# Clear error file if empty
if [ -f "$ERROR_FILE" ] && [ ! -s "$ERROR_FILE" ]; then
    rm -f "$ERROR_FILE"
fi
