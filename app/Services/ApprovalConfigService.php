<?php

namespace App\Services;

use App\Models\ApprovalLevel;
use App\Models\ApprovalRule;
use App\Models\User;
use App\Support\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin-side configuration of the dynamic approval chain: levels (+ approver users) and the
 * amount rules that pick which levels apply. Both save methods validate the whole submitted
 * list first and throw ValidationException (keyed 'levels' / 'rules') before touching data.
 */
class ApprovalConfigService
{
    /**
     * @param array $items each: ['id' => ?int, 'name' => string, 'is_active' => bool,
     *                     'user_ids' => int[], 'delete' => ?bool]; array order = sort_order.
     */
    public function saveLevels(array $items): void
    {
        $levels = $this->normalizeLevels($items);
        $this->validateLevels($levels);

        DB::transaction(function () use ($levels) {
            $old = $this->snapshotLevels();
            $order = 1;

            foreach ($levels as $item) {
                if ($item['delete']) {
                    if ($item['id']) {
                        ApprovalLevel::whereKey($item['id'])->delete();
                    }
                    continue;
                }

                $level = $item['id'] ? ApprovalLevel::findOrFail($item['id']) : new ApprovalLevel();
                $level->name = $item['name'];
                $level->sort_order = $order++;
                $level->is_active = $item['is_active'];
                $level->save();
                $level->users()->sync($item['user_ids']);
            }

            (new AuditLogger())->log(
                AuditEvent::APPROVAL_SETTING_CHANGED, null, null,
                ['levels' => $old], ['levels' => $this->snapshotLevels()]
            );
        });
    }

    /**
     * @param array $items each: ['max_amount' => string|int|null, 'level_ids' => int[]];
     *                     array order = rule order. max_amount may carry thousand separators.
     */
    public function saveRules(array $items): void
    {
        $rules = $this->normalizeRules($items);
        $this->validateRules($rules);

        DB::transaction(function () use ($rules) {
            $old = $this->snapshotRules();

            ApprovalRule::query()->delete(); // approval_rule_levels cascade

            foreach ($rules as $index => $item) {
                $rule = ApprovalRule::create(['max_amount' => $item['max_amount'], 'sort_order' => $index + 1]);
                $rule->levels()->sync($item['level_ids']);
            }

            (new AuditLogger())->log(
                AuditEvent::APPROVAL_SETTING_CHANGED, null, null,
                ['rules' => $old], ['rules' => $this->snapshotRules()]
            );
        });
    }

    protected function normalizeLevels(array $items): array
    {
        $levels = [];
        foreach (array_values($items) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $levels[] = [
                'id' => isset($item['id']) && $item['id'] !== '' ? (int) $item['id'] : null,
                'name' => trim((string) (isset($item['name']) ? $item['name'] : '')),
                'is_active' => filter_var(isset($item['is_active']) ? $item['is_active'] : false, FILTER_VALIDATE_BOOLEAN),
                'delete' => filter_var(isset($item['delete']) ? $item['delete'] : false, FILTER_VALIDATE_BOOLEAN),
                'user_ids' => array_values(array_unique(array_map('intval', (array) (isset($item['user_ids']) ? $item['user_ids'] : [])))),
            ];
        }

        return $levels;
    }

