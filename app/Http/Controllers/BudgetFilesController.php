<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\FileAttachment;
use App\Services\FileService;
use Diglactic\Breadcrumbs\Breadcrumbs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class BudgetFilesController extends Controller
{
    public function __construct(private FileService $fileService) {}

    public function index(Request $request, Budget $budget)
    {
        $this->authorize('view', $budget);

        // The dashboard loads its attachment list as JSON from this URL.
        if ($request->wantsJson()) {
            return app(FileController::class)->getBudgetAttachments($budget);
        }

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

        return $this->fileService->downloadResponse($fileAttachment);
    }

    public function destroy(Budget $budget, FileAttachment $fileAttachment)
    {
        $this->authorize('update', $budget);

        if ($fileAttachment->attachable_id !== $budget->id || $fileAttachment->attachable_type !== Budget::class) {
            abort(404);
        }

        // Removes the attachment, and the file and its S3 object once unused.
        $this->fileService->detachFile($fileAttachment, Auth::id());

        return redirect()->back()->with('message', 'File deleted successfully.');
    }
}
