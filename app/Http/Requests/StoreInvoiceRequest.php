<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Ownership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Invoice::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $userId = Ownership::userIdList($this->user());

        return [
            /** The client, picked from the client register — see ClientDirectory. */
            // Who the work is for. What it is on is the project, which an
            // estimate or invoice inherits from the job it belongs to.
            //
            // Each of these must be one of this manager's own rows: refused
            // here rather than trusted, so a hand-made request cannot raise an
            // invoice against another manager's client, job or estimate.
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereIn('user_id', $userId)],
            'job_id' => ['nullable', 'integer', Rule::exists('work_jobs', 'id')->whereIn('user_id', $userId)],
            // An invoice bills an estimate — every one is raised from one.
            'estimate_id' => ['required', 'integer', Rule::exists('estimates', 'id')->whereIn('user_id', $userId)],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'tax_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            /*
             * The lines, when the screen sends them. Sent, they are the invoice's
             * lines exactly — copied from an estimate and edited, or typed — and the
             * estimate is not copied a second time behind them. Left out, an
             * estimate still copies itself in, as it always did.
             */
            'items' => ['nullable', 'array', 'max:200'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.source_category' => ['nullable', Rule::in([
                InvoiceItem::CATEGORY_LABOR,
                InvoiceItem::CATEGORY_MATERIAL,
                InvoiceItem::CATEGORY_EQUIPMENT,
                InvoiceItem::CATEGORY_OTHER,
            ])],
            // Where the line came from — the screen knows, since it built them.
            'items.*.source' => ['nullable', Rule::in([InvoiceItem::SOURCE_ESTIMATE, InvoiceItem::SOURCE_MANUAL])],
            'items.*.quantity' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:100000000'],
            /** "Issue Invoice": raised and sent in one go — needs at least one line. */
            'issue' => ['nullable', 'boolean'],
        ];
    }

    /** An issued invoice is not sent empty. */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->boolean('issue') && $this->input('items') !== null && count($this->input('items')) === 0) {
                $validator->errors()->add('items', 'Add at least one line item before issuing this invoice.');
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'client_id.required' => 'Client is required',
            'client_id.exists' => 'Pick a client from the list',
            'estimate_id.required' => 'Pick the estimate this invoice bills',
            'estimate_id.exists' => 'Pick an estimate from the list',
            'invoice_date.required' => 'Invoice date is required',
            'due_date.after_or_equal' => 'Due date cannot be before the invoice date',
            'items.*.description.required' => 'Every line needs a description',
        ];
    }
}
