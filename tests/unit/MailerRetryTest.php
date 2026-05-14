<?php

namespace W4MSolutions\W4mEmailGateway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use W4MSolutions\W4mEmailGateway\Mailer;
use yii\symfonymailer\Message;

class MailerRetryTest extends TestCase
{
    public function testSendMessageRetriesUntilSuccess(): void
    {
        $mailer = $this->createMailerWithResponses([
            ['ok' => false, 'statusCode' => 500, 'body' => 'error-1', 'error' => 'failed'],
            ['ok' => false, 'statusCode' => 503, 'body' => 'error-2', 'error' => 'failed'],
            ['ok' => true, 'statusCode' => 200, 'body' => '{}', 'error' => ''],
        ]);

        $result = $mailer->callSendMessage($this->createMessage());

        $this->assertTrue($result);
        $this->assertSame([1, 3], $mailer->waits);
        $this->assertSame(3, $mailer->sendPayloadCalls);
    }

    public function testSendMessageFailsAfterMaxRetries(): void
    {
        $mailer = $this->createMailerWithResponses([
            ['ok' => false, 'statusCode' => 500, 'body' => 'error-1', 'error' => 'failed'],
            ['ok' => false, 'statusCode' => 502, 'body' => 'error-2', 'error' => 'failed'],
            ['ok' => false, 'statusCode' => 504, 'body' => 'error-3', 'error' => 'failed'],
        ]);

        $result = $mailer->callSendMessage($this->createMessage());

        $this->assertFalse($result);
        $this->assertSame([1, 3], $mailer->waits);
        $this->assertSame(3, $mailer->sendPayloadCalls);
    }

    private function createMailerWithResponses(array $responses): MailerRetryHarness
    {
        $mailer = new MailerRetryHarness();
        $mailer->gatewayUrl = 'https://gateway.local';
        $mailer->gatewayApiKey = 'test-api-key';
        $mailer->maxRetries = 3;
        $mailer->retryInitialDelaySeconds = 1;
        $mailer->retryDelayIncrementSeconds = 2;
        $mailer->setResponses($responses);

        return $mailer;
    }

    private function createMessage(): Message
    {
        $message = new Message();
        $message
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo(['receiver@example.com' => 'Receiver'])
            ->setSubject('Retry Test')
            ->setTextBody('Body');

        return $message;
    }
}

class MailerRetryHarness extends Mailer
{
    /**
     * @var array<int, array{ok: bool, statusCode: int, body: string, error: string}>
     */
    private array $responses = [];

    /**
     * @var int[]
     */
    public array $waits = [];

    public int $sendPayloadCalls = 0;

    /**
     * @param array<int, array{ok: bool, statusCode: int, body: string, error: string}> $responses
     */
    public function setResponses(array $responses): void
    {
        $this->responses = $responses;
    }

    public function callSendMessage(Message $message): bool
    {
        return $this->sendMessage($message);
    }

    protected function sendPayload(array $payload): array
    {
        $this->sendPayloadCalls++;

        if ($this->responses === []) {
            return [
                'ok' => false,
                'statusCode' => 500,
                'body' => '',
                'error' => 'No mocked responses available',
            ];
        }

        return array_shift($this->responses);
    }

    protected function waitBeforeRetry(int $seconds): void
    {
        $this->waits[] = $seconds;
    }
}
