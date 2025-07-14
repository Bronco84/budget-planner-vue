<?php

namespace App\Services;

use App\Models\File;
use App\Models\FileAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FileService
{
    /**
     * Upload a file and create attachment to a model.
     *
     * @param UploadedFile $uploadedFile
     * @param mixed $attachable
     * @param int $userId
     * @param string|null $description
     * @return FileAttachment
     * @throws \Exception
     */
    public function uploadAndAttach(UploadedFile $uploadedFile, $attachable, int $userId, ?string $description = null): FileAttachment
    {
        // Validate file
        $this->validateFile($uploadedFile);

        // Generate hash of file content
        $hash = $this->generateFileHash($uploadedFile);

        // Check if file already exists
        $existingFile = File::where('hash', $hash)->first();

        if ($existingFile) {
            // File already exists, just create attachment
            $fileAttachment = $this->createAttachment($existingFile, $attachable, $userId, $description);
            
            activity()
                ->performedOn($fileAttachment)
                ->causedBy($userId)
                ->log('File attached (existing)');
            
            // Also log on the parent model if it's a transaction
            if ($attachable instanceof \App\Models\Transaction) {
                $attachable->logActivity('File attached', [
                    'file_name' => $existingFile->original_name,
                    'file_size' => $existingFile->formatted_size,
                    'description' => $description,
                    'attachment_type' => 'existing'
                ], $userId);
            }
            
            // Log activity for budget attachments
            if ($attachable instanceof \App\Models\Budget) {
                activity()
                    ->performedOn($attachable)
                    ->causedBy($userId)
                    ->withProperties([
                        'file_name' => $existingFile->original_name,
                        'file_size' => $existingFile->formatted_size,
                        'description' => $description,
                        'attachment_type' => 'existing'
                    ])
                    ->log('File attached to budget');
            }
                
            return $fileAttachment;
        }

        // Upload to S3 and create file record
        return DB::transaction(function () use ($uploadedFile, $hash, $attachable, $userId, $description) {
            // Upload to S3
            $uploaded = Storage::disk('s3')->putFileAs('', $uploadedFile, $hash);
            
            if (!$uploaded) {
                throw new \Exception('Failed to upload file to S3');
            }

            // Create file record
            $file = File::create([
                'hash' => $hash,
                'original_name' => $uploadedFile->getClientOriginalName(),
                'mime_type' => $uploadedFile->getMimeType(),
                'size_bytes' => $uploadedFile->getSize(),
                'extension' => $uploadedFile->getClientOriginalExtension(),
                'uploaded_by' => $userId,
            ]);

            // Create attachment
            $fileAttachment = $this->createAttachment($file, $attachable, $userId, $description);

            activity()
                ->performedOn($fileAttachment)
                ->causedBy($userId)
                ->log('File uploaded and attached');

            // Also log on the parent model if it's a transaction
            if ($attachable instanceof \App\Models\Transaction) {
                $attachable->logActivity('File uploaded and attached', [
                    'file_name' => $file->original_name,
                    'file_size' => $file->formatted_size,
                    'description' => $description,
                    'attachment_type' => 'new'
                ], $userId);
            }
            
            // Log activity for budget attachments
            if ($attachable instanceof \App\Models\Budget) {
                activity()
                    ->performedOn($attachable)
                    ->causedBy($userId)
                    ->withProperties([
                        'file_name' => $file->original_name,
                        'file_size' => $file->formatted_size,
                        'description' => $description,
                        'attachment_type' => 'new'
                    ])
                    ->log('File uploaded and attached to budget');
            }

            return $fileAttachment;
        });
    }

    /**
     * Detach a file from a model.
     *
     * @param FileAttachment $attachment
     * @param int $userId
     * @return bool
     */
    public function detachFile(FileAttachment $attachment, int $userId): bool
    {
        return DB::transaction(function () use ($attachment, $userId) {
            $file = $attachment->file;
            $fileName = $file->original_name;
            $fileHash = $file->hash;
            $attachable = $attachment->attachable;
            
            // Log the attachment deletion
            activity()
                ->performedOn($attachment)
                ->causedBy($userId)
                ->withProperties([
                    'file_name' => $fileName,
                    'attachable_type' => $attachment->attachable_type,
                    'attachable_id' => $attachment->attachable_id,
                ])
                ->log('File attachment deleted');

            // Also log on the parent model if it's a transaction
            if ($attachable instanceof \App\Models\Transaction) {
                $attachable->logActivity('File attachment deleted', [
                    'file_name' => $fileName,
                    'file_size' => $file->formatted_size,
                    'description' => $attachment->description,
                ], $userId);
            }
            
            // Log activity for budget attachments
            if ($attachable instanceof \App\Models\Budget) {
                activity()
                    ->performedOn($attachable)
                    ->causedBy($userId)
                    ->withProperties([
                        'file_name' => $fileName,
                        'file_size' => $file->formatted_size,
                        'description' => $attachment->description,
                    ])
                    ->log('File attachment deleted from budget');
            }

            // Delete the attachment record
            $attachment->delete();

            // Check if this was the last reference to the file
            $remainingAttachments = $file->attachments()->count();
            
            if ($remainingAttachments === 0) {
                // No more references - delete from S3 and database
                try {
                    $s3DeleteSuccess = $file->deleteFromS3IfUnused();
                    
                    if ($s3DeleteSuccess) {
                        // S3 deletion successful - remove from database
                        $file->delete();
                        
                        activity()
                            ->performedOn($file)
                            ->causedBy($userId)
                            ->withProperties([
                                'file_name' => $fileName,
                                'file_hash' => $fileHash,
                                'size_bytes' => $file->size_bytes,
                            ])
                            ->log('File completely removed from S3 and database');
                    } else {
                        // S3 deletion failed - log but don't fail the operation
                        activity()
                            ->performedOn($file)
                            ->causedBy($userId)
                            ->withProperties([
                                'file_name' => $fileName,
                                'file_hash' => $fileHash,
                                'error' => 'Failed to delete from S3',
                            ])
                            ->log('File attachment deleted but S3 cleanup failed');
                    }
                } catch (\Exception $e) {
                    // Log S3 error but don't fail the attachment deletion
                    activity()
                        ->performedOn($file)
                        ->causedBy($userId)
                        ->withProperties([
                            'file_name' => $fileName,
                            'file_hash' => $fileHash,
                            'error' => $e->getMessage(),
                        ])
                        ->log('File attachment deleted but S3 cleanup encountered error');
                }
            } else {
                // File is still referenced by other attachments
                activity()
                    ->performedOn($file)
                    ->causedBy($userId)
                    ->withProperties([
                        'file_name' => $fileName,
                        'remaining_attachments' => $remainingAttachments,
                    ])
                    ->log('File attachment deleted but file retained (still in use)');
            }

            return true;
        });
    }

    /**
     * Get download URL for file attachment.
     *
     * @param FileAttachment $attachment
     * @param int $expiresInMinutes
     * @return string
     */
    public function getDownloadUrl(FileAttachment $attachment, int $expiresInMinutes = 60): string
    {
        return $attachment->file->getTemporaryUrl($expiresInMinutes);
    }

    /**
     * Check if user can access file attachment.
     *
     * @param FileAttachment $attachment
     * @param int $userId
     * @return bool
     */
    public function canUserAccessAttachment(FileAttachment $attachment, int $userId): bool
    {
        $attachable = $attachment->attachable;

        // Check access based on attachable type
        if ($attachable instanceof \App\Models\Transaction) {
            return $attachable->budget->user_id === $userId || 
                   $attachable->budget->connected_users()->where('user_id', $userId)->exists();
        }

        if ($attachable instanceof \App\Models\Budget) {
            return $attachable->user_id === $userId || 
                   $attachable->connected_users()->where('user_id', $userId)->exists();
        }

        return false;
    }

    /**
     * Generate SHA-256 hash of file content.
     *
     * @param UploadedFile $file
     * @return string
     */
    private function generateFileHash(UploadedFile $file): string
    {
        return hash_file('sha256', $file->getRealPath());
    }

    /**
     * Validate uploaded file.
     *
     * @param UploadedFile $file
     * @throws \Exception
     */
    private function validateFile(UploadedFile $file): void
    {
        // Check file size (max 10MB)
        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new \Exception('File size exceeds 10MB limit');
        }

        // Check mime type (allow common document and image types)
        $allowedMimeTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'text/plain',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/json',
            'text/csv',
        ];

        if (!in_array($file->getMimeType(), $allowedMimeTypes)) {
            throw new \Exception('File type not allowed');
        }
    }

    /**
     * Create file attachment.
     *
     * @param File $file
     * @param mixed $attachable
     * @param int $userId
     * @param string|null $description
     * @return FileAttachment
     */
    private function createAttachment(File $file, $attachable, int $userId, ?string $description = null): FileAttachment
    {
        return FileAttachment::create([
            'file_id' => $file->id,
            'attachable_type' => get_class($attachable),
            'attachable_id' => $attachable->id,
            'attached_by' => $userId,
            'description' => $description,
        ]);
    }

    /**
     * Sanitize filename for safe download.
     * 
     * @param string $filename
     * @return string
     */
    public function sanitizeFilename(string $filename): string
    {
        // Remove any path separators and null bytes
        $filename = basename($filename);
        $filename = str_replace(["\0", "\n", "\r"], '', $filename);
        
        // Replace Windows-problematic characters with underscores (optional - depends on your needs)
        // Uncomment the line below if you want stricter sanitization for Windows compatibility
        // $filename = preg_replace('/[<>:"|?*]/', '_', $filename);
        
        // If filename is empty or only contains dots, generate a default name
        if (empty($filename) || preg_match('/^\.+$/', $filename)) {
            $filename = 'download';
        }
        
        // Ensure filename is not too long (max 255 characters)
        if (strlen($filename) > 255) {
            $pathInfo = pathinfo($filename);
            $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
            $basename = $pathInfo['filename'] ?? 'download';
            $maxBasenameLength = 255 - strlen($extension);
            $filename = substr($basename, 0, $maxBasenameLength) . $extension;
        }
        
        return $filename;
    }

    /**
     * Get properly formatted Content-Disposition header for file download.
     * 
     * @param string $filename
     * @return string
     */
    public function getContentDispositionHeader(string $filename): string
    {
        $sanitizedFilename = $this->sanitizeFilename($filename);
        $encodedFilename = rawurlencode($sanitizedFilename);
        
        return 'attachment; filename="' . $sanitizedFilename . '"; filename*=UTF-8\'\'' . $encodedFilename;
    }

    /**
     * Clean up orphaned files (files with no attachments).
     * This is useful for maintenance and can be run as a scheduled job.
     * 
     * @param int|null $userId Optional user ID for activity logging
     * @return array Summary of cleanup results
     */
    public function cleanupOrphanedFiles(?int $userId = null): array
    {
        $orphanedFiles = File::whereDoesntHave('attachments')->get();
        $cleanupSummary = [
            'total_orphaned' => $orphanedFiles->count(),
            'successfully_deleted' => 0,
            'failed_deletions' => 0,
            'storage_freed_bytes' => 0,
            'errors' => []
        ];

        foreach ($orphanedFiles as $file) {
            try {
                $storageFreed = $file->size_bytes;
                $s3DeleteSuccess = $file->deleteFromS3IfUnused();
                
                if ($s3DeleteSuccess) {
                    $file->delete();
                    $cleanupSummary['successfully_deleted']++;
                    $cleanupSummary['storage_freed_bytes'] += $storageFreed;
                    
                    if ($userId) {
                        activity()
                            ->performedOn($file)
                            ->causedBy($userId)
                            ->withProperties([
                                'file_name' => $file->original_name,
                                'file_hash' => $file->hash,
                                'size_bytes' => $storageFreed,
                                'cleanup_type' => 'orphaned_file_cleanup'
                            ])
                            ->log('Orphaned file cleaned up');
                    }
                } else {
                    $cleanupSummary['failed_deletions']++;
                    $cleanupSummary['errors'][] = "Failed to delete S3 object for file: {$file->original_name}";
                }
            } catch (\Exception $e) {
                $cleanupSummary['failed_deletions']++;
                $cleanupSummary['errors'][] = "Error deleting file {$file->original_name}: " . $e->getMessage();
            }
        }

        return $cleanupSummary;
    }

    /**
     * Get statistics about file storage usage.
     * 
     * @return array Storage statistics
     */
    public function getStorageStatistics(): array
    {
        $totalFiles = File::count();
        $totalAttachments = FileAttachment::count();
        $orphanedFiles = File::whereDoesntHave('attachments')->count();
        $totalStorageBytes = File::sum('size_bytes');
        $orphanedStorageBytes = File::whereDoesntHave('attachments')->sum('size_bytes');

        return [
            'total_files' => $totalFiles,
            'total_attachments' => $totalAttachments,
            'orphaned_files' => $orphanedFiles,
            'total_storage_bytes' => $totalStorageBytes,
            'total_storage_formatted' => $this->formatBytes($totalStorageBytes),
            'orphaned_storage_bytes' => $orphanedStorageBytes,
            'orphaned_storage_formatted' => $this->formatBytes($orphanedStorageBytes),
            'deduplication_ratio' => $totalAttachments > 0 ? round(($totalAttachments - $totalFiles) / $totalAttachments * 100, 2) : 0,
        ];
    }

    /**
     * Format bytes to human readable format.
     * 
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
} 