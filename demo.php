<?php 
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/config.php';
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

function fetchAmazon($token) {
	try {
		$amazon = curl_init('https://api.amazon.com/user/profile');
		curl_setopt($amazon, CURLOPT_HTTPHEADER, array('Authorization: bearer ' . $token));
		curl_setopt($amazon, CURLOPT_RETURNTRANSFER, true);

		$profile = curl_exec($amazon); curl_close($amazon);

		return json_decode($profile);

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
    case "LightModule": $response->respond("Ei Kapitän, Licht ".$command." im ".$room);
  			$response->endSession();
		break;
    case "MyLight": 	$response->respond("Ei Kapitän, Licht ".$command. " im Kapitänsquartier.");
  			$response->endSession();
		break;
    case "Visitor": 	$work = array("intentSequence" => "Visitor2");
  			$response->sessionAttributes=$work;
			$response->respond("Kapitän, bekommen wir wirklich Besuch?");
			$response->reprompt("Was ist denn nun mit dem Besuch?");
			$handled = true;
		break;
    case "Visitor2": 
			if ($command == "ja") { 
				$response->respond("Eindämmungsfelder aktiviert und stabil. Bereit für Besucher."); 
			} else {
				$response->respond("Dann eben nicht."); 
				$handled = true;
			}
  			$response->endSession();
		break;

    case "NoVisitor": 	$work = array("intentSequence" => "NoVisitor2");
  			$response->sessionAttributes=$work;
			$response->respond("Soll der Normalbetrieb wieder aktiviert werden?");
			$response->reprompt("Was ist denn nun?");
			$handled = true;
		break;
	
    case "NoVisitor2":	if ($command == "ja") { 
				$response->respond("Zurück zum Normalbetrieb. Ei.");
			} else {
				$response->respond("Dann eben nicht."); 
				$handled = true;
			}
  			$response->endSession();
		break;
 
    case "Sleep": 	$response->respond("Wachwechsel, zu Befehl.");
  			$response->endSession();
		break;

    case "Commands":
			$response->respond($command." zu Befehl, Kapitän.");
  			$response->endSession();
		break;

    case "Status": 	$rpc = new RPC_Symcon();
			$response->respond($rpc->rexec(json_encode($alexa)));
  			$response->endSession();
			$handled = true;
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

class RPC_Symcon {
    private $connection;
    private $channel;
    private $callback_queue;
    private $response;
    private $corr_id;

    public function __construct() {
        $this->connection = new AMQPStreamConnection(
            'docker-prod.srv.nightserv.de', 5672, 'fred', 'nasen4hasen', 'nightserv');
        $this->channel = $this->connection->channel();
        list($this->callback_queue, ,) = $this->channel->queue_declare(
            "", false, false, true, false);
        $this->channel->basic_consume(
            $this->callback_queue, '', false, false, false, false,
            array($this, 'on_response'));
    }
    public function on_response($rep) {
        if($rep->get('correlation_id') == $this->corr_id) {
            $this->response = $rep->body;
        }
    }

    public function rexec($n) {
        $this->response = null;
        $this->corr_id = uniqid();

        $msg = new AMQPMessage(
            (string) $n,
            array('correlation_id' => $this->corr_id,
                  'reply_to' => $this->callback_queue)
            );
        $this->channel->basic_publish($msg, '', 'symcon-alexa');
        while(!$this->response) { $this->channel->wait(); }
        return $this->response;
    }
};

function RequestLog($object) {
   $f = "/var/www/vhosts/devel.worta.de/httpdocs/logs/request.log";
   $h = fopen($f, "a+"); fwrite($h, json_encode($object, JSON_PRETTY_PRINT));
   fflush($h); fclose($h);
}


$alexa = getAlexa(); if (!$alexa->user->accessToken) { msgAlexa("Für diesen Skill wird eine Accountverbindung benötigt."); return "erf"; }
RequestLog($alexa);

if (smartAlexa($alexa)) { return "rpc"; }
putMessage(json_encode($alexa));

?>
