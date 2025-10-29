<?php

ini_set('display_errors', 1);
error_reporting(E_ALL & ~E_NOTICE);
mb_internal_encoding("UTF-8");
ignore_user_abort(true); 

require __DIR__ . '/../vendor/autoload.php';
use focusbp\OpenAIResponsePhp\OpenAI;
use focusbp\OpenAIResponsePhp\SessionRecorder;
use focusbp\OpenAIResponsePhp\Controller;
use focusbp\OpenAIResponsePhp\SessionStatusManager;

//------------
// API KEY
//------------
if(is_file("config_dev/config.php")){
	include "config_dev/config.php";
}else{
	include "config/config.php";
}

if(empty($apikey)){
	$html = file_get_contents("html/error.html");
	$html = str_replace('{$msg}', "Please set the API key.",$html);
	echo $html;
	exit;
}

//------------
// Setting
//------------
$vector_store_dir = __DIR__ . "/vector_store";
$vector_store_name = "test";
$vector_store_id_file = __DIR__ . "/log/vector_store_id.txt";
$tools_dir = __DIR__ . "/function_tools";
$model = "gpt-5";
$logfile = __DIR__ . "/log/log.txt";

//------------------
// Vector Store ID
//------------------
$vectorStorID = null;
if(is_file($vector_store_id_file)){
	$vectorStorID = file_get_contents($vector_store_id_file);
}

//------------------
// Create Instances
//------------------ 
$session_name = "openai_response_php";
$session_recorder = new SessionRecorder($session_name);
$status_manager = new SessionStatusManager($session_name);
$controller = new Controller();
$openai = new OpenAI($apikey,$vector_store_dir,$vector_store_name,$vectorStorID,$tools_dir,$model,$logfile,$session_recorder,$status_manager,$controller);

//----------------------------------
// Syncronize vector store files
//----------------------------------
if($vectorStorID === null){
	$vectorStorID = $openai->syncVectorStore();
	file_put_contents($vector_store_id_file, $vectorStorID);
}

session_start();

$method = $_SERVER['REQUEST_METHOD'];

if($method == 'GET'){
	
	if (($_GET['sync'] ?? '') === 'true') {
		$openai->syncVectorStore();
	}
	
	$openai->clear_messages();
	$openai->add_system('For weather-related questions, please use Function Calling. For all other questions, respond by referring to the documents in the Vector Store.');
	$html = file_get_contents("html/index.html");
	echo $html;
	
} elseif ($method === 'POST') {
	
	$msg = $_POST["msg"];
	$res = $openai->respond($msg);
	$history = $res->get_history();
	outputJson($history);
	
}else{
        http_response_code(405);
        header('Allow: GET, POST');
        exit('Method not allowed');
}


function outputJson(array $data) {
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	header('Pragma: no-cache');
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}