<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\ClinicalFile;
use App\Services\Clinical\ClinicalFileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Entrega radiografías y fotos solo a quien puede ver la historia del paciente. */
class ClinicalFileController extends Controller
{
    public function show(Request $request, ClinicalFile $file, ClinicalFileService $files): StreamedResponse
    {
        abort_unless(auth()->user()->can('view', $file), 403);

        $path = $request->boolean('mini') ? ($files->thumbnailPath($file) ?? $file->path) : $file->path;

        abort_unless(Storage::disk($file->disk)->exists($path), 404);

        $name = str($file->title ?: 'archivo')->slug()->append('.', pathinfo($path, PATHINFO_EXTENSION))->toString();

        return Storage::disk($file->disk)->response($path, $name, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
