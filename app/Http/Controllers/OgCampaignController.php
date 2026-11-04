<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use Illuminate\Http\Request;

class OgCampaignController extends Controller
{
    public function show(Request $request, $id)
    {
        $campaign = Campaign::query()->find($id);

        $title = $campaign->campaign_name ?? 'Jalan Kebaikan';
        $image = $campaign->campaign_thumbnail ?? 'https://jalankebaikan.id/image/logo-jk-full.png';
        $description = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($campaign->description ?? ''))) ?? '');
        if ($description === '') {
            $description = 'Detail campaign Lazismu DIY';
        }
        if (mb_strlen($description) > 180) {
            $description = mb_substr($description, 0, 177) . '...';
        }

        if ($image !== '' && !preg_match('#^https?://#i', $image)) {
            $image = 'https://jalankebaikan.id/' . ltrim($image, '/');
        }

        $canonical = 'https://jalankebaikan.id/detailCampaign?id=' . rawurlencode((string) $id);
        if ($request->query('code')) {
            $canonical .= '&code=' . rawurlencode((string) $request->query('code'));
        }

        return response()
            ->view('og-campaign', compact('title', 'description', 'image', 'canonical'))
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function json($id)
    {
        $campaign = Campaign::query()->find($id);
        if (!$campaign) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json([
            'id' => $campaign->id,
            'campaign_name' => $campaign->campaign_name,
            'description' => $campaign->description,
            'campaign_thumbnail' => $campaign->campaign_thumbnail,
        ]);
    }
}
