<?php
/*
Executes a shell script that downloads a Dailymotion video, via yt-dlp
(yt-dlp has a built-in Dailymotion extractor, same as YouTube/etc.)
Optionally converts the video to webOS-friendly formats, via ffmpeg

This is a thin sibling of add.php, not a fork: getconvertyoutube.sh,
job-functions.php, and common.php are already fully provider-agnostic
(nothing in any of them references youtube.com specifically - they just
hand whatever URL they're given to yt-dlp), so this reuses all three
unmodified. The only thing this file does differently from add.php is
exist under a clearly separate name, matching how add-reddit.php already
gets its own named entry point rather than overloading add.php - a
webOS client can tell from the endpoint alone which source it's asking
for, without needing to inspect the URL itself.

Why this exists: legacy webOS (this was built against a TouchPad on
3.0.5) can't reach Dailymotion's CDN for playback at all - its media
pipeline's HTTP source is hard-locked to the device's original TLS 1.0
stack (by design, to avoid destabilizing other consumers - see
webOSArchive/OpenSSL-legacyWebOS's README, "What it does NOT do" and the
existing media-pipeline env-scrub notes), and Dailymotion's CDN is
Cloudflare-fronted requiring TLS 1.2+. No amount of client-side work can
fix that; downloading and re-serving the file from a modern-TLS server,
exactly what this project already does for YouTube, is the only path
that works.
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
$client_keys = normalize_keys($client_key);	//client_key may be a string or an array of strings

$request = file_get_contents("php://input");

$request_headers = get_request_headers();
if (!empty($client_keys) && $debug_key != '') {	//If configuration includes both client key values, enforce them
	if (!array_key_exists('Client-Id', $request_headers)) {
        echo "{\"status\": \"error\", \"msg\": \"ERROR: Not authorized\"}";
        die;
	} else {
        $request_key = $request_headers['Client-Id'];
        if ((!in_array($request_key, $client_keys, true)) && ($request_key != $debug_key)) {
            echo "{\"status\": \"error\", \"msg\": \"ERROR: No authorized user.\"}";
            die;
        }
    }
}

if ($server_id == '' || ($server_id != '' && strpos($request, $server_id) !== false))		//If configuration includes a server key value, enforce it
{
    //decode inbound request
    $request = str_replace($server_id, "", $request);
    $request = base64_decode($request); //Requested Dailymotion URL

    //check convert flag early (needed for duplicate detection)
    $convert = false;
    if ((isset($request_headers['Convert']) && strtolower($request_headers['Convert']) == "true") ||
        (isset($request_headers['convert']) && strtolower($request_headers['convert']) == "true")) {
        $convert = true;
    }

    //check for duplicate request (same URL within 30 min)
    //if client needs conversion, only return duplicates that were converted
    $existing_job = find_duplicate_job($request, $file_dir, $convert);
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
    if (!have_binary('ffmpeg')) {
        echo "{\"status\": \"error\", \"msg\": \"ERROR: FFMpeg not found on server.\"}";
        die;
    }

    //check if yt-dlp exists (getconvertyoutube.sh calls yt-dlp, not youtube-dl)
    if (!have_binary('yt-dlp')) {
        echo "{\"status\": \"error\", \"msg\": \"ERROR: yt-dlp not found on server.\"}";
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

    //create job for tracking (include convert flag for future duplicate detection)
    $job_id = create_job($request, $target, $file_dir, $convert);

    //getconvertyoutube.sh is provider-agnostic despite the name - it just
    //hands $1 to yt-dlp as-is, same as add.php uses it for YouTube URLs.
    //Not duplicated into a getconvertdailymotion.sh: there is nothing
    //Dailymotion-specific to add, and a copy would just be two files to
    //keep in sync. Worth a rename to something provider-neutral
    //(getconvert.sh?) at the maintainer's discretion - left alone here
    //since that's a broader change than this endpoint needs.
    $command = dirname(__FILE__) . "/getconvertyoutube.sh " . escapeshellarg($request) . " " . escapeshellarg($file_dir) . " " . escapeshellarg($save) . " " . escapeshellarg($quality);
    if ($convert) {
        $command = $command . " convert";
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
