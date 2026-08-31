<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\DefinesProjectDocuments;
use Illuminate\Foundation\Http\FormRequest;

/** Adding drawing PDFs to a project that already exists. */
class StoreProjectDocumentRequest extends FormRequest
{
    use DefinesProjectDocuments;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->documentRules(required: true);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->documentMessages();
    }
}
