<?php
require_once(__DIR__ . '/../vendor/autoload.php');

$mqtt = new \Mqtt\Socket\MqttSocketClient();

$mqtt->subscribe(
    [
        'x/y' => [
            'qos' => 2,
            'handler' => function (\Mqtt\Socket\MqttPublishPackage $publishPackage) use ($mqtt) {
                $msg = sprintf(
                        '%s: %s',
                        $publishPackage->getTopic(),
                        $publishPackage->getPayload()
                    ) . PHP_EOL;

                echo $msg;

                $mqtt->publish(
                    'y/x',
                    $msg,
                    2
                );
            }
        ]

    ]
);

$mqtt->loop();

