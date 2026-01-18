# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHP service wrapper around yt-dlp and FFmpeg that provides video downloading/conversion capabilities for legacy devices (specifically webOS devices like Palm Pre and HP Touchpad). Acts as an HTTP API proxy enabling older devices to search YouTube, download/convert videos from YouTube and Reddit, and stream content.

## Architecture

**No framework, no package manager** - Simple PHP scripts with shell execution for yt-dlp/FFmpeg operations.

### Key Endpoints

| File | Purpose |
|------|---------|
| `search.php` | YouTube search proxy - queries Google YouTube API v3, filters live videos |
| `details.php` | YouTube video details proxy - fetches video metadata (duration, etc.) from Google API |
| `add.php` | YouTube download - invokes `getconvertyoutube.sh` with optional FFmpeg conversion |
| `add-reddit.php` | Reddit video download - invokes `getconvertreddit.sh` with FFmpeg |
| `status.php` | Job status endpoint - returns progress, status, errors for a job_id or target |
| `list.php` | Returns JSON of available MP4 files (excludes files with `.lock` companion) |
| `play.php` | Video streaming with obfuscation/security checks |
| `common.php` | Shared `get_request_headers()` utility |
| `job-functions.php` | Job tracking helpers - create/find/update jobs in `/tmp/metube-jobs/` |

### Shell Scripts

All shell scripts source `config.sh` for paths and environment setup.

- `config.sh` - Environment configuration (copy from `config-sample.sh`)
  - `VIDEO_DIR` - Video download directory (must match `file_dir` in config.php)
  - `JOBS_DIR` - Job tracking directory (default: `/tmp/metube-jobs`)
  - `PATH` - Ensures yt-dlp, deno, ffmpeg are findable when run via web server
- `getconvertyoutube.sh` - Orchestrates yt-dlp and optional FFmpeg conversion
  - Args: `$1`=URL, `$2`=output_dir, `$3`=filename, `$4`=quality, `$5`="convert" or job_id, `$6`=job_id (if converting)
  - Writes status to `.status`, progress to `.progress`, errors to `.error` files
  - Quality format uses yt-dlp `-f` syntax: e.g., `bestvideo[ext=mp4]+bestaudio[ext=aac]/best[ext=mp4]/best`
- `getconvertreddit.sh` - Downloads and converts Reddit HLS streams via FFmpeg
  - Args: `$1`=HLS_URL, `$2`=output_dir, `$3`=filename, `$4`=job_id
  - Same status/progress file pattern as getconvertyoutube.sh
- `youtube-cleanup.sh` - Removes old files (videos, status files, job JSON) after 30 minutes
  - Run via cron, e.g.: `*/15 * * * * /path/to/youtube-cleanup.sh`
- `watch-server.sh` - Real-time monitoring of downloads, jobs, and errors
  - Shows active downloads with progress, ready files, job queue, and failed downloads
  - Pauses on errors and waits for keypress; clears error files when acknowledged

### yt-dlp Reference

The project calls `yt-dlp` directly for video extraction. Key format selection syntax:
- `bestvideo`/`worstvideo` - quality presets passed via `Quality` header
- `[ext=mp4]` - filter by container format
- `+` - merge video and audio streams
- `/` - fallback chain (try left side first, then right)

### Request Flow

- **Async processing**: Shell commands run via `shell_exec()` (non-blocking for long operations)
- **Job tracking**: Each download creates a job in `/tmp/metube-jobs/` with status files in `file_dir`
- **Lock files**: In-progress downloads have a `.lock` file; `list.php` excludes locked files
- **Polling options**: Clients can poll `list.php` or use `status.php` for real-time progress
- **Deduplication**: Same URL requested within 30 min returns existing job (no duplicate downloads)
- **Security through obfuscation**: Base64 encoding, server_id token insertion, header-based auth (not cryptographic security)

## Client Protocol

