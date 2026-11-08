# Porting this API to plain PHP

This document describes how to recreate **alew-virify** without Laravel. The behavior to match is a single endpoint:

`POST /api/verifications`

---

## What the API does

1. Authenticates with `Authorization: Bearer <API_BEARER_TOKEN>`.
2. Validates JSON body: `type` (`cbe` | `telebirr`) and `transactionId` (string, max 255).
3. Calls the bank’s public receipt endpoint.
4. Returns normalized fields: `creditParty`, `debitParty`, `date`, `amount`.

No database is required for verification itself (only optional file-based rate limiting).

---

## Laravel → plain PHP map

| Laravel | Plain PHP equivalent |
|--------|----------------------|
| `routes/api.php` | Router in `public/index.php` (or `src/Router.php`) |
| `AuthenticateApiBearer` | `Auth::requireBearer($config)` |
| `throttle:verifications` | `RateLimiter` using files under `storage/rate-limit/` |
| `SecurityHeaders` middleware | `header(...)` before output |
| `StoreVerificationRequest` | Manual validation function |
| `VerificationType` enum | `const TYPE_CBE = 'cbe'` or PHP 8.1 `enum` |
| `VerificationService` | Same class, no container (construct clients manually) |
| `CbeClient` / `TelebirrClient` | Same logic with `curl_*` or `GuzzleHttp\Client` |
| `TelebirrReceiptParser` | Copy almost unchanged (`DOMDocument` + `DOMXPath`) |
| `VerificationException` | Custom exception + `jsonError($status, $message)` |
| `config/*.php` + `.env` | `config.php` loading `parse_ini_file('.env')` or `getenv()` |
| `Illuminate\Http\Client` | cURL |

---

## Suggested directory layout

```text
plain-virify/
├── public/
│   └── index.php          # Front controller
├── src/
│   ├── Config.php
│   ├── Router.php
│   ├── Http/
│   │   ├── JsonResponse.php
│   │   ├── BearerAuth.php
│   │   ├── RateLimiter.php
│   │   └── SecurityHeaders.php
│   ├── Validation/
│   │   └── VerifyRequestValidator.php
│   ├── Enum/
│   │   └── VerificationType.php
│   ├── Service/
│   │   ├── VerificationService.php
│   │   ├── CbeClient.php
│   │   ├── TelebirrClient.php
│   │   └── TelebirrReceiptParser.php
│   └── Exception/
│       └── VerificationException.php
├── storage/
│   └── rate-limit/        # Writable
├── .env
└── .env.example
```

Point the web server document root at `public/`. Rewrite all requests to `index.php` (same idea as Laravel’s `public/index.php`).

---

## Environment variables

Copy from this project’s `.env.example`:

| Variable | Purpose |
|----------|---------|
| `API_BEARER_TOKEN` | Required secret for API access |
| `API_RATE_LIMIT_PER_MINUTE` | Default `60` |
| `CBE_BASE_URL` | Default `https://mb.cbe.com.et` |
| `CBE_APP_ID` / `CBE_APP_VERSION` | Required CBE headers |
| `CBE_COOKIE` | Optional; refresh from browser if CBE blocks requests |
| `CBE_TIMEOUT` | Seconds (default `15`) |
| `TELEBIRR_BASE_URL` | Default `https://transactioninfo.ethiotelecom.et` |
| `TELEBIRR_TIMEOUT` | Seconds (default `20`) |
| `TELEBIRR_USER_AGENT` | Browser-like UA for receipt HTML |

---

## HTTP contract

### Request

```http
POST /api/verifications
Authorization: Bearer <token>
Content-Type: application/json

{
  "type": "cbe",
  "transactionId": "v2-hfHCxGxrwgrJ9XsCeot8"
}
```

`transactionId` may be a full receipt URL; extract the last path segment (see `normalizeReceiptId` in `app/Services/Cbe/CbeClient.php` and Telebirr client).

### Success (201)

```json
{
  "message": "Verification submitted",
  "data": {
    "type": "cbe",
    "transactionId": "v2-hfHCxGxrwgrJ9XsCeot8",
    "creditParty": "...",
    "debitParty": "...",
    "date": "...",
    "amount": "..."
  }
}
```

### Errors

| HTTP | Body |
|------|------|
| 401 | `{"status":"failed","message":"Unauthorized."}` |
| 422 | Validation errors (Laravel-style `errors` object is fine, or mirror `{status, message}`) |
| 404 | `{"status":"failed","message":"..."}` (receipt not found) |
| 429 | `{"status":"failed","message":"Too many requests."}` |
| 502 | Provider error / bad upstream response |
| 503 | `API_BEARER_TOKEN` not configured |
| 504 | Timeout / connection failure → `"Unable to complete verification."` |

Security response headers (from `app/Http/Middleware/SecurityHeaders.php`):

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: no-referrer`
- `X-Robots-Tag: noindex, nofollow`
- `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`

---

## Front controller sketch

```php
<?php
// public/index.php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php'; // optional if you only use plain PHP

use App\Config;
use App\Http\BearerAuth;
use App\Http\JsonResponse;
use App\Http\RateLimiter;
use App\Http\SecurityHeaders;
use App\Exception\VerificationException;
use App\Service\VerificationService;
use App\Validation\VerifyRequestValidator;

