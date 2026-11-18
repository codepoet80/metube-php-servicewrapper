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
$client_keys = normalize_keys($client_key);	//client_key may be a string or an array of strings
$reddit_cookies_file = $config['reddit_cookies_file'] ?? '';

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
    $original_url = base64_decode($request); //Requested Reddit URL

    //check for duplicate request (same URL within 30 min)
    $existing_job = find_duplicate_job($original_url, $file_dir);
    if ($existing_job !== null) {
        echo json_encode(array(
            'status' => 'ok',
            'target' => $existing_job['target'],
            'job_id' => $existing_job['job_id'],
            'duplicate' => true
        ));
        die;
    }

    $media = extract_reddit_video_link($original_url);    // ['video' => url, 'audio' => url|null]
    $video_url = $media['video'];
    $audio_url = $media['audio'];

    //check if ffmpeg exists
    if (!have_binary('ffmpeg')) {
        echo "{\"status\": \"error\", \"msg\": \"ERROR: FFMpeg not found on server.\"}";
        die;
    }

    $save = uniqid();
    $target = $save . ".mp4";

    //create job for tracking (Reddit videos are always converted)
    $job_id = create_job($original_url, $target, $file_dir, true);

    $command = dirname(__FILE__) . "/getconvertreddit.sh " . escapeshellarg($video_url) . " " . escapeshellarg($audio_url ?? '') . " " . escapeshellarg($file_dir) . " " . escapeshellarg($save) . " " . escapeshellarg($job_id);

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
    if (!preg_match('/(?:^|\.)reddit\.com$/', $post_host) and $post_host !== 'v.redd.it') {
	echo "{\"status\": \"error\", \"msg\": \"ERROR: No Reddit URL found in request.\"}";
	die;
    }
    global $reddit_cookies_file;

    // Reddit's bot protection answers libcurl with a 403 "blocked by network
    // security" page regardless of cookies, but lets yt-dlp through. yt-dlp
    // also resolves v.redd.it and /video/ share links, and -g prints the
    // direct progressive video/audio files (ffmpeg's HLS demuxer mishandles
    // this CDN's BYTERANGE playlists).
    $config_sh = dirname(__FILE__) . '/config.sh';
    $command = (is_readable($config_sh) ? '. ' . escapeshellarg($config_sh) . ' && ' : '') . 'yt-dlp --no-update -g -f ' . escapeshellarg('bestvideo+bestaudio/best');
    if ($reddit_cookies_file !== '') {
        $command .= ' --cookies ' . escapeshellarg($reddit_cookies_file);
    }
    exec($command . ' -- ' . escapeshellarg($post_url) . ' 2>&1', $output, $exit_code);
    $media_urls = preg_grep('/^https:\/\//', $output);
    if ($exit_code !== 0 || empty($media_urls)) {
        echo json_encode(array('status' => 'error', 'msg' => end($output) ?: 'ERROR: No video found in Reddit post.'));
        die;
    }

    // Only hand ffmpeg files from Reddit's own CDN.
    foreach ($media_urls as $media_url) {
        $parsed = parse_url($media_url);
        if (!preg_match('/(?:^|\.)redd\.it$/', strtolower($parsed['host'] ?? ''))) {
            echo "{\"status\": \"error\", \"msg\": \"ERROR: Invalid video URL in Reddit response.\"}";
            die;
        }
    }
    $media_urls = array_values($media_urls);
    return array('video' => $media_urls[0], 'audio' => $media_urls[1] ?? null);
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
