<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentNumberSequence extends Model
{
    protected $fillable = ['branch_id', 'document_type', 'period', 'last_number'];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
