<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Http\Responses\ApiResponse;
use App\Modules\Shared\Models\Attachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Download a private attachment after policy authorization.
     */
    public function download(Attachment $attachment): StreamedResponse|JsonResponse
    {
        $authorization = Gate::inspect('download', $attachment);

        if ($authorization->denied()) {
            return ApiResponse::error($authorization->message() ?: 'This action is unauthorized.', [], 403);
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($attachment->file_path)) {
            return ApiResponse::error('Attachment file was not found.', [], 404);
        }

        return $disk->download(
            $attachment->file_path,
            $this->safeDownloadName($attachment->name),
            ['Content-Type' => $attachment->file_type ?: 'application/octet-stream']
        );
    }

    private function safeDownloadName(string $name): string
    {
        $name = preg_replace('/[\r\n\/\\\\]+/', '_', $name) ?: 'attachment';

        return trim($name) !== '' ? $name : 'attachment';
    }
}
