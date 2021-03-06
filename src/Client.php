<?php

/*
 * The MIT License
 *
 * Copyright 2016 Zer0.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace ByZer0\SmsAssistantBy;

use ByZer0\SmsAssistantBy\Exceptions\Exception;
use ByZer0\SmsAssistantBy\Exceptions\AuthentificationException;
use ByZer0\SmsAssistantBy\Http\ClientInterface as HttpClientInterface;

/**
 * sms-assistent.by HTTP API client class.
 *
 * @author Zer0
 */
class Client
{
    /**
     * Base API URL.
     *
     * @var string
     */
    protected $baseUrl = 'https://userarea.sms-assistent.by/api/v1/';

    /**
     * HTTP client instance which will actually perform requests.
     *
     * @var HttpClientInterface
     */
    protected $client;

    /**
     * Your sms-assistent.by username, need for API authorization.
     *
     * @var string
     */
    protected $username;

    /**
     * Your sms-assistent.by access token, need for API authorization.
     * Either token or password must be set.
     *
     * @var string
     */
    protected $token;

    /**
     * Your sms-assistent.by account password, need for API authorization.
     * Either token or password must be set.
     *
     * @var string
     */
    protected $password;

    /**
     * Sender name. Messages will be sent from this name. It must be one of available
     * to your account senders.
     *
     * @var string
     *
     * @link http://help.sms-assistent.by/termini-i-opredeleniya/otpravitel-soobscheniya/
     */
    protected $sender;

    /**
     * Construct instance of Client, underlying HTTP client instance must be set.
     *
     * @param HttpClientInterface $httpClient
     */
    public function __construct($httpClient)
    {
        if (empty($httpClient)) {
            throw new Exception('HTTP client instance must be set.');
        }

        $this->client = $httpClient;
    }

    /**
     * Make absolute API endpoint URL from relative.
     *
     * @param string $uri
     *
     * @return string
     */
    protected function getEndpointUrl($uri)
    {
        return $this->baseUrl.$uri;
    }

    /**
     * Check if authorization data is set.
     *
     * @throws \Exception
     */
    protected function checkAuthorizationData()
    {
        if (empty($this->username)) {
            throw new AuthentificationException('Username cannot be empty.');
        }

        if (empty($this->token) && empty($this->password)) {
            throw new AuthentificationException('Either token or account password must be set.');
        }
    }

    /**
     * Add authorization data to request data and/or headers.
     *
     * @param array $data
     * @param array $headers
     */
    protected function buildAuthorizationData(&$data, &$headers)
    {
        $data['user'] = $this->username;
        if ($this->token) {
            $headers['requestAuthToken'] = $this->token;
        } else {
            $data['password'] = $this->password;
        }
    }

    /**
     * Retreive current user balance status. Returns available amount of credits.
     *
     * @throws Exception
     *
     * @return float
     */
    public function getBalance()
    {
        $this->checkAuthorizationData();

        $data = $headers = [];
        $this->buildAuthorizationData($data, $headers);
        $response = $this->client->get($this->getEndpointUrl('credits/plain'), $data, $headers);
        $balance = floatval($response);
        if ($balance >= 0) {
            return $balance;
        } else {
            Exception::raiseFromCode($balance);
        }
    }

    /**
     * Send single message.
     *
     * @param string    $phone  Recipient phone number.
     * @param string    $text   Message text.
     * @param \DateTime $time   Time when send message. Optional, only if message delivery must be delayed.
     * @param string    $sender Sender name, default internal sender name will be used if empty.
     *
     * @return bool
     */
    public function sendMessage($phone, $text, $time = null, $sender = null)
    {
        $this->checkAuthorizationData();

        $data = [
            'user'      => $this->username,
            'recipient' => $phone,
            'message'   => $text,
            'sender'    => $sender ?: $this->sender,
        ];
        if (!is_null($time)) {
            $data['date_send'] = $time->format('YmdHi');
        }
        $headers = [];
        $this->buildAuthorizationData($data, $headers);
        $response = $this->client->get($this->getEndpointUrl('send_sms/plain'), $data, $headers);
        $code = intval($response);
        if ($code < 0) {
            Exception::raiseFromCode($code);
        } else {
            return $code;
        }
    }

    /**
     * Send multiple messages.
     *
     * Every message field (if presented) will override value from $default.
     * Only phone is required in every message. Each message can have following fields:
     *
     * - phone : (required). Phone number of recipient.
     * - text: (optional). Override default message text for this message with custom text.
     * - sender: (optional). Override default sender name for this message with custom name.
     *
     * Default message can have following fields (all fields are optional):
     *
     * - text: Common text for all messages.
     * - sender: Common sender name for all messages.
     *
     * @param array     $messages Array of messages.
     * @param array     $default  Default message config.
     * @param \DateTime $time     Time when send message. Optional, only if messages delivery must be delayed.
     *
     * @return bool
     */
    public function sendMessages($messages, $default = [], $time = null)
    {
        $this->checkAuthorizationData();

        $data = '<?xml version="1.0" encoding="utf-8" ?>';
        $attributes = "login=\"{$this->username}\"";
        if (isset($time)) {
            $attributes .= " date_send=\"{$time->format('YmdHi')}\"";
        }
        $this->token ?: $attributes .= " password=\"{$this->password}\"";
        $data .= "<package $attributes><message>";

        $data .= $this->makeDefaultMessageXml($default);

        foreach ($messages as $message) {
            $data .= $this->makeMessageXml($message);
        }
        $data .= '</message></package>';

        $headers = $this->token ? ['requestAuthToken' => $this->token] : [];

        return $this->client->postXml($this->getEndpointUrl('xml'), $data, $headers);
    }

    /**
     * Convert default message parameters to XML string.
     *
     * @param array $message
     *
     * @return string
     */
    protected function makeDefaultMessageXml($message)
    {
        $attributes = '';
        if (isset($message['sender'])) {
            $attributes .= " sender=\"{$message['sender']}\"";
        } else {
            $attributes .= " sender=\"{$this->sender}\"";
        }
        $text = isset($message['text']) ? $message['text'] : '';

        return "<default$attributes>$text</default>";
    }

    /**
     * Convert message parameters to XML string.
     *
     * @param array $message
     *
     * @return string
     */
    protected function makeMessageXml($message)
    {
        $text = isset($message['text']) ? $message['text'] : '';
        $attributes = '';
        if (isset($message['sender'])) {
            $attributes .= " sender=\"{$message['sender']}\"";
        }

        return "<msg recipient=\"{$message['phone']}\"$attributes>$text</msg>";
    }

    /**
     * Change username for API requests.
     *
     * @param string $username
     *
     * @return $this
     */
    public function setUsername($username)
    {
        $this->username = $username;

        return $this;
    }

    /**
     * Change token for API requests.
     *
     * @param string $token
     *
     * @return $this
     */
    public function setToken($token)
    {
        $this->token = $token;

        return $this;
    }

    /**
     * Change password for API requests.
     *
     * @param string $password
     *
     * @return $this
     */
    public function setPassword($password)
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Change sender name.
     *
     * @param string $sender
     *
     * @return $this
     */
    public function setSender($sender)
    {
        $this->sender = $sender;

        return $this;
    }

    /**
     * Change base API URL, can be used for testing purposes.
     *
     * @param string $url
     *
     * @return $this
     */
    public function setBaseUrl($url)
    {
        if ($url[strlen($url) - 1] !== '/') {
            $url .= '/';
        }
        $this->baseUrl = $url;

        return $this;
    }
}
