<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class MediaLibraryLite_FileOperations
{
    public static function formatFileSize($bytes)
    {
        $bytes = max(0, (int)$bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $pow = min($pow, count($units) - 1);
        $value = $bytes / pow(1024, $pow);
        return round($value, 2) . ' ' . $units[$pow];
    }

    public static function resolveAttachmentPath($path)
    {
        $path = (string)$path;
        if ($path === '') {
            return null;
        }

        if (strpos($path, __TYPECHO_ROOT_DIR__) === 0 || preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path)) {
            return $path;
        }

        if (preg_match('#^https?://#i', $path)) {
            return null;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        return rtrim(__TYPECHO_ROOT_DIR__, '/\\') . '/' . $normalized;
    }

    public static function deleteFiles(array $cids, $db)
    {
        $deleted = 0;

        foreach ($cids as $cidValue) {
            $cidValue = (int)$cidValue;
            if ($cidValue <= 0) {
                continue;
            }

            $row = $db->fetchRow($db->select()->from('table.contents')
                ->where('cid = ? AND type = ?', $cidValue, 'attachment'));

            if (!$row) {
                continue;
            }

            $attachmentData = @unserialize($row['text']);
            if (!is_array($attachmentData)) {
                $attachmentData = [];
            }

            $filePath = self::resolveAttachmentPath($attachmentData['path'] ?? '');
            if ($filePath && file_exists($filePath)) {
                @unlink($filePath);
            }

            $db->query($db->delete('table.contents')->where('cid = ?', $cidValue));
            $deleted++;
        }

        return ['success' => true, 'deleted' => $deleted, 'message' => "成功删除 {$deleted} 个文件"];
    }

    public static function getFileInfo($cid, $db, $options)
    {
        $cid = (int)$cid;
        $row = $db->fetchRow($db->select()->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment'));

        if (!$row) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        $attachmentData = @unserialize($row['text']);
        if (!is_array($attachmentData)) {
            $attachmentData = [];
        }

        $path = (string)($attachmentData['path'] ?? '');
        $url = $path !== '' ? Typecho_Common::url($path, $options->siteUrl) : '';

        $mime = (string)($attachmentData['mime'] ?? 'application/octet-stream');
        $name = (string)($attachmentData['name'] ?? ($row['title'] ?? ''));

        return [
            'success' => true,
            'data' => [
                'cid' => $cid,
                'name' => $name,
                'mime' => $mime,
                'size_bytes' => (int)($attachmentData['size'] ?? 0),
                'size' => self::formatFileSize((int)($attachmentData['size'] ?? 0)),
                'path' => $path,
                'url' => $url,
                'created' => (int)($row['created'] ?? 0),
                'created_label' => !empty($row['created']) ? date('Y-m-d H:i', (int)$row['created']) : ''
            ]
        ];
    }

    public static function normalizeUploadFiles(array $files)
    {
        $normalized = [];

        foreach ($files as $field) {
            if (!is_array($field) || !isset($field['name'])) {
                continue;
            }

            if (is_array($field['name'])) {
                $count = count($field['name']);
                for ($index = 0; $index < $count; $index++) {
                    $normalized[] = [
                        'name' => $field['name'][$index] ?? '',
                        'type' => $field['type'][$index] ?? '',
                        'tmp_name' => $field['tmp_name'][$index] ?? '',
                        'error' => $field['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $field['size'][$index] ?? 0,
                    ];
                }
            } else {
                $normalized[] = $field;
            }
        }

        return array_values(array_filter($normalized, function ($file) {
            return is_array($file) && !empty($file['name']);
        }));
    }

    public static function isAllowedByCategory($category, $filename)
    {
        $category = strtolower((string)$category);
        $extension = strtolower((string)pathinfo((string)$filename, PATHINFO_EXTENSION));

        if ($category === 'image') {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'avif'];
            return $extension !== '' && in_array($extension, $allowed, true);
        }

        return true;
    }
}

