<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Services\Api\IdempotencyService;
use App\Services\Api\IntegrationContentJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationContentJobController extends BaseApiController
{
    public function store(Request $request, IntegrationContentJobService $jobs): JsonResponse
    {
        $this->requireIdempotencyKey($request);
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /integrations/content-jobs');
        if ($cached !== null) {
            return $cached;
        }

        $auth = $this->auth($request);

        return $this->success(
            $request,
            $jobs->create($request->all(), $auth->token, $auth->auditAdminId),
            201,
            'POST /integrations/content-jobs'
        );
    }

    public function show(Request $request, string $contentJob, IntegrationContentJobService $jobs): JsonResponse
    {
        return $this->success($request, $jobs->get($contentJob));
    }

    public function finalize(Request $request, string $contentJob, IntegrationContentJobService $jobs): JsonResponse
    {
        $this->requireIdempotencyKey($request);
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /integrations/content-jobs/{id}/finalize');
        if ($cached !== null) {
            return $cached;
        }

        return $this->success(
            $request,
            $jobs->finalize($contentJob, $request->all(), $this->auth($request)->auditAdminId),
            200,
            'POST /integrations/content-jobs/{id}/finalize'
        );
    }

    public function cancel(Request $request, string $contentJob, IntegrationContentJobService $jobs): JsonResponse
    {
        $this->requireIdempotencyKey($request);
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /integrations/content-jobs/{id}/cancel');
        if ($cached !== null) {
            return $cached;
        }

        return $this->success(
            $request,
            $jobs->cancel($contentJob, $request->all()),
            200,
            'POST /integrations/content-jobs/{id}/cancel'
        );
    }

    private function requireIdempotencyKey(Request $request): void
    {
        $key = trim((string) $request->header('X-Idempotency-Key', ''));
        if (! preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $key)) {
            throw new ApiException('idempotency_key_required', '写请求必须提供有效的 X-Idempotency-Key', 422);
        }
    }
}
