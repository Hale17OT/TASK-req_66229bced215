<?php
namespace app\service\reimbursement;

use app\exception\BusinessException;
use app\model\Reimbursement;
use app\model\ReimbursementAttachment;
use think\file\UploadedFile;

/**
 * Attachment validation + storage (spec §17.6):
 *  - PDF/JPG/JPEG/PNG only (sniffed via finfo, not header-trusted)
 *  - max 10 MB per file
 *  - max 5 per reimbursement
 *  - stored under storage/attachments/{yyyy}/{mm}/{ulid}.{ext}
 */
class AttachmentService
{
    public function attach(Reimbursement $r, UploadedFile $file, int $uploaderId): ReimbursementAttachment
    {
        $cfg = (array)config('app.studio.attachments');
        $maxBytes = (int)$cfg['max_bytes'];
        $maxPer = (int)$cfg['max_per_request'];
        $allowed = (array)$cfg['allowed_mime']; // mime => ext
        $root = (string)$cfg['storage_root'];

        $current = ReimbursementAttachment::where('reimbursement_id', $r->id)->whereNull('deleted_at')->count();
        if ($current >= $maxPer) {
            throw new BusinessException("Attachment limit reached ({$maxPer})", 40040, 422);
        }
        if ($file->getSize() > $maxBytes) {
            throw new BusinessException('Attachment too large', 40041, 422, ['file' => ['max ' . ($maxBytes / 1024 / 1024) . ' MB']]);
        }
        if ($file->getSize() <= 0) {
            throw new BusinessException('Empty file', 40042, 422);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string)finfo_file($finfo, $file->getRealPath());
        finfo_close($finfo);
        if (!isset($allowed[$mime])) {
            throw new BusinessException("Unsupported file type ({$mime})", 40043, 422, ['file' => ['allowed: pdf, jpg, png']]);
        }
        $ext = $allowed[$mime];

        $ulid = ulid();
        $relDir = date('Y') . '/' . date('m');
        $dir = rtrim($root, '/') . '/' . $relDir;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $absPath = $dir . '/' . $ulid . '.' . $ext;
        if (!@move_uploaded_file($file->getRealPath(), $absPath)) {
            // fallback (some environments — e.g., test harness)
            if (!@copy($file->getRealPath(), $absPath)) {
                throw new BusinessException('Failed to store attachment', 50000, 500);
            }
        }
        $sha = hash_file('sha256', $absPath);

        return ReimbursementAttachment::create([
            'reimbursement_id'    => (int)$r->id,
            'file_name'           => substr($file->getOriginalName(), 0, 255),
            'mime_type'           => $mime,
            'size_bytes'          => filesize($absPath),
            'storage_path'        => $absPath,
            'sha256'              => $sha,
            'uploaded_by_user_id' => $uploaderId,
        ]);
    }

    public function softDelete(int $attachmentId, int $by): void
    {
        $row = ReimbursementAttachment::find($attachmentId);
        if (!$row) return;
        $row->deleted_at = date('Y-m-d H:i:s');
        $row->save();
    }
}
