<?php
/*
 * Job management functions for tracking download/conversion status
 */

define('JOBS_DIR', '/tmp/metube-jobs');
define('JOB_TTL', 1800); // 30 minutes in seconds

/**
 * Ensure the jobs directory exists
 */
function ensure_jobs_dir() {
    if (!is_dir(JOBS_DIR)) {
        mkdir(JOBS_DIR, 0755, true);
    }
}

/**
 * Create a new job and return the job_id
 * $converted: boolean indicating if this job includes ffmpeg conversion
 */
function create_job($url, $target, $file_dir, $converted = false) {
    ensure_jobs_dir();

    $job_id = 'job_' . uniqid();
    $url_hash = hash('sha256', $url);

    $job = array(
        'job_id' => $job_id,
        'url_hash' => $url_hash,
        'url' => $url,
        'target' => $target,
        'converted' => $converted,
        'status' => 'queued',
        'created' => time(),
        'updated' => time()
    );

    $job_file = JOBS_DIR . '/' . $job_id . '.json';
    file_put_contents($job_file, json_encode($job, JSON_PRETTY_PRINT));

    // Create lock and status files
    $base_name = pathinfo($target, PATHINFO_FILENAME);
    file_put_contents($file_dir . $base_name . '.lock', $job_id);
    file_put_contents($file_dir . $base_name . '.status', 'queued');

    return $job_id;
}

/**
 * Get job data by job_id
 */
function get_job($job_id) {
    $job_file = JOBS_DIR . '/' . $job_id . '.json';
    if (!file_exists($job_file)) {
        return null;
    }
    return json_decode(file_get_contents($job_file), true);
}

/**
 * Find job by target filename
 */
function find_job_by_target($target) {
    ensure_jobs_dir();

    $files = glob(JOBS_DIR . '/job_*.json');
    foreach ($files as $file) {
        $job = json_decode(file_get_contents($file), true);
        if ($job && $job['target'] === $target) {
            return $job;
        }
    }
    return null;
}

/**
 * Find a recent duplicate job by URL hash (within TTL)
 * $needs_conversion: if true, only return duplicates that were converted
 *                    (converted videos satisfy all requests; unconverted only satisfy unconverted requests)
 */
function find_duplicate_job($url, $needs_conversion = false) {
    ensure_jobs_dir();

    $url_hash = hash('sha256', $url);
    $cutoff = time() - JOB_TTL;

    $files = glob(JOBS_DIR . '/job_*.json');
    foreach ($files as $file) {
        $job = json_decode(file_get_contents($file), true);
        if ($job &&
            $job['url_hash'] === $url_hash &&
            $job['created'] > $cutoff &&
            $job['status'] !== 'failed') {
            // Check conversion compatibility:
            // - If client needs conversion, only return jobs that were converted
            // - If client doesn't need conversion, any job is fine
            $job_was_converted = isset($job['converted']) && $job['converted'];
            if ($needs_conversion && !$job_was_converted) {
                // Client needs converted, but this job wasn't - skip it
                continue;
            }
            return $job;
        }
    }
    return null;
}

/**
 * Update job status in the JSON file
 */
function update_job_status($job_id, $status) {
    $job_file = JOBS_DIR . '/' . $job_id . '.json';
    if (!file_exists($job_file)) {
        return false;
    }

    $job = json_decode(file_get_contents($job_file), true);
    $job['status'] = $status;
    $job['updated'] = time();

    file_put_contents($job_file, json_encode($job, JSON_PRETTY_PRINT));
    return true;
}

/**
 * Get current status for a target from sidecar files
 * Returns array with status, progress, and error info
 */
function get_target_status($target, $file_dir) {
    $base_name = pathinfo($target, PATHINFO_FILENAME);

    $result = array(
        'status' => 'unknown',
        'progress' => null,
        'error' => null
    );

    // Check if final file exists
    $video_file = $file_dir . $target;
    $lock_file = $file_dir . $base_name . '.lock';
    $status_file = $file_dir . $base_name . '.status';
    $progress_file = $file_dir . $base_name . '.progress';
    $error_file = $file_dir . $base_name . '.error';

    // Read status file if exists
    if (file_exists($status_file)) {
        $result['status'] = trim(file_get_contents($status_file));
    } elseif (file_exists($video_file) && !file_exists($lock_file)) {
        // Video exists and no lock = ready
        $result['status'] = 'ready';
    }

    // Read progress if exists
    if (file_exists($progress_file)) {
        $progress = trim(file_get_contents($progress_file));
        if (is_numeric($progress)) {
            $result['progress'] = (int)$progress;
        }
    }

    // Read error if exists
    if (file_exists($error_file)) {
        $result['error'] = file_get_contents($error_file);
    }

    return $result;
}

/**
 * Clean up old job files (called by cleanup script)
 */
function cleanup_old_jobs() {
    ensure_jobs_dir();

    $cutoff = time() - JOB_TTL;
    $files = glob(JOBS_DIR . '/job_*.json');

    foreach ($files as $file) {
        $job = json_decode(file_get_contents($file), true);
        if ($job && $job['created'] < $cutoff) {
            unlink($file);
        }
    }
}
?>