    protected function validateLevels(array $levels): void
    {
        $errors = [];
        $kept = array_values(array_filter($levels, function ($level) {
            return ! $level['delete'];
        }));

        $seen = [];
        foreach ($kept as $level) {
            if ($level['name'] === '') {
                $errors[] = 'Nama level wajib diisi.';
                continue;
            }
            if (mb_strlen($level['name']) > 100) {
                $errors[] = "Nama level \"{$level['name']}\" terlalu panjang (maksimal 100 karakter).";
            }
            $key = mb_strtolower($level['name']);
            if (isset($seen[$key])) {
                $errors[] = "Nama level \"{$level['name']}\" dipakai dua kali.";
            }
            $seen[$key] = true;
        }

        $existingIds = ApprovalLevel::pluck('id')->all();
        $usedIds = DB::table('approval_rule_levels')->pluck('approval_level_id')->map(function ($id) {
            return (int) $id;
        })->all();

        foreach ($levels as $level) {
            if ($level['id'] && ! in_array($level['id'], $existingIds, true)) {
                $errors[] = 'Level yang dipilih tidak ditemukan.';
                continue;
            }

            $used = $level['id'] && in_array($level['id'], $usedIds, true);
            if ($used && $level['delete']) {
                $errors[] = "Level \"{$level['name']}\" masih dipakai aturan nominal dan tidak dapat dihapus.";
            }
            if ($used && ! $level['delete'] && ! $level['is_active']) {
                $errors[] = "Level \"{$level['name']}\" masih dipakai aturan nominal dan tidak dapat dinonaktifkan.";
            }
            if ($level['delete']) {
                continue;
            }

            if (! empty($level['user_ids'])) {
                $activeCount = User::whereIn('id', $level['user_ids'])->where('is_active', true)->count();
                if ($activeCount !== count($level['user_ids'])) {
                    $errors[] = "Level \"{$level['name']}\" memuat user yang tidak aktif atau tidak ditemukan.";
                }
            }
        }

        $this->failIf($errors, 'levels');
    }

    protected function normalizeRules(array $items): array
    {
        $rules = [];
        foreach (array_values($items) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $digits = preg_replace('/\D/', '', (string) (isset($item['max_amount']) ? $item['max_amount'] : ''));
            $rules[] = [
                'max_amount' => $digits === '' ? null : (int) $digits,
                'level_ids' => array_values(array_unique(array_map('intval', (array) (isset($item['level_ids']) ? $item['level_ids'] : [])))),
            ];
        }

        return $rules;
    }

    protected function validateRules(array $rules): void
    {
        $errors = [];
        if (empty($rules)) {
            $this->failIf(['Minimal satu aturan approval diperlukan.'], 'rules');
        }

        $activeLevelIds = ApprovalLevel::where('is_active', true)->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();
        $lastIndex = count($rules) - 1;
        $previous = null;

        foreach ($rules as $index => $rule) {
            $number = $index + 1;

            if ($index === $lastIndex) {
                if ($rule['max_amount'] !== null) {
                    $errors[] = 'Aturan terakhir harus tanpa batas atas (berlaku untuk nominal di atas batas sebelumnya).';
                }
            } else {
                if ($rule['max_amount'] === null || $rule['max_amount'] <= 0) {
                    $errors[] = "Aturan #{$number} harus memiliki batas atas lebih dari 0.";
                } elseif ($previous !== null && $rule['max_amount'] <= $previous) {
                    $errors[] = "Batas atas aturan #{$number} harus lebih besar dari aturan sebelumnya.";
                }
                if ($rule['max_amount'] !== null) {
                    $previous = $rule['max_amount'];
                }
            }

            if (empty($rule['level_ids'])) {
                $errors[] = "Aturan #{$number} harus memiliki minimal satu level.";
            } else {
                foreach ($rule['level_ids'] as $levelId) {
                    if (! in_array($levelId, $activeLevelIds, true)) {
                        $errors[] = "Aturan #{$number} memuat level yang tidak aktif atau tidak ditemukan.";
                        break;
                    }
                }
            }
        }

        $this->failIf($errors, 'rules');
    }

    protected function failIf(array $errors, string $key): void
    {
        if (! empty($errors)) {
            throw ValidationException::withMessages([$key => array_values(array_unique($errors))]);
        }
    }

    protected function snapshotLevels(): array
    {
        return ApprovalLevel::with('users:id')->orderBy('sort_order')->get()->map(function ($level) {
            return [
                'id' => $level->id,
                'name' => $level->name,
                'sort_order' => $level->sort_order,
                'is_active' => $level->is_active,
                'user_ids' => $level->users->pluck('id')->all(),
            ];
        })->all();
    }

    protected function snapshotRules(): array
    {
        return ApprovalRule::with('levels:id')->orderBy('sort_order')->get()->map(function ($rule) {
            return [
                'max_amount' => $rule->max_amount,
                'level_ids' => $rule->levels->pluck('id')->all(),
            ];
        })->all();
    }
}
