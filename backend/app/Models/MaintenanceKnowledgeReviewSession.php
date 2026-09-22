<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * V1.11 - operational analytics for the human review/publish workflow, not a governed
 * knowledge record. Never audited through GovernanceAudit and never able to mutate the
 * catalog itself - see ReviewSessionTracker.
 */
class MaintenanceKnowledgeReviewSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'account_id', 'document_import_id', 'code', 'reviewer_user_id',
        'started_at', 'last_activity_at', 'source_review_started_at', 'authoring_started_at', 'authoring_saved_at', 'completed_at',
        'active_seconds', 'source_page_views', 'authoring_edits', 'validation_failures',
        'current_stage', 'outcome', 'last_client_seq',
    ];

    protected $casts = [
        'started_at' => 'datetime', 'last_activity_at' => 'datetime', 'source_review_started_at' => 'datetime',
        'authoring_started_at' => 'datetime', 'authoring_saved_at' => 'datetime', 'completed_at' => 'datetime',
        'active_seconds' => 'integer', 'source_page_views' => 'integer', 'authoring_edits' => 'integer', 'validation_failures' => 'integer',
        'last_client_seq' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function documentImport()
    {
        return $this->belongsTo(MaintenanceDocumentImport::class, 'document_import_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
}
