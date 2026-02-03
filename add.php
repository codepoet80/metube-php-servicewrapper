<?php
/*
Executes a shell script that downloads a YouTube video, via youtube-dl
Optionally converts the video to webOS-friendly formats, via ffmepg
*/
//$debugMode = true; //Switch to true to get verbose shell command output

header('Content-Type: application/json');
include('common.php');
include('job-functions.php');
$config = include('config.php');
$file_dir = $config['file_dir'];
$server_id = $config['server_id'];
$client_key = $config['client_key'];
$debug_key = $config['debug_key'];

$request = file_get_contents("php://input");

$request_headers = get_request_headers();
if ($client_key != '' && $debug_key != '') {	//If configuration includes both client key values, enforce them
	if (!array_key_exists('Client-Id', $request_headers)) {
        echo "{\"status\": \"error\", \"msg\": \"ERROR: Not authorized\"}";
        die;
	} else {
        $request_key = $request_headers['Client-Id'];
        if (($request_key != $client_key) && ($request_key != $debug_key)) {
            echo "{\"status\": \"error\", \"msg\": \"ERROR: No authorized user.\"}";
            die;
        }
    }
}

if ($server_id == '' || ($server_id != '' && strpos($request, $server_id) !== false))		//If configuration includes a server key value, enforce it
{
    //decode inbound request
    $request = str_replace($server_id, "", $request);
    $request = base64_decode($request); //Requested YouTube URL

    //check for duplicate request (same URL within 30 min)
    $existing_job = find_duplicate_job($request);
    if ($existing_job !== null) {
        echo json_encode(array(
            'status' => 'ok',
            'target' => $existing_job['target'],
            'job_id' => $existing_job['job_id'],
            'duplicate' => true
        ));
        die;
    }

    //check if ffmpeg exists
    $try_ffmpeg = trim(shell_exec('type ffmpeg 2>&1'));
    if (empty($try_ffmpeg) || strpos($try_ffmpeg, "not found") !== false) {
        echo "{\"status\": \"error\", \"msg\": \"ERROR: FFMpeg not found on server.\"}";
        die;
    }

    //check if youtube-dl exists
    $try_youtubedl = trim(shell_exec('type youtube-dl 2>&1'));
    if (empty($try_youtubedl) || strpos($try_youtubedl, "not found") !== false) {
        echo "{\"status\": \"error\", \"msg\": \"ERROR: Youtube-dl not found on server.\"}";
        die;
    }
    //determine quality
    $quality = "bestvideo";	//allow video quality override at client request
	if (array_key_exists('Quality', $request_headers)) {
		$requested_quality = $request_headers['Quality'];
		// Validate quality parameter to prevent command injection
		// Only allow alphanumeric characters, brackets, slashes, plus, and equals
		if (preg_match('/^[a-zA-Z0-9\[\]\/\+\=\-]+$/', $requested_quality)) {
			$quality = $requested_quality;
		}
	}

    $save = uniqid();
    $target = $save . ".mp4";

    //create job for tracking
    $job_id = create_job($request, $target, $file_dir);

    $command = dirname(__FILE__) . "/getconvertyoutube.sh " . escapeshellarg($request) . " " . escapeshellarg($file_dir) . " " . escapeshellarg($save) . " " . escapeshellarg($quality);
    $convert = false;
    if ((isset($request_headers['Convert']) && strtolower($request_headers['Convert']) == "true") ||
        (isset($request_headers['convert']) && strtolower($request_headers['convert']) == "true")) {
            $command = $command . " convert";
            $convert = true;
    }
    //pass job_id as 6th argument
    $command = $command . " " . escapeshellarg($job_id);

    if (isset($debugMode) && $debugMode == true) {
        //$output = shell_exec($command);
        echo json_encode(array(
            'status' => 'ok',
            'command' => $command,
            'output' => $output
        ));
    }
    else {
        execute_async_shell_command($command);
        echo json_encode(array(
            'status' => 'ok',
            'target' => $target,
            'job_id' => $job_id
        ));
    }
}
else
{
    echo "{\"status\": \"error\", \"msg\": \"ERROR: Bad request content.\"}";
}

function execute_async_shell_command($command = null){
    if(!$command){
        throw new Exception("No command given");
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') { // windows
        system($command." > NUL");
    } else {  //*nix - run in background with & and redirect output
        exec($command . " > /dev/null 2>&1 &");
    }
}

?>
