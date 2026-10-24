#!/bin/bash
# Downloads and converts Reddit video via FFmpeg
# Args: $1=video URL, $2=audio URL (empty if none), $3=output_dir, $4=filename (no ext), $5=job_id
# URLs point directly at Reddit's progressive media files, not HLS playlists -
# ffmpeg's HLS demuxer mishandles this CDN's BYTERANGE segments.

# Load common environment config
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/config.sh"

# Max playable height (TouchPad ~768px); downscale only, never up
MAX_COMPATIBLE_HEIGHT=768
SCALE_FILTER="scale=-2:'min(ih,${MAX_COMPATIBLE_HEIGHT})'"

VIDEO_URL="$1"
AUDIO_URL="$2"
OUTPUT_DIR="$3"
FILENAME="$4"
JOB_ID="$5"

# Status file paths (in output dir, using filename as base)
STATUS_FILE="${OUTPUT_DIR}${FILENAME}.status"
PROGRESS_FILE="${OUTPUT_DIR}${FILENAME}.progress"
ERROR_FILE="${OUTPUT_DIR}${FILENAME}.error"
LOCK_FILE="${OUTPUT_DIR}${FILENAME}.lock"
SAVEPATH="${OUTPUT_DIR}${FILENAME}.mp4"

# Update status
echo "converting" > "$STATUS_FILE"
echo "0" > "$PROGRESS_FILE"

# Get duration for progress calculation
DURATION=$(ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "$VIDEO_URL" 2>/dev/null)

if [ -n "$AUDIO_URL" ]; then
    INPUT_ARGS=(-i "$VIDEO_URL" -i "$AUDIO_URL")
    MAP_ARGS=(-map 0:v:0 -map 1:a:0 -c:a aac -b:a 128k -ac 2 -ar 44100)
else
    INPUT_ARGS=(-i "$VIDEO_URL")
    MAP_ARGS=()
fi

# Convert with progress (settings for webOS compatibility + speed)
ffmpeg "${INPUT_ARGS[@]}" -threads 0 -c:v libx264 -preset fast -crf 20 \
    -profile:v baseline -level 3.1 -pix_fmt yuv420p \
    -maxrate 3500k -bufsize 6000k -g 60 \
    -vf "$SCALE_FILTER" -movflags +faststart \
    "${MAP_ARGS[@]}" \
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
