<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Author;
use App\Models\Category;
use App\Services\Api\IdempotencyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntegrationContentJobApiTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Author $author;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('g', 32)),
            'geoflow.integration_callback_allowed_hosts' => ['orchestration-service'],
        ]);

        // The legacy PostgreSQL bootstrap owns this table in production, but
        // its SQLite compatibility path intentionally omits review history.
        if (! Schema::hasTable('article_reviews')) {
            Schema::create('article_reviews', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('article_id');
                $table->unsignedBigInteger('admin_id');
                $table->string('review_status', 20);
                $table->text('review_note')->default('');
                $table->timestamp('created_at')->nullable();
            });
        }

        $this->admin = Admin::query()->create([
            'username' => 'integration_admin',
            'password' => 'secret-123',
            'email' => 'integration@example.com',
            'display_name' => 'Integration Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->author = Author::query()->create([
            'name' => 'Orchestration',
            'bio' => '',
        ]);
        $this->category = Category::query()->create([
            'name' => 'GEO Integration',
            'slug' => 'geo-integration',
            'description' => '',
            'sort_order' => 1,
        ]);
    }

    public function test_create_show_and_finalize_content_job(): void
    {
        $this->withoutExceptionHandling();

        $token = $this->token(['integrations:read', 'integrations:write', 'integrations:finalize']);
        $payload = $this->createPayload('package-001', 'v1');

        $create = $this->signedPost('/api/v1/integrations/content-jobs', $payload, $token, 'create-package-001');
        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ready_for_review')
            ->assertJsonPath('data.content_package_id', 'package-001')
            ->assertJsonPath('data.article.status', 'draft')
            ->assertJsonPath('data.article.review_status', 'pending');

        $publicId = (string) $create->json('data.id');
        $this->assertDatabaseHas('integration_content_jobs', [
            'public_id' => $publicId,
            'content_package_id' => 'package-001',
            'content_version' => 'v1',
            'status' => 'ready_for_review',
        ]);

        $this->signedGet("/api/v1/integrations/content-jobs/{$publicId}", $token)
            ->assertOk()
            ->assertJsonPath('data.id', $publicId)
            ->assertJsonPath('data.callback.configured', false);

        $finalize = $this->signedPost(
            "/api/v1/integrations/content-jobs/{$publicId}/finalize",
            [
                'approved_version' => 'v1',
                'review_note' => 'Owner approved',
                'publish' => true,
            ],
            $token,
            'finalize-package-001'
        );

        $finalize->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.article.status', 'published')
            ->assertJsonPath('data.article.review_status', 'approved')
            ->assertJsonPath('data.result.published', true);

        $this->assertNotEmpty($finalize->json('data.result.published_url'));
    }

    public function test_create_is_idempotent_with_a_fresh_signed_nonce(): void
    {
        $token = $this->token(['integrations:write']);
        $payload = $this->createPayload('package-idempotent', 'v1');

        $first = $this->signedPost('/api/v1/integrations/content-jobs', $payload, $token, 'same-idempotency-key');
        $second = $this->signedPost('/api/v1/integrations/content-jobs', $payload, $token, 'same-idempotency-key');

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('integration_content_jobs', 1);
    }

    public function test_reused_nonce_is_rejected_before_replay(): void
    {
        $token = $this->token(['integrations:write']);
        $payload = $this->createPayload('package-replay', 'v1');
        $timestamp = (string) now()->timestamp;
        $nonce = 'fixed-nonce-1234567890';
        $headers = $this->signatureHeaders('POST', '/api/v1/integrations/content-jobs', $payload, $token, $timestamp, $nonce);
        $headers['X-Idempotency-Key'] = 'replay-idempotency';

        $this->withHeaders($headers)
            ->postJson('/api/v1/integrations/content-jobs', $payload)
            ->assertCreated();

        $this->withHeaders($headers)
            ->postJson('/api/v1/integrations/content-jobs', $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'replay_detected');
    }

    public function test_invalid_signature_and_missing_scope_are_rejected(): void
    {
        $payload = $this->createPayload('package-auth', 'v1');
        $token = $this->token(['integrations:write']);
        $headers = $this->signatureHeaders('POST', '/api/v1/integrations/content-jobs', $payload, $token);
        $headers['X-Integration-Signature'] = 'sha256='.str_repeat('0', 64);
        $headers['X-Idempotency-Key'] = 'bad-signature-key';

        $this->withHeaders($headers)
            ->postJson('/api/v1/integrations/content-jobs', $payload)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_signature');

        $readOnlyToken = $this->token(['integrations:read']);
        $this->signedPost('/api/v1/integrations/content-jobs', $payload, $readOnlyToken, 'missing-scope-key')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_digest_version_and_cancel_state_are_enforced(): void
    {
        $token = $this->token(['integrations:write', 'integrations:finalize']);
        $badDigest = $this->createPayload('package-digest', 'v1');
        $badDigest['content_digest'] = str_repeat('a', 64);
        $this->signedPost('/api/v1/integrations/content-jobs', $badDigest, $token, 'bad-digest-key')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'content_digest_mismatch');

        $payload = $this->createPayload('package-cancel', 'v2');
        $create = $this->signedPost('/api/v1/integrations/content-jobs', $payload, $token, 'create-cancel-key');
        $publicId = (string) $create->json('data.id');

        $this->signedPost(
            "/api/v1/integrations/content-jobs/{$publicId}/finalize",
            ['approved_version' => 'v1'],
            $token,
            'wrong-version-key'
        )
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'content_version_mismatch');

        $this->signedPost(
            "/api/v1/integrations/content-jobs/{$publicId}/cancel",
            ['reason' => 'Owner withdrew the content'],
            $token,
            'cancel-content-key'
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    /**
     * @param  list<string>  $scopes
     */
    private function token(array $scopes): string
    {
        return $this->admin->createToken('integration-test-'.Str::random(8), $scopes)->plainTextToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(string $packageId, string $version): array
    {
        $content = "# GEO content\n\nVerified source content for {$packageId}.";

        return [
            'source_system' => 'ai-orchestration',
            'content_package_id' => $packageId,
            'content_version' => $version,
            'content_digest' => hash('sha256', $content),
            'locale' => 'zh-CN',
            'title' => 'GEO content '.$packageId,
            'content' => $content,
            'excerpt' => 'A verified integration draft.',
            'keywords' => ['GEO', 'AI'],
            'meta_description' => 'A verified GEO integration draft.',
            'target_sites' => ['local-test-site'],
            'evidence_refs' => [
                ['id' => 'evidence-1', 'url' => 'https://example.com/source'],
            ],
            'asset_refs' => [
                ['id' => 'asset-1', 'url' => 'https://cdn.example.com/image.webp'],
            ],
            'publication' => [
                'category_id' => (int) $this->category->id,
                'author_id' => (int) $this->author->id,
            ],
        ];
    }

    private function signedPost(string $path, array $payload, string $token, string $idempotencyKey)
    {
        $headers = $this->signatureHeaders('POST', $path, $payload, $token);
        $headers['X-Idempotency-Key'] = $idempotencyKey;

        return $this->withHeaders($headers)->postJson($path, $payload);
    }

    private function signedGet(string $path, string $token)
    {
        return $this->withHeaders($this->signatureHeaders('GET', $path, [], $token))->getJson($path);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function signatureHeaders(
        string $method,
        string $path,
        array $payload,
        string $token,
        ?string $timestamp = null,
        ?string $nonce = null
    ): array {
        $timestamp ??= (string) now()->timestamp;
        $nonce ??= 'test-'.Str::uuid()->toString();
        $body = $method === 'GET' ? '' : json_encode(
            IdempotencyService::normalizePayload($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $canonical = implode("\n", [
            $timestamp,
            strtoupper($method),
            $path,
            hash('sha256', $body),
        ]);

        return [
            'Authorization' => 'Bearer '.$token,
            'X-Integration-Timestamp' => $timestamp,
            'X-Integration-Nonce' => $nonce,
            'X-Integration-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $token),
        ];
    }
}
