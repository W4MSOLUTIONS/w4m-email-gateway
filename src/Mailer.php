<?php

namespace W4MSolutions\W4mEmailGateway;

use Throwable;
use Yii;
use yii\symfonymailer\Mailer as SymfonyMailer;
use yii\symfonymailer\Message;

/**
 * Mailer adapter that keeps Yii/Symfony compose and render behavior while
 * delivering messages through the W4M email gateway.
 */
class Mailer extends SymfonyMailer
{
    /**
     * @var string Gateway base URL, for example https://gateway.example.com
     */
    public string $gatewayUrl = '';

    /**
     * @var string Gateway API key value.
     */
    public string $gatewayApiKey = '';

    /**
     * @var string Relative path used for the send endpoint.
     */
    public string $sendPath = '/send';

    /**
     * @var string Header name used for authentication.
     */
    public string $authHeaderName = 'Authorization';

    /**
     * @var string Optional auth prefix, for example "Bearer ".
     */
    public string $authHeaderPrefix = '';

    /**
     * @var int Request timeout in seconds.
     */
    public int $requestTimeout = 15;

    /**
     * @var int Total send attempts, including the first attempt.
     */
    public int $maxRetries = 3;

    /**
     * @var int Initial delay before the first retry in seconds.
     */
    public int $retryInitialDelaySeconds = 1;

    /**
     * @var int Additional delay applied after each failed retry in seconds.
     */
    public int $retryDelayIncrementSeconds = 2;

    /**
     * @var callable|null Optional sleep override for tests.
     */
    public $sleepHandler = null;

    /**
     * @var \W4MSolutions\W4mEmailGateway\GatewayClient|null HTTP client used to send gateway requests.
     */
    public ?\W4MSolutions\W4mEmailGateway\GatewayClient $gatewayClient = null;

    /**
     * @var \W4MSolutions\W4mEmailGateway\GatewayPayloadBuilder|null Payload mapper for Message to gateway JSON.
     */
    public ?\W4MSolutions\W4mEmailGateway\GatewayPayloadBuilder $payloadBuilder = null;

    /**
     * Initializes default collaborators when they are not injected.
     */
    public function init()
    {
        parent::init();

        if ($this->payloadBuilder === null) {
            $this->payloadBuilder = new \W4MSolutions\W4mEmailGateway\GatewayPayloadBuilder();
        }

        if ($this->gatewayClient === null) {
            $this->gatewayClient = new \W4MSolutions\W4mEmailGateway\GatewayClient();
        }
    }

    /**
     * Sends a message using the gateway with retry/backoff handling.
     *
     * @param mixed $message Message instance created by the mailer.
     */
    protected function sendMessage($message): bool
    {
        if (!($message instanceof Message)) {
            Yii::error(
                sprintf(
                    'Gateway mailer expects %s, %s received.',
                    Message::class,
                    is_object($message) ? get_class($message) : gettype($message)
                ),
                __METHOD__
            );

            return false;
        }

        if ($this->gatewayUrl === '') {
            Yii::error('Gateway URL is not configured.', __METHOD__);

            return false;
        }

        if ($this->gatewayApiKey === '') {
            Yii::error('Gateway API key is not configured.', __METHOD__);

            return false;
        }

        try {
            $payload = $this->payloadBuilder->build($message);
        } catch (Throwable $exception) {
            Yii::error('Failed to build gateway payload: ' . $exception->getMessage(), __METHOD__);

            return false;
        }

        return $this->sendWithRetry($payload);
    }

    /**
     * Attempts to send payload up to maxRetries times.
     *
     * Cooldown defaults:
     * - retry #1 waits 1 second
     * - retry #2 waits 3 seconds
     */
    protected function sendWithRetry(array $payload): bool
    {
        $maxRetries = max(1, $this->maxRetries);
        $delay = max(0, $this->retryInitialDelaySeconds);
        $increment = max(0, $this->retryDelayIncrementSeconds);

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $result = $this->sendPayload($payload);

            if (($result['ok'] ?? false) === true) {
                return true;
            }

            $this->logFailedAttempt($attempt, $maxRetries, $result);

            if ($attempt < $maxRetries) {
                $this->waitBeforeRetry($delay);
                $delay += $increment;
            }
        }

        return false;
    }

    /**
     * Sends one payload attempt to the gateway.
     */
    protected function sendPayload(array $payload): array
    {
        if ($this->gatewayClient === null) {
            $this->gatewayClient = new \W4MSolutions\W4mEmailGateway\GatewayClient();
        }

        return $this->gatewayClient->postJson(
            $this->buildSendEndpoint(),
            $payload,
            $this->authHeaderName,
            $this->authHeaderPrefix . $this->gatewayApiKey,
            $this->requestTimeout
        );
    }

    /**
     * Waits before the next retry. Override in tests to avoid real sleeping.
     */
    protected function waitBeforeRetry(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if (is_callable($this->sleepHandler)) {
            call_user_func($this->sleepHandler, $seconds);

            return;
        }

        sleep($seconds);
    }

    /**
     * Logs failed send attempts with warning/error severity.
     */
    private function logFailedAttempt(int $attempt, int $maxRetries, array $result): void
    {
        $statusCode = (int) ($result['statusCode'] ?? 0);
        $error = (string) ($result['error'] ?? '');
        $body = (string) ($result['body'] ?? '');

        $message = sprintf(
            'Gateway send attempt %d/%d failed. status=%d error="%s" body="%s"',
            $attempt,
            $maxRetries,
            $statusCode,
            $error,
            $body
        );

        if ($attempt < $maxRetries) {
            Yii::warning($message, __METHOD__);

            return;
        }

        Yii::error($message, __METHOD__);
    }

    /**
     * Builds full send endpoint from base URL and relative path.
     */
    private function buildSendEndpoint(): string
    {
        $baseUrl = rtrim($this->gatewayUrl, '/');
        $path = '/' . ltrim($this->sendPath, '/');

        return $baseUrl . $path;
    }
}
