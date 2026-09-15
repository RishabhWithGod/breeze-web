<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add Project: whose it is, what it is called, and — optionally — the budget
 * its estimate is meant to land on.
 *
 * Everything else about the work itself — its counts, its bill of quantities,
 * its price — comes from the takeoff that runs against it. Asking here would
 * be asking someone to guess. The place comes from the client's address book.
 *
 * No drawings are accepted either. A PDF only ever arrives through AI Takeoff,
 * uploaded against a project that already exists, so the product has one
 * upload path rather than three.
 */
class StoreProjectRequest extends FormRequest
{
    /** The form posts an empty string when the field is left blank. */
    protected function prepareForValidation(): void
    {
        if ($this->input('estimate_target_total') === '') {
            $this->merge(['estimate_target_total' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            // A project is always for somebody.
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            /*
             * No address here. A project and the job on it are at the same
             * place, and that place is in the client's address book — asking
             * again on this form would be a second copy free to drift.
             */
            // The budget an estimate raised on this project should land on.
            // Optional — most projects still price off the takeoff alone.
            'estimate_target_total' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            /*
             * This project's own vendor rates. Optional — while nothing has
             * been uploaded, its estimates fall back to the price book
             * instead. Any format a vendor actually sends: an Excel workbook,
             * a PDF quote sheet, or a Word document (.doc/.docx), plus RTF
             * and OpenDocument text for whatever export produced those —
             * {@see \App\Services\Estimating\RateListReader} reads whichever
             * one it is. Any number of files per submit; their lines are
             * pooled into this project's one rate book.
             */
            'vendor_rate_list' => ['nullable', 'array', 'max:20'],
            'vendor_rate_list.*' => ['file', 'mimes:xlsx,pdf,doc,docx,rtf,odt', 'max:10240'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Project name is required',
            'client_id.required' => 'Pick the client this project is for',
            'name.min' => 'Use at least 3 characters',
            'estimate_target_total.numeric' => 'Enter a valid amount',
            'estimate_target_total.min' => 'Amount cannot be negative',
            'vendor_rate_list.max' => 'Upload up to 20 files at a time',
            'vendor_rate_list.*.mimes' => 'Upload an Excel, PDF, or Word (.doc/.docx) rate list',
            'vendor_rate_list.*.max' => 'Each file must be smaller than 10MB',
        ];
    }
}
