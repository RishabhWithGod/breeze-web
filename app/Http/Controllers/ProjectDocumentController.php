<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectDocumentRequest;
use App\Models\Project;
use App\Models\Upload;
use App\Services\Takeoff\ProjectDocumentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The drawing PDFs defined against a project: added, opened and removed. */
class ProjectDocumentController extends Controller
{
    public function __construct(private readonly ProjectDocumentStore $documents) {}

    public function store(StoreProjectDocumentRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $uploads = $this->documents->add(
            $project,
            $request->file('documents'),
            (array) $request->input('document_titles', []),
            $request->user(),
        );

        // The project may not have named a drawing yet — these are its first.
        if (blank($project->drawing_name)) {
            $project->update(['drawing_name' => $uploads[0]->name]);
        }

        $count = count($uploads);

        $project->activities()->create([
            'title' => 'Drawings added',
            'description' => $count.' drawing '.($count === 1 ? 'PDF' : 'PDFs').' added to the project',
            'tone' => 'info',
            'occurred_at' => now(),
        ]);

        return back()->with(
            'success',
            $count === 1
                ? "“{$uploads[0]->label()}” was added."
                : "{$count} PDFs were added."
        );
    }

    /**
     * The PDF itself, streamed inline so the browser's own viewer renders it in
     * place rather than downloading it.
     */
    public function show(Project $project, Upload $document): StreamedResponse
    {
        $this->authorize('view', $project);
        abort_unless($document->project_id === $project->id, 404);

        $disk = Storage::disk((string) config('takeoff.uploads.disk'));
        abort_unless(filled($document->path) && $disk->exists($document->path), 404);

        return $disk->response($document->path, $document->name, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.addslashes($document->name).'"',
        ]);
    }

    public function destroy(Project $project, Upload $document): RedirectResponse
    {
        $this->authorize('update', $project);
        abort_unless($document->project_id === $project->id, 404);

        $label = $document->label();
        $this->documents->remove($document);

        // The removed PDF may have been the drawing the project was named after.
        $project->update(['drawing_name' => $this->documents->primaryDrawingName($project)]);

        $project->activities()->create([
            'title' => 'Drawing removed',
            'description' => "“{$label}” was removed from the project",
            'tone' => 'warning',
            'occurred_at' => now(),
        ]);

        return back()->with('warning', "“{$label}” was removed.");
    }
}
