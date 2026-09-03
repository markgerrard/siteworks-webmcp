<?php

namespace App\Models\Site;

use Illuminate\Database\Eloquent\Model;

final class EditorOperationLog extends Model
{
    public $timestamps = false;

    protected $table = 'editor_operation_log';

    protected $fillable = [
        'site_id',
        'page_id',
        'actor_user_id',
        'actor_channel',
        'operation',
        'result_code',
        'duration_ms',
        'subject_type',
        'subject_ref',
        'created_at',
    ];
}
