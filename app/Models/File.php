<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class File extends Model
{
    use HasFactory, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'hash',
        'original_name',
        'mime_type',
        'size_bytes',
        'extension',
        'uploaded_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'size_bytes' => 'integer',
    ];

    /**
     * Get the user who uploaded the file.
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get all attachments for this file.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(FileAttachment::class);
    }

    /**
     * Get the S3 URL for this file.
     */
    public function getS3Url(): string
    {
        return Storage::disk('s3')->url($this->hash);
    }

    /**
     * Get a temporary download URL for this file.
     */
    public function getTemporaryUrl(int $expiresInMinutes = 60): string
    {
        return Storage::disk('s3')->temporaryUrl($this->hash, now()->addMinutes($expiresInMinutes));
    }

    /**
     * Check if file exists in S3.
     */
    public function existsInS3(): bool
    {
        return Storage::disk('s3')->exists($this->hash);
    }

    /**
     * Delete the file from S3 if no other attachments reference it.
     */
    public function deleteFromS3IfUnused(): bool
    {
        if ($this->attachments()->count() === 0) {
            return Storage::disk('s3')->delete($this->hash);
        }
        return false;
    }

    /**
     * Get formatted file size.
     */
    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->size_bytes;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get activity log options.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['original_name', 'mime_type', 'size_bytes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
} 