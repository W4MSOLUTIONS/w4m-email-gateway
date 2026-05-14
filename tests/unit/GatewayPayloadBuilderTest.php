<?php

namespace W4MSolutions\W4mEmailGateway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use W4MSolutions\W4mEmailGateway\GatewayPayloadBuilder;
use yii\symfonymailer\Message;

class GatewayPayloadBuilderTest extends TestCase
{
    /**
     * @var string[]
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }

        $this->tempFiles = [];
        parent::tearDown();
    }

    public function testBuildUsesHtmlBodyWhenAvailable(): void
    {
        $message = new Message();
        $message
            ->setFrom(['sender@example.com' => 'Sender Name'])
            ->setTo(['receiver@example.com' => 'Receiver'])
            ->setReplyTo(['reply@example.com' => 'Reply'])
            ->setSubject('Gateway Subject')
            ->setTextBody('Plain text body')
            ->setHtmlBody('<p>HTML body</p>');

        $payload = (new GatewayPayloadBuilder())->build($message);

        $this->assertSame(['receiver@example.com'], $payload['to']);
        $this->assertSame('Gateway Subject', $payload['subject']);
        $this->assertSame('<p>HTML body</p>', $payload['body']);
        $this->assertSame('sender@example.com', $payload['from']);
        $this->assertSame('Sender Name', $payload['from_name']);
        $this->assertSame(['reply@example.com'], $payload['reply_to']);
    }

    public function testBuildConvertsAttachmentsAndCidEmbeds(): void
    {
        $inlinePath = $this->createTempFile('inline-content');
        $attachmentPath = $this->createTempFile('attachment-content');

        $message = new Message();
        $message
            ->setFrom(['sender@example.com' => 'Sender Name'])
            ->setTo('receiver@example.com')
            ->setSubject('Message With Attachments')
            ->setHtmlBody('<p>Image: <img src="cid:inline-image.jpg"></p>');

        $message->embed($inlinePath, ['fileName' => 'inline-image.jpg', 'contentType' => 'image/jpeg']);
        $message->attach($attachmentPath, ['fileName' => 'doc.txt', 'contentType' => 'text/plain']);

        $payload = (new GatewayPayloadBuilder())->build($message);

        $this->assertStringContainsString(
            'data:image/jpeg;base64,' . base64_encode('inline-content'),
            $payload['body']
        );
        $this->assertCount(1, $payload['attachments']);
        $this->assertSame('doc.txt', $payload['attachments'][0]['filename']);
        $this->assertSame(base64_encode('attachment-content'), $payload['attachments'][0]['content']);
        $this->assertSame('text/plain', $payload['attachments'][0]['filetype']);
    }

    public function testBuildRequiresRecipients(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $message = new Message();
        $message
            ->setFrom(['sender@example.com' => 'Sender Name'])
            ->setSubject('Missing To')
            ->setTextBody('Body');

        (new GatewayPayloadBuilder())->build($message);
    }

    private function createTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'w4m-mail-');
        if ($path === false) {
            $this->fail('Failed to create temporary file.');
        }

        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
