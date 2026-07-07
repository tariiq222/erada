<?php

namespace App\Modules\Shared\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class FileUploadValidator
{
    public const COMMENT_ATTACHMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

    public const COMMENT_ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Cross-check server-detected MIME (finfo) against the extension's allowed MIME list,
     * and enforce a hard size limit. Throws ValidationException on mismatch.
     *
     * @param  array<int, string>  $allowedExtensions
     *
     * @throws ValidationException
     */
    public function validate(UploadedFile $file, array $allowedExtensions, int $maxBytes = self::COMMENT_ATTACHMENT_MAX_BYTES): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'file' => ['فشل رفع الملف. حاول مرة أخرى.'],
            ]);
        }

        if (filesize($file->getPathname()) > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => [sprintf('حجم الملف يتجاوز الحد المسموح (%d ميجابايت).', (int) ($maxBytes / 1024 / 1024))],
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, array_map('strtolower', $allowedExtensions), true)) {
            throw ValidationException::withMessages([
                'file' => ['نوع الملف غير مسموح. الأنواع المسموحة: '.implode(', ', $allowedExtensions)],
            ]);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($file->getPathname()) ?: '';

        $allowedMimes = $this->allowedMimesForExtension($extension);
        if ($allowedMimes === null) {
            throw ValidationException::withMessages([
                'file' => ['نوع الملف غير مسموح. الأنواع المسموحة: '.implode(', ', $allowedExtensions)],
            ]);
        }

        if (! in_array($detected, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'file' => ['نوع الملف المكتشف لا يطابق الامتداد. تم رفض الملف لأسباب أمنية.'],
            ]);
        }
    }

    /**
     * @return array<int, string>|null
     */
    private function allowedMimesForExtension(string $extension): ?array
    {
        $map = [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'doc' => ['application/msword', 'application/CDFV2'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xls' => ['application/vnd.ms-excel', 'application/CDFV2'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'txt' => ['text/plain'],
        ];

        return $map[$extension] ?? null;
    }
}
