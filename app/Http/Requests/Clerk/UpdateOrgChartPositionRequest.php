<?php

namespace App\Http\Requests\Clerk;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateOrgChartPositionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Role and tenant authorization are enforced by middleware and controller.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $barangayId = $this->user()->barangay_id;

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'assigned_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('org_chart_positions', 'id')->where(function ($query) use ($barangayId) {
                    $query->where('barangay_id', $barangayId);
                }),
            ],
            'photo' => ['nullable', 'image', 'max:2048'],
        ];
    }

    /** Reject an unparsed/empty edit instead of reporting a successful save. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $fields = array_intersect(['title', 'assigned_person', 'parent_id'], array_keys($this->all()));
            if ($fields === [] && ! $this->hasFile('photo')) {
                $validator->errors()->add('title', 'No changes were received. Please submit your edits again.');
            }
        }];
    }
}
