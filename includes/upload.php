<?php
/** Validated file uploads into /uploads/<subdir>. */

const UPLOAD_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const UPLOAD_DOC_TYPES   = [
    'application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain', 'text/csv',
];

/**
 * Store one uploaded file.
 *
 * @param array  $file    A single entry from $_FILES.
 * @param string $subdir  Folder under /uploads (assets, documents, completions, issues, branding).
 * @param array  $allowed Allowed MIME types.
 * @return array [relativePath|null, error|null]
 */
function store_upload(array $file, string $subdir, array $allowed = UPLOAD_DOC_TYPES): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];                       // nothing submitted - not an error
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, upload_error_text((int) $file['error'])];
    }

    $maxBytes = setting_int('max_upload_mb', 12) * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        return [null, 'File is larger than the ' . setting_int('max_upload_mb', 12) . ' MB limit.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, 'Upload failed validation.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
    if (!in_array($mime, $allowed, true)) {
        return [null, 'File type not allowed (' . $mime . ').'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
    if (in_array($ext, ['php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'htaccess', 'cgi', 'pl', 'sh'], true)) {
        return [null, 'Executable file types are not allowed.'];
    }

    $dir = rtrim(UPLOAD_PATH, '/\\') . '/' . $subdir . '/' . date('Y') . '/' . date('m');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return [null, 'Upload folder is not writable. Check permissions on /uploads.'];
    }

    $base = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $base = substr($base, 0, 60);
    $name = $base . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;

    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return [null, 'Could not save the uploaded file.'];
    }
    @chmod($dir . '/' . $name, 0644);

    return [$subdir . '/' . date('Y') . '/' . date('m') . '/' . $name, null];
}

/** Record an attachment row against any entity. */
function attach(string $entityType, int $entityId, string $relPath, array $file, string $docType = 'other', ?string $title = null): int
{
    return db_insert('attachments', [
        'entity_type'   => $entityType,
        'entity_id'     => $entityId,
        'doc_type'      => $docType,
        'title'         => $title ?: pathinfo($file['name'] ?? $relPath, PATHINFO_FILENAME),
        'file_path'     => $relPath,
        'original_name' => $file['name'] ?? null,
        'mime_type'     => $file['type'] ?? null,
        'file_size'     => $file['size'] ?? null,
        'uploaded_by'   => current_user_id(),
    ]);
}

function attachments_for(string $entityType, int $entityId): array
{
    return db_all(
        'SELECT a.*, u.full_name AS uploaded_by_name
           FROM attachments a
           LEFT JOIN users u ON u.id = a.uploaded_by
          WHERE a.entity_type = :t AND a.entity_id = :i AND a.is_void = 0
          ORDER BY a.uploaded_at DESC',
        [':t' => $entityType, ':i' => $entityId]
    );
}

function upload_error_text(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:  return 'The file is too large for the server upload limit.';
        case UPLOAD_ERR_PARTIAL:    return 'The file was only partially uploaded. Try again.';
        case UPLOAD_ERR_NO_TMP_DIR: return 'Server has no temp folder configured for uploads.';
        case UPLOAD_ERR_CANT_WRITE: return 'Server could not write the uploaded file to disk.';
        case UPLOAD_ERR_EXTENSION:  return 'A PHP extension blocked the upload.';
        default:                    return 'Upload failed (code ' . $code . ').';
    }
}

function human_filesize(?int $bytes): string
{
    if (!$bytes) {
        return '—';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 3) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $i ? 1 : 0) . ' ' . $units[$i];
}

function file_icon(?string $mime): string
{
    if (!$mime) return 'bi-file-earmark';
    if (str_starts_with($mime, 'image/')) return 'bi-file-earmark-image';
    if ($mime === 'application/pdf') return 'bi-file-earmark-pdf';
    if (str_contains($mime, 'word')) return 'bi-file-earmark-word';
    if (str_contains($mime, 'sheet') || str_contains($mime, 'excel')) return 'bi-file-earmark-spreadsheet';
    return 'bi-file-earmark-text';
}
