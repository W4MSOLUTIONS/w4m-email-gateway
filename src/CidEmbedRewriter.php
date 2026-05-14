<?php

namespace W4MSolutions\W4mEmailGateway;

use Symfony\Component\Mime\Part\DataPart;

/**
 * Rewrites inline cid: image references to data URIs for gateway compatibility.
 */
final class CidEmbedRewriter
{
    /**
     * @param DataPart[] $attachments
     * @return array{string, DataPart[]}
     */
    public function rewrite(string $html, array $attachments): array
    {
        $remainingAttachments = [];

        foreach ($attachments as $attachment) {
            if (!$attachment instanceof DataPart) {
                $remainingAttachments[] = $attachment;

                continue;
            }

            if (strtolower((string) $attachment->getDisposition()) !== 'inline') {
                $remainingAttachments[] = $attachment;

                continue;
            }

            $dataUri = $this->buildDataUri($attachment);
            $candidates = $this->buildCidCandidates($attachment);

            $wasReplaced = false;
            foreach ($candidates as $candidate) {
                $needle = 'cid:' . $candidate;
                if (strpos($html, $needle) === false) {
                    continue;
                }

                $html = str_replace($needle, $dataUri, $html);
                $wasReplaced = true;
            }

            if (!$wasReplaced) {
                $remainingAttachments[] = $attachment;
            }
        }

        return [$html, $remainingAttachments];
    }

    /**
     * Builds all candidate CID tokens that can appear in HTML references.
     *
     * @return string[]
     */
    private function buildCidCandidates(DataPart $attachment): array
    {
        $candidates = [];

        $name = $attachment->getName();
        if ($name !== null && $name !== '') {
            $candidates[] = trim($name, '<>');
        }

        $filename = $attachment->getFilename();
        if ($filename !== null && $filename !== '') {
            $candidates[] = trim($filename, '<>');
        }

        if ($attachment->hasContentId()) {
            $contentId = trim($attachment->getContentId(), '<>');
            if ($contentId !== '') {
                $candidates[] = $contentId;
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Builds a data URI from inline attachment data.
     */
    private function buildDataUri(DataPart $attachment): string
    {
        return 'data:'
            . $attachment->getContentType()
            . ';base64,'
            . base64_encode($attachment->getBody());
    }
}
