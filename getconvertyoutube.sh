#!/bin/bash
# Downloads YouTube video via yt-dlp with optional FFmpeg conversion
# Args: $1=URL, $2=output_dir, $3=filename (no ext), $4=quality, $5="convert" or job_id, $6=job_id (if $5 is "convert")

# Load common environment config
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/config.sh"

URL="$1"
OUTPUT_DIR="$2"
FILENAME="$3"
QUALITY="$4"

# Determine if converting and get job_id
if [ "$5" == "convert" ]; then
    DO_CONVERT=true
    JOB_ID="$6"
else
    DO_CONVERT=false
    JOB_ID="$5"
fi

# Status file paths (in output dir, using filename as base)
STATUS_FILE="${OUTPUT_DIR}${FILENAME}.status"
PROGRESS_FILE="${OUTPUT_DIR}${FILENAME}.progress"
ERROR_FILE="${OUTPUT_DIR}${FILENAME}.error"
LOCK_FILE="${OUTPUT_DIR}${FILENAME}.lock"

# Update status
echo "downloading" > "$STATUS_FILE"

# Download with progress reporting
# yt-dlp progress template outputs percentage
yt-dlp "$URL" -f "${QUALITY}[ext=mp4][vcodec^=avc]+bestaudio[ext=m4a]/best[ext=mp4][vcodec^=avc]/best[ext=mp4]" \
    --output "/tmp/${FILENAME}.tmp" \
    --progress-template "download:%(progress._percent_str)s" \
    --newline 2>"$ERROR_FILE" | while read -r line; do
    # Extract percentage from progress output
    if [[ "$line" =~ ([0-9]+(\.[0-9]+)?%) ]]; then
        # Remove % and decimals, just get integer
        PCT="${BASH_REMATCH[1]}"
        PCT="${PCT%\%}"
        PCT="${PCT%.*}"
        echo "$PCT" > "$PROGRESS_FILE"
    fi
done

# Check if download succeeded
if [ ! -f "/tmp/${FILENAME}.tmp" ]; then
    echo "failed" > "$STATUS_FILE"
    exit 1
fi

# Clear any download errors since we succeeded
if [ -f "$ERROR_FILE" ] && [ ! -s "$ERROR_FILE" ]; then
    rm -f "$ERROR_FILE"
fi

if [ "$DO_CONVERT" = true ]; then
    echo "converting" > "$STATUS_FILE"
    echo "0" > "$PROGRESS_FILE"

    # Get duration for progress calculation
    DURATION=$(ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "/tmp/${FILENAME}.tmp" 2>/dev/null)

    # Convert with progress (settings for webOS compatibility + speed)
    ffmpeg -i "/tmp/${FILENAME}.tmp" -threads 0 -c:v libx264 -preset fast -crf 20 \
        -profile:v baseline -movflags +faststart \
        -c:a aac -b:a 128k \
        -progress pipe:1 "/tmp/${FILENAME}.mp4" 2>>"$ERROR_FILE" | while read -r line; do
        if [[ "$line" =~ ^out_time_ms=([0-9]+) ]]; then
            TIME_MS="${BASH_REMATCH[1]}"
            if [ -n "$DURATION" ] && [ "$DURATION" != "N/A" ]; then
                DURATION_MS=$(echo "$DURATION * 1000000" | bc 2>/dev/null || echo "0")
                if [ "$DURATION_MS" != "0" ]; then
                    PCT=$(echo "scale=0; $TIME_MS * 100 / $DURATION_MS" | bc 2>/dev/null || echo "0")
                    echo "$PCT" > "$PROGRESS_FILE"
                fi
            fi
        fi
    done

    rm -f "/tmp/${FILENAME}.tmp"

    if [ ! -f "/tmp/${FILENAME}.mp4" ]; then
        echo "failed" > "$STATUS_FILE"
        exit 1
    fi

    mv "/tmp/${FILENAME}.mp4" "$OUTPUT_DIR"
else
    mv "/tmp/${FILENAME}.tmp" "${OUTPUT_DIR}${FILENAME}.mp4"
fi

# Success - update status and clean up
echo "ready" > "$STATUS_FILE"
rm -f "$LOCK_FILE" "$PROGRESS_FILE"

# Clear error file if empty or conversion succeeded
if [ -f "$ERROR_FILE" ] && [ ! -s "$ERROR_FILE" ]; then
    rm -f "$ERROR_FILE"
fi