Reference client: [webos-metube](https://github.com/codepoet80/webos-metube) - see `app/models/metube-model.js`

### Headers

All requests include:
- `Client-Id`: Shared secret matching `client_key` or `debug_key` in config

Add requests (`add.php`, `add-reddit.php`) also include:
- `Convert`: Boolean - whether to run FFmpeg conversion
- `Quality`: yt-dlp format string (e.g., `bestvideo`, `worstvideo`)

### Request Encoding Scheme

For `add.php` POST body and `play.php` requestid parameter:

1. Base64 encode the URL/payload
2. Generate random position within the encoded string
3. Insert `server_id` at that random position
4. Server must find and remove `server_id`, then base64 decode

Example: `https://youtube.com/...` → base64 → `aHR0cHM6Ly...` → insert server_id at random position → `aHR0cHSERVER_IDM6Ly...`

### Response Formats

**Add response** (`add.php`, `add-reddit.php`):
```json
{"status": "ok", "target": "abc123.mp4", "job_id": "job_abc123"}
```
- `target`: filename to watch for in `list.php`
- `job_id`: unique identifier for tracking via `status.php`
- `duplicate`: true if returning existing job for same URL

**Status response** (`status.php?job_id=X` or `status.php?target=X`):
```json
{"status": "downloading", "progress": 45, "target": "abc123.mp4", "job_id": "job_abc123"}
```
- `status`: queued | downloading | converting | ready | failed
- `progress`: percentage (0-100) when available
- `error`: error message if status is "failed"

**List response** (`list.php`):
```json
{"status": "ok", "files": [{"file": "encoded_filename.mp4"}, ...]}
```
Filenames in the response are also encoded with `server_id` insertion.

**Error response** (any endpoint):
```json
{"status": "error", "msg": "Error description"}
```

### Play Request Format

```
/play.php?video=<filename>&requestid=<encoded>
```
Where `<encoded>` = `encodeRequest(client_key + "|" + filename)`

The `video` param and decoded filename in `requestid` must match for validation.

### Typical Client Flow

**Legacy flow** (backward compatible):
1. `POST /add.php` with encoded video URL → receive `target` and `job_id`
2. Poll `GET /list.php` every 2 seconds until `target` appears in file list
3. Build play URL and stream via `GET /play.php`

**Enhanced flow** (with progress tracking):
1. `POST /add.php` with encoded video URL → receive `target` and `job_id`
2. Poll `GET /status.php?job_id=X` for real-time status and progress percentage
3. When status is "ready", build play URL and stream via `GET /play.php`

Server cleanup runs every 30 minutes. Duplicate URL requests within that window return existing job info.

## Configuration

Copy `config-sample.php` to `config.php` and set:
- `api_key` - Google YouTube API v3 key
- `file_dir` - Writable directory for video downloads (must be writable by www-data)
- `client_key` / `debug_key` - Optional shared secrets for Client-Id header auth
- `server_id` - Optional token embedded in request body for obfuscation
- `obscure_filepath` - If true, PHP streams video content; if false, expose download directory directly
- `use_xsendfile` - Apache X-Sendfile optimization (requires mod_xsendfile and .htaccess config)

## System Dependencies

- PHP 7.3+ with php-gd, php-xml, php-curl
- yt-dlp (successor to youtube-dl, handles throttling better)
- FFmpeg
- Apache2 or Nginx

## Shell Script Setup

Copy `config-sample.sh` to `config.sh` and configure:
1. Set `VIDEO_DIR` to match `file_dir` in config.php
2. Set `JOBS_DIR` (default `/tmp/metube-jobs` is usually fine)
3. Add paths to yt-dlp, deno, and ffmpeg if not in web server's PATH

The web server (www-data) runs with a minimal PATH, so binaries installed in user directories won't be found unless added to config.sh.

## Security Considerations

- Shell argument escaping via `escapeshellarg()` is critical
- URL encoding and parameter whitelisting applied
- `basename()` used to prevent path traversal
- Security is obfuscation-based only (legacy devices can't use TLS effectively)

## Local Testing

No test suite exists. To test locally:
1. Configure `config.php` with valid paths and API keys
2. Start PHP's built-in server: `php -S localhost:8080`
3. Test endpoints with curl, e.g.: `curl -H "Client-Id: YOUR_KEY" "http://localhost:8080/search.php?q=test&maxResults=5"`

## Debugging

### File Structure During Download
When a download is in progress, you'll see these files in `file_dir`:
```
abc123.lock      # Present while download/conversion is running
abc123.status    # Contains: "queued", "downloading", "converting", "ready", or "failed"
abc123.progress  # Contains integer 0-100 (percentage)
abc123.error     # Contains error output if something failed
abc123.mp4       # Final video file (appears when ready)
```

### Job Tracking Files
Jobs are tracked in `/tmp/metube-jobs/`:
```
job_abc123.json  # Contains job metadata (url, target, status, timestamps)
```

### Common Issues

**Progress not updating**: Check that yt-dlp supports `--progress-template`. Older versions may not.

**Lock file stuck**: If a download was interrupted, `.lock` file may remain. Delete it manually or wait for cleanup script.

**Status shows "unknown"**: The `.status` file wasn't created. Check shell script permissions and that `file_dir` is writable.

**Duplicate detection not working**: Jobs are matched by URL hash. Check `/tmp/metube-jobs/` for existing job files.

### Test Commands
```bash
# Watch server activity (recommended)
./watch-server.sh

# Test add endpoint (returns job_id)
curl -X POST -H "Client-Id: YOUR_KEY" -d "BASE64_ENCODED_URL" http://localhost:8080/add.php

# Test status endpoint
curl -H "Client-Id: YOUR_KEY" "http://localhost:8080/status.php?job_id=job_abc123"

# Check status files directly
cat /path/to/file_dir/abc123.status
cat /path/to/file_dir/abc123.progress

# Check job file
cat /tmp/metube-jobs/job_abc123.json
```
