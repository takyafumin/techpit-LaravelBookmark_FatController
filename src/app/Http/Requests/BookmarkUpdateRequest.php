<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookmarkUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return arrays
     */
    public function rules()
    {
        return [
            'comment' => 'required|string|min:10|max:1000',
            'category' => 'required|integer|exists:bookmark_categories,id',
        ];
    }
}
