<?php
/*
Send a YouTube video request to Google on behalf of a device that cannot
*/

header('Content-Type: application/json');
include('common.php');
$config = include('config.php');
$api_key = $config['api_key'];
$client_key = $config['client_key'];
$debug_key = $config['debug_key'];

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

// Allow client to override API key
if (isset($_GET["key"])) {
	$api_key = $_GET["key"];
}

// Validate and sanitize query parameters
$allowed_params = ['id', 'part'];
$safe_params = array();

foreach ($allowed_params as $param) {
	if (isset($_GET[$param])) {
		$value = $_GET[$param];
		$safe_params[$param] = $value;
	}
}

if (empty($safe_params)) {
	echo "{\"status\": \"error\", \"msg\": \"ERROR: No query.\"}";
	die;
}

// Build query string with validated parameters
$query_parts = array();
foreach ($safe_params as $key => $value) {
	$query_parts[] = urlencode($key) . "=" . urlencode($value);
}
$the_query = implode("&", $query_parts);

$search_path = "https://www.googleapis.com/youtube/v3/videos?" . $the_query . "&part=contentDetails&key=" . urlencode($api_key);

$myfile = fopen($search_path, "rb");
$content = stream_get_contents($myfile);
fclose($myfile);
if (!isset($content) || $content == "") {
	if (isset($_GET["key"])) {
	        echo "{\"status\": \"error\", \"msg\": \"ERROR: No usable response from Google. API key not allowed or quota exceeded.\"}";
	} else {
	        echo "{\"status\": \"error\", \"msg\": \"ERROR: No usable response from Google. API quota may have been exceeded.\"}";
	}
	die;
}

print_r($content);

?>
