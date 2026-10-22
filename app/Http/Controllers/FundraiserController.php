<?php

namespace App\Http\Controllers;

use App\Helpers\JWT;
use App\Models\Campaign;
use App\Models\Fundraiser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FundraiserController extends Controller
{
    public function register(Request $request)
    {
        $userId = $this->userIdFromRequest($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $existing = Fundraiser::where('user_id', $userId)->first();
        if ($existing) {
            return response()->json([
                'message' => 'Sudah terdaftar sebagai fundraiser',
                'code' => $existing->code,
            ]);
        }

        $fundraiser = Fundraiser::create([
            'user_id' => $userId,
            'code' => $this->generateCode(),
        ]);

        return response()->json([
            'message' => 'Fundraiser berhasil didaftarkan',
            'code' => $fundraiser->code,
        ], 201);
    }

    public function me(Request $request)
    {
        $userId = $this->userIdFromRequest($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $fundraiser = Fundraiser::where('user_id', $userId)->first();
        if (!$fundraiser) {
            return response()->json(['registered' => false]);
        }

        $user = User::find($userId);
        $rows = $this->successfulRows($fundraiser->id);
        $summary = $this->summarize($rows);

        return response()->json([
            'registered' => true,
            'code' => $fundraiser->code,
            'name' => $user->name ?? '',
            'donor_count' => $summary['donor_count'],
            'total_amount' => $summary['total_amount'],
            'campaigns' => $this->campaignBreakdown($rows),
        ]);
    }

    public function stats(Request $request)
    {
        $code = strtoupper(trim((string) $request->query('code', '')));
        $campaignId = $request->query('campaign_id');

        if ($code === '' || !$campaignId) {
            return response()->json(['message' => 'Fundraiser tidak ditemukan'], 404);
        }

        $fundraiser = Fundraiser::with('user')->where('code', $code)->first();
        if (!$fundraiser) {
            return response()->json(['message' => 'Fundraiser tidak ditemukan'], 404);
        }

        $rows = $this->successfulRows($fundraiser->id, $campaignId);
        $summary = $this->summarize($rows);

        return response()->json([
            'name' => $fundraiser->user->name ?? '',
            'code' => $fundraiser->code,
            'donor_count' => $summary['donor_count'],
            'total_amount' => $summary['total_amount'],
        ]);
    }

    public function byCampaign($campaignId)
    {
        $fundraiserIds = DB::table('transactions')
            ->where('campaign_id', $campaignId)
            ->where('success', 1)
            ->where('category', 'campaign')
            ->whereNotNull('fundraiser_id')
            ->distinct()
            ->pluck('fundraiser_id');

        $items = [];
        foreach ($fundraiserIds as $fundraiserId) {
            $fundraiser = Fundraiser::with('user')->find($fundraiserId);
            if (!$fundraiser) {
                continue;
            }

            $rows = $this->successfulRows($fundraiserId, $campaignId);
            $summary = $this->summarize($rows);
            $items[] = [
                'name' => $fundraiser->user->name ?? '',
                'code' => $fundraiser->code,
                'donor_count' => $summary['donor_count'],
                'total_amount' => $summary['total_amount'],
            ];
        }

        usort($items, function ($a, $b) {
            return ($b['total_amount'] <=> $a['total_amount'])
                ?: ($b['donor_count'] <=> $a['donor_count']);
        });

        return response()->json([
            'campaign_id' => (int) $campaignId,
            'data' => $items,
        ]);
    }

    public function index()
    {
        $fundraisers = Fundraiser::with('user')->orderByDesc('id')->get();
        $data = [];

        foreach ($fundraisers as $fundraiser) {
            $rows = $this->successfulRows($fundraiser->id);
            $summary = $this->summarize($rows);

            $data[] = [
                'id' => $fundraiser->id,
                'code' => $fundraiser->code,
                'name' => $fundraiser->user->name ?? '',
                'phone_number' => $fundraiser->user->phone_number ?? '',
                'donor_count' => $summary['donor_count'],
                'total_amount' => $summary['total_amount'],
                'campaigns' => $this->campaignBreakdown($rows),
                'created_at' => optional($fundraiser->created_at)->toDateTimeString(),
            ];
        }

        usort($data, function ($a, $b) {
            return ($b['total_amount'] <=> $a['total_amount'])
                ?: ($b['donor_count'] <=> $a['donor_count']);
        });

        return response()->json(['data' => $data]);
    }

    private function userIdFromRequest(Request $request)
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authHeader, 7));

        try {
            $key = env('JWT_SECRET', 'LazismuDIY_ICT');
            $decoded = JWT::decode($token, $key);
            return $decoded->sub ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $length = strlen($alphabet);

        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, $length - 1)];
            }
        } while (Fundraiser::where('code', $code)->exists());

        return $code;
    }

    private function successfulRows($fundraiserId, $campaignId = null)
    {
        $query = DB::table('transactions')
            ->where('fundraiser_id', $fundraiserId)
            ->where('success', 1)
            ->where('category', 'campaign')
            ->whereNotNull('campaign_id');

        if ($campaignId !== null) {
            $query->where('campaign_id', $campaignId);
        }

        return $query->get(['transaction_id', 'campaign_id', 'phone_number', 'transaction_amount']);
    }

    private function campaignBreakdown($rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->campaign_id][] = $row;
        }

        if ($grouped === []) {
            return [];
        }

        $names = Campaign::whereIn('id', array_keys($grouped))->pluck('campaign_name', 'id');
        $campaigns = [];

        foreach ($grouped as $campaignId => $group) {
            $summary = $this->summarize($group);
            $campaigns[] = [
                'campaign_id' => (int) $campaignId,
                'campaign_name' => $names[$campaignId] ?? '',
                'donor_count' => $summary['donor_count'],
                'total_amount' => $summary['total_amount'],
            ];
        }

        usort($campaigns, function ($a, $b) {
            return $b['total_amount'] <=> $a['total_amount'];
        });

        return $campaigns;
    }

    private function summarize($rows): array
    {
        $people = [];
        $total = 0.0;

        foreach ($rows as $row) {
            $phone = $this->normalizePhone($row->phone_number ?? '');
            $key = $phone !== '' ? $phone : 'tx-' . ($row->transaction_id ?? uniqid('', true));
            $people[$key] = true;
            $total += (float) ($row->transaction_amount ?? 0);
        }

        return [
            'donor_count' => count($people),
            'total_amount' => $total,
        ];
    }

    private function normalizePhone($phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === null || $digits === '') {
            return '';
        }

        if (str_starts_with($digits, '62')) {
            return '0' . substr($digits, 2);
        }

        return $digits;
    }
}
