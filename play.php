<?php

include('common.php');
$config = include('config.php');
$dir = $config['file_dir'];
$server_id = $config['server_id'];
$client_key = $config['client_key'];
$client_keys = normalize_keys($client_key);	//client_key may be a string or an array of strings
$obscure_filepath = true;
if (isset($config['obscure_filepath']))
	$obscure_filepath = $config['obscure_filepath'];
$allow_xsendfile = false;
if (isset($config['use_xsendfile']))
	$allow_xsendfile = $config['use_xsendfile'];
$debug_key = $config['debug_key'];
$debug_mode = false;

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
	if (!empty($client_keys) && $debug_key != '') {	//If configuration includes both client key values, enforce them
		if ((!array_intersect($client_keys, $request_parts)) && (!in_array($debug_key, $request_parts))) {
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
$request_id = str_replace($client_keys, "", $request_id);	//str_replace accepts an array of needles
$request_id = str_replace($debug_key, "", $request_id);

//try to find and send the requested file
$file_name = basename($request_id);  //Use basename to prevent path traversal
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

		$file_size = filesize($file_name);
		$is_head = ($_SERVER['REQUEST_METHOD'] === 'HEAD');

		// webOS 3.x refuses to play a media response that is compressed or
		// chunked, and derives seekability from Accept-Ranges/Content-Length.
		@ini_set('zlib.output_compression', 'Off');
		if (function_exists('apache_setenv')) {
			@apache_setenv('no-gzip', '1');
			@apache_setenv('dont-vary', '1');
		}
		while (ob_get_level()) ob_end_clean();

		header('Content-Type: video/mp4');
		header('Accept-Ranges: bytes');
		header_remove('Content-Encoding');

		// Parse a single byte range; webOS only ever sends one.
		$start = 0;
		$end = $file_size - 1;
		$is_range = false;
		if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)) {
			if ($m[1] === '' && $m[2] === '') {
				$satisfiable = false;
			} elseif ($m[1] === '') {			//suffix range: last N bytes
				$suffix = (int)$m[2];
				$satisfiable = ($suffix > 0);
				$start = max(0, $file_size - $suffix);
			} else {
				$start = (int)$m[1];
				if ($m[2] !== '')
					$end = min((int)$m[2], $file_size - 1);
				$satisfiable = ($start < $file_size && $start <= $end);
			}
			if (!$satisfiable) {
				header('HTTP/1.1 416 Requested Range Not Satisfiable');
				header('Content-Range: bytes */' . $file_size);
				header('Content-Length: 0');
				die;
			}
			$is_range = true;
		}

		$length = $end - $start + 1;

		if ($useXSendFile) {
			// mod_xsendfile serves the range itself; it must not see our
			// Content-Length or it will truncate the response.
			header('X-Sendfile: ' . $file_name);
			die;
		}

		if ($is_range) {
			header('HTTP/1.1 206 Partial Content');
			header('Content-Range: bytes ' . $start . '-' . $end . '/' . $file_size);
		} else {
			header('HTTP/1.1 200 OK');
		}
		header('Content-Length: ' . $length);

		if ($is_head)
			die;

		set_time_limit(0);
		$fp = fopen($file_name, 'rb');
		if ($fp === false) {
			header('HTTP/1.1 500 Internal Server Error');
			die;
		}
		fseek($fp, $start);
		$remaining = $length;
		while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
			$chunk = fread($fp, min(262144, $remaining));
			if ($chunk === false || $chunk === '')
				break;
			echo $chunk;
			flush();
			$remaining -= strlen($chunk);
		}
		fclose($fp);
		exit;
	} else {
		$file_name_parts = explode("/", $file_name);
		$file_name = end($file_name_parts);

		$dirparts = explode("/", $dir);
		$dir = end($dirparts);
		$dir = prev($dirparts);

		// Always plain http: the webOS media pipeline's TLS stack (GnuTLS 2.x,
		// TLS 1.0, 2011 CA bundle) cannot handshake with a modern origin, so an
		// https media URL fails before any video moves.
		$link = "http://" . $_SERVER['HTTP_HOST'] . "/" . $dir . "/" . $file_name;
		header("Location: " . $link);
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