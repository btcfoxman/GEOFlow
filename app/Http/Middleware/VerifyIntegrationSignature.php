<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Services\Api\IdempotencyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class VerifyIntegrationSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = trim((string) $request->header('X-Integration-Timestamp', ''));
        $nonce = trim((string) $request->header('X-Integration-Nonce', ''));
        $signature = strtolower(trim((string) $request->header('X-Integration-Signature', '')));

        if (! ctype_digit($timestamp) || ! preg_match('/^[A-Za-z0-9._-]{16,120}$/', $nonce)) {
            throw new ApiException('invalid_signature_headers', '集成签名时间戳或 Nonce 无效', 401);
        }

        $clockSkew = max(30, (int) config('geoflow.integration_signature_clock_skew_seconds', 300));
        if (abs(now()->timestamp - (int) $timestamp) > $clockSkew) {
            throw new ApiException('signature_expired', '集成签名已过期', 401);
        }

        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            throw new ApiException('invalid_signature', '集成签名无效', 401);
        }

        $authorization = (string) $request->header('Authorization', '');
        if (! preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            throw new ApiException('unauthorized', '缺少有效 Bearer Token', 401);
        }

        $bodyHash = hash('sha256', $this->canonicalBody($request));
        $canonical = implode("\n", [
            $timestamp,
            strtoupper($request->method()),
            '/'.$request->path(),
            $bodyHash,
        ]);
        $expected = hash_hmac('sha256', $canonical, trim($matches[1]));
        if (! hash_equals($expected, $signature)) {
            throw new ApiException('invalid_signature', '集成签名无效', 401);
        }

        $context = $request->attributes->get('api_auth');
        if (! $context instanceof ApiAuthContext) {
            throw new ApiException('unauthorized', '未认证', 401);
        }

        $tokenId = (int) ($context->token['id'] ?? 0);
        $nonceKey = sprintf('geoflow:integration:nonce:%d:%s', $tokenId, hash('sha256', $nonce));
        if (! Cache::add($nonceKey, true, now()->addSeconds($clockSkew * 2))) {
            throw new ApiException('replay_detected', '检测到重复的集成请求', 409);
        }

        return $next($request);
    }

    private function canonicalBody(Request $request): string
    {
        if ($request->getContent() === '') {
            return '';
        }

        try {
            return json_encode(
                IdempotencyService::normalizePayload($request->all()),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new ApiException('invalid_json', '请求体不是有效 JSON', 422);
        }
    }
}
