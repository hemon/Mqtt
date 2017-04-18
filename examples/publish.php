<?php
require_once(__DIR__ . '/../vendor/autoload.php');

$mqtt = new \Mqtt\Socket\MqttSocketClient();
$mqtt
    ->setKeepAlive(10)
    ;

$published = $mqtt->publish('x/y', 'test', 2);

echo $published ? 'Published.' : 'Error!';
echo PHP_EOL;

