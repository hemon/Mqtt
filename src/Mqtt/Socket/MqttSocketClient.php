<?php

namespace Mqtt\Socket;

use Mqtt\Exception\MqttSocketException;

/**
 * Class MqttSocketClient
 * @package Mqtt\Socket
 */
class MqttSocketClient
{

    /** @var resource */
    protected $socket;

    protected $address = 'localhost';

    protected $port = 1883;

    protected $protocol = 4;

    /** @var array backward compatibility */
    protected $protocols =
        [
            3 => 'MQIsdp',  // v3.1
            4 => 'MQTT'     // v3.1.1
        ];

    private $packageId = 0;

    protected $topics = [];

    /** @var string */
    protected $clientId;

    /** @var int */
    protected $keepAlive = 10;

    /** @var string */
    protected $username = '';

    /** @var string */
    protected $password = '';

    /** @var bool */
    protected $cleanSession = true;

    /** @var bool */
    protected $inLoop = true;

    public function connect()
    {
        if ( $this->isCleanSession() ) {
            $this->socket = fsockopen(gethostbyname($this->address), $this->port, $errno, $errstr, 60);
        } else {
            $this->socket = pfsockopen(gethostbyname($this->address), $this->port, $errno, $errstr, 60);
            if (ftell($this->socket) > 0) {
                return true;
            }
        }
        stream_set_timeout($this->socket, 1);

        $username = $this->getUsername();
        $password = $this->getPassword();

        // protocol name
        $protocol = $this->protocols[$this->protocol];
        // protocol length
        $buffer = $this->calculateMsbLsb(strlen($protocol));
        $buffer .= $protocol;
        // protocol level
        $buffer .= chr($this->protocol);
        // flags
        $buffer .= $this->toBinary(
            sprintf(
                '%s%s%s%s%s%s%s',
                $username ? 1 : 0,                      // User Name Flag
                $password ? 1 : 0,                      // Password Flag
                '0',                                    // Will Retain
                '00',                                   // Will QoS
                '0',                                    // Will Flag
                $this->isCleanSession() ? '1' : '0',    // Clean Session
                '0'                                     // Reserved
            )
        );
        // keep alive
        $buffer .= $this->calculateMsbLsb($this->getKeepAlive());

        // clientId
        $clientId = $this->getClientId();
        if (!$clientId && $this->isCleanSession() && $this->getProtocol() < 4) {
            $clientId = $this->generateClientId();
        }
        $buffer .= $this->calculateMsbLsb(strlen($clientId));
        $buffer .= $clientId;

        // username
        if ($username) {
            $buffer .= $this->calculateMsbLsb(strlen($username));
            $buffer .= $username;
        }

        // password
        if ($password) {
            $buffer .= $this->calculateMsbLsb(strlen($password));
            $buffer .= $password;
        }

        $head = hex2bin('10') . $this->calculateLength(strlen($buffer));
        $this->write($head);
        $this->write($buffer);

        $response = $this->read(4, true);

        $success = false;
        if (strpos(bin2hex($response), '2002') === 0) {
            $errorCode = ord($response[strlen($response) - 1]);
            $errorMessage = '';
            switch ($errorCode) {
                case 0:
                    // CONNECT success.
                    break;
                case 1:
                    $errorMessage = 'Connection Refused, unacceptable protocol version';
                    break;
                case 2:
                    $errorMessage = 'Connection Refused, identifier rejected';
                    break;
                case 3:
                    $errorMessage = 'Connection Refused, Server unavailable';
                    break;
                case 4:
                    $errorMessage = 'Connection Refused, bad user name or password';
                    break;
                case 5:
                    $errorMessage = 'Connection Refused, not authorized';
                    break;
                default:
                    $errorMessage = 'Connect error.';
            }
            $success = !$errorMessage;

            if ($errorMessage) {
                throw new MqttSocketException('MQTT connect error: ' . $errorMessage);
            }
        }

        return $success;
    }

    public function publish($topic, $message, $qos = 0, $retain = false)
    {
        $buffer = $this->calculateMsbLsb(strlen($topic));
        $buffer .= $topic;

        if ($qos > 0) {
            $buffer .= $this->calculateMsbLsb($this->getNextPackageId()); // package number
        }
        $buffer .= $message;

        /*
         * 0011 - package 3
         * flat bits:
         * 1st: DUP (duplicate)
         * 2nd & 3rd: qos
         * 4th: retain
         */
        $binary = sprintf(
            '00110%s%s%s',
            $qos == 2 ? 1 : 0,
            $qos == 1 ? 1 : 0,
            $retain ? 1 : 0
        );
        // convert binary string to binary
        $head = $this->toBinary($binary) . $this->calculateLength(strlen($buffer));
        $this->write($head);
        $written = $this->write($buffer);

        if ($qos == 0) {
            return true;
        }

        while ($qos-- > 0) {
            $this->handlePackage();
        }
        return true;
    }

