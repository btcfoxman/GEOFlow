<?php

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Jobs\SendIntegrationContentJobCallback;
use App\Models\IntegrationContentJob;
use App\Services\GeoFlow\ArticleGeoFlowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class IntegrationContentJobService
{
    public function __construct(
        private ArticleGeoFlowService $articles
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $token
     * @return array<string, mixed>
     */
    public function create(array $payload, array $token, int $adminId): array
    {
        $validated = $this->validateCreate($payload);
        $digest = strtolower((string) $validated['content_digest']);
        if (! hash_equals($digest, hash('sha256', (string) $validated['content']))) {
            throw new ApiException('content_digest_mismatch', 'content_digest 与正文内容不匹配', 422, [
                'field_errors' => ['content_digest' => '必须是 content 字段的 SHA-256'],
            ]);
        }

        $exists = IntegrationContentJob::query()
            ->where('source_system', $validated['source_system'])
            ->where('content_package_id', $validated['content_package_id'])
            ->where('content_version', $validated['content_version'])
            ->exists();
        if ($exists) {
            throw new ApiException('content_job_exists', '该内容包版本已经提交', 409);
        }

        $job = DB::transaction(function () use ($validated, $token, $adminId, $digest): IntegrationContentJob {
            $publication = $validated['publication'];
            $article = $this->articles->createArticle([
                'title' => $validated['title'],
                'content' => $validated['content'],
                'excerpt' => $validated['excerpt'] ?? '',
                'keywords' => implode(',', $validated['keywords'] ?? []),
                'meta_description' => $validated['meta_description'] ?? '',
                'slug' => $publication['slug'] ?? null,
                'category_id' => $publication['category_id'],
                'author_id' => $publication['author_id'],
                'status' => 'draft',
                'review_status' => 'pending',
                'is_ai_generated' => 0,
            ]);

            $callback = $validated['callback'] ?? [];

            return IntegrationContentJob::query()->create([
                'public_id' => Str::uuid()->toString(),
                'source_system' => $validated['source_system'],
                'content_package_id' => $validated['content_package_id'],
                'content_version' => $validated['content_version'],
                'content_digest' => $digest,
                'status' => 'ready_for_review',
                'locale' => $validated['locale'] ?? 'zh-CN',
                'request_payload' => $validated,
                'target_sites' => $validated['target_sites'],
                'evidence_refs' => $validated['evidence_refs'] ?? [],
                'asset_refs' => $validated['asset_refs'] ?? [],
                'callback_url' => $callback['url'] ?? null,
                'callback_secret' => $callback['secret'] ?? null,
                'article_id' => (int) $article['id'],
                'created_by_token_id' => isset($token['id']) ? (int) $token['id'] : null,
                'created_by_admin_id' => $adminId,
            ]);
        });

        DB::afterCommit(function () use ($job): void {
            if ($job->callback_url) {
                SendIntegrationContentJobCallback::dispatch($job->public_id, 'content_job.ready');
            }
        });

        return $this->present($job->fresh(['article']));
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $publicId): array
    {
        return $this->present($this->find($publicId));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function finalize(string $publicId, array $payload, int $adminId): array
    {
        $validator = Validator::make($payload, [
            'approved_version' => ['required', 'string', 'max:80'],
            'title' => ['sometimes', 'string', 'max:500'],
            'content' => ['sometimes', 'string', 'max:2000000'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'keywords' => ['sometimes', 'array', 'max:30'],
            'keywords.*' => ['string', 'max:100'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'publish' => ['sometimes', 'boolean'],
            'review_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $validated = $this->validatedOrFail($validator);

        $job = $this->find($publicId);
        if ($job->status !== 'ready_for_review') {
            throw new ApiException('content_job_not_finalizable', '当前内容任务状态不允许确认', 409, [
                'status' => $job->status,
            ]);
        }
        if (! hash_equals($job->content_version, (string) $validated['approved_version'])) {
            throw new ApiException('content_version_mismatch', '批准版本与任务版本不一致', 409);
        }

        $job = DB::transaction(function () use ($job, $validated, $adminId): IntegrationContentJob {
            $articleChanges = [];
            foreach (['title', 'content', 'excerpt', 'meta_description'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $articleChanges[$field] = $validated[$field];
                }
            }
            if (array_key_exists('keywords', $validated)) {
                $articleChanges['keywords'] = implode(',', $validated['keywords']);
            }
            if ($articleChanges !== []) {
                $this->articles->updateArticle((int) $job->article_id, $articleChanges);
            }

            $article = $this->articles->reviewArticle(
                (int) $job->article_id,
                'approved',
                trim((string) ($validated['review_note'] ?? 'Approved by orchestration')),
                $adminId
            );
            $publish = (bool) ($validated['publish'] ?? true);
            if ($publish) {
                $article = $this->articles->publishArticle((int) $job->article_id);
            }

            $publishedUrl = $publish ? route('site.article', ['slug' => $article['slug']]) : null;
            $job->forceFill([
                'status' => 'completed',
                'result_payload' => [
                    'article_id' => (int) $article['id'],
                    'slug' => $article['slug'],
                    'published' => $publish,
                    'published_url' => $publishedUrl,
                ],
                'finalized_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ])->save();

            return $job->fresh(['article']);
        });

        DB::afterCommit(function () use ($job): void {
            if ($job->callback_url) {
                SendIntegrationContentJobCallback::dispatch($job->public_id, 'content_job.completed');
            }
        });

        return $this->present($job);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function cancel(string $publicId, array $payload): array
    {
        $validator = Validator::make($payload, [
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $validated = $this->validatedOrFail($validator);
        $job = $this->find($publicId);

        if ($job->status === 'cancelled') {
            return $this->present($job);
        }
        if ($job->status === 'completed') {
            throw new ApiException('content_job_not_cancellable', '已完成的内容任务不能取消', 409);
        }

        $job->forceFill([
            'status' => 'cancelled',
            'result_payload' => ['cancel_reason' => $validated['reason']],
            'cancelled_at' => now(),
        ])->save();

        if ($job->callback_url) {
            SendIntegrationContentJobCallback::dispatch($job->public_id, 'content_job.cancelled');
        }

        return $this->present($job->fresh(['article']));
    }

    /**
     * @return array<string, mixed>
     */
    public function present(IntegrationContentJob $job): array
    {
        $job->loadMissing('article');

        return [
            'id' => $job->public_id,
            'source_system' => $job->source_system,
            'content_package_id' => $job->content_package_id,
            'content_version' => $job->content_version,
            'content_digest' => $job->content_digest,
            'status' => $job->status,
            'locale' => $job->locale,
            'target_sites' => $job->target_sites ?? [],
            'evidence_refs' => $job->evidence_refs ?? [],
            'asset_refs' => $job->asset_refs ?? [],
            'article' => $job->article ? [
                'id' => (int) $job->article->id,
                'title' => $job->article->title,
                'slug' => $job->article->slug,
                'status' => $job->article->status,
                'review_status' => $job->article->review_status,
            ] : null,
            'result' => $job->result_payload,
            'error' => $job->error_code ? [
                'code' => $job->error_code,
                'message' => $job->error_message,
            ] : null,
            'callback' => $job->callback_url ? [
                'configured' => true,
                'status' => $job->callback_status,
                'attempted_at' => $job->callback_attempted_at?->toIso8601String(),
            ] : ['configured' => false],
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
            'finalized_at' => $job->finalized_at?->toIso8601String(),
            'cancelled_at' => $job->cancelled_at?->toIso8601String(),
        ];
    }

    private function find(string $publicId): IntegrationContentJob
    {
        $job = IntegrationContentJob::query()->with('article')->where('public_id', $publicId)->first();
        if (! $job) {
            throw new ApiException('content_job_not_found', '集成内容任务不存在', 404);
        }

        return $job;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateCreate(array $payload): array
    {
        $validator = Validator::make($payload, [
            'source_system' => ['required', 'string', 'max:100'],
            'content_package_id' => ['required', 'string', 'max:120'],
            'content_version' => ['required', 'string', 'max:80'],
            'content_digest' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
            'locale' => ['sometimes', 'string', 'max:32'],
            'title' => ['required', 'string', 'max:500'],
            'content' => ['required', 'string', 'max:2000000'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'keywords' => ['sometimes', 'array', 'max:30'],
            'keywords.*' => ['string', 'max:100'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'target_sites' => ['required', 'array', 'min:1', 'max:20'],
            'target_sites.*' => ['string', 'max:120', 'distinct'],
            'evidence_refs' => ['sometimes', 'array', 'max:100'],
            'evidence_refs.*' => ['array'],
            'asset_refs' => ['sometimes', 'array', 'max:100'],
            'asset_refs.*' => ['array'],
            'publication' => ['required', 'array'],
            'publication.category_id' => ['required', 'integer', 'exists:categories,id'],
            'publication.author_id' => ['required', 'integer', 'exists:authors,id'],
            'publication.slug' => ['sometimes', 'nullable', 'string', 'max:500'],
            'callback' => ['sometimes', 'nullable', 'array'],
            'callback.url' => ['required_with:callback', 'url:http,https', 'max:1000'],
            'callback.secret' => ['required_with:callback', 'string', 'min:16', 'max:512'],
        ]);
        $validated = $this->validatedOrFail($validator);
        $this->validateCallbackHost($validated['callback']['url'] ?? null);

        return $validated;
    }

    private function validateCallbackHost(?string $callbackUrl): void
    {
        if (! $callbackUrl) {
            return;
        }

        $host = strtolower((string) parse_url($callbackUrl, PHP_URL_HOST));
        $allowed = array_map('strtolower', config('geoflow.integration_callback_allowed_hosts', []));
        if ($host === '' || ! in_array($host, $allowed, true)) {
            throw new ApiException('callback_host_forbidden', '回调地址不在允许列表中', 422, [
                'field_errors' => ['callback.url' => '回调主机未获授权'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedOrFail(\Illuminate\Validation\Validator $validator): array
    {
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $field => $messages) {
                $errors[$field] = $messages[0] ?? '参数无效';
            }

            throw new ApiException('validation_failed', '参数校验失败', 422, ['field_errors' => $errors]);
        }

        return $validator->validated();
    }
}