$config = Config::load(dirname(__DIR__));
SecurityHeaders::apply();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'POST' && $path === '/api/verifications') {
    try {
        BearerAuth::check($config['api_bearer_token']);
        RateLimiter::check('verifications', $_SERVER['REMOTE_ADDR'] ?? 'cli', (int) $config['rate_limit']);

        $body = json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        JsonResponse::send(['status' => 'failed', 'message' => 'Invalid JSON.'], 400);
    } catch (VerificationException $e) {
        JsonResponse::send(['status' => 'failed', 'message' => $e->getMessage()], $e->statusCode());
    }

    $dto = VerifyRequestValidator::validate($body);
    $service = new VerificationService($config);
    $result = $service->verify($dto);

    JsonResponse::send(['message' => 'Verification submitted', 'data' => $result], 201);
}

http_response_code(404);
JsonResponse::send(['status' => 'failed', 'message' => 'Not found.'], 404);
```

Implement `JsonResponse::send` with `header('Content-Type: application/json')`, `echo json_encode(...)`, and `exit`.

---

## Bearer auth

Mirror `app/Http/Middleware/AuthenticateApiBearer.php`:

```php
$token = null;
if (preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
    $token = trim($m[1]);
}
// Some servers pass Authorization differently:
if ($token === null && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    // same regex
}

if ($configured === '' || !is_string($token) || !hash_equals($configured, $token)) {
    // 401 or 503 if token not configured
}
```

---

## Validation

Rules from `app/Http/Requests/StoreVerificationRequest.php`:

- `type`: required, must be `telebirr` or `cbe`
- `transactionId`: required, non-empty string, max length 255

---

## CBE integration

**URL:** `{CBE_BASE_URL}/api/v1/transactions/public/transaction-detail/{receiptId}`

**Headers:** Copy the full set from `app/Services/Cbe/CbeClient.php` (`request()`), especially:

- `Origin: https://mbreciept.cbe.com.et`
- `Referer: https://mbreciept.cbe.com.et/`
- `x-app-id` / `x-app-version`
- Optional `Cookie`

**cURL example:**

```php
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_HTTPHEADER => $headerLines,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($body === false) {
    throw VerificationException::failed();
}
```

**Map JSON to response** (`VerificationService::verifyCbe`):

| Output | Source field |
|--------|----------------|
| `creditParty` | `creditAccountHolder` |
| `debitParty` | `debitAccountHolder` |
| `date` | `dateTimes[0]` or `processingDate` |
| `amount` | `amountCreditedWithCurrency` or `creditCurrency` + `amountCredited` |

Treat missing `id` in JSON as not found.

---

## Telebirr integration

**URL:** `{TELEBIRR_BASE_URL}/receipt/{transactionId}`

Returns **HTML**. Parsing lives in `app/Services/Telebirr/TelebirrReceiptParser.php` — you can copy this file with minimal changes (replace `VerificationException` import).

**Table row labels to match (English substring):**

| Field | Label in HTML |
|-------|----------------|
| `debitParty` | `Payer Name` |
| `creditParty` | `Credited Party name` |
| `date` | Second column in invoice row after header row containing `Payment date` |
| `amount` | Third column (`Settled Amount` row) |

Detect provider outage HTML (`Gateway Time-out`, `502`, `503`) before parsing — see `TelebirrClient::looksLikeProviderOutage`.

---

## Verification service dispatch

```php
return match ($type) {
    'telebirr' => $this->verifyTelebirr($transactionId),
    'cbe' => $this->verifyCbe($transactionId),
};
```

Logic is in `app/Services/VerificationService.php`.

---

## Rate limiting (file-based)

Laravel uses `RateLimiter::for('verifications', ...)`. In plain PHP:

1. Key file: `storage/rate-limit/{sha256(ip)}.json`
2. Store `{ "reset": unix_timestamp, "count": n }`
3. If `time() > reset`, reset window to 60 seconds and `count = 1`
4. If `count > limit`, throw 429

---

## PHP requirements

- PHP **8.3+** (or 8.1+ if you keep enums)
- Extensions: **curl**, **dom**, **json**, **libxml**
- Writable `storage/rate-limit/` if using file rate limits

Optional: `composer require guzzlehttp/guzzle` instead of raw cURL.

---

## Source files to copy or translate line-for-line

Priority order when porting:

1. `app/Services/Telebirr/TelebirrReceiptParser.php` — mostly standalone
2. `app/Services/Cbe/CbeClient.php` — replace HTTP client with cURL
3. `app/Services/Telebirr/TelebirrClient.php` — replace HTTP client with cURL
4. `app/Services/VerificationService.php` — thin mapping layer
5. `app/Http/Middleware/AuthenticateApiBearer.php` — auth
6. `app/Exceptions/VerificationException.php` — error types and status codes
7. `app/Http/Requests/StoreVerificationRequest.php` — validation rules only

Ignore for a minimal API: Sanctum, migrations, `routes/web.php`, views, queues, mail.

---

## nginx example

```nginx
server {
    root /var/www/plain-virify/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

---

## Checklist

- [ ] Single `POST /api/verifications` route; everything else 404
- [ ] Bearer token with `hash_equals`
- [ ] JSON validation for `type` and `transactionId`
- [ ] CBE headers + timeout handling
- [ ] Telebirr HTML parser + outage detection
- [ ] Unified `{ "status": "failed", "message": "..." }` on errors
- [ ] Security headers on every response
- [ ] Rate limit per IP
- [ ] `APP_DEBUG` equivalent off in production (never leak stack traces)

---

## Testing parity with Laravel

Use the same curl against both implementations:

```bash
curl -s -X POST http://127.0.0.1:8000/api/verifications \
  -H "Authorization: Bearer $API_BEARER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"cbe","transactionId":"v2-..."}'
```

Compare `data` fields and HTTP status codes for success, invalid token, invalid `type`, and timeout cases.
