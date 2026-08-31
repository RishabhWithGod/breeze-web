<?php

namespace App\Services\Export;

use App\Models\TimeEntry;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Exports a set of time entries — the same rows the report screen shows, so an
 * export always matches what is on screen.
 */
class TimesheetExporter
{
    private const HEADINGS = [
        'Date', 'Team member', 'Job', 'Task', 'Start', 'End', 'Break (min)',
        'Hours', 'Regular', 'Overtime', 'Billable', 'Status',
    ];

    /** @param  Collection<int, TimeEntry>  $entries */
    public function csv(Collection $entries): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::HEADINGS);

        foreach ($this->rows($entries) as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /**
     * Writes a real .xlsx workbook to a temporary file; the caller streams it
     * and deletes it.
     *
     * @param  Collection<int, TimeEntry>  $entries
     */
    public function xlsx(Collection $entries, string $sheetName = 'Time Entries'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'timesheet_').'.xlsx';

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(str($sheetName)->limit(28, '')->value());

        $header = (new Style)->setFontBold();
        $writer->addRow(Row::fromValues(self::HEADINGS, $header));

        foreach ($this->rows($entries) as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $path;
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     * @return list<list<string|int|float>>
     */
    private function rows(Collection $entries): array
    {
        return $entries->map(fn (TimeEntry $entry) => [
            $entry->date->toDateString(),
            $entry->teamMember?->name ?? $entry->user?->name ?? '',
            $entry->job?->name ?? '',
            $entry->jobTask?->title ?? $entry->task_label ?? '',
            $entry->start_time ?? '',
            $entry->end_time ?? '',
            $entry->break_minutes,
            (float) $entry->hours,
            (float) $entry->regular_hours,
            (float) $entry->overtime_hours,
            $entry->billable ? 'Yes' : 'No',
            ucfirst($entry->status),
        ])->values()->all();
    }
}
