<?php

namespace App\Http\Controllers;

use App\Models\Reports;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReportsController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'thumbnail' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
            'file' => 'nullable|mimes:pdf|max:10240',
            'drive_link' => 'nullable|url|max:1000',
        ]);

        $driveLink = trim((string) $request->input('drive_link', ''));
        $hasPdf = $request->hasFile('file');

        if (!$hasPdf && $driveLink === '') {
            throw ValidationException::withMessages([
                'drive_link' => 'Unggah file PDF atau isi link Google Drive.',
            ]);
        }

        if ($driveLink !== '' && !$this->isGoogleDriveLink($driveLink)) {
            throw ValidationException::withMessages([
                'drive_link' => 'Link harus berupa URL Google Drive yang valid.',
            ]);
        }

        $thumbnailUrl = Cloudinary::upload(
            $request->file('thumbnail')->getRealPath(),
            ['folder' => 'report_thumbnails']
        )->getSecurePath();

        $filePath = $hasPdf
            ? $request->file('file')->store('reports', 'public')
            : null;

        $reports = Reports::create([
            'title' => $request->title,
            'thumbnail' => $thumbnailUrl,
            'file_path' => $filePath,
            'drive_link' => $driveLink !== '' ? $driveLink : null,
        ]);

        return response()->json([
            'message' => 'File uploaded successfully',
            'reports' => $reports,
        ], 201);
    }

    public function index(Request $request)
    {
        $reports = Reports::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json($reports);
    }

    private function isGoogleDriveLink(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        $host = strtolower($host);

        return str_contains($host, 'drive.google.com')
            || str_contains($host, 'docs.google.com');
    }
}
