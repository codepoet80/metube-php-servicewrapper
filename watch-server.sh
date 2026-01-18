#!/bin/bash
# Watch script for metube-php-servicewrapper
# Shows real-time status of downloads, jobs, and files

# Load common environment config
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/config.sh"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

show_status() {
    clear
    echo -e "${CYAN}=== MeTube Server Watch ===${NC}"
    echo -e "Video Dir: ${BLUE}$VIDEO_DIR${NC}"
    echo -e "Jobs Dir:  ${BLUE}$JOBS_DIR${NC}"
    echo ""

    # Active downloads (files with .lock)
    echo -e "${YELLOW}--- Active Downloads ---${NC}"
    shopt -s nullglob
    lock_files=("$VIDEO_DIR"/*.lock)
    if [ ${#lock_files[@]} -eq 0 ]; then
        echo "  (none)"
    else
        for lock in "${lock_files[@]}"; do
            base=$(basename "$lock" .lock)
            status_file="$VIDEO_DIR/${base}.status"
            progress_file="$VIDEO_DIR/${base}.progress"
            error_file="$VIDEO_DIR/${base}.error"

            status="unknown"
            progress=""
            [ -f "$status_file" ] && status=$(cat "$status_file")
            [ -f "$progress_file" ] && progress=$(cat "$progress_file")

            # Color based on status
            case "$status" in
                downloading) color=$BLUE ;;
                converting)  color=$YELLOW ;;
                failed)      color=$RED ;;
                *)           color=$NC ;;
            esac

            echo -ne "  ${color}$base${NC}: $status"
            [ -n "$progress" ] && echo -ne " [${progress}%]"
            echo ""

            # Show error if present
            if [ -f "$error_file" ] && [ -s "$error_file" ]; then
                echo -e "    ${RED}Error: $(head -1 "$error_file")${NC}"
            fi
        done
    fi
    echo ""

    # Ready files (completed downloads)
    echo -e "${GREEN}--- Ready Files ---${NC}"
    mp4_files=("$VIDEO_DIR"/*.mp4)
    if [ ${#mp4_files[@]} -eq 0 ]; then
        echo "  (none)"
    else
        for mp4 in "${mp4_files[@]}"; do
            base=$(basename "$mp4")
            size=$(du -h "$mp4" 2>/dev/null | cut -f1)
            age=$(( ($(date +%s) - $(stat -c %Y "$mp4" 2>/dev/null || stat -f %m "$mp4" 2>/dev/null)) / 60 ))
            echo "  $base ($size, ${age}m ago)"
        done
    fi
    echo ""

    # Job files
    echo -e "${CYAN}--- Job Queue ---${NC}"
    if [ -d "$JOBS_DIR" ]; then
        job_files=("$JOBS_DIR"/job_*.json)
        if [ ${#job_files[@]} -eq 0 ]; then
            echo "  (none)"
        else
            for job in "${job_files[@]}"; do
                if [ -f "$job" ]; then
                    job_id=$(basename "$job" .json)
                    target=$(grep -o '"target":"[^"]*"' "$job" 2>/dev/null | cut -d'"' -f4)
                    url=$(grep -o '"url":"[^"]*"' "$job" 2>/dev/null | cut -d'"' -f4 | head -c 60)
                    echo "  $job_id -> $target"
                    [ -n "$url" ] && echo "    $url..."
                fi
            done
        fi
    else
        echo "  (jobs dir not found)"
    fi
    echo ""

    # Orphaned status files (no lock, no mp4)
    echo -e "${RED}--- Orphaned/Failed ---${NC}"
    found_orphan=false
    for status_file in "$VIDEO_DIR"/*.status; do
        [ -f "$status_file" ] || continue
        base=$(basename "$status_file" .status)
        lock_file="$VIDEO_DIR/${base}.lock"
        mp4_file="$VIDEO_DIR/${base}.mp4"
        if [ ! -f "$lock_file" ] && [ ! -f "$mp4_file" ]; then
            status=$(cat "$status_file")
            error_file="$VIDEO_DIR/${base}.error"
            echo -ne "  $base: $status"
            if [ -f "$error_file" ] && [ -s "$error_file" ]; then
                echo -ne " - $(head -1 "$error_file" | head -c 50)"
            fi
            echo ""
            found_orphan=true
        fi
    done
    [ "$found_orphan" = false ] && echo "  (none)"
    echo ""

    echo -e "${NC}Press Ctrl+C to exit. Refreshing every 2 seconds..."
}

# Main loop
while true; do
    show_status
    sleep 2
done
