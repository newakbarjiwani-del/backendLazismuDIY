<?php

namespace App\Http\Controllers;

use App\Models\Reports;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'thumbnail' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
            'file' => 'required|mimes:pdf|max:10240',
        ]);

        $thumbnailUrl = Cloudinary::upload(
            $request->file('thumbnail')->getRealPath(),
            ['folder' => 'report_thumbnails']
        )->getSecurePath();

        $filePath = $request->file('file')->store('reports', 'public');

        $reports = Reports::create([
            'title' => $request->title,
            'thumbnail' => $thumbnailUrl,
            'file_path' => $filePath,
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
}