    protected function checkTopicConfig($config = array())
    {
        if (!is_array($config)) {
            throw new \InvalidArgumentException(
                'Topic config must be an array.'
            );
        }

        if (!isset($config['qos']) || !in_array($config['qos'], [0,1,2])) {
            throw new \InvalidArgumentException(
                'Topic qos must be between 0 and 2.'
            );
        }

        if (!isset($config['handler']) || !is_callable($config['handler'])) {
            throw new \InvalidArgumentException(
                'Topic must have a callable handler.'
            );
        }
    }

    public function subscribe(array $topics = array())
    {
        $buffer = hex2bin('0001');

        foreach ($topics as $topic => $config) {
            $this->checkTopicConfig($config);

            $buffer .= $this->calculateMsbLsb(strlen($topic));  // set topic length
            $buffer .= $topic;                                  // set topic
            $buffer .= chr($config['qos']);                     // set qos
        }

        $head = hex2bin('82') . $this->calculateLength(strlen($buffer));

        $this->write($head);
        $this->write($buffer);

        $this->setTopics(
            array_merge(
                $this->getTopics(),
                $topics
            )
        );

        $this->handlePackage();
    }

    public function unSubscribe($topics)
    {
        $buffer = hex2bin('0001');

        foreach ($topics as $topic) {
            $buffer .= $this->calculateMsbLsb(strlen($topic));
            $buffer .= $topic;
        }

        $head = hex2bin('a2') . $this->calculateLength(strlen($buffer));

        $this->write($head);
        $this->write($buffer);

        $this->handlePackage();
    }

    public function disconnect()
    {
        if ($this->isSocketOpen()) {
            $this->write(hex2bin('e000'));
            $this->close();
        }
    }

    public function ping()
    {
        $head = hex2bin('c000');
        $this->write($head);
    }

    public function loop()
    {
        $lastPing = 0;
        $this->inLoop = true;
        while ($this->inLoop) {
            $this->handlePackage();

            if (microtime(true) - $lastPing >= floor($this->getKeepAlive() / 2) - 1) {
                $lastPing = microtime(true);
                $this->ping();
            }

        }
    }

    /**
     * @return bool
     */
    protected function isSocketOpen()
    {
        return is_resource($this->socket);
    }

    protected function autoConnect()
    {
        if (!$this->isSocketOpen()) {
            $this->connect();

            if ($this->getTopics()) {
                $this->subscribe($this->getTopics());
            }
        }
    }

    /**
     * @param $buffer
     * @return int
     * @throws MqttSocketException
     */
    protected function write($buffer)
    {
        $this->autoConnect();

        $written = @fwrite($this->socket, $buffer, strlen($buffer));

        if ($written != strlen($buffer)) {
            throw new MqttSocketException(
                sprintf(
                    'Tried to write %d bytes, but %d bytes written. Buffer: %s.',
                    strlen($buffer),
                    $written,
                    bin2hex($buffer)
                ),
                102
            );
        }

        return $written;
    }

    protected function read($length = 256, $noRepeat = false)
    {
        $this->autoConnect();

        $string = "";
        $togo = $length;

        if (!$noRepeat) {
            return fread($this->socket, $togo);
        }

        while (!feof($this->socket) && $togo > 0) {
            $fread = fread($this->socket, $togo);
            $string .= $fread;
            $togo = $length - strlen($string);
        }

        return $string;
    }

    protected function close()
    {
        fclose($this->socket);
    }

    /**
     * @param string $binaryString
     * @return string
     */
    protected function toBinary($binaryString)
    {
        $hex = dechex(bindec($binaryString));
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }

