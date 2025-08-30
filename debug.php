<?php
require 'vendor/autoload.php';
 = new App\Kernel('test', true);
 = Symfony\Component\HttpFoundation\Request::create('/ticketsystem/save','POST',[
  'name'=>'debugTS',
  'type'=>'',
  'url'=>'',
  'ticketUrl'=>'',
  'login'=>'',
  'password'=>'',
  'publicKey'=>'',
  'privateKey'=>''
]);
 = ->handle();
echo STATUS=.->getStatusCode().n;
echo BODY=.->getContent().n;
->terminate(,);
