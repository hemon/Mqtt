<?php

namespace Mqtt\Socket;

/**
 * Class MqttPublishPackage
 * @package Mqtt\Socket
 */
class MqttPublishPackage
{

    /** @var string */
    protected $topic;

    /** @var string */
    protected $payload;

    /** @var int */
    protected $qos;

    /** @var callable */
    protected $confirmationCallback;

    /** @var bool */
    protected $confirmed = false;

    public function confirm()
    {
        $callback = $this->getConfirmationCallback();
        $callback();

        $this->confirmed = true;
    }

    /**
     * @return string
     */
    public function getTopic()
    {
        return $this->topic;
    }

    /**
     * @param string $topic
     * @return static
     */
    public function setTopic($topic)
    {
        $this->topic = $topic;
        return $this;
    }

    /**
     * @return string
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * @param string $payload
     * @return static
     */
    public function setPayload($payload)
    {
        $this->payload = $payload;
        return $this;
    }

    /**
     * @return int
     */
    public function getQos()
    {
        return $this->qos;
    }

    /**
     * @param int $qos
     * @return static
     */
    public function setQos($qos)
    {
        $this->qos = $qos;
        return $this;
    }

    /**
     * @return callable
     */
    public function getConfirmationCallback()
    {
        return $this->confirmationCallback;
    }

    /**
     * @param callable $confirmationCallback
     * @return static
     */
    public function setConfirmationCallback($confirmationCallback)
    {
        $this->confirmationCallback = $confirmationCallback;
        return $this;
    }

    /**
     * @return bool
     */
    public function isConfirmed()
    {
        return $this->confirmed;
    }

}