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

    //create job for tracking
    $job_id = create_job($original_url, $target, $file_dir);

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
    if (!isset($post_url) or trim($post_url) == '' or strpos($post_url, 'reddit.com') === false) {
	echo "{\"status\": \"error\", \"msg\": \"ERROR: No Reddit URL found in request.\"}";
	die;
    }
    $data = json_decode(curl_get_contents("" . $post_url . ".json"), true);
    $video_link = $data[0]['data']['children'][0]['data']['secure_media']['reddit_video']['hls_url'];
    return $video_link;
}

function execute_async_shell_command($command = null){
    if(!$command){
        throw new Exception("No command given");
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') { // windows
        system($command." > NUL");
    } else{  //*nix
	shell_exec($command);
	//The below is better, but didn't work with nginx (did work with apache2)
        //shell_exec("/usr/bin/nohup ".$command." > /dev/null 2>&1 &");
    }
}

function curl_get_contents($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

?>
