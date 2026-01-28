<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/MediaLibraryLite/includes/PanelHelper.php';
require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/MediaLibraryLite/includes/AjaxHandler.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$db = Typecho_Db::get();

if ($request->get('action')) {
    MediaLibraryLite_AjaxHandler::handleRequest($request, $db, $options, $user);
    exit;
}

include 'header.php';
include 'menu.php';

$config = MediaLibraryLite_PanelHelper::getPluginConfig();
$pageSize = (int)($config['pageSize'] ?? 24);
$defaultTab = (string)($config['defaultTab'] ?? 'image');

$type = strtolower((string)$request->get('type', $defaultTab ?: 'image'));
$type = in_array($type, ['image', 'video', 'audio', 'document'], true) ? $type : 'image';
$page = max(1, (int)$request->get('page', 1));

$data = MediaLibraryLite_PanelHelper::getMediaList($db, $page, $pageSize, $type);
$items = $data['items'];
$total = (int)$data['total'];
$pageCount = (int)$data['pageCount'];

$tabs = [
    'image' => '图片',
    'video' => '视频',
    'audio' => '音频',
    'document' => '文档'
];

$baseUrl = Typecho_Common::url('extending.php?panel=MediaLibraryLite%2Fpanel.php', $options->adminUrl);
$security = Typecho_Widget::widget('Widget_Security');
$token = $security->getToken($baseUrl);
$currentLabel = $tabs[$type] ?? '文件';
$uploadLabel = '上传' . $currentLabel;
$cssVersion = '0.1.1';
?>

<link rel="stylesheet" href="<?php echo Helper::options()->pluginUrl; ?>/MediaLibraryLite/assets/css/panel.css?v=<?php echo $cssVersion; ?>">

