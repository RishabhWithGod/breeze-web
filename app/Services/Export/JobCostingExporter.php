<?php

namespace App\Services\Export;

use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Exports the Job Costing dashboard's own rows — one per job, the exact
 * figures on screen for the filters in effect, so an export never disagrees
 * with what was just looked at.
 */
class JobCostingExporter
{
    private const HEADINGS = [
        'Job', 'Client', 'Status',
        'Estimated Labor Hours', 'Actual Labor Hours', 'Estimated Labor Cost', 'Actual Labor Cost',
        'Estimated Material Cost', 'Actual Material Cost',
        'Estimated Equipment Cost', 'Actual Equipment Cost',
        'Estimated Total Cost', 'Actual Total Cost', 'Variance', 'Variance %',
        'Revenue', 'Billed', 'Paid', 'Outstanding',
        'Profit', 'Margin %',
    ];

    /** @param  Collection<int, array<string, mixed>>  $rows Each a `JobCostSummary::for()` result. */
    public function csv(Collection $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::HEADINGS);

        foreach ($this->rows($rows) as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    public function xlsx(Collection $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'job_costing_').'.xlsx';

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Job Costing');

        $header = (new Style)->setFontBold();
        $writer->addRow(Row::fromValues(self::HEADINGS, $header));

        foreach ($this->rows($rows) as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $path;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<list<string|int|float>>
     */
    private function rows(Collection $rows): array
    {
        return $rows->map(fn (array $row) => [
            $row['jobName'],
            $row['client'] ?? '',
            ucfirst(str_replace('-', ' ', $row['jobStatus'])),
            $row['estimatedLaborHours'], $row['actualLaborHours'],
            $row['estimatedLaborCost'], $row['actualLaborCost'],
            $row['estimatedMaterialCost'], $row['actualMaterialCost'],
            $row['estimatedEquipmentCost'], $row['actualEquipmentCost'],
            $row['estimatedTotalCost'], $row['actualTotalCost'],
            $row['totalCostVariance'], $row['totalCostVariancePct'] ?? '',
            $row['revenue'], $row['billed'], $row['paid'], $row['outstanding'],
            $row['profit'], $row['marginPct'] ?? '',
        ])->values()->all();
    }
}
