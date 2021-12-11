<?php

$config = include('config.php');
$dir = $config['file_dir'];
$server_id = $config['server_id'];
$client_key = $config['client_key'];
$obscure_filepath = true;
if (isset($config['obscure_filepath']))
	$obscure_filepath = $config['obscure_filepath'];
$allow_xsendfile = false;
if (isset($config['use_xsendfile']))
	$allow_xsendfile = $config['use_xsendfile'];
$debug_key = $config['debug_key'];
$debug_mode = true;

$request_id = $_GET['requestid'];
//figure out the query
$video_requested = $_SERVER['QUERY_STRING'];
$video_requested = str_replace("&requestid=". $request_id, "", $video_requested);
$video_requested = str_replace("video=", "", urldecode($video_requested));

//validate request id
if ($server_id != '' && strpos($request_id, $server_id) === false) 		//If configuration includes a server key value, enforce it
{
	echo "{\"status\": \"error\", \"msg\": \"ERROR: Not authorized\"}";
	die;
}
$request_id = str_replace($server_id, "", $request_id);

//decode the authentication parts
$request_id = base64_decode($request_id);
if (strpos($request_id, "|") !== false) {	//Required for a secured request
	$request_parts = explode("|", $request_id);
	if ($client_key != '' && $debug_key != '') {	//If configuration includes both client key values, enforce them
		if ((!in_array($client_key, $request_parts)) && (!in_array($debug_key, $request_parts))) {
			//no client key in request
			header('HTTP/1.1 403 Forbidden');
			echo ("Not authorized");
			die;
		}
	}
	//extract decoded filename
	if (in_array($debug_key, $request_parts))
		$debug_mode = true;
	$request_id = str_replace("|", "", $request_id);
}
$request_id = str_replace($client_key, "", $request_id);
$request_id = str_replace($debug_key, "", $request_id);

//try to find and send the requested file
$file_name = $request_id;
$file_name = $dir . $file_name;

if (file_exists($file_name)) {
	if ($obscure_filepath) {
		$useXSendFile = false;
		if ($allow_xsendfile) {
			try {
				// try to find xsendfile, which is more efficient
				if (in_array('mod_xsendfile', apache_get_modules())) {
					$useXSendFile = true;
				}
			} catch (Exception $ex) {
				//guess we couldn't find it
			}
		}

		if (file_exists($file_name)) {
			$file_size = (string)(filesize($file_name));
			header('Content-Type: video/mp4');
			header('Accept-Ranges: bytes');
			header('Content-Length: '.$file_size);
			if ($useXSendFile) {
				//$fp = fopen($file_name, 'rb');
				header('X-Sendfile: ' . $file_name);
				//fpassthru($fp);
			} else {
				// dump the file and stop the script
				$fp = fopen($file_name, 'r');
				fpassthru($fp);
				exit;
				/*header("Content-Disposition: inline;");
				header("Content-Range: bytes .$file_size");
				header("Content-Transfer-Encoding: binary\n");
				header('Connection: close');
				readfile($file_name);*/
			}
		}
	} else {
		$file_name_parts = explode("/", $file_name);
		$file_name = end($file_name_parts);

		$dirparts = explode("/", $dir);
		$dir = end($dirparts);
		$dir = prev($dirparts);

		if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
        	$link = "https";
		else $link = "http";
			$link .= "://";
		$link .= $_SERVER['HTTP_HOST'] . "/" . $dir . "/" . $file_name;
		die("Location: " . $link);
	}
} else {
	header("HTTP/1.1 410 Gone");
	echo ("File doesn't exist<br>");
	if ($debug_mode) {
		echo $file_name . "<br>";
		echo rawurldecode($request_id) . "<br>";
	}
	die;
}
?>