<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoiceNumber' => $this->invoice_number,
            'client' => $this->client,
            'jobId' => $this->job_id,
            'jobName' => $this->job?->name,
            // ISO strings throughout — the client formats with date-fns.
            'date' => $this->invoice_date->toISOString(),
            'dueDate' => $this->due_date?->toISOString(),
            'total' => (float) $this->total,
            'paidAmount' => (float) $this->paid_amount,
            'outstanding' => $this->outstanding(),
            'status' => $this->displayStatus(),
            'isEditable' => $this->isEditable(),
        ];
    }
}
