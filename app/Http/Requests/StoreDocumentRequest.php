<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Plusieurs PDF possibles (un document par fichier, mêmes signataires) ; « document » reste accepté seul.
            'documents' => ['required_without:document', 'array', 'min:1', 'max:20'],
            'documents.*' => ['file', 'mimes:pdf', 'max:25600'],
            'document' => ['nullable', 'file', 'mimes:pdf', 'max:25600'],
            'self_sign' => ['nullable', 'boolean'],
            'signers' => ['nullable', 'array', 'max:20'],
            'signers.*' => ['nullable', 'email:rfc', 'distinct'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'document_type' => ['required', 'string', Rule::exists('document_types', 'name')->where('is_active', true)],
        ];
    }

    /**
     * Au moins un signataire : une adresse saisie ou soi-même.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('self_sign') && $this->signerEmails() === []) {
                    $validator->errors()->add('signers', 'Indiquez au moins un signataire, ou cochez « Je signe moi-même ».');
                }
            },
        ];
    }

    /**
     * Adresses saisies, sans les lignes vides.
     *
     * @return list<string>
     */
    public function signerEmails(): array
    {
        return array_values(array_filter((array) $this->input('signers', []), fn ($email) => filled($email)));
    }

    /**
     * Fichiers PDF envoyés.
     *
     * @return list<UploadedFile>
     */
    public function pdfFiles(): array
    {
        return array_values(array_filter($this->file('documents') ?? [$this->file('document')]));
    }
}
