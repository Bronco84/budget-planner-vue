<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\File;
use App\Models\FileAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Diglactic\Breadcrumbs\Breadcrumbs;

class BudgetFilesController extends Controller
{
    public function index(Budget $budget)
    {
        $this->authorize('view', $budget);

        $files = $budget->fileAttachments()
            ->with('file')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($attachment) {
                $file = $attachment->file;
                return [
                    'id' => $attachment->id,
                    'original_filename' => $file->original_name,
                    'mime_type' => $file->mime_type,
                    'size' => $file->size_bytes,
                    'created_at' => $attachment->created_at,
                ];
            });

        return Inertia::render('Budgets/Files/Index', [
            'budget' => $budget,
            'files' => $files,
        ])->with('breadcrumbs', function () use ($budget) {
            return Breadcrumbs::generate('budgets.files.index', $budget);
        });
    }

    public function download(Budget $budget, FileAttachment $fileAttachment)
    {
        $this->authorize('view', $budget);

        if ($fileAttachment->attachable_id !== $budget->id || $fileAttachment->attachable_type !== Budget::class) {
            abort(404);
        }

        $file = $fileAttachment->file;
        $path = 'files/' . $file->hash;

        return Storage::download($path, $file->original_name);
    }

    public function destroy(Budget $budget, FileAttachment $fileAttachment)
    {
        $this->authorize('update', $budget);

        if ($fileAttachment->attachable_id !== $budget->id || $fileAttachment->attachable_type !== Budget::class) {
            abort(404);
        }

        $file = $fileAttachment->file;
        $path = 'files/' . $file->hash;

        // Delete the attachment record
        $fileAttachment->delete();

        // Check if this file is still attached to other entities
        $remainingAttachments = FileAttachment::where('file_id', $file->id)->count();

        // If no other attachments exist, delete the file from storage and database
        if ($remainingAttachments === 0) {
            if (Storage::exists($path)) {
                Storage::delete($path);
            }
            $file->delete();
        }

        return redirect()->back()->with('success', 'File deleted successfully.');
    }
}

