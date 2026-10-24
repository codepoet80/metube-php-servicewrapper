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

# Max playable height (TouchPad ~768px)
MAX_COMPATIBLE_HEIGHT=768

# Downscale only (never up) to the height cap above
SCALE_FILTER="scale=-2:'min(ih,${MAX_COMPATIBLE_HEIGHT})'"

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
yt-dlp "$URL" -f "${QUALITY}[ext=mp4]+bestaudio[ext=m4a]/${QUALITY}+bestaudio/best" \
    -S "vcodec:h264" \
    --merge-output-format mp4 \
    --output "/tmp/${FILENAME}.%(ext)s" \
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

# --merge-output-format can rename the output, so find it rather than assume ".tmp"
DL_FILE=$(ls "/tmp/${FILENAME}".* 2>/dev/null | grep -v '\.part$' | head -n1)

# Check if download succeeded
if [ -z "$DL_FILE" ] || [ ! -f "$DL_FILE" ]; then
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
    DURATION=$(ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "$DL_FILE" 2>/dev/null)

    # Convert with progress (settings for webOS compatibility + speed)
    # Distinct name - $DL_FILE may already be "*.mp4" itself
    CONVERTED_FILE="/tmp/${FILENAME}.converted.mp4"
    ffmpeg -i "$DL_FILE" -threads 0 -c:v libx264 -preset fast -crf 20 \
        -profile:v baseline -level 3.1 -pix_fmt yuv420p \
        -maxrate 3500k -bufsize 6000k -g 60 \
        -vf "$SCALE_FILTER" -movflags +faststart \
        -c:a aac -b:a 128k -ac 2 -ar 44100 \
        -progress pipe:1 "$CONVERTED_FILE" 2>>"$ERROR_FILE" | while read -r line; do
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

    rm -f "$DL_FILE"

    if [ ! -f "$CONVERTED_FILE" ]; then
        echo "failed" > "$STATUS_FILE"
        exit 1
    fi

    mv "$CONVERTED_FILE" "${OUTPUT_DIR}${FILENAME}.mp4"
else
    # codec_name alone isn't enough - Main/High profile mp4 reports as "h264" too
    VIDEO_INFO=$(ffprobe -v error -select_streams v:0 -show_entries stream=codec_name,profile,height -of csv=p=0 "$DL_FILE" 2>/dev/null)
    VCODEC=$(echo "$VIDEO_INFO" | cut -d',' -f1)
    VPROFILE=$(echo "$VIDEO_INFO" | cut -d',' -f2)
    VHEIGHT=$(echo "$VIDEO_INFO" | cut -d',' -f3)

    COMPATIBLE=true
    if [ "$VCODEC" != "h264" ]; then
        COMPATIBLE=false
    elif [ "$VPROFILE" != "Baseline" ] && [ "$VPROFILE" != "Constrained Baseline" ]; then
        COMPATIBLE=false
    elif [[ "$VHEIGHT" =~ ^[0-9]+$ ]] && [ "$VHEIGHT" -gt "$MAX_COMPATIBLE_HEIGHT" ]; then
        COMPATIBLE=false
    fi

    if [ "$COMPATIBLE" = false ]; then
        # Incompatible codec - need to convert
        echo "converting" > "$STATUS_FILE"
        echo "0" > "$PROGRESS_FILE"

        DURATION=$(ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "$DL_FILE" 2>/dev/null)

        # Distinct name - $DL_FILE may already be "*.mp4" itself
        CONVERTED_FILE="/tmp/${FILENAME}.converted.mp4"
        ffmpeg -i "$DL_FILE" -threads 0 -c:v libx264 -preset fast -crf 20 \
            -profile:v baseline -level 3.1 -pix_fmt yuv420p \
            -maxrate 3500k -bufsize 6000k -g 60 \
            -vf "$SCALE_FILTER" -movflags +faststart \
            -c:a aac -b:a 128k -ac 2 -ar 44100 \
            -progress pipe:1 "$CONVERTED_FILE" 2>>"$ERROR_FILE" | while read -r line; do
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

        rm -f "$DL_FILE"

        if [ ! -f "$CONVERTED_FILE" ]; then
            echo "failed" > "$STATUS_FILE"
            exit 1
        fi

        mv "$CONVERTED_FILE" "${OUTPUT_DIR}${FILENAME}.mp4"
    else
        # Baseline h264 at a compatible resolution - remux only, to move the moov
        # atom to the front. webOS cannot report a duration (so cannot seek or
        # restart) until it has read moov.
        REMUXED_FILE="/tmp/${FILENAME}.faststart.mp4"
        if ffmpeg -i "$DL_FILE" -c copy -movflags +faststart -y "$REMUXED_FILE" 2>>"$ERROR_FILE" && [ -s "$REMUXED_FILE" ]; then
            rm -f "$DL_FILE"
            mv "$REMUXED_FILE" "${OUTPUT_DIR}${FILENAME}.mp4"
        else
            rm -f "$REMUXED_FILE"
            mv "$DL_FILE" "${OUTPUT_DIR}${FILENAME}.mp4"
        fi
    fi
fi

# Success - update status and clean up
echo "ready" > "$STATUS_FILE"
rm -f "$LOCK_FILE" "$PROGRESS_FILE"

# Clear error file if empty or conversion succeeded
if [ -f "$ERROR_FILE" ] && [ ! -s "$ERROR_FILE" ]; then
    rm -f "$ERROR_FILE"
fi
