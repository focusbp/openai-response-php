<?php

session_start();

$session_name = "openai_response_php";

try {
	$statusMessage = $_SESSION[$session_name]['_status_msg'];
	$response['ok'] = true;
	$response['status_message'] = $statusMessage;
	if($statusMessage == "END"){
		$response['done'] = true;
	}else{
		$response['done'] = false;
	}
	outputJson($response);
} catch (Exception $e) {
	$response['ok'] = true;
	$response['status_message'] = 'Wrong code ' + $windowcode + " " + $vectorStoreName;
	$response['done'] = true;
	outputJson($response);
}

exit;


function outputJson(array $data) {
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	header('Pragma: no-cache');
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
