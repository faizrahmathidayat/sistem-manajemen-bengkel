<?php

namespace App\Services;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestApproval;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The approval message sent to an approver on Telegram: request summary, the items being approved
 * and inline buttons. Callback data: a = ask to confirm approving, y = confirm, n = back,
 * r = reject (asks for a reason), t = toggle an item; shaped "<action>:<request>:<step>[:<line>]".
 * The step number makes a button on an old message harmless once that step was decided.
 */
class TelegramApprovalMessage
{
    const MAX_TOGGLES = 30;
    const MAX_ITEM_LINES = 25;

    /** Line ids currently checked by this approver (default: every line still selected on the request). */
    public static function selectedIds(PurchaseRequest $request, PurchaseRequestApproval $step, User $user): array
    {
        $stored = Cache::get(self::cacheKey($request, $step, $user));
        $current = $request->lines->filter(function ($line) {
            return (bool) $line->is_selected;
        })->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();

        if (! is_array($stored)) {
            return $current;
        }

        return array_values(array_intersect($current, array_map('intval', $stored)));
    }

    public static function storeSelection(PurchaseRequest $request, PurchaseRequestApproval $step, User $user, array $ids): void
    {
        Cache::put(self::cacheKey($request, $step, $user), array_values($ids), now()->addDay());
    }

    public static function forgetSelection(PurchaseRequest $request, PurchaseRequestApproval $step, User $user): void
    {
        Cache::forget(self::cacheKey($request, $step, $user));
    }

    /** @return array{text:string, reply_markup:array} */
    public static function build(PurchaseRequest $request, PurchaseRequestApproval $step, User $user, bool $confirm = false): array
    {
        $request->loadMissing(['branch', 'creator', 'lines.sparepartBranch.sparepart', 'lines.supplier']);
        $selectable = $step->maySelectItems();
        $selected = self::selectedIds($request, $step, $user);

        $lines = $request->lines->filter(function ($line) {
            return (bool) $line->is_selected;
        })->values();
        $total = 0.0;
        $text = ($request->is_urgent ? '[URGENT] ' : '') . "Approval {$request->number}\n"
            . "Cabang: {$request->branch->name} | Pemohon: " . (optional($request->creator)->name ?? '-') . "\n"
            . "Level Anda: {$step->level_name}\n\nBarang:\n";

        foreach ($lines->take(self::MAX_ITEM_LINES) as $line) {
            $on = in_array((int) $line->id, $selected, true);
            $mark = $selectable ? ($on ? '✅ ' : '⬜ ') : '• ';
            $supplier = $line->supplier ? ' (' . $line->supplier->name . ')' : '';
            $text .= $mark . $line->sparepartBranch->sparepart->name . ' - '
                . self::qty($line->qty) . ' x Rp ' . self::money($line->unit_price) . $supplier . "\n";
        }
        if ($lines->count() > self::MAX_ITEM_LINES) {
            $text .= '... dan ' . ($lines->count() - self::MAX_ITEM_LINES) . " barang lainnya\n";
        }
        foreach ($lines as $line) {
            if (in_array((int) $line->id, $selected, true)) {
                $total += round((float) $line->qty * (float) $line->unit_price, 2);
            }
        }
        $text .= "\nTotal: Rp " . self::money($total) . ($selectable ? ' (' . count($selected) . ' dari ' . $lines->count() . ' barang dipilih)' : '');

        $base = rtrim((string) (config('services.approval_notifications.base_url') ?: config('app.url')), '/');
        $text .= "\nDetail: " . $base . route('purchase-requests.show', $request, false);

        $key = ":{$request->id}:{$step->step_no}";
        if ($confirm) {
            $text .= "\n\nYakin menyetujui" . ($selectable ? ' ' . count($selected) . ' barang ini' : '') . '?';
            $rows = [[
                ['text' => '✅ Ya, setujui', 'callback_data' => 'y' . $key],
                ['text' => '↩ Batal', 'callback_data' => 'n' . $key],
            ]];
        } else {
            $rows = [];
            if ($selectable && $lines->count() <= self::MAX_TOGGLES) {
                foreach ($lines as $line) {
                    $on = in_array((int) $line->id, $selected, true);
                    $rows[] = [['text' => ($on ? '✅ ' : '⬜ ') . self::short($line->sparepartBranch->sparepart->name) . ' x' . self::qty($line->qty), 'callback_data' => 't' . $key . ':' . $line->id]];
                }
            }
            $rows[] = [
                ['text' => '✅ Setuju', 'callback_data' => 'a' . $key],
                ['text' => '❌ Tolak', 'callback_data' => 'r' . $key],
            ];
        }

        return ['text' => mb_substr($text, 0, 4000), 'reply_markup' => ['inline_keyboard' => $rows]];
    }

    protected static function cacheKey(PurchaseRequest $request, PurchaseRequestApproval $step, User $user): string
    {
        return "tg_sel:{$request->id}:{$step->step_no}:{$user->id}";
    }

    protected static function money($value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    protected static function qty($value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    protected static function short(string $name): string
    {
        return mb_strlen($name) > 28 ? mb_substr($name, 0, 27) . '…' : $name;
    }
}
