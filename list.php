<?php
header('Content-Type: application/json');

$config = include('config.php');
$dir = $config['file_dir'];
$server_id = $config['server_id'];
$client_key = $config['client_key'];
$debug_key = $config['debug_key'];
$request_key = '';

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

//make file list, checking for files that are still changing
if(is_dir($dir)){
	$check_array = list_dir_contents($dir);
	sleep(1);
    $file_array = list_dir_contents($dir);
	$ready_array = array();
	foreach ($file_array as $thisfile) {
		$changed = false;
		foreach ($check_array as $checkfile) {
			if ($checkfile->file == $thisfile->file) {
				if ($checkfile->size != $thisfile->size) {
					$changed = true;
				}
			}
		}
		if (!$changed) {
			$newfileObj = (object)[
				'file' => encode_response($thisfile->file, $server_id),
			];
			array_push($ready_array, $newfileObj);
		}
	}
	$return_array = array('files'=> $ready_array);
    echo json_encode($return_array);
}

function list_dir_contents($dir) {
	$list = array(); //main array
	if($dh = opendir($dir)){
        while(($file = readdir($dh)) != false){
            if($file == "." or $file == ".." or strpos($file, ".") == false or strpos($file, ".php") != false or strpos($file, ".mp4.part") != false){
                //skip this file
            } else { //create object with two fields
				$path = $dir . "/" . $file;
				$ret_file = $file;
                $newfileObj = (object)[
					'file' => $ret_file,
					'size' => filesize($path)
				];
                array_push($list, $newfileObj);
            }
        }
    }
	return $list;
}

function encode_response($the_response, $server_id) {
	if (strpos($the_response, "|") !== false) {
		$strlength = strpos($the_response, "|");
	} else {
		$strlength = strlen($the_response);
	}
	$split_pos = rand(1, $strlength);
	$the_response = base64_encode($the_response);
	$str1 = substr($the_response, 0, $split_pos);
	$str2 = substr($the_response, $split_pos);
	$the_response = $str1 . $server_id . $str2;
	return $the_response;
}
?>

