<?php
/*
Send a YouTube search request to Google on behalf of a device that cannot
*/

header('Content-Type: application/json');
$config = include('config.php');
$api_key = $config['api_key'];
$client_key = $config['client_key'];
$debug_key = $config['debug_key'];
$safeSearch="moderate"; 	//other values here: https://developers.google.com/youtube/v3/docs/search/list

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

$the_query = $_SERVER['QUERY_STRING'];
if (isset($_GET["key"])) {
	$api_key = $_GET["key"];
}

if ($the_query == "") {
	echo "{\"status\": \"error\", \"msg\": \"ERROR: No query.\"}";
	die;
}

$search_path = "https://www.googleapis.com/youtube/v3/search?" . $the_query . "&safeSearch=". $safeSearch . "&key=". $api_key;

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

$json_a = json_decode($content);
$items = $json_a->items;
$newitems = array();
foreach ($items as $item) { 
	foreach ( $item as $key => $val) {
		if ($key == "snippet"){

			$myArray = (array) $val;
			if ($myArray['liveBroadcastContent'] != 'live')
			{
				array_push($newitems, $item);
			}
		}
	 }
}
$json_a->items = $newitems;
print_r (json_encode($json_a));
?>
