<?php

namespace W4MSolutions\W4mEmailGateway;

/**
 * Lightweight HTTP client for posting JSON payloads to the gateway.
 */
final class GatewayClient
{
    /**
     * Sends a JSON payload to the provided URL.
     *
     * @return array{ok: bool, statusCode: int, body: string, error: string}
     */
    public function postJson(
        string $url,
        array $payload,
        string $authHeaderName,
        string $authHeaderValue,
        int $timeout = 15
    ): array {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return $this->buildResult(false, 0, '', 'Failed to encode payload as JSON.');
        }

        if (function_exists('curl_init')) {
            return $this->postJsonWithCurl($url, $json, $authHeaderName, $authHeaderValue, $timeout);
        }

        return $this->postJsonWithStreams($url, $json, $authHeaderName, $authHeaderValue, $timeout);
    }

    /**
     * Sends a JSON payload using cURL.
     *
     * @return array{ok: bool, statusCode: int, body: string, error: string}
     */
    private function postJsonWithCurl(
        string $url,
        string $json,
        string $authHeaderName,
        string $authHeaderValue,
        int $timeout
    ): array {
        $handle = curl_init($url);
        if ($handle === false) {
            return $this->buildResult(false, 0, '', 'Failed to initialize cURL handle.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $this->createHeaders($authHeaderName, $authHeaderValue),
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
        ]);

        $body = curl_exec($handle);
        $error = '';
        if ($body === false) {
            $error = curl_error($handle);
            $body = '';
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $isSuccessful = $error === '' && $statusCode >= 200 && $statusCode < 300;

        return $this->buildResult($isSuccessful, $statusCode, (string) $body, $error);
    }

    /**
     * Sends a JSON payload using PHP stream contexts when cURL is unavailable.
     *
     * @return array{ok: bool, statusCode: int, body: string, error: string}
     */
    private function postJsonWithStreams(
        string $url,
        string $json,
        string $authHeaderName,
        string $authHeaderValue,
        int $timeout
    ): array {
        $headers = $this->createHeaders($authHeaderName, $authHeaderValue);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $json,
                'ignore_errors' => true,
                'timeout' => $timeout,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $error = '';
        if ($body === false) {
            $body = '';
            $error = 'HTTP stream request failed.';
        }

        $statusLine = null;
        if (isset($http_response_header[0])) {
            $statusLine = $http_response_header[0];
        }

        $statusCode = $this->parseStatusCode($statusLine);
        $isSuccessful = $error === '' && $statusCode >= 200 && $statusCode < 300;

        return $this->buildResult($isSuccessful, $statusCode, $body, $error);
    }

    /**
     * Creates request headers for JSON gateway requests.
     *
     * @return string[]
     */
    private function createHeaders(string $authHeaderName, string $authHeaderValue): array
    {
        $headers = [
            'Content-Type: application/json',
        ];

        if ($authHeaderValue !== '') {
            $headers[] = $authHeaderName . ': ' . $authHeaderValue;
        }

        return $headers;
    }

    /**
     * Parses an HTTP status code from response status line.
     */
    private function parseStatusCode(?string $statusLine): int
    {
        if ($statusLine === null) {
            return 0;
        }

        if (preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $statusLine, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Builds normalized result payload for caller handling.
     *
     * @return array{ok: bool, statusCode: int, body: string, error: string}
     */
    private function buildResult(bool $ok, int $statusCode, string $body, string $error): array
    {
        return [
            'ok' => $ok,
            'statusCode' => $statusCode,
            'body' => $body,
            'error' => $error,
        ];
    }
}
