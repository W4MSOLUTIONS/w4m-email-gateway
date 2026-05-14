# W4M Email Gateway Mailer

Yii2 mailer adapter for sending emails through the W4M Email Gateway API.

## Package

- Name: `w4msolutions/w4m-email-gateway`
- Type: `yii2-extension`
- Namespace: `W4MSolutions\\W4mEmailGateway`

## Features

- Drop-in compatible mailer component for current Yii2 mail usage.
- Keeps Yii compose/render/layout flow by extending `yii\\symfonymailer\\Mailer`.
- Supports existing `yii\\symfonymailer\\Message` calls used across the app.
- Sends payload to gateway `/send` endpoint with API key authentication.
- Converts regular attachments to gateway `attachments[]` payload.
- Rewrites inline CID embeds (for example `cid:image.jpg`) into `data:` URIs.
- Retries failed sends with incremental cooldown (default: 3 attempts, waits 1s then 3s).

## Class and Function Reference

### `W4MSolutions\\W4mEmailGateway\\Mailer`

Main Yii mailer component.

- `init()` initializes default collaborators (`GatewayPayloadBuilder`, `GatewayClient`).
- `sendMessage($message)` validates config, maps payload, and executes retry-aware delivery.
- `sendWithRetry(array $payload)` runs delivery attempts with incremental cooldown.
- `sendPayload(array $payload)` performs one HTTP call through `GatewayClient`.
- `waitBeforeRetry(int $seconds)` sleeps between retries (override-friendly for tests).

### `W4MSolutions\\W4mEmailGateway\\GatewayPayloadBuilder`

Converts `yii\\symfonymailer\\Message` into gateway JSON payload.

- `build(Message $message)` maps recipients, subject/body, from/from_name, reply_to, attachments.
- `normalizeAddressList(...)` and `extractPrimaryAddressAndName(...)` standardize address data.
- `buildAttachmentPayload(...)` base64-encodes attachment data for gateway transport.

### `W4MSolutions\\W4mEmailGateway\\CidEmbedRewriter`

Makes inline embedded images compatible with gateway body-only HTML transport.

- `rewrite(string $html, array $attachments)` replaces `cid:` references with `data:` URIs.

### `W4MSolutions\\W4mEmailGateway\\GatewayClient`

HTTP transport client.

- `postJson(...)` sends request using cURL (preferred) or stream fallback.

## Configuration

This is the full configuration array for the mailer component with all options:

```php
'components' => [
    'mailer' => [
        'class' => \W4MSolutions\W4mEmailGateway\Mailer::class,
        'viewPath' => '@common/mail',
        'gatewayUrl' => 'http://mail-gateway.local',
        'gatewayApiKey' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'authHeaderName' => 'Authorization', // Optional, default: 'Authorization'
        'authHeaderPrefix' => '', // Optional, default: ''
        'sendPath' => '/send', // Optional, default: '/send'
        'requestTimeout' => 15, // Optional, default: 15 seconds
        'maxRetries' => 3, // Optional, default: 3
        'retryInitialDelaySeconds' => 1, // Optional, default: 1
        'retryDelayIncrementSeconds' => 2, // Optional, default: 2
        'useFileTransport' => true, // default: true
    ],
],
```

## Retry Behavior

Default retry flow is:

1. Attempt 1 immediately.
2. If it fails, wait 1 second.
3. Attempt 2.
4. If it fails, wait 3 seconds (1 + 2).
5. Attempt 3.

You can tune this with:

- `maxRetries`
- `retryInitialDelaySeconds`
- `retryDelayIncrementSeconds`

## Running Tests (Docker)

Run tests in a dedicated container from this package folder.

### Required Tools

- Docker Engine
- Docker Compose v2 (`docker compose`)

All commands below are executed from the package root.

### Run All Package Tests

```bash
docker compose run --rm tests
```

### Run One Test File (Optional)

```bash
docker compose run --rm tests sh docker/run-tests.sh tests/unit/MailerRetryTest.php
```

### Run One Test Method (Optional)

```bash
docker compose run --rm tests sh docker/run-tests.sh tests/unit/MailerRetryTest.php --filter testSendMessageRetriesUntilSuccess
```

### Build or Rebuild the Test Image (Optional)

```bash
docker compose build
```

### Open a Shell in the Test Container (Optional)

```bash
docker compose run --rm tests bash
```

### Notes About the Standalone Setup

- `docker-compose.yml` and `Dockerfile` live inside this package and do not depend on the parent project.
- `docker/run-tests.sh` installs Composer dependencies automatically when `vendor/autoload.php` is missing.
- A Docker named volume (`composer-cache`) is used to speed up repeated Composer installs.

## Notes

- The gateway schema documents `to`, `cc`, `bcc`, `subject`, `body`, `attachments`, `from_name`, and `reply_to`.
- This adapter also forwards a `from` field when available.
- If `useFileTransport` is true, Yii saves `.eml` files and skips gateway delivery.
