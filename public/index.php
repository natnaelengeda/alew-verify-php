<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use App\Config;
use App\Exception\VerificationException;
use App\Http\BearerAuth;
use App\Http\JsonResponse;
use App\Http\RateLimiter;
use App\Http\SecurityHeaders;
use App\Service\CbeClient;
use App\Service\TelebirrClient;
use App\Service\TelebirrReceiptParser;
use App\Service\VerificationService;
use App\Validation\VerifyRequestValidator;

$basePath = dirname(__DIR__);
$config = Config::load($basePath);

if (! $config['app_debug']) {
    ini_set('display_errors', '0');
}

SecurityHeaders::apply();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'GET' && $path === '/') {
    JsonResponse::send(['status' => 'ok']);
}

if ($method === 'POST' && $path === '/') {
    try {
        BearerAuth::check($config['api_bearer_token']);

        RateLimiter::check(
            'verifications',
            $_SERVER['REMOTE_ADDR'] ?? 'cli',
            $config['rate_limit'],
            $basePath.'/storage/rate-limit',
        );

        $raw = file_get_contents('php://input') ?: '';
        $body = $raw === '' ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if ($body !== null && ! is_array($body)) {
            JsonResponse::send(['status' => 'failed', 'message' => 'Invalid JSON.'], 400);
        }

        $dto = VerifyRequestValidator::validate($body);

        $verificationService = new VerificationService(
            new CbeClient($config['cbe']),
            new TelebirrClient($config['telebirr'], new TelebirrReceiptParser),
        );

        $result = $verificationService->verify($dto);

        JsonResponse::send([
            'message' => 'Verification submitted',
            'data' => $result,
        ], 201);
    } catch (\JsonException) {
        JsonResponse::send(['status' => 'failed', 'message' => 'Invalid JSON.'], 400);
    } catch (VerificationException $e) {
        JsonResponse::send($e->toJsonPayload(), $e->statusCode());
    } catch (\Throwable $e) {
        if ($config['app_debug']) {
            JsonResponse::send([
                'status' => 'failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        JsonResponse::send(['status' => 'failed', 'message' => 'Server error.'], 500);
    }
}

JsonResponse::send(['status' => 'failed', 'message' => 'Not found.'], 404);
