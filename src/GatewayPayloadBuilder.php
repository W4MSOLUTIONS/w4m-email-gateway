<?php

namespace W4MSolutions\W4mEmailGateway;

use InvalidArgumentException;
use Symfony\Component\Mime\Part\DataPart;
use yii\symfonymailer\Message;

/**
 * Maps yii\symfonymailer\Message into the gateway send payload format.
 */
final class GatewayPayloadBuilder
{
    /**
     * @var \W4MSolutions\W4mEmailGateway\CidEmbedRewriter Rewrites cid: references into data URIs.
     */
    private \W4MSolutions\W4mEmailGateway\CidEmbedRewriter $cidEmbedRewriter;

    /**
     * @param \W4MSolutions\W4mEmailGateway\CidEmbedRewriter|null $cidEmbedRewriter Embed rewriter instance.
     */
    public function __construct(?\W4MSolutions\W4mEmailGateway\CidEmbedRewriter $cidEmbedRewriter = null)
    {
        $this->cidEmbedRewriter = $cidEmbedRewriter
            ?? new \W4MSolutions\W4mEmailGateway\CidEmbedRewriter();
    }

    /**
     * Builds gateway payload from a Yii Symfony message.
     *
     * @return array<string, mixed>
     */
    public function build(Message $message): array
    {
        $to = $this->normalizeAddressList($message->getTo());
        if ($to === []) {
            throw new InvalidArgumentException('Message has no recipients.');
        }

        $email = $message->getSymfonyEmail();
        $htmlBody = $this->bodyToString($email->getHtmlBody());
        $textBody = $this->bodyToString($email->getTextBody());

        $attachments = $email->getAttachments();
        if ($htmlBody !== '' && $attachments !== []) {
            [$htmlBody, $attachments] = $this->cidEmbedRewriter->rewrite($htmlBody, $attachments);
        }

        $payload = [
            'to' => $to,
            'subject' => (string) $message->getSubject(),
            'body' => ($htmlBody !== '' ? $htmlBody : $textBody),
        ];

        [$from, $fromName] = $this->extractPrimaryAddressAndName($message->getFrom());
        if ($from !== '') {
            $payload['from'] = $from;
        }

        if ($fromName !== '') {
            $payload['from_name'] = $fromName;
        }

        $cc = $this->normalizeAddressList($message->getCc());
        if ($cc !== []) {
            $payload['cc'] = $cc;
        }

        $bcc = $this->normalizeAddressList($message->getBcc());
        if ($bcc !== []) {
            $payload['bcc'] = $bcc;
        }

        $replyTo = $this->normalizeAddressList($message->getReplyTo());
        if ($replyTo !== []) {
            $payload['reply_to'] = $replyTo;
        }

        $attachmentPayload = $this->buildAttachmentPayload($attachments);
        if ($attachmentPayload !== []) {
            $payload['attachments'] = $attachmentPayload;
        }

        return $payload;
    }

    /**
     * @param string|array $addresses
     * @return string[]
     */
    private function normalizeAddressList($addresses): array
    {
        if (is_string($addresses)) {
            $email = $this->extractEmailFromString($addresses);

            return $email === '' ? [] : [$email];
        }

        if (!is_array($addresses)) {
            return [];
        }

        $normalized = [];
        foreach ($addresses as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value)) {
                    continue;
                }

                $email = $this->extractEmailFromString($value);
            } else {
                $email = $this->extractEmailFromString((string) $key);
            }

            if ($email !== '') {
                $normalized[$email] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * @param string|array $from
     * @return array{string, string}
     */
    private function extractPrimaryAddressAndName($from): array
    {
        if (is_string($from)) {
            return [$this->extractEmailFromString($from), ''];
        }

        if (!is_array($from)) {
            return ['', ''];
        }

        foreach ($from as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value)) {
                    continue;
                }

                return [$this->extractEmailFromString($value), ''];
            }

            $address = $this->extractEmailFromString((string) $key);
            $name = is_string($value) ? $value : '';

            return [$address, $name];
        }

        return ['', ''];
    }

    /**
     * Extracts pure email from plain or display-name formatted input.
     */
    private function extractEmailFromString(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/<([^>]+)>/', $value, $matches) === 1) {
            return trim($matches[1]);
        }

        return $value;
    }

    /**
     * @param DataPart[] $attachments
        * @return array<int, array{filename: string, content: string, filetype?: string}>
     */
    private function buildAttachmentPayload(array $attachments): array
    {
        $payload = [];
        $index = 1;

        foreach ($attachments as $attachment) {
            if (!$attachment instanceof DataPart) {
                continue;
            }

            $filename = $attachment->getFilename();
            if ($filename === null || $filename === '') {
                $filename = $attachment->getName() ?: ('attachment-' . $index);
            }

            $item = [
                'filename' => $filename,
                'content' => base64_encode($attachment->getBody()),
            ];

            $contentType = $attachment->getContentType();
            if ($contentType !== '') {
                $item['filetype'] = $contentType;
            }

            $payload[] = $item;
            $index++;
        }

        return $payload;
    }

    /**
     * @param mixed $body
     */
    private function bodyToString($body): string
    {
        if ($body === null) {
            return '';
        }

        if (is_string($body)) {
            return $body;
        }

        if (is_resource($body)) {
            $metadata = stream_get_meta_data($body);
            if (!empty($metadata['seekable'])) {
                rewind($body);
            }

            $content = stream_get_contents($body);

            return $content === false ? '' : $content;
        }

        return (string) $body;
    }
}
