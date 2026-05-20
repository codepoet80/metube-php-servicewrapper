<?php
/*
Request a Reddit video, converted by FFMpeg
Thanks to https://github.com/cp6/Reddit-video-downloader/blob/master/rdt-video.php
*/
$debugMode = false; //Switch to true to get verbose shell command output

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
    $original_url = base64_decode($request); //Requested Reddit URL

    //check for duplicate request (same URL within 30 min)
    $existing_job = find_duplicate_job($original_url);
    if ($existing_job !== null) {
        echo json_encode(array(
            'status' => 'ok',
            'target' => $existing_job['target'],
            'job_id' => $existing_job['job_id'],
            'duplicate' => true
        ));
        die;
    }

    $reddit_url = str_replace("www.reddit", "old.reddit", $original_url);
    $hls_url = extract_reddit_video_link($reddit_url);    //Converted Reddit video URL

    //check if ffmpeg exists
    $try_ffmpeg = trim(shell_exec('type ffmpeg 2>&1'));
    if (empty($try_ffmpeg) || strpos($try_ffmpeg, "not found") !== false) {
        echo "{\"status\": \"error\", \"msg\": \"ERROR: FFMpeg not found on server.\"}";
        die;
    }

    $save = uniqid();
    $target = $save . ".mp4";

    //create job for tracking (Reddit videos are always converted)
    $job_id = create_job($original_url, $target, $file_dir, true);

    $command = dirname(__FILE__) . "/getconvertreddit.sh " . escapeshellarg($hls_url) . " " . escapeshellarg($file_dir) . " " . escapeshellarg($save) . " " . escapeshellarg($job_id);

    if ($debugMode) {
        $output = shell_exec($command);
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

function extract_reddit_video_link(string $post_url)
{
    $parsed_post = parse_url($post_url);
    $post_host = strtolower($parsed_post['host'] ?? '');
    if (!isset($post_url) or trim($post_url) == '' or !preg_match('/(?:^|\.)reddit\.com$/', $post_host)) {
	echo "{\"status\": \"error\", \"msg\": \"ERROR: No Reddit URL found in request.\"}";
	die;
    }
    $data = json_decode(curl_get_contents("" . $post_url . ".json"), true);
    $video_link = $data[0]['data']['children'][0]['data']['secure_media']['reddit_video']['hls_url'];

    // Fix #2: validate the HLS URL is from a known Reddit CDN before passing to ffmpeg.
    // If legacy clients break due to SSL changes in curl_get_contents, revert CURLOPT_SSL_VERIFYPEER
    // in that function (search for "Fix #2: SSL" below) and remove this block together with it.
    $parsed_hls = parse_url($video_link ?? '');
    $hls_host = strtolower($parsed_hls['host'] ?? '');
    if (($parsed_hls['scheme'] ?? '') !== 'https' || !preg_match('/(?:^|\.)redd\.it$/', $hls_host)) {
        echo "{\"status\": \"error\", \"msg\": \"ERROR: Invalid video URL in Reddit response.\"}";
        die;
    }

    return $video_link;
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

function curl_get_contents($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    // Fix #2: SSL — re-enabled peer verification to prevent MITM HLS URL injection.
    // If legacy clients break (old CA bundles, self-signed Reddit certs), revert this to false
    // and also remove the HLS URL allowlist block in extract_reddit_video_link() above.
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

?>
