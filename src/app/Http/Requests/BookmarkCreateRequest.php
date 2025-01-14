<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookmarkCreateRequest extends FormRequest
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
     * @return array
     */
    public function rules()
    {
        return [
            'url' => 'required|string|url',
            'comment' => 'required|string|min:10|max:1000',
            'category' => 'required|integer|exists:bookmark_categories,id',
        ];
    }
}
