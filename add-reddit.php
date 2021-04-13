<?php
/*
Request a Reddit video, converted by FFMpeg
Thanks to https://github.com/cp6/Reddit-video-downloader/blob/master/rdt-video.php
*/
$debugMode = false; //Switch to true to get verbose shell command output

header('Content-Type: application/json');

$config = include('config.php');
$metube_url = $config['metube_url'] . "/add";
$metube_port = $config['metube_port'];
$server_id = $config['server_id'];
$client_key = $config['client_key'];
$debug_key = $config['debug_key'];

$request = file_get_contents("php://input");

$request_headers = getallheaders();
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
    $request = base64_decode($request); //Requested Reddit URL
    $request = extract_reddit_video_link($request);    //Converted Reddit video URL

    $try_ffmpeg = trim(shell_exec('type ffmpeg'));
    if (empty($try_ffmpeg) || strpos($try_ffmpeg, "not found") !== false) {
        echo "{\"status\": \"error\", \"msg\": \"ERROR: FFMpeg not found on server.\"}";
        die;
    }

    $preset = 'fast';
    $crf = 20;
    $save = uniqid;
    $savepath = $config['file_dir'] . $save . ".mp4";
    $command = "ffmpeg -i '" . $request . "' -c:v libx264 -preset " . $preset . " -crf " . $crf . " -profile:v baseline -movflags +faststart '" . $savepath . "' 2>&1";
    if ($debugMode) {
        $output = shell_exec($command);
        echo "{\"status\": \"ok\", \"command\", \"" . $command . "\", \"output\": \"" . $output . "\"}";
    }
    else {
        execute_async_shell_command($command);
	echo "{\"status\": \"ok\", \"target\": \"" . $save . ".mp4\"}";
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
    $data = json_decode(file_get_contents("" . $post_url . ".json"), true);
    $video_link = $data[0]['data']['children'][0]['data']['secure_media']['reddit_video']['dash_url'];
    return $video_link;
}

function execute_async_shell_command($command = null){
    if(!$command){
        throw new Exception("No command given");
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') { // windows
        system($command." > NUL");
    } else{  //*nix
        shell_exec("/usr/bin/nohup ".$command." >/dev/null 2>&1 &");
    }
}

?>
