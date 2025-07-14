<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class FileAttachment extends Model
{
    use HasFactory, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'file_id',
        'attachable_type',
        'attachable_id',
        'attached_by',
        'description',
    ];

    /**
     * Get the file associated with this attachment.
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /**
     * Get the attachable model (Transaction or Budget).
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user who attached the file.
     */
    public function attachedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attached_by');
    }

    /**
     * Get activity log options.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['file_id', 'attachable_type', 'attachable_id', 'description'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
} 