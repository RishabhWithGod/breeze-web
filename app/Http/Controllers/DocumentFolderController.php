<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentFolder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DocumentFolderController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', 'exists:document_folders,id'],
            'job_id' => ['nullable', 'integer', 'exists:work_jobs,id'],
        ]);

        $folder = DocumentFolder::create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', "Folder “{$folder->name}” was created.");
    }
}