<div class="ml-lite">
    <div class="ml-header">
        <div class="ml-title">
            <h2>文件管理</h2>
            <p><?php echo htmlspecialchars($currentLabel); ?> · 共 <?php echo number_format($total); ?> 个</p>
        </div>
        <button class="ml-btn ml-btn-primary" type="button" data-ml-action="open-upload">
            <?php echo htmlspecialchars($uploadLabel); ?>
        </button>
    </div>

    <div class="ml-tabs" role="tablist">
        <?php foreach ($tabs as $tabKey => $tabLabel): ?>
            <a class="ml-tab<?php echo $type === $tabKey ? ' is-active' : ''; ?>"
               href="<?php echo htmlspecialchars($baseUrl . '&type=' . urlencode($tabKey)); ?>">
                <?php echo htmlspecialchars($tabLabel); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($items)): ?>
        <div class="ml-empty">
            <div class="ml-empty-title">暂无文件</div>
            <button class="ml-btn ml-btn-outline" type="button" data-ml-action="open-upload">上传文件</button>
        </div>
    <?php else: ?>
        <?php if ($type === 'image' || $type === 'video'): ?>
            <div class="ml-grid" data-ml-view="grid">
                <?php foreach ($items as $item): ?>
                    <div class="ml-card"
                         data-cid="<?php echo (int)$item['cid']; ?>"
                         data-url="<?php echo htmlspecialchars($item['url']); ?>"
                         data-name="<?php echo htmlspecialchars($item['name']); ?>"
                         data-mime="<?php echo htmlspecialchars($item['mime'] ?? ''); ?>">
                        <?php if ($type === 'image'): ?>
                            <button type="button" class="ml-card-thumb" data-ml-action="preview">
                                <img src="<?php echo htmlspecialchars($item['url']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" loading="lazy">
                            </button>
                        <?php else: ?>
                            <button type="button" class="ml-card-thumb ml-video-thumb" data-ml-action="play-video">
                                <div class="ml-video-thumb-inner">
                                    <div class="ml-video-play" aria-hidden="true"></div>
                                    <div class="ml-video-tip">点击播放</div>
                                </div>
                            </button>
                        <?php endif; ?>
                        <div class="ml-card-meta">
                            <div class="ml-card-name" title="<?php echo htmlspecialchars($item['name']); ?>"><?php echo htmlspecialchars($item['name']); ?></div>
                            <div class="ml-card-actions">
                                <button class="ml-action-btn" type="button" data-ml-action="copy">复制</button>
                                <?php if ($type === 'video'): ?>
                                    <button class="ml-action-btn" type="button" data-ml-action="play-video">播放</button>
                                <?php endif; ?>
                                <button class="ml-action-btn" type="button" data-ml-action="open">打开</button>
                                <button class="ml-action-btn ml-danger" type="button" data-ml-action="delete">删除</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="ml-list" data-ml-view="list">
                <?php foreach ($items as $item): ?>
                    <div class="ml-row" data-cid="<?php echo (int)$item['cid']; ?>" data-url="<?php echo htmlspecialchars($item['url']); ?>" data-name="<?php echo htmlspecialchars($item['name']); ?>">
                        <div class="ml-row-main">
                            <div class="ml-row-name"><?php echo htmlspecialchars($item['name']); ?></div>
                            <div class="ml-row-url"><?php echo htmlspecialchars($item['url']); ?></div>
                            <div class="ml-row-meta"><?php echo htmlspecialchars($item['size']); ?> · <?php echo htmlspecialchars($item['created_label']); ?></div>
                        </div>
                        <div class="ml-row-actions">
                            <button class="ml-action-btn" type="button" data-ml-action="copy">复制</button>
                            <button class="ml-action-btn" type="button" data-ml-action="open">打开</button>
                            <button class="ml-action-btn ml-danger" type="button" data-ml-action="delete">删除</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($pageCount > 1): ?>
            <?php
            $prev = max(1, $page - 1);
            $next = min($pageCount, $page + 1);
            ?>
            <div class="ml-pagination">
                <a class="ml-page-link<?php echo $page <= 1 ? ' is-disabled' : ''; ?>"
                   href="<?php echo htmlspecialchars($baseUrl . '&type=' . urlencode($type) . '&page=' . $prev); ?>">上一页</a>
                <span class="ml-page-info"><?php echo (int)$page; ?> / <?php echo (int)$pageCount; ?></span>
                <a class="ml-page-link<?php echo $page >= $pageCount ? ' is-disabled' : ''; ?>"
                   href="<?php echo htmlspecialchars($baseUrl . '&type=' . urlencode($type) . '&page=' . $next); ?>">下一页</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="ml-modal" data-ml-modal="upload" aria-hidden="true">
        <div class="ml-modal-backdrop" data-ml-action="close-modal"></div>
        <div class="ml-modal-card" role="dialog" aria-modal="true" aria-labelledby="ml-upload-title">
            <div class="ml-modal-header">
                <div class="ml-modal-title" id="ml-upload-title"><?php echo htmlspecialchars($uploadLabel); ?></div>
                <button class="ml-text-btn" type="button" data-ml-action="close-modal">关闭</button>
            </div>
            <div class="ml-modal-body">
                <div class="ml-dropzone" data-ml-action="pick-files">
                    <input class="ml-file-input" type="file" data-ml-input="file" <?php echo $type === 'image' ? 'accept="image/*"' : ''; ?> multiple>
                    <div class="ml-dropzone-title">点击或拖动文件到该区域来上传</div>
                    <div class="ml-dropzone-hint"><?php echo $type === 'image' ? '仅支持图片格式' : '支持 Typecho 允许的附件类型'; ?></div>
                </div>
                <div class="ml-upload-status" data-ml-upload-status hidden></div>
            </div>
        </div>
    </div>

    <div class="ml-modal" data-ml-modal="preview" aria-hidden="true">
        <div class="ml-modal-backdrop" data-ml-action="close-modal"></div>
        <div class="ml-modal-card ml-modal-card-wide" role="dialog" aria-modal="true" aria-labelledby="ml-preview-title">
            <div class="ml-modal-header">
                <div class="ml-modal-title" id="ml-preview-title">预览</div>
                <button class="ml-text-btn" type="button" data-ml-action="close-modal">关闭</button>
            </div>
            <div class="ml-modal-body">
                <img class="ml-preview-img" data-ml-preview-img alt="">
                <div class="ml-preview-meta" data-ml-preview-meta></div>
            </div>
        </div>
    </div>

    <div class="ml-modal" data-ml-modal="video" aria-hidden="true">
        <div class="ml-modal-backdrop" data-ml-action="close-modal"></div>
        <div class="ml-modal-card ml-modal-card-wide" role="dialog" aria-modal="true" aria-labelledby="ml-video-title">
            <div class="ml-modal-header">
                <div class="ml-modal-title" id="ml-video-title">视频播放</div>
                <button class="ml-text-btn" type="button" data-ml-action="close-modal">关闭</button>
            </div>
            <div class="ml-modal-body">
                <video class="ml-preview-video" data-ml-preview-video controls preload="metadata"></video>
                <div class="ml-preview-meta" data-ml-video-meta></div>
            </div>
        </div>
    </div>
</div>

<script>
window.MediaLibraryLite = {
    ajaxUrl: <?php echo json_encode($baseUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>,
    type: <?php echo json_encode($type, JSON_UNESCAPED_UNICODE); ?>,
    token: <?php echo json_encode($token, JSON_UNESCAPED_UNICODE); ?>
};
</script>
<script src="<?php echo Helper::options()->pluginUrl; ?>/MediaLibraryLite/assets/js/panel.js?v=<?php echo $cssVersion; ?>"></script>

<?php
include 'common-js.php';
include 'footer.php';
?>
