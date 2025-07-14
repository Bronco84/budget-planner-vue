<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\FileAttachment;
use App\Services\FileService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FileController extends Controller
{
    public function __construct(private FileService $fileService)
    {
    }

    /**
     * Upload file and attach to transaction.
     */
    public function uploadToTransaction(Request $request, Transaction $transaction): JsonResponse
    {
        // Check if user can access this transaction
        if (!$this->canUserAccessTransaction($transaction, Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
            'description' => 'nullable|string|max:255',
        ]);

        try {
            $attachment = $this->fileService->uploadAndAttach(
                $request->file('file'),
                $transaction,
                Auth::id(),
                $request->input('description')
            );

            return response()->json([
                'message' => 'File uploaded successfully',
                'attachment' => [
                    'id' => $attachment->id,
                    'file' => [
                        'id' => $attachment->file->id,
                        'original_name' => $attachment->file->original_name,
                        'mime_type' => $attachment->file->mime_type,
                        'formatted_size' => $attachment->file->formatted_size,
                    ],
                    'description' => $attachment->description,
                    'attached_by' => $attachment->attachedBy->name,
                    'created_at' => $attachment->created_at,
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Upload file and attach to budget.
     */
    public function uploadToBudget(Request $request, Budget $budget): JsonResponse
    {
        // Check if user can access this budget
        if (!$this->canUserAccessBudget($budget, Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
            'description' => 'nullable|string|max:255',
        ]);

        try {
            $attachment = $this->fileService->uploadAndAttach(
                $request->file('file'),
                $budget,
                Auth::id(),
                $request->input('description')
            );

            return response()->json([
                'message' => 'File uploaded successfully',
                'attachment' => [
                    'id' => $attachment->id,
                    'file' => [
                        'id' => $attachment->file->id,
                        'original_name' => $attachment->file->original_name,
                        'mime_type' => $attachment->file->mime_type,
                        'formatted_size' => $attachment->file->formatted_size,
                    ],
                    'description' => $attachment->description,
                    'attached_by' => $attachment->attachedBy->name,
                    'created_at' => $attachment->created_at,
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get file attachments for a transaction.
     */
    public function getTransactionAttachments(Transaction $transaction): JsonResponse
    {
        // Check if user can access this transaction
        if (!$this->canUserAccessTransaction($transaction, Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $attachments = $transaction->fileAttachments()
            ->with(['file', 'attachedBy'])
            ->get()
            ->map(function (FileAttachment $attachment) {
                return [
                    'id' => $attachment->id,
                    'file' => [
                        'id' => $attachment->file->id,
                        'original_name' => $attachment->file->original_name,
                        'mime_type' => $attachment->file->mime_type,
                        'formatted_size' => $attachment->file->formatted_size,
                    ],
                    'description' => $attachment->description,
                    'attached_by' => $attachment->attachedBy->name,
                    'created_at' => $attachment->created_at,
                ];
            });

        return response()->json(['attachments' => $attachments]);
    }

    /**
     * Get file attachments for a budget.
     */
    public function getBudgetAttachments(Budget $budget): JsonResponse
    {
        // Check if user can access this budget
        if (!$this->canUserAccessBudget($budget, Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $attachments = $budget->fileAttachments()
            ->with(['file', 'attachedBy'])
            ->get()
            ->map(function (FileAttachment $attachment) {
                return [
                    'id' => $attachment->id,
                    'file' => [
                        'id' => $attachment->file->id,
                        'original_name' => $attachment->file->original_name,
                        'mime_type' => $attachment->file->mime_type,
                        'formatted_size' => $attachment->file->formatted_size,
                    ],
                    'description' => $attachment->description,
                    'attached_by' => $attachment->attachedBy->name,
                    'created_at' => $attachment->created_at,
                ];
            });

        return response()->json(['attachments' => $attachments]);
    }

    /**
     * Download file attachment (streams through Laravel - best practice).
     */
    public function download(FileAttachment $attachment): Response
    {
        // Check if user can access this attachment
        if (!$this->fileService->canUserAccessAttachment($attachment, Auth::id())) {
            abort(403, 'Unauthorized');
        }

        try {
            // Log the download activity
            activity('file_download')
                ->performedOn($attachment->file)
                ->withProperties([
                    'attachment_id' => $attachment->id,
                    'original_name' => $attachment->file->original_name,
                    'attachable_type' => $attachment->attachable_type,
                    'attachable_id' => $attachment->attachable_id,
                ])
                ->log('downloaded');

            // Stream file from S3 through Laravel
            $file = $attachment->file;
            $fileContents = Storage::disk('s3')->get($file->hash);
            
            // Get properly formatted Content-Disposition header with original filename
            $contentDisposition = $this->fileService->getContentDispositionHeader($file->original_name);
            
            return response($fileContents, 200, [
                'Content-Type' => $file->mime_type,
                'Content-Disposition' => $contentDisposition,
                'Content-Length' => $file->size_bytes,
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0'
            ]);
        } catch (\Exception $e) {
            abort(500, 'Failed to download file');
        }
    }

    /**
     * Alternative: Stream large files efficiently (for files > 50MB)
     * 
     * For very large files, consider using this approach instead:
     * 
     * return response()->streamDownload(function() use ($file) {
     *     $stream = Storage::disk('s3')->readStream($file->hash);
     *     fpassthru($stream);
     *     fclose($stream);
     * }, $file->original_name, [
     *     'Content-Type' => $file->mime_type,
     *     'Content-Length' => $file->size_bytes,
     * ]);
     */



    /**
     * Delete file attachment.
     */
    public function delete(FileAttachment $attachment): JsonResponse
    {
        // Check if user can access this attachment
        if (!$this->fileService->canUserAccessAttachment($attachment, Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $this->fileService->detachFile($attachment, Auth::id());

            return response()->json(['message' => 'File attachment deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Check if user can access transaction.
     */
    private function canUserAccessTransaction(Transaction $transaction, int $userId): bool
    {
        return $transaction->budget->user_id === $userId ||
               $transaction->budget->connected_users()->where('user_id', $userId)->exists();
    }

    /**
     * Check if user can access budget.
     */
    private function canUserAccessBudget(Budget $budget, int $userId): bool
    {
        return $budget->user_id === $userId ||
               $budget->connected_users()->where('user_id', $userId)->exists();
    }
}
