<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Upload;
use App\Services\Takeoff\ProjectDocumentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A client's drawing PDFs: opened, chosen and removed.
 *
 * Not added — a PDF only ever arrives through AI Takeoff now, which uploads it
 * against a client that already exists. This screen shows the set that upload
 * produced, and which of them the next takeoff will run against.
 */
class ProjectDocumentController extends Controller
{
    public function __construct(private readonly ProjectDocumentStore $documents) {}

    /**
     * Chooses the drawing the next takeoff runs against.
     *
     * `drawing_name` is kept in step because it is the copy every list and
     * every finished takeoff reads — leaving it behind would have the client
     * screen and the Clients list naming two different drawings.
     */
    public function select(Project $project, Upload $document): RedirectResponse
    {
        $this->authorize('update', $project);
        abort_unless($document->project_id === $project->id, 404);

        $project->update([
            'selected_upload_id' => $document->id,
            'drawing_name' => $document->name,
        ]);

        $project->activities()->create([
            'title' => 'Drawing selected',
            'description' => "“{$document->label()}” is the drawing the next takeoff will run against",
            'tone' => 'info',
            'occurred_at' => now(),
        ]);

        return back()->with('success', "“{$document->label()}” is now the selected drawing.");
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

        /*
         * The removed PDF may have been the selected one. The foreign key has
         * already cleared the choice, so `takeoffDrawing()` falls back to the
         * first still on record — and the name follows it.
         */
        $project->refresh();
        $project->update(['drawing_name' => $project->takeoffDrawing()?->name]);

        $project->activities()->create([
            'title' => 'Drawing removed',
            'description' => "“{$label}” was removed from the project",
            'tone' => 'warning',
            'occurred_at' => now(),
        ]);

        return back()->with('warning', "“{$label}” was removed.");
    }
}
