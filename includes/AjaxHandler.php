<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/MediaLibraryLite/includes/FileOperations.php';
require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/MediaLibraryLite/includes/Logger.php';

class MediaLibraryLite_AjaxHandler
{
    public static function handleRequest($request, $db, $options, $user)
    {
        $action = (string)$request->get('action');

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        try {
            $user->pass('administrator');

            MediaLibraryLite_Logger::log('ajax_request', '收到请求', [
                'action' => $action,
                'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null,
                'request_url' => method_exists($request, 'getRequestUrl') ? $request->getRequestUrl() : null,
                'referer' => method_exists($request, 'getReferer') ? $request->getReferer() : null
            ], 'debug');

            if (in_array($action, ['upload', 'delete'], true)) {
                self::assertSecurityToken($request, $options);
            }

            switch ($action) {
                case 'upload':
                    self::handleUploadAction($request, $db, $options, $user);
                    break;
                case 'delete':
                    self::handleDeleteAction($request, $db);
                    break;
                case 'get_info':
                    self::handleGetInfoAction($request, $db, $options);
                    break;
                default:
                    MediaLibraryLite_Logger::log('ajax_unknown', '未知操作', [
                        'action' => $action
                    ], 'warning');
                    echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
            }
        } catch (Throwable $exception) {
            MediaLibraryLite_Logger::log('ajax_error', '请求处理异常: ' . $exception->getMessage(), [
                'action' => $action,
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ], 'error');
            echo json_encode(['success' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    private static function assertSecurityToken($request, $options)
    {
        $security = Typecho_Widget::widget('Widget_Security');
        $token = (string)$request->get('_');
        if ($token === '') {
            MediaLibraryLite_Logger::log('security', '安全校验失败：缺少 token', [
                'referer' => (string)$request->getReferer(),
                'request_url' => (string)$request->getRequestUrl()
            ], 'warning');
            throw new Exception('安全校验失败，请刷新页面重试');
        }

        $candidates = [];
        $referer = (string)$request->getReferer();
        if ($referer !== '') {
            $candidates[] = (string)$security->getToken($referer);
        }

        $requestUrl = (string)$request->getRequestUrl();
        if ($requestUrl !== '') {
            $candidates[] = (string)$security->getToken($requestUrl);
        }

        $baseUrl = Typecho_Common::url('extending.php?panel=MediaLibraryLite%2Fpanel.php', $options->adminUrl);
        $candidates[] = (string)$security->getToken($baseUrl);

        if (!in_array($token, $candidates, true)) {
            MediaLibraryLite_Logger::log('security', '安全校验失败：token 不匹配', [
                'candidate_count' => count($candidates),
                'referer' => $referer,
                'request_url' => (string)$request->getRequestUrl(),
                'base_url' => $baseUrl
            ], 'warning');
            throw new Exception('安全校验失败，请刷新页面重试');
        }
    }

    private static function handleDeleteAction($request, $db)
    {
        $cids = $request->get('cid');
        if (is_array($cids)) {
            $cids = array_map('intval', $cids);
        } else {
            $cids = array_filter(array_map('intval', preg_split('/[\\s,]+/', (string)$cids)));
        }

        if (empty($cids)) {
            MediaLibraryLite_Logger::log('delete', '删除失败：缺少文件 ID', [], 'warning');
            echo json_encode(['success' => false, 'message' => '缺少文件 ID'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $result = MediaLibraryLite_FileOperations::deleteFiles($cids, $db);
        MediaLibraryLite_Logger::log('delete', '删除完成', [
            'cids' => $cids,
            'result' => $result
        ], !empty($result['success']) ? 'info' : 'warning');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    private static function handleGetInfoAction($request, $db, $options)
    {
        $cid = (int)$request->get('cid');
        $result = MediaLibraryLite_FileOperations::getFileInfo($cid, $db, $options);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    private static function handleUploadAction($request, $db, $options, $user)
    {
        if (empty($_FILES)) {
            $diagnostic = self::diagnoseEmptyFiles();
            MediaLibraryLite_Logger::log('upload', '上传失败：$_FILES 为空', $diagnostic, 'warning');
            echo json_encode([
                'success' => false,
                'message' => $diagnostic['message'] ?? '没有文件上传'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $uploadHandlerClass = null;
        if (class_exists('Widget_Upload') && method_exists('Widget_Upload', 'uploadHandle')) {
            $uploadHandlerClass = 'Widget_Upload';
        } elseif (class_exists('\\Widget\\Upload') && method_exists('\\Widget\\Upload', 'uploadHandle')) {
            $uploadHandlerClass = '\\Widget\\Upload';
        }

        if (!$uploadHandlerClass) {
            MediaLibraryLite_Logger::log('upload', '上传失败：Upload 组件不存在或不支持 uploadHandle', [
                'has_Widget_Upload' => class_exists('Widget_Upload'),
                'has_Widget_Upload_ns' => class_exists('\\Widget\\Upload')
            ], 'error');
            throw new Exception('Typecho Upload 组件不存在或不支持 uploadHandle，可能与当前 Typecho 版本不兼容');
        }

        $category = (string)$request->get('category', 'image');
        $files = MediaLibraryLite_FileOperations::normalizeUploadFiles($_FILES);
        if (empty($files)) {
            MediaLibraryLite_Logger::log('upload', '上传失败：没有可用的上传文件', [
                'files' => self::summarizeUploadedFiles($_FILES)
            ], 'warning');
            echo json_encode(['success' => false, 'message' => '没有可用的上传文件'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $optionsWidget = Typecho_Widget::widget('Widget_Options');
        $allowedTypes = [];
        if (isset($optionsWidget->allowedAttachmentTypes) && is_array($optionsWidget->allowedAttachmentTypes)) {
            $allowedTypes = array_values(array_filter(array_map('strtolower', $optionsWidget->allowedAttachmentTypes)));
        }

        $results = [];
        $errors = [];

        MediaLibraryLite_Logger::log('upload', '开始处理上传', [
            'category' => $category,
            'count' => count($files),
            'handler' => $uploadHandlerClass,
            'allowed_types_count' => count($allowedTypes)
        ], 'info');

        foreach ($files as $file) {
            if ($request && method_exists($request, 'isAjax') && $request->isAjax()) {
                $file['name'] = urldecode($file['name']);
            }

            $fileName = (string)($file['name'] ?? '未知文件');
            $fileError = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
            $tmpName = (string)($file['tmp_name'] ?? '');

            if ($fileError !== UPLOAD_ERR_OK) {
                $errorText = self::describeUploadError($fileError);
                MediaLibraryLite_Logger::log('upload', '上传校验失败', [
                    'name' => $fileName,
                    'error' => $fileError,
                    'error_text' => $errorText,
                    'tmp_name' => $tmpName
                ], 'warning');
                $errors[] = $fileName . ' 上传失败：' . $errorText;
                continue;
            }

            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                MediaLibraryLite_Logger::log('upload', '上传校验失败：临时文件无效', [
                    'name' => $fileName,
                    'tmp_name' => $tmpName,
                    'is_uploaded_file' => $tmpName !== '' ? (bool)is_uploaded_file($tmpName) : false
                ], 'warning');
                $errors[] = $fileName . ' 上传失败：临时文件无效';
                continue;
            }

            if (!MediaLibraryLite_FileOperations::isAllowedByCategory($category, $file['name'] ?? '')) {
                MediaLibraryLite_Logger::log('upload', '上传类型不符合分类限制', [
                    'category' => $category,
                    'name' => $fileName
                ], 'warning');
                $errors[] = $fileName . ' 不符合当前分类的上传类型';
                continue;
            }

            $ext = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
            if ($ext === '') {
                MediaLibraryLite_Logger::log('upload', '上传失败：无法识别文件扩展名', [
                    'name' => $fileName
                ], 'warning');
                $errors[] = $fileName . ' 上传失败：无法识别文件扩展名';
                continue;
            }

            if (!empty($allowedTypes) && !in_array($ext, $allowedTypes, true)) {
                MediaLibraryLite_Logger::log('upload', '上传失败：Typecho 不允许的文件类型', [
                    'name' => $fileName,
                    'ext' => $ext,
                    'allowed' => $allowedTypes
                ], 'warning');
                $errors[] = $fileName . ' 上传失败：Typecho 不允许上传 .' . $ext . '（请到 后台-设置-基本 设置允许上传类型）';
                continue;
            }

            $uploadResult = call_user_func([$uploadHandlerClass, 'uploadHandle'], $file);
            if ($uploadResult === false || !is_array($uploadResult) || empty($uploadResult['path'])) {
                $uploadDirInfo = self::getUploadTargetDirInfo();
                MediaLibraryLite_Logger::log('upload', 'Widget_Upload::uploadHandle 失败', [
                    'name' => $fileName,
                    'category' => $category,
                    'ext' => $ext,
                    'handler' => $uploadHandlerClass,
                    'upload_result_type' => gettype($uploadResult),
                    'upload_dir' => $uploadDirInfo
                ], 'warning');

                $errors[] = $fileName . ' 上传失败：保存失败（请检查上传目录权限）';
                if (!empty($uploadDirInfo['dir']) && !$uploadDirInfo['writable']) {
                    $errors[] = '上传目录不可写：' . $uploadDirInfo['dir'];
                }
                continue;
            }

            $slug = basename((string)$uploadResult['path']);
            $insertData = [
                'title' => $uploadResult['name'],
                'slug' => $slug ?: $uploadResult['name'],
                'created' => time(),
                'modified' => time(),
                'text' => serialize($uploadResult),
                'order' => 0,
                'authorId' => (int)$user->uid,
                'template' => null,
                'type' => 'attachment',
                'status' => 'publish',
                'password' => null,
                'commentsNum' => 0,
                'allowComment' => 1,
                'allowPing' => 0,
                'allowFeed' => 1,
                'parent' => 0
            ];

            $insertId = $db->query($db->insert('table.contents')->rows($insertData));

            $path = (string)$uploadResult['path'];
            $url = $path !== '' ? Typecho_Common::url($path, $options->siteUrl) : '';
            $mime = (string)($uploadResult['mime'] ?? 'application/octet-stream');

            MediaLibraryLite_Logger::log('upload', '上传入库成功', [
                'cid' => (int)$insertId,
                'name' => (string)$uploadResult['name'],
                'path' => $path,
                'mime' => $mime,
                'size' => (int)($uploadResult['size'] ?? 0)
            ], 'info');

            $results[] = [
                'cid' => (int)$insertId,
                'name' => (string)$uploadResult['name'],
                'url' => $url,
                'path' => $path,
                'size_bytes' => (int)($uploadResult['size'] ?? 0),
                'size' => MediaLibraryLite_FileOperations::formatFileSize((int)($uploadResult['size'] ?? 0)),
                'mime' => $mime,
                'is_image' => strpos($mime, 'image/') === 0
            ];
        }

        if (empty($results)) {
            MediaLibraryLite_Logger::log('upload', '上传失败：无成功文件', [
                'errors' => $errors,
                'files' => self::summarizeUploadedFiles($_FILES)
            ], 'warning');
            echo json_encode([
                'success' => false,
                'message' => $errors ? $errors[0] : '上传失败'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        MediaLibraryLite_Logger::log('upload', '上传完成', [
            'success_count' => count($results),
            'warnings_count' => count($errors)
        ], 'info');

        echo json_encode([
            'success' => true,
            'count' => count($results),
            'data' => $results,
            'warnings' => $errors
        ], JSON_UNESCAPED_UNICODE);
    }

    private static function summarizeUploadedFiles(array $files)
    {
        $result = [];
        foreach ($files as $key => $field) {
            if (!is_array($field) || !isset($field['name'])) {
                $result[$key] = gettype($field);
                continue;
            }

            if (is_array($field['name'])) {
                $items = [];
                $count = count($field['name']);
                for ($i = 0; $i < $count; $i++) {
                    $items[] = [
                        'name' => $field['name'][$i] ?? '',
                        'size' => $field['size'][$i] ?? 0,
                        'error' => $field['error'][$i] ?? null
                    ];
                }
                $result[$key] = $items;
            } else {
                $result[$key] = [
                    'name' => $field['name'] ?? '',
                    'size' => $field['size'] ?? 0,
                    'error' => $field['error'] ?? null
                ];
            }
        }

        return $result;
    }

    private static function describeUploadError($code)
    {
        $code = (int)$code;
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
                return '文件大小超过 upload_max_filesize 限制';
            case UPLOAD_ERR_FORM_SIZE:
                return '文件大小超过表单限制';
            case UPLOAD_ERR_PARTIAL:
                return '文件仅部分上传';
            case UPLOAD_ERR_NO_FILE:
                return '没有选择文件';
            case UPLOAD_ERR_NO_TMP_DIR:
                return '缺少临时目录';
            case UPLOAD_ERR_CANT_WRITE:
                return '写入磁盘失败';
            case UPLOAD_ERR_EXTENSION:
                return '上传被扩展中断';
            default:
                return '未知错误（error=' . $code . '）';
        }
    }

    private static function diagnoseEmptyFiles()
    {
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : null;
        $postMax = function_exists('ini_get') ? (string)ini_get('post_max_size') : '';
        $uploadMax = function_exists('ini_get') ? (string)ini_get('upload_max_filesize') : '';

        $postMaxBytes = self::iniSizeToBytes($postMax);
        $uploadMaxBytes = self::iniSizeToBytes($uploadMax);

        $message = '没有文件上传';
        if ($contentLength !== null && $contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
            $message = '上传失败：请求体过大，超过 post_max_size（' . $postMax . '）';
        }

        return [
            'message' => $message,
            'content_length' => $contentLength,
            'post_max_size' => $postMax,
            'upload_max_filesize' => $uploadMax
        ];
    }

    private static function iniSizeToBytes($value)
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float)$value;

        switch ($unit) {
            case 'g':
                return (int)round($number * 1024 * 1024 * 1024);
            case 'm':
                return (int)round($number * 1024 * 1024);
            case 'k':
                return (int)round($number * 1024);
            default:
                return (int)round($number);
        }
    }

    private static function getUploadTargetDirInfo()
    {
        $dir = null;
        $exists = null;
        $writable = null;

        try {
            $uploadDir = null;
            if (defined('__TYPECHO_UPLOAD_DIR__')) {
                $uploadDir = __TYPECHO_UPLOAD_DIR__;
            } elseif (defined('Widget_Upload::UPLOAD_DIR')) {
                $uploadDir = Widget_Upload::UPLOAD_DIR;
            } elseif (defined('\\Widget\\Upload::UPLOAD_DIR')) {
                $uploadDir = \Widget\Upload::UPLOAD_DIR;
            }

            if (!$uploadDir) {
                return ['dir' => null, 'exists' => null, 'writable' => null];
            }

            $date = new Typecho_Date();
            $uploadRoot = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
            $dir = Typecho_Common::url($uploadDir, $uploadRoot) . '/' . $date->year . '/' . $date->month;

            $exists = is_dir($dir);
            $writable = $exists ? is_writable($dir) : is_writable(dirname($dir));
        } catch (Throwable $e) {
            // ignore
        }

        return ['dir' => $dir, 'exists' => $exists, 'writable' => $writable];
    }
}
