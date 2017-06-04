<?php 
require_once __DIR__ . '/vendor/autoload.php';
date_default_timezone_set('Europe/Berlin');

use Alexa\Response;
use Alexa\Request;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function getAlexa() {
  try {
	$jd = file_get_contents('php://input');
	$request = json_decode($jd, true);
	$object = json_decode($jd, false);
	// RequestLog($request);
  		
	$alexa = \Alexa\Request\Request::fromData($request);
	
	if ($object->session->new == false) { $alexa->sessionData=$object->session->attributes; }

	return $alexa;

  } catch (Exception $e) {
	return null;
  }
}

function putMessage($kv) {

  $rcon = new AMQPStreamConnection('docker-prod.srv.nightserv.de', 5672, 'fred', 'password', 'nightserv');

  $rch = $rcon->channel();

  $rch->queue_declare('symcon-alexa', false, false, false, false);
  $rmsg = new AMQPMessage($kv);

  $rch->basic_publish($rmsg, '', 'symcon-alexa');

  $rch->close();
  $rcon->close();
}

function msgAlexa($msg) {
  $response = new \Alexa\Response\Response;
  $response->endSession();
  $response->respond($msg);

  header('Content-Type: application/json');
  echo json_encode($response->render());
}

function smartAlexa($alexa) {
  $response = new \Alexa\Response\Response;

  $command = $alexa->slots['command'];
  $room = $alexa->slots['room'];

  if ($alexa->sessionData->intentSequence != "") {
	// If we're in a chat sequence, get Intent from sequqence variable
	$intent = $alexa->sessionData->intentSequence;
  } else
  {
	$intent = $alexa->intentName;
  }

  $handled = false;

  switch ($intent) {
    case "": $response->respond("Ei Kapitän, Licht ".$command." im ".$room);
  			 $response->endSession();
		break;

    default: 
    		$response->respond("Das geht leider nicht, Kapitän.");
  			$response->endSession();
			$handled = false;
		
  }

  header('Content-Type: application/json');
  echo json_encode($response->render());
	
  return $handled;
}

function RequestLog($object) {
   //$f = "request.log";
   //$h = fopen($f, "a+"); fwrite($h, json_encode($object, JSON_PRETTY_PRINT));
   //fflush($h); fclose($h);
}


// $alexa = getAlexa(); if (!$alexa->user->accessToken) { msgAlexa("Für diesen Skill wird eine Accountverbindung benötigt."); return "erf"; }
$alexa = getAlexa(); 
RequestLog($alexa);

smartAlexa($alexa);
putMessage(json_encode($alexa));

?>
