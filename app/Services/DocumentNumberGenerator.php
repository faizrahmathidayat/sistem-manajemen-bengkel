<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DocumentNumberSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DocumentNumberGenerator
{
    public function next(Branch $branch, string $documentType, string $format = '{type}/{branch}/{period}/{number:5}'): string
    {
        return DB::transaction(function () use ($branch, $documentType, $format) {
            $period = now()->timezone(config('app.business_timezone', 'Asia/Jakarta'))->format('Ym');

            $sequence = DocumentNumberSequence::where('branch_id', $branch->id)
                ->where('document_type', $documentType)
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                try {
                    DocumentNumberSequence::create([
                        'branch_id' => $branch->id,
                        'document_type' => $documentType,
                        'period' => $period,
                        'last_number' => 0,
                    ]);
                } catch (QueryException $e) {
                    if (($e->errorInfo[1] ?? null) !== 1062) {
                        throw $e;
                    }
                    // Another concurrent transaction created the row first — fall through and lock it below.
                }

                $sequence = DocumentNumberSequence::where('branch_id', $branch->id)
                    ->where('document_type', $documentType)
                    ->where('period', $period)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $sequence->last_number += 1;
            $sequence->save();

            return $this->format($format, $branch, $documentType, $period, $sequence->last_number);
        });
    }

    protected function format(string $format, Branch $branch, string $documentType, string $period, int $number): string
    {
        $formatted = preg_replace_callback('/\{number(?::(\d+))?\}/', function ($matches) use ($number) {
            $pad = isset($matches[1]) ? (int) $matches[1] : 5;
            return str_pad((string) $number, $pad, '0', STR_PAD_LEFT);
        }, $format);

        return strtr($formatted, [
            '{type}' => strtoupper($documentType),
            '{branch}' => strtoupper($branch->code),
            '{period}' => $period,
        ]);
    }
}
