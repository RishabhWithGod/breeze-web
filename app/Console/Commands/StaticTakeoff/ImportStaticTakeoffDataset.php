<?php

namespace App\Console\Commands\StaticTakeoff;

use App\Models\StaticTakeoffDataset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Registers a static takeoff dataset: a PDF plus the takeoff response (and,
 * optionally, estimate/BOQ data) that static mode should answer with when it
 * sees that exact file again.
 *
 * The AI engine's own `AnalysisResult` shape is what `payload` must contain
 * (see `App\Services\Ai\AiResponseNormaliser`) — `symbols`/`needs_review` at a
 * minimum, since that is the same "no usable content" rule the dynamic
 * ingest pipeline already enforces.
 */
class ImportStaticTakeoffDataset extends Command
{
    protected $signature = 'takeoff:static-import
        {pdf : Path to the reference PDF}
        {payload : Path to the takeoff/AI response JSON (AnalysisResult shape)}
        {--estimate= : Path to a separate estimate/BOQ JSON, merged into the payload}
        {--name= : Friendly name for this dataset}
        {--inactive : Import as inactive (not matched until activated)}';

    protected $description = 'Register a static (DB-backed) takeoff dataset for a known PDF, matched by content hash';

    public function handle(): int
    {
        $pdfPath = $this->argument('pdf');
        $payloadPath = $this->argument('payload');

        if (! is_file($pdfPath)) {
            $this->error("PDF not found: {$pdfPath}");

            return self::FAILURE;
        }

        if (str_ends_with(strtolower($pdfPath), '.pdf') === false) {
            $this->error('The reference file must be a .pdf.');

            return self::FAILURE;
        }

        if (! is_file($payloadPath)) {
            $this->error("Payload JSON not found: {$payloadPath}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($payloadPath), true);

        if (! is_array($payload)) {
            $this->error('Payload file is not valid JSON.');

            return self::FAILURE;
        }

        if (empty($payload['symbols']) && empty($payload['needs_review'])) {
            $this->error('Payload must contain at least one of "symbols" or "needs_review" — an empty takeoff cannot be reviewed.');

            return self::FAILURE;
        }

        $metadata = [];

        if ($estimatePath = $this->option('estimate')) {
            if (! is_file($estimatePath)) {
                $this->error("Estimate JSON not found: {$estimatePath}");

                return self::FAILURE;
            }

            $estimate = json_decode((string) file_get_contents($estimatePath), true);

            if (! is_array($estimate)) {
                $this->error('Estimate file is not valid JSON.');

                return self::FAILURE;
            }

            if (isset($estimate['estimate']) && is_array($estimate['estimate'])) {
                $payload['estimate'] = $estimate['estimate'];
            } elseif (isset($estimate['subtotal']) || isset($estimate['grand_total'])) {
                $payload['estimate'] = $estimate;
            }

            if (isset($estimate['boq']) && is_array($estimate['boq'])) {
                $payload['boq'] = $estimate['boq'];
            }

            $metadata['imported_estimate_source'] = $estimate;
        }

        $hash = hash_file('sha256', $pdfPath);
        $storedPath = "static-takeoffs/{$hash}.pdf";
        Storage::disk('local')->put($storedPath, (string) file_get_contents($pdfPath));

        $dataset = StaticTakeoffDataset::updateOrCreate(
            ['file_hash' => $hash],
            [
                'name' => $this->option('name') ?: basename($pdfPath),
                'original_filename' => basename($pdfPath),
                'file_size' => filesize($pdfPath),
                'mime_type' => 'application/pdf',
                'pdf_path' => $storedPath,
                'takeoff_payload' => $payload,
                'metadata' => $metadata === [] ? null : $metadata,
                'is_active' => ! $this->option('inactive'),
            ],
        );

        $this->info("Static takeoff dataset #{$dataset->id} ({$dataset->name}) registered for hash {$hash}.");

        return self::SUCCESS;
    }
}
