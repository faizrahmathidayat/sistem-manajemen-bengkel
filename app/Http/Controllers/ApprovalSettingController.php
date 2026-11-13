<?php

namespace App\Http\Controllers;

use App\Exceptions\ApprovalChainException;
use App\Http\Requests\SaveApprovalLevelsRequest;
use App\Http\Requests\SaveApprovalRulesRequest;
use App\Models\ApprovalLevel;
use App\Models\ApprovalRule;
use App\Models\PurchaseRequestApproval;
use App\Models\User;
use App\Services\ApprovalChainResolver;
use App\Services\ApprovalConfigService;
use App\Support\PurchaseRequestApprovalStatus;
use App\Support\PurchaseRequestStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApprovalSettingController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('approval_setting.view');

        $tab = $request->query('tab') === 'rules' ? 'rules' : 'levels';
        $levels = ApprovalLevel::with('users:id,name,username')->withCount('rules')->orderBy('sort_order')->get();
        $rules = ApprovalRule::with('levels:id')->orderBy('sort_order')->get();

        // Requests currently waiting on each level — shown so an admin knows what a change touches.
        $runningByLevel = PurchaseRequestApproval::query()
            ->select('approval_level_id', DB::raw('count(distinct purchase_request_id) as running'))
            ->where('status', PurchaseRequestApprovalStatus::PENDING)
            ->whereHas('purchaseRequest', function ($query) {
                $query->where('status', PurchaseRequestStatus::WAITING_APPROVAL);
            })
            ->groupBy('approval_level_id')
            ->pluck('running', 'approval_level_id');

        return view('approval-settings.index', [
            'tab' => $tab,
            'levels' => $levels,
            'rules' => $rules,
            'runningByLevel' => $runningByLevel,
            'canManage' => $request->user()->can('approval_setting.manage'),
        ]);
    }

    public function saveLevels(SaveApprovalLevelsRequest $request, ApprovalConfigService $service)
    {
        $service->saveLevels($request->input('levels'));

        return redirect()->route('approval-settings.index', ['tab' => 'levels'])
            ->with('status', 'Level dan approver berhasil disimpan.');
    }

    public function saveRules(SaveApprovalRulesRequest $request, ApprovalConfigService $service)
    {
        $service->saveRules($request->input('rules', []));

        return redirect()->route('approval-settings.index', ['tab' => 'rules'])
            ->with('status', 'Aturan nominal berhasil disimpan.');
    }

    /**
     * "Uji Aturan": which chain a given total would produce with the configuration as saved.
     * Always answers 200 with ok=false and a readable message instead of failing.
     */
    public function preview(Request $request, ApprovalChainResolver $resolver)
    {
        $this->authorize('approval_setting.view');

        $digits = preg_replace('/\D/', '', (string) $request->query('total', ''));
        if ($digits === '') {
            return response()->json(['ok' => false, 'message' => 'Masukkan nominal yang valid.']);
        }

        try {
            $steps = $resolver->resolve((float) $digits);
        } catch (ApprovalChainException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        $names = User::whereIn('id', collect($steps)->pluck('user_ids')->flatten())->pluck('name', 'id');

        return response()->json([
            'ok' => true,
            'steps' => array_map(function ($step) use ($names) {
                return [
                    'level_name' => $step['level_name'],
                    'users' => array_values(array_map(function ($id) use ($names) {
                        return $names[$id] ?? ('#' . $id);
                    }, $step['user_ids'])),
                ];
            }, $steps),
        ]);
    }

    public function lookupUsers(Request $request)
    {
        $this->authorize('approval_setting.view');

        $term = $request->query('q');
        $term = is_string($term) ? trim($term) : '';
        if (mb_strlen($term) < 3) {
            return response()->json([]);
        }

        $like = '%' . addcslashes($term, '%_\\') . '%';

        return response()->json(
            User::where('is_active', true)
                ->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)->orWhere('username', 'like', $like);
                })
                ->orderBy('name')->limit(20)->get(['id', 'name', 'username'])
                ->map(function (User $user) {
                    return ['id' => $user->id, 'text' => $user->name . ' (' . $user->username . ')'];
                })
                ->values()
        );
    }
}
