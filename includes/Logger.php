<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * MediaLibraryLite 简易日志工具
 *
 * 默认关闭，通过插件设置项 enableLogging 开启。
 * 日志为 JSON Lines 格式，方便复制后排查。
 */
class MediaLibraryLite_Logger
{
    const LOG_DIR = '/usr/plugins/MediaLibraryLite/data';
    const LOG_FILE = 'medialibrarylite.log';
    const MAX_BYTES = 1048576; // 1 MiB
    const TRIM_LINES = 400;

    private static function getLogDir()
    {
        $root = rtrim(__TYPECHO_ROOT_DIR__, '/\\');

        $primary = $root . self::LOG_DIR;
        if (self::ensureDirWritable($primary)) {
            return $primary;
        }

        // 兜底：如果插件目录不可写，尝试写入 uploads 目录（通常可写）
        $fallback = $root . '/usr/uploads';
        if (self::ensureDirWritable($fallback)) {
            return $fallback;
        }

        // 再兜底：系统临时目录
        if (function_exists('sys_get_temp_dir')) {
            $temp = rtrim((string)sys_get_temp_dir(), '/\\') . '/medialibrarylite';
            if (self::ensureDirWritable($temp)) {
                return $temp;
            }
        }

        return $primary;
    }

    public static function getLogFile()
    {
        $file = self::getLogDir() . '/' . self::LOG_FILE;
        if (!file_exists($file)) {
            @touch($file);
        }
        return $file;
    }

    private static function ensureDirWritable($dir)
    {
        $dir = (string)$dir;
        if ($dir === '') {
            return false;
        }

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return false;
            }
        }

        return is_writable($dir);
    }

    private static function isLoggingEnabled()
    {
        try {
            if (!class_exists('Helper')) {
                return false;
            }
            $options = Helper::options();
            $pluginConfig = $options->plugin('MediaLibraryLite');
            return !empty($pluginConfig->enableLogging);
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function shouldLog($level)
    {
        $level = strtolower((string)$level);

        // 未开启日志时，仍保留 warning/error，方便排查上传失败等问题
        if (!self::isLoggingEnabled()) {
            return in_array($level, ['warning', 'warn', 'error'], true);
        }

        return true;
    }

    public static function log($action, $message, array $context = [], $level = 'info')
    {
        if (!self::shouldLog($level)) {
            return;
        }

        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => (string)$level,
            'action' => (string)$action,
            'message' => (string)$message,
            'context' => $context,
            'ip' => self::getClientIp(),
            'user' => self::getCurrentUser()
        ];

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }

        @file_put_contents(self::getLogFile(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        self::maybeTrim();
    }

    public static function tail($limit = 200)
    {
        $limit = max(1, min(2000, (int)$limit));
        $file = self::getLogFile();
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines) || empty($lines)) {
            return '';
        }

        $lines = array_slice($lines, -$limit);
        return implode(PHP_EOL, $lines);
    }

    public static function clear()
    {
        $file = self::getLogFile();
        if (!is_file($file)) {
            return ['success' => true, 'message' => '日志文件不存在，无需清空'];
        }
        if (!is_writable($file)) {
            return ['success' => false, 'message' => '日志文件不可写，请检查权限'];
        }
        $result = @file_put_contents($file, '');
        return $result === false
            ? ['success' => false, 'message' => '清空日志失败']
            : ['success' => true, 'message' => '日志已清空'];
    }

    private static function maybeTrim()
    {
        $file = self::getLogFile();
        if (!is_file($file)) {
            return;
        }
        $size = @filesize($file);
        if ($size === false || $size <= self::MAX_BYTES) {
            return;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || empty($lines)) {
            return;
        }

        $lines = array_slice($lines, -self::TRIM_LINES);
        @file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    private static function getClientIp()
    {
        $server = isset($_SERVER) ? $_SERVER : [];
        if (!empty($server['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $server['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        if (!empty($server['REMOTE_ADDR'])) {
            return $server['REMOTE_ADDR'];
        }
        return null;
    }

    private static function getCurrentUser()
    {
        try {
            if (!class_exists('Typecho_Widget')) {
                return null;
            }
            $user = Typecho_Widget::widget('Widget_User');
            if ($user && $user->hasLogin()) {
                return [
                    'uid' => $user->uid,
                    'name' => $user->name,
                    'screenName' => $user->screenName,
                    'group' => $user->group
                ];
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }
}
