<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['title' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:200'], 'content' => ['nullable', 'string', 'max:10000'], 'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'], 'staff_id' => ['prohibited'], 'id' => ['prohibited']];
    }
}
