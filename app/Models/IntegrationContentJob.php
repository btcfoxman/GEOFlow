<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationContentJob extends Model
{
    protected $fillable = [
        'public_id',
        'source_system',
        'content_package_id',
        'content_version',
        'content_digest',
        'status',
        'locale',
        'request_payload',
        'target_sites',
        'evidence_refs',
        'asset_refs',
        'callback_url',
        'callback_secret',
        'callback_status',
        'callback_error',
        'callback_attempted_at',
        'article_id',
        'created_by_token_id',
        'created_by_admin_id',
        'result_payload',
        'error_code',
        'error_message',
        'finalized_at',
        'cancelled_at',
    ];

    protected $hidden = [
        'callback_secret',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'target_sites' => 'array',
            'evidence_refs' => 'array',
            'asset_refs' => 'array',
            'callback_secret' => 'encrypted',
            'callback_attempted_at' => 'datetime',
            'article_id' => 'integer',
            'created_by_token_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'result_payload' => 'array',
            'finalized_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }
}
