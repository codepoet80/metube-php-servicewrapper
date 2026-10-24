<?php
function get_request_headers() {
	//Cross platform way to get request headers, thanks to https://stackoverflow.com/a/20164575/8216691
	$request_headers = [];
	if (!function_exists('getallheaders')) {
	    foreach ($_SERVER as $name => $value) {
	        /* RFC2616 (HTTP/1.1) defines header fields as case-insensitive entities. */
	        if (strtolower(substr($name, 0, 5)) == 'http_') {
	            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
	        }
	    }
	    $request_headers = $headers;
	} else {
	    $request_headers = getallheaders();
	}
	return $request_headers;
}

function normalize_keys($value) {
	//A config key may be a single string or an array of strings; return a
	//clean list so callers can accept multiple client secrets uniformly.
	return array_values(array_filter((array) $value, function($k) { return $k !== ''; }));
}

function have_binary($name) {
	//The web server runs with a minimal PATH that usually lacks yt-dlp/ffmpeg,
	//so source config.sh first - the same PATH the shell scripts get.
	$config_sh = dirname(__FILE__) . '/config.sh';
	$prefix = is_readable($config_sh) ? '. ' . escapeshellarg($config_sh) . ' && ' : '';
	$found = trim((string) shell_exec($prefix . 'command -v ' . escapeshellarg($name) . ' 2>&1'));
	return ($found !== '' && strpos($found, 'not found') === false);
}
?>