        return hex2bin($hex);
    }

    /**
     * @return int
     */
    protected function getRemainingLength()
    {
        $multiplier = 1;
        $value = 0;
        do {
            $read = $this->read(1);
            $digit = ord($read);
            $value += ($digit & 127) * $multiplier;
            $multiplier *= 128;
        } while (($digit & 128) != 0);

        return $value;
    }

    protected function calculateLength($len)
    {
        $string = "";
        do {
            $digit = $len % 128;
            $len = $len >> 7;
            // if there are more digits to encode, set the top bit of this digit
            if ($len > 0)
                $digit = ($digit | 0x80);
            $string .= chr($digit);
        } while ($len > 0);
        return $string;
    }

    protected function calculateMsbLsb($value)
    {
        $lsb = chr($value & 0xFF);
        $msb = chr(($value >> 8) & 0xFF);

        return $msb . $lsb;
    }

    protected function handlePackage()
    {
        $package = $this->read(1);

        if (!bin2hex($package)) {
            return false;
        }

        $command = bin2hex($package);

        $totalLength = $this->getRemainingLength();

        // PUBLISH
        if (strpos($command, '3') === 0) {
            $flags = $command[1];
            $qos = (hexdec($command) & 6) >> 1;

            $topicLength = hexdec(bin2hex($this->read(2)));
            $topic = $this->read($topicLength);

            $packetId = null;
            $shift = 0;
            // qos 1 and 2 have packetId
            if ($qos > 0) {
                $packetId = $this->read(2);
                $shift = 2;
            }

            $payloadSize = $totalLength - $topicLength - 2 - $shift;

            $payload = $payloadSize > 0 ? $this->read($payloadSize, true) : null;

            $topics = $this->getTopics();
            if (isset($topics[$topic]['handler'])) {
                $handler = $topics[$topic]['handler'];

                $publishPackage = new MqttPublishPackage();
                $publishPackage
                    ->setTopic($topic)
                    ->setPayload($payload)
                    ->setQos($qos)
                    ->setConfirmationCallback(
                        function () use ($topic, $packetId, $qos) {
                            $this->handlePublishReceived($topic, $packetId, $qos);
                        }
                    );

                $this->callHandler($handler, $publishPackage);

                if (!$publishPackage->isConfirmed()) {
                    $publishPackage->confirm();
                }
            } else {
                // unSubscribe automatically
                $this->unSubscribe([$topic]);
            }
        } else if ($command === 'd0') {
            // PING
        } else if (strpos($command, '6') === 0) {
            // PUBREL
            $packetId = $this->read(2);

            $this->write(hex2bin('7002') . $packetId);
        } else if (strpos($command, '9') === 0) {
            // SUBACK
            $packetId = $this->read(2);

            $data = $this->read($totalLength - 2);

        } else if (strpos($command, 'b0') === 0) {
            // UNSUBACK
            $data = $this->read($totalLength);

        } else if (strpos($command, '40') === 0) {
            // PUBACK
            $packetId = $this->read(2);
        } else if (strpos($command, '50') === 0) {
            // PUBREC
            $head = hex2bin('6202');
            $buffer = $this->read(2);

            // PUBREL
            $this->write($head);
            $this->write($buffer);
        } else if (strpos($command, '70') === 0) {
            // PUBCOMP
            $packetId = $this->read(2);
        } else {
            throw new MqttSocketException(
                sprintf(
                    'Command "%s" not recognized. Please report to developer.',
                    $command
                )
            );
        }
    }

    /**
     * override this method to send different parameters to your handler
     *
     * @param callable $handler
     * @param MqttPublishPackage $publishPackage
     */
    protected function callHandler(callable $handler, MqttPublishPackage $publishPackage)
    {
        $handler($publishPackage);
    }

    protected function handlePublishReceived($topic, $packetId, $qos)
    {
        if ($qos == 1) {
            $this->write(hex2bin('4002') . $packetId);
        } else if ($qos == 2) {
            $this->write(hex2bin('5002') . $packetId);
            $this->handlePackage();
        }
    }

    /**
     * @return int
     */
    protected function getNextPackageId()
    {
        $packageId = ++$this->packageId;

        if ($packageId > 65535) {
            $packageId = 1;
        }

        $this->packageId = $packageId;

        return $this->packageId;
    }

    /**
     * @return string
     */
    public function generateClientId()
    {
        return uniqid('generated_');
    }

    public function __destruct()
    {
        if ($this->socket) {
            $this->close();
        }
    }

    /**
     * @return string
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * @param string $address
     * @return static
     */
    public function setAddress($address)
    {
        $this->address = $address;
        return $this;
    }

    /**
     * @return int
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * @param int $port
     * @return static
     */
    public function setPort($port)
    {
        $this->port = $port;
        return $this;
    }

    /**
     * @return int
     */
    public function getProtocol()
    {
        return $this->protocol;
    }

    /**
     * @param int $protocol
     * @return static
     */
    public function setProtocol($protocol)
    {
        if (!in_array($protocol, array_keys($this->protocols))) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid protocol "%s". Available: %s.',
                    $protocol,
                    implode(', ', array_keys($this->protocols))
                )
            );
        }
        $this->protocol = $protocol;
        return $this;
    }

    /**
     * @return array
     */
    public function getTopics()
    {
        return $this->topics;
    }

    /**
     * @param array $topics
     * @return static
     */
    protected function setTopics($topics)
    {
        $this->topics = $topics;
        return $this;
    }

    /**
     * @return string
     */
    public function getClientId()
    {
        return $this->clientId;
    }

    /**
     * @param string $clientId
     * @return static
     */
    public function setClientId($clientId)
    {
        $this->clientId = $clientId;
        return $this;
    }

    /**
     * @return int
     */
    public function getKeepAlive()
    {
        return $this->keepAlive;
    }

    /**
     * @param int $keepAlive
     * @return static
     */
    public function setKeepAlive($keepAlive)
    {
        $this->keepAlive = $keepAlive;
        return $this;
    }

    /**
     * @return string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * @param string $username
     * @return static
     */
    public function setUsername($username)
    {
        $this->username = $username;
        return $this;
    }

    /**
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * @param string $password
     * @return static
     */
    public function setPassword($password)
    {
        $this->password = $password;
        return $this;
    }

    /**
     * @return bool
     */
    public function isCleanSession()
    {
        return $this->cleanSession;
    }

    /**
     * @param bool $cleanSession
     * @return static
     */
    public function setCleanSession($cleanSession)
    {
        $this->cleanSession = $cleanSession;
        return $this;
    }

    /**
     * @return static
     */
    public function exitLoop()
    {
        $this->inLoop = false;
        return $this;
    }

}
