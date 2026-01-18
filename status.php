<?php
/*
Returns the current status of a download/conversion job
Supports lookup by job_id or target filename
*/

header('Content-Type: application/json');

include('common.php');
include('job-functions.php');
$config = include('config.php');
$file_dir = $config['file_dir'];
$client_key = $config['client_key'];
$debug_key = $config['debug_key'];

// Authenticate request
$request_headers = get_request_headers();
if ($client_key != '' && $debug_key != '') {
    if (!array_key_exists('Client-Id', $request_headers)) {
        echo json_encode(array('status' => 'error', 'msg' => 'ERROR: Not authorized'));
        die;
    } else {
        $request_key = $request_headers['Client-Id'];
        if (($request_key != $client_key) && ($request_key != $debug_key)) {
            echo json_encode(array('status' => 'error', 'msg' => 'ERROR: No authorized user.'));
            die;
        }
    }
}

// Get job by job_id or target
$job = null;
$target = null;

if (isset($_GET['job_id'])) {
    $job_id = $_GET['job_id'];
    // Validate job_id format to prevent path traversal
    if (!preg_match('/^job_[a-f0-9]+$/', $job_id)) {
        echo json_encode(array('status' => 'error', 'msg' => 'ERROR: Invalid job_id format'));
        die;
    }
    $job = get_job($job_id);
    if ($job) {
        $target = $job['target'];
    }
} elseif (isset($_GET['target'])) {
    $target = basename($_GET['target']); // Prevent path traversal
    $job = find_job_by_target($target);
}

if (!$target) {
    echo json_encode(array('status' => 'error', 'msg' => 'ERROR: Job not found. Provide job_id or target parameter.'));
    die;
}

// Get current status from sidecar files
$status_info = get_target_status($target, $file_dir);

// Build response
$response = array(
    'target' => $target,
    'status' => $status_info['status']
);

// Add job_id if we have it
if ($job) {
    $response['job_id'] = $job['job_id'];
}

// Add progress if available
if ($status_info['progress'] !== null) {
    $response['progress'] = $status_info['progress'];
}

// Add error if available
if ($status_info['error'] !== null) {
    $response['error'] = $status_info['error'];
}

echo json_encode($response);
?>
