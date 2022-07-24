<?php
/*
Executes a shell script that downloads a YouTube video, via youtube-dl
Optionally converts the video to webOS-friendly formats, via ffmepg
*/
//$debugMode = true; //Switch to true to get verbose shell command output

header('Content-Type: application/json');
include('common.php');
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

if (true == true || $server_id == '' || ($server_id != '' && strpos($request, $server_id) !== false))		//If configuration includes a server key value, enforce it
{
    //decode inbound request
    $request = str_replace($server_id, "", $request);
    $request = base64_decode($request); //Requested Reddit URL

    //check if ffmpeg exists
    $try_ffmpeg = trim(shell_exec('type ffmpeg'));
    if (empty($try_ffmpeg) || strpos($try_ffmpeg, "not found") !== false) {
        echo "{\"status\": \"error\", \"msg\": \"ERROR: FFMpeg not found on server. Ensure it is in the PATH.\"}";
        die;
    }

    //check if youtube-dl exists
    $try_youtubedl = trim(shell_exec('type youtube-dl'));
    if (empty($try_youtubedl) || strpos($try_youtubedl, "not found") !== false) {
        echo "{\"status\": \"error\", \"msg\": \"ERROR: Youtube-dl not found on server. Ensure it is in the PATH.\"}";
        die;
    }
    //determine quality
    $quality = "bestvideo";	//allow video quality override at client request
	if (array_key_exists('Quality', $request_headers)) {
		$quality = $request_headers['Quality'];
	}

    $save = uniqid();
    $command = dirname(__FILE__) . "/getconvertyoutube.sh " . escapeshellarg($request) . " " . $file_dir . " " . $save . " " . $quality;
    if ((isset($request_headers['Convert']) && strtolower($request_headers['Convert']) == "true") ||
        (isset($request_headers['convert']) && strtolower($request_headers['convert']) == "true")) {
            $command = $command . " convert";
    }
    if (isset($debugMode) && $debugMode == true) {
        //$output = shell_exec($command);
        echo "{\"status\": \"ok\", \"command\": \"" . $command . "\", \"output\": \"" . $output . "\"}";
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

function execute_async_shell_command($command = null){
    if(!$command){
        throw new Exception("No command given");
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') { // windows
        system($command." > NUL");
    } else{  //*nix
	shell_exec($command);
	return;
	//The below is better, but didn't work with nginx (did work with apache2)
        //shell_exec("/usr/bin/nohup ".$command." > /dev/null 2>&1 &");
    }
}

?>
