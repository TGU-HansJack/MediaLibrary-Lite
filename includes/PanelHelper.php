<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/MediaLibraryLite/includes/FileOperations.php';

class MediaLibraryLite_PanelHelper
{
    public static function getPluginConfig()
    {
        $defaults = [
            'pageSize' => 24,
            'defaultTab' => 'image'
        ];

        try {
            $config = Helper::options()->plugin('MediaLibraryLite');
        } catch (Exception $exception) {
            return $defaults;
        }

        return [
            'pageSize' => max(1, (int)($config->pageSize ?? $defaults['pageSize'])),
            'defaultTab' => (string)($config->defaultTab ?? $defaults['defaultTab'])
        ];
    }

    public static function getMediaList($db, $page, $pageSize, $type)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $page = max(1, (int)$page);
        $pageSize = max(1, (int)$pageSize);

        $type = strtolower((string)$type);
        $type = in_array($type, ['image', 'video', 'audio', 'document'], true) ? $type : 'image';

        $select = $db->select()->from('table.contents')
            ->where('table.contents.type = ?', 'attachment')
            ->order('table.contents.created', Typecho_Db::SORT_DESC);

        self::applyTypeFilter($select, $type);

        $countSelect = clone $select;
        $total = (int)$db->fetchObject($countSelect->select('COUNT(table.contents.cid) AS total'))->total;

        $rows = $db->fetchAll($select->limit($pageSize)->offset(($page - 1) * $pageSize));

        $items = [];
        foreach ($rows as $row) {
            $items[] = self::hydrateAttachmentRow($row, $options);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'pageCount' => $pageSize > 0 ? (int)ceil($total / $pageSize) : 1,
            'type' => $type
        ];
    }

    private static function applyTypeFilter($select, $type)
    {
        switch ($type) {
            case 'image':
                $select->where('table.contents.text LIKE ?', '%image%');
                break;
            case 'video':
                $select->where('table.contents.text LIKE ?', '%video%');
                break;
            case 'audio':
                $select->where('table.contents.text LIKE ?', '%audio%');
                break;
            case 'document':
                $select->where('(table.contents.text LIKE ? OR table.contents.text LIKE ?)', '%application%', '%text/%');
                break;
            default:
                break;
        }
    }

    private static function hydrateAttachmentRow(array $row, $options)
    {
        $attachmentData = @unserialize($row['text']);
        if (!is_array($attachmentData)) {
            $attachmentData = [];
        }

        $path = (string)($attachmentData['path'] ?? '');
        $url = $path !== '' ? Typecho_Common::url($path, $options->siteUrl) : '';
        $mime = (string)($attachmentData['mime'] ?? 'application/octet-stream');
        $name = (string)($attachmentData['name'] ?? ($row['title'] ?? ''));

        $isImage = strpos($mime, 'image/') === 0 || strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'avif';

        return [
            'cid' => (int)($row['cid'] ?? 0),
            'name' => $name !== '' ? $name : ('附件 #' . (int)($row['cid'] ?? 0)),
            'title' => (string)($row['title'] ?? $name),
            'mime' => $mime,
            'path' => $path,
            'url' => $url,
            'size_bytes' => (int)($attachmentData['size'] ?? 0),
            'size' => MediaLibraryLite_FileOperations::formatFileSize((int)($attachmentData['size'] ?? 0)),
            'created' => (int)($row['created'] ?? 0),
            'created_label' => !empty($row['created']) ? date('Y-m-d H:i', (int)$row['created']) : '',
            'is_image' => $isImage
        ];
    }
}
