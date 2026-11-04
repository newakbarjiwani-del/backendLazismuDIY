<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use Illuminate\Http\Request;

class OgCampaignController extends Controller
{
    /**
     * Halaman HTML minimal untuk crawler WhatsApp/Facebook.
     * Baca langsung dari DB — tidak bergantung FO→API.
     */
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

        $canonical = 'https://jalankebaikan.id/detailCampaign?id=' . urlencode((string) $id);
        if ($request->query('code')) {
            $canonical .= '&code=' . urlencode((string) $request->query('code'));
        }

        return response()
            ->view('og-campaign', [
                'title' => $title,
                'description' => $description,
                'image' => $image,
                'canonical' => $canonical,
            ])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
