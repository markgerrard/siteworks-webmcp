<?php

namespace App\Models\Site;

use App\Models\GeneratedPage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageRevision extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'generated_page_revisions';

    protected $fillable = [
        'page_id', 'parent_revision_id', 'content_data',
        'ai_generated', 'ai_model_version', 'ai_prompt_used',
        'created_by_user_id', 'created_at',
    ];

    protected $casts = [
        'content_data' => 'array',
        'ai_generated' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(GeneratedPage::class, 'page_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
