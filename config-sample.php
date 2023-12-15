<?php
return array(
	'api_key' => 'YOURAPIKEY',	//Your Google API Key to enable YouTube search features
	'file_dir' => '/FOLDER/TO/SAVE/VIDEOS/TO/',
	'obscure_filepath' => false, //If set to true, the actual URL of the video will be hidden by PHP. If set to false, you must expose the download directory.
	'use_xsendfile' => false, //Not used if obscure_filepath is false. Make sure you have your Apache and htaccess configured before turning this on
	'client_key' => '',	//If you want some security, put a random string here and in debug_key. Your client must know this value.
	'debug_key' => '',	//If you want some security, put a random string here and in client_key. Your client must know this value.
	'server_id' => ''	//If you want some more security, put a random string here. Your client must know this value.
);
?>