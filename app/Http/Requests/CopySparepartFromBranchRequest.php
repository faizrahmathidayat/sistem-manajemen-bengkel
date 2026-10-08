<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CopySparepartFromBranchRequest extends FormRequest
{
    public function authorize()
    {
        $targetId = (int) $this->input('branch_id');
        $sourceId = (int) $this->input('source_branch_id');
        $user = $this->user();

        return $targetId
            && $sourceId
            && $user->hasPermissionToInBranch('sparepart.create', $targetId)
            && $user->hasPermissionToInBranch('sparepart.view', $sourceId);
    }

    public function rules()
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'source_branch_id' => ['required', 'integer', 'exists:branches,id', 'different:branch_id'],
        ];
    }

    public function messages()
    {
        return [
            'source_branch_id.required' => 'Pilih cabang asal terlebih dahulu.',
            'source_branch_id.different' => 'Cabang asal harus berbeda dari cabang tujuan.',
        ];
    }
}
