<?php
declare(strict_types=1);
require dirname(__DIR__).'/server/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(int $code, array $data): never {
 http_response_code($code);
 echo json_encode($data, JSON_UNESCAPED_UNICODE);
 exit;
}
function validToken($value): bool {
 return is_string($value) && preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $value) === 1;
}
function validateFields($value): bool {
 if (!is_array($value) || count($value)>100) return false;
 foreach ($value as $key=>$item) {
  if (!is_string($key) || strlen($key)>100) return false;
  if (!is_null($item) && !is_bool($item) && !is_int($item) && !is_float($item) && !is_string($item)) return false;
  if (is_string($item) && strlen($item)>10000) return false;
 }
 return true;
}
function sameOriginRequest(): bool {
 $site=$_SERVER['HTTP_SEC_FETCH_SITE']??'';
 if ($site!=='' && $site!=='same-origin' && $site!=='none') return false;
 $origin=$_SERVER['HTTP_ORIGIN']??'';
 if ($origin==='') return true; // Some older browsers omit Origin; JSON and Fetch Metadata still apply.
 $host=strtolower((string)(parse_url($origin, PHP_URL_HOST)??''));
 $port=parse_url($origin, PHP_URL_PORT);
 $authority=$host.($port===null?'':':'.$port);
 return $authority!=='' && hash_equals(strtolower((string)($_SERVER['HTTP_HOST']??'')),$authority);
}

try {
 if (($_SERVER['REQUEST_METHOD']??'')!=='POST') reply(405,['ok'=>false]);
 $mediaType=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''),2)[0]));
 if ($mediaType!=='application/json') reply(415,['ok'=>false]);
 if (!sameOriginRequest()) reply(403,['ok'=>false]);
 $raw=file_get_contents('php://input',false,null,0,60001);
 if (!is_string($raw) || strlen($raw)>60000) reply(413,['ok'=>false]);
 try {
  $d=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
 } catch(JsonException $e) { reply(400,['ok'=>false,'error'=>'invalid_json']); }
 if (!is_array($d) || array_is_list($d)) reply(422,['ok'=>false]);
 limit('collect',90);
 if (!validToken($d['visitor']??null) || !validToken($d['session']??null)) reply(422,['ok'=>false]);
 $visitor=$d['visitor']; $session=$d['session'];
 $kind=$d['kind']??null;
 if (!is_string($kind)) reply(422,['ok'=>false]);

 if ($kind==='order') {
  limit('order',10);
  limitWindow('order-ip-15m',35,900,ip());
  if (!validToken($d['request_id']??null) || !validateFields($d['fields']??null)) reply(422,['ok'=>false]);
  $f=$d['fields'];
  foreach (['name','phone','shipper','cargo','origin','destination','shipmentMode'] as $key) {
   if (!is_string($f[$key]??null) || trim($f[$key])==='' || strlen($f[$key])>200)
    reply(422,['ok'=>false,'error'=>'required_fields']);
  }
  if (!in_array($f['shipmentMode'],['Sea Freight - FCL','Sea Freight - LCL','Air Freight','Land Transport'],true)) reply(422,['ok'=>false]);
  if ($f['shipmentMode']==='Sea Freight - FCL'
   && (empty($f['containerSize']) || empty($f['containerType'])
   || !filter_var($f['qty']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))) reply(422,['ok'=>false]);
  if (!empty($f['customsEnabled']) && (empty($f['ccLocation']) || !in_array($f['ccType']??'',['Import','Export'],true))) reply(422,['ok'=>false]);
  if (!empty($f['truckEnabled']) && $f['shipmentMode']!=='Land Transport') {
   $leg=$f['truckLeg']??'';
   if (!in_array($leg,['origin','destination','both'],true)
    || (in_array($leg,['origin','both'],true) && empty($f['truckPickup']))
    || (in_array($leg,['destination','both'],true) && empty($f['truckDelivery']))) reply(422,['ok'=>false]);
  }
  $reference=$d['reference']??'';
  $message=$d['message']??'';
  if (!is_string($reference) || strlen($reference)>64 || !is_string($message) || strlen($message)>20000) reply(422,['ok'=>false]);
  [$country,] = geo();
  query('INSERT INTO orders(request_id,reference,created_at,name,company,phone,service,visitor,country,payload) VALUES(?,?,UTC_TIMESTAMP(),?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE request_id=VALUES(request_id)',[
   $d['request_id'],$reference,$f['name'],$f['shipper'],$f['phone'],$f['shipmentMode'],$visitor,$country,
   json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
  ]);
  reply(200,['ok'=>true]);
 }

 if (!in_array($kind,['pageview','quote_start','whatsapp_open'],true)) reply(422,['ok'=>false]);
 if (preg_match('/bot|crawler|spider|headless/i',(string)($_SERVER['HTTP_USER_AGENT']??''))) reply(200,['ok'=>true]);
 $page=$d['page']??'';
 if (!is_string($page) || strlen($page)>250) reply(422,['ok'=>false]);
 [$country,$city]=geo();
 query('INSERT INTO events(created_at,visitor,session_id,kind,page,ip,country,city) VALUES(UTC_TIMESTAMP(),?,?,?,?,?,?,?)',[
  $visitor,$session,$kind,$page,ip(),$country,$city,
 ]);
 reply(200,['ok'=>true]);
} catch(Throwable $e) {
 error_log('AK collection unavailable: '.get_class($e));
 reply(503,['ok'=>false,'error'=>'unavailable']);
}
