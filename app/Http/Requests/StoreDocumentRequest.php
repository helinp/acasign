<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) ($this->user()?->can_send || $this->user()?->isAdmin());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'document' => ['required', 'file', 'mimes:pdf', 'max:25600'],
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*' => ['required', 'email:rfc', 'distinct'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'document_type' => ['required', 'string', Rule::exists('document_types', 'name')->where('is_active', true)],
        ];
    }
}
