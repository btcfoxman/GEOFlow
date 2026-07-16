<?php

namespace App\Jobs;

use App\Models\IntegrationContentJob;
use App\Services\Api\IdempotencyService;
use App\Services\Api\IntegrationContentJobService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SendIntegrationContentJobCallback implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(
        public string $publicId,
        public string $event,
        public string $occurredAt = ''
    ) {
        $this->occurredAt = $occurredAt !== '' ? $occurredAt : now()->toIso8601String();
        $this->onQueue('geoflow');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(IntegrationContentJobService $jobs): void
    {
        $job = IntegrationContentJob::query()->where('public_id', $this->publicId)->first();
        if (! $job || ! $job->callback_url || ! $job->callback_secret) {
            return;
        }

        $payload = [
            'event' => $this->event,
            'occurred_at' => $this->occurredAt,
            'job' => $jobs->present($job),
        ];
        $encoded = json_encode(
            IdempotencyService::normalizePayload($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp."\n".$encoded, $job->callback_secret);
        $idempotencyKey = hash('sha256', $this->publicId.'|'.$this->event.'|'.$this->occurredAt);

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->withHeaders([
                    'X-GEOFlow-Timestamp' => $timestamp,
                    'X-GEOFlow-Signature' => 'sha256='.$signature,
                    'X-Idempotency-Key' => $idempotencyKey,
                ])
                ->post($job->callback_url, $payload);

            $job->forceFill([
                'callback_status' => $response->successful() ? 'delivered' : 'failed',
                'callback_error' => $response->successful() ? null : 'HTTP '.$response->status(),
                'callback_attempted_at' => now(),
            ])->save();

            if (! $response->successful()) {
                throw new RuntimeException('Integration callback returned HTTP '.$response->status());
            }
        } catch (\Throwable $exception) {
            $job->forceFill([
                'callback_status' => 'failed',
                'callback_error' => mb_substr($exception->getMessage(), 0, 2000),
                'callback_attempted_at' => now(),
            ])->save();

            throw $exception;
        }
    }
}
