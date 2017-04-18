<?php
require_once(__DIR__ . '/../vendor/autoload.php');

$mqtt = new \Mqtt\Socket\MqttSocketClient();
$mqtt
    ->setClientId('exampleSubscribe')
    ->setKeepAlive(10)
    ->setCleanSession(false)
;

$mqtt->subscribe(
    [
        'x/y' => [
            'qos' => 2,
            'handler' => function (\Mqtt\Socket\MqttPublishPackage $publishPackage) {
                echo sprintf(
                    '%s: %s',
                        $publishPackage->getTopic(),
                        $publishPackage->getPayload()
                    ) . PHP_EOL;
            }
        ]
    ]
);

$mqtt->loop();

