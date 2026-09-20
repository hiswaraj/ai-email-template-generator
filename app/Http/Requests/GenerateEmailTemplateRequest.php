<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purpose' => ['required', 'string', 'min:3', 'max:500'],
            'recipient_name' => ['required', 'string', 'min:2', 'max:100'],
            'tone' => ['required', 'string', 'min:2', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'purpose.required' => 'The email purpose is required.',
            'purpose.max' => 'The email purpose may not exceed 500 characters.',
            'recipient_name.required' => 'The recipient name is required.',
            'recipient_name.max' => 'The recipient name may not exceed 100 characters.',
            'tone.required' => 'The email tone is required.',
        ];
    }
}
