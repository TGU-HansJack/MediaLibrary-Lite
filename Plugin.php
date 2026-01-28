<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * MediaLibrary Lite - Typecho 精简版媒体库管理插件
 *
 * @package MediaLibraryLite
 * @author HansJack
 * @version lite_0.1.0
 * @link https://github.com/TGU-HansJack/MediaLibrary-Lite
 */
class MediaLibraryLite_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Helper::addPanel(3, 'MediaLibraryLite/panel.php', '文件管理', '文件管理', 'administrator');
        return 'MediaLibrary Lite 启用成功！';
    }

    public static function deactivate()
    {
        Helper::removePanel(3, 'MediaLibraryLite/panel.php');
        return 'MediaLibrary Lite 已禁用！';
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $enableLogging = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'enableLogging',
            ['1' => '启用日志记录'],
            [],
            '日志记录',
            '启用后记录更详细的日志（含 info/debug）；未启用时仍会记录 warning/error 便于排查'
        );
        $form->addInput($enableLogging);

        $pageSize = new Typecho_Widget_Helper_Form_Element_Text(
            'pageSize',
            null,
            '24',
            '每页数量',
            '用于文件列表与图片网格的分页数量'
        );
        $pageSize->addRule('isInteger', '每页数量必须是整数');
        $form->addInput($pageSize);

        $defaultTab = new Typecho_Widget_Helper_Form_Element_Select(
            'defaultTab',
            [
                'image' => '图片',
                'video' => '视频',
                'audio' => '音频',
                'document' => '文档'
            ],
            'image',
            '默认打开分类'
        );
        $form->addInput($defaultTab);

        require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/MediaLibraryLite/includes/Logger.php';

        $logFile = MediaLibraryLite_Logger::getLogFile();
        $logContent = MediaLibraryLite_Logger::tail(200);
        $emptyText = '暂无日志内容。可先重试上传/删除；如需更多细节请勾选“启用日志记录”并保存。';
        $displayContent = trim($logContent) !== '' ? $logContent : $emptyText;

        echo '<div class="ml-lite-log-viewer">';
        echo '<div class="ml-lite-log-head">';
        echo '<div><h4 style="margin:0 0 6px 0;">操作日志</h4>';
        echo '<p style="margin:0;color:#666;font-size:13px;">用于排查上传/删除失败原因（显示最近 200 行）。</p></div>';
        echo '<button type="button" class="ml-lite-log-copy-btn" id="ml-lite-copy-log-btn" title="复制日志内容">Copy</button>';
        echo '</div>';
        echo '<div class="ml-lite-log-meta">日志文件位置：<code style="font-size:12px;">' . htmlspecialchars($logFile) . '</code></div>';
        echo '<div class="ml-lite-log-raw-wrap"><pre class="ml-lite-log-raw">'
            . htmlspecialchars($displayContent)
            . '</pre></div>';
        echo '</div>';

        echo '<style>
.ml-lite-log-viewer{background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;margin:20px 0;box-shadow:0 1px 3px rgba(0,0,0,0.05);}
.ml-lite-log-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:10px;}
.ml-lite-log-copy-btn{background:#0073aa;border:1px solid #0073aa;color:#fff;padding:6px 14px;border-radius:4px;font-size:13px;cursor:pointer;transition:all .2s;white-space:nowrap;}
.ml-lite-log-copy-btn:hover{background:#005a87;border-color:#005a87;}
.ml-lite-log-copy-btn.success{background:#46b450;border-color:#46b450;}
.ml-lite-log-copy-btn[disabled]{opacity:.6;cursor:not-allowed;}
.ml-lite-log-meta{font-size:12px;color:#777;margin-bottom:10px;line-height:1.6;}
.ml-lite-log-raw-wrap{border:1px solid #eee;background:#0f172a;color:#e2e8f0;border-radius:6px;max-height:360px;overflow:auto;padding:14px;font-family:SFMono-Regular,Consolas,\"Liberation Mono\",Menlo,monospace;font-size:13px;}
.ml-lite-log-raw{margin:0;white-space:pre-wrap;word-break:break-word;}
</style>';

        echo '<script>
(function(){
  var btn = document.getElementById("ml-lite-copy-log-btn");
  if (!btn) return;
  var originalText = btn.textContent;
  btn.addEventListener("click", function(){
    if (btn.disabled) return;
    var pre = document.querySelector(".ml-lite-log-raw");
    var text = pre ? pre.textContent : "";
    if (!text) return;

    function markSuccess(){
      btn.textContent = "✓";
      btn.classList.add("success");
      btn.disabled = true;
      setTimeout(function(){
        btn.textContent = originalText;
        btn.classList.remove("success");
        btn.disabled = false;
      }, 1600);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(markSuccess).catch(function(){
        fallbackCopy(text);
      });
      return;
    }

    fallbackCopy(text);

    function fallbackCopy(value){
      try {
        var textarea = document.createElement("textarea");
        textarea.value = value;
        textarea.style.position = "fixed";
        textarea.style.left = "-9999px";
        document.body.appendChild(textarea);
        textarea.select();
        var ok = document.execCommand("copy");
        document.body.removeChild(textarea);
        if (ok) markSuccess();
      } catch (e) {}
    }
  });
})();
</script>';
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }
}
