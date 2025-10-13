<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

include 'header.php';
include 'menu.php';


// 在文件开头添加错误处理
error_reporting(E_ALL);
ini_set('display_errors', 0); // 生产环境关闭错误显示
ini_set('log_errors', 1);


// GPS解析辅助函数
function gps2Num($coordPart) {
    if (is_array($coordPart) && count($coordPart) >= 2) {
        $parts = $coordPart;
    } else {
        $parts = explode('/', $coordPart);
    }
    
    if (count($parts) >= 2 && floatval($parts[1]) != 0) {
        return floatval($parts[0]) / floatval($parts[1]);
    }
    return floatval($coordPart);
}

function exifToFloat($exifCoord, $ref) {
    if (!is_array($exifCoord) || count($exifCoord) < 3) {
        return 0;
    }
    
    $degrees = gps2Num($exifCoord[0]);
    $minutes = gps2Num($exifCoord[1]);
    $seconds = gps2Num($exifCoord[2]);
    $float = $degrees + ($minutes / 60) + ($seconds / 3600);
    return ($ref === 'S' || $ref === 'W') ? -$float : $float;
}


/**
 * 检查 ExifTool 是否可用
 */
function isExifToolAvailable() {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }
    
    try {
        $exiftoolPath = __TYPECHO_ROOT_DIR__ . '/usr/plugins/MediaLibrary/lib/PHPExiftool/Exiftool.php';
        if (!file_exists($exiftoolPath)) {
            $checked = false;
            return false;
        }
        
        // 检查是否可以包含文件
        if (!is_readable($exiftoolPath)) {
            $checked = false;
            return false;
        }
        
        // 尝试包含并检查类是否存在
        @include_once $exiftoolPath;
        
        // 检查类是否存在（支持不同的命名空间）
        $classExists = class_exists('PHPExiftool\\Exiftool') || 
                      class_exists('PHPExiftool\Exiftool') || 
                      class_exists('Exiftool');
        
        $checked = $classExists;
        return $checked;
        
    } catch (Exception $e) {
        error_log('isExifToolAvailable error: ' . $e->getMessage());
        $checked = false;
        return false;
    } catch (Error $e) {
        error_log('isExifToolAvailable fatal error: ' . $e->getMessage());
        $checked = false;
        return false;
    }
}
/**
 * 使用 PHP EXIF 扩展读取 EXIF 数据
 */
function readExifWithPhpExif($filePath) {
    if (!extension_loaded('exif') || !function_exists('exif_read_data')) {
        return false;
    }
    
    try {
        // 检查文件是否存在且可读
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return false;
        }
        
        // 检查是否为图片文件
        $imageInfo = @getimagesize($filePath);
        if ($imageInfo === false) {
            return false;
        }
        
        // 读取 EXIF 数据
        $exifData = @exif_read_data($filePath, 'ANY_TAG', true);
        
        if ($exifData === false || !is_array($exifData)) {
            return false;
        }
        
        return $exifData;
    } catch (Exception $e) {
        error_log('PHP EXIF read error: ' . $e->getMessage());
        return false;
    } catch (Error $e) {
        error_log('PHP EXIF read fatal error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 检查图片隐私信息
 */
function checkImagePrivacy($cid, $db, $options) {
    try {
        $attachment = $db->fetchRow($db->select()->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment'));
            
        if (!$attachment) {
            return ['success' => false, 'cid' => $cid, 'message' => '文件不存在'];
        }
        
        $attachmentData = @unserialize($attachment['text']);
        if (!is_array($attachmentData) || !isset($attachmentData['path'])) {
            return ['success' => false, 'cid' => $cid, 'message' => '文件数据错误'];
        }
        
        $filePath = __TYPECHO_ROOT_DIR__ . $attachmentData['path'];
        if (!file_exists($filePath)) {
            return ['success' => false, 'cid' => $cid, 'message' => '文件不存在'];
        }
        
        // 检查是否为图片
        if (!isset($attachmentData['mime']) || strpos($attachmentData['mime'], 'image/') !== 0) {
            return ['success' => false, 'cid' => $cid, 'message' => '只能检测图片文件'];
        }
        
        $filename = $attachmentData['name'] ?? basename($filePath);
        $privacyInfo = [];
        $hasPrivacy = false;
        $gpsCoords = null;
        
        // 尝试使用 PHP EXIF 扩展
        $exifData = readExifWithPhpExif($filePath);
        
        if ($exifData && is_array($exifData)) {
            // 检查 GPS 信息
            if (isset($exifData['GPS']) && is_array($exifData['GPS'])) {
                $gps = $exifData['GPS'];
                if (isset($gps['GPSLatitude'], $gps['GPSLongitude'], $gps['GPSLatitudeRef'], $gps['GPSLongitudeRef'])) {
                    try {
                        $lat = exifToFloat($gps['GPSLatitude'], $gps['GPSLatitudeRef']);
                        $lng = exifToFloat($gps['GPSLongitude'], $gps['GPSLongitudeRef']);
                        
                        if ($lat != 0 && $lng != 0) {
                            $privacyInfo['GPS位置'] = "纬度: {$lat}, 经度: {$lng}";
                            $gpsCoords = [$lng, $lat];
                            $hasPrivacy = true;
                        }
                    } catch (Exception $e) {
                        error_log('GPS parsing error: ' . $e->getMessage());
                    }
                }
            }
            
            // 检查相机信息
            if (isset($exifData['IFD0']) && is_array($exifData['IFD0'])) {
                $ifd0 = $exifData['IFD0'];
                $cameraInfo = [];
                
                if (isset($ifd0['Make'])) $cameraInfo[] = $ifd0['Make'];
                if (isset($ifd0['Model'])) $cameraInfo[] = $ifd0['Model'];
                
                if (!empty($cameraInfo)) {
                    $privacyInfo['设备信息'] = implode(' ', $cameraInfo);
                    $hasPrivacy = true;
                }
                
                if (isset($ifd0['DateTime'])) {
                    $privacyInfo['拍摄时间'] = $ifd0['DateTime'];
                    $hasPrivacy = true;
                }
            }
            
            // 检查 EXIF 信息
            if (isset($exifData['EXIF']) && is_array($exifData['EXIF'])) {
                $exif = $exifData['EXIF'];
                
                if (isset($exif['DateTimeOriginal'])) {
                    $privacyInfo['原始拍摄时间'] = $exif['DateTimeOriginal'];
                    $hasPrivacy = true;
                }
                
                if (isset($exif['DateTimeDigitized'])) {
                    $privacyInfo['数字化时间'] = $exif['DateTimeDigitized'];
                    $hasPrivacy = true;
                }
            }
        }
        
        $message = $hasPrivacy ? '发现隐私信息' : '未发现隐私信息';
        
        return [
            'success' => true,
            'cid' => $cid,
            'filename' => $filename,
            'has_privacy' => $hasPrivacy,
            'privacy_info' => $privacyInfo,
            'message' => $message,
            'gps_coords' => $gpsCoords,
            'image_url' => isset($attachmentData['path']) ? Typecho_Common::url($attachmentData['path'], $options->siteUrl) : null
        ];
        
    } catch (Exception $e) {
        error_log('checkImagePrivacy error: ' . $e->getMessage());
        return [
            'success' => false, 
            'cid' => $cid, 
            'message' => '检测失败: ' . $e->getMessage()
        ];
    }
}


/**
 * 使用 ExifTool 多步骤彻底清除 EXIF 信息
 */
function removeExifWithExifTool($filePath) {
    try {
        // 检查系统是否安装了 exiftool 命令行工具
        $exiftoolBinary = null;
        
        $possiblePaths = [
            'exiftool',
            '/usr/bin/exiftool',
            '/usr/local/bin/exiftool',
            '/opt/homebrew/bin/exiftool',
        ];
        
        foreach ($possiblePaths as $path) {
            $output = [];
            $return_var = 0;
            @exec($path . ' -ver 2>&1', $output, $return_var);
            if ($return_var === 0 && !empty($output)) {
                $exiftoolBinary = $path;
                break;
            }
        }
        
        if (!$exiftoolBinary) {
            return ['success' => false, 'message' => '系统未安装 exiftool 命令行工具'];
        }
        
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => '文件不存在'];
        }
        
        // 备份原文件信息
        $originalSize = filesize($filePath);
        $originalPerms = fileperms($filePath);
        
        // 检查文件格式
        $imageInfo = @getimagesize($filePath);
        if ($imageInfo === false) {
            return ['success' => false, 'message' => '不是有效的图片文件'];
        }
        
        // 多步骤清除EXIF信息
        $commands = [
            // 第一步：清除所有EXIF信息
            $exiftoolBinary . ' -EXIF:all= -overwrite_original ' . escapeshellarg($filePath),
            // 第二步：清除所有GPS信息
            $exiftoolBinary . ' -GPS:all= -overwrite_original ' . escapeshellarg($filePath),
            // 第三步：清除所有XMP信息
            $exiftoolBinary . ' -XMP:all= -overwrite_original ' . escapeshellarg($filePath),
            // 第四步：清除所有IPTC信息
            $exiftoolBinary . ' -IPTC:all= -overwrite_original ' . escapeshellarg($filePath),
            // 第五步：清除所有Maker Notes
            $exiftoolBinary . ' -MakerNotes:all= -overwrite_original ' . escapeshellarg($filePath),
            // 第六步：清除所有时间相关信息
            $exiftoolBinary . ' -DateTime= -DateTimeOriginal= -DateTimeDigitized= -CreateDate= -ModifyDate= -overwrite_original ' . escapeshellarg($filePath),
        ];
        
        foreach ($commands as $cmd) {
            $output = [];
            $return_var = 0;
            @exec($cmd . ' 2>&1', $output, $return_var);
            
            // 检查文件是否仍然存在且有效
            if (!file_exists($filePath)) {
                return ['success' => false, 'message' => '处理过程中文件丢失'];
            }
            
            $checkImageInfo = @getimagesize($filePath);
            if ($checkImageInfo === false) {
                return ['success' => false, 'message' => '处理过程中图片文件损坏'];
            }
            
            if ($checkImageInfo[0] !== $imageInfo[0] || $checkImageInfo[1] !== $imageInfo[1]) {
                return ['success' => false, 'message' => '处理过程中图片尺寸发生变化'];
            }
        }
        
        // 最终验证：检查是否还有隐私信息
        $verifyOutput = [];
        $verifyCmd = $exiftoolBinary . ' -a -s ' . escapeshellarg($filePath) . ' 2>&1';
        @exec($verifyCmd, $verifyOutput, $verifyReturn);
        
        $hasPrivacyInfo = false;
        $remainingInfo = [];
        
        foreach ($verifyOutput as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // 排除非隐私信息的标签
            if (preg_match('/^(FileModifyDate|FileAccessDate|FileInodeChangeDate|FilePermissions|FileType|FileTypeExtension|MIMEType|ExifByteOrder|ImageWidth|ImageHeight|EncodingProcess|BitsPerSample|ColorComponents|YCbCrSubSampling|Compression|PhotometricInterpretation|Orientation|XResolution|YResolution|ResolutionUnit|YCbCrPositioning|ExifVersion|ComponentsConfiguration|FlashpixVersion|ColorSpace|ExifImageWidth|ExifImageHeight|ProfileCMM|ProfileVersion|ProfileClass|ColorSpaceData|ProfileConnectionSpace|ProfileDateTime|ProfileFileSignature|PrimaryPlatform|CMMFlags|DeviceManufacturer|DeviceModel|DeviceAttributes|RenderingIntent|ConnectionSpaceIlluminant|ProfileCreator|ProfileID|ProfileDescription|ProfileCopyright|MediaWhitePoint|MediaBlackPoint|RedMatrixColumn|GreenMatrixColumn|BlueMatrixColumn|RedTRC|GreenTRC|BlueTRC|WhitePoint|PrimaryChromaticities)/i', $line)) {
                continue; // 跳过这些非隐私信息
            }
            
            // 检查是否包含真正的隐私信息
            if (preg_match('/DateTime(?!.*Profile)|GPS|Make|Model|Artist|Copyright|Software|UserComment|ImageDescription|CreateDate|ModifyDate|SerialNumber|LensModel|LensSerialNumber|OwnerName|CameraOwnerName/i', $line)) {
                $hasPrivacyInfo = true;
                $remainingInfo[] = $line;
            }
        }
        
        // 恢复文件权限
        @chmod($filePath, $originalPerms);
        
        $newSize = filesize($filePath);
        $sizeInfo = '';
        
        if ($newSize < $originalSize) {
            $saved = $originalSize - $newSize;
            $sizeInfo = '，清除了 ' . formatFileSize($saved) . ' 的元数据';
        } elseif ($newSize === $originalSize) {
            $sizeInfo = '，文件大小保持不变';
        }
        
        if ($hasPrivacyInfo) {
            // 检查剩余信息是否都是非隐私信息
            $nonPrivacyOnly = true;
            foreach ($remainingInfo as $info) {
                if (!preg_match('/FileModifyDate|ProfileDateTime|FileAccessDate|FileInodeChangeDate|FilePermissions|FileType|MIMEType|ImageWidth|ImageHeight|ColorSpace|ProfileDescription/i', $info)) {
                    $nonPrivacyOnly = false;
                    break;
                }
            }
            
            if ($nonPrivacyOnly) {
                return [
                    'success' => true, 
                    'message' => 'EXIF隐私信息已彻底清除' . $sizeInfo . '，剩余信息为系统和颜色配置信息（非隐私）'
                ];
            } else {
                return [
                    'success' => false, 
                    'message' => '部分隐私信息可能仍然存在' . $sizeInfo . '。残留信息: ' . implode(', ', array_slice($remainingInfo, 0, 2))
                ];
            }
        } else {
            return [
                'success' => true, 
                'message' => 'EXIF隐私信息已彻底清除' . $sizeInfo . '，图像质量保持不变'
            ];
        }
        
    } catch (Exception $e) {
        error_log('ExifTool remove error: ' . $e->getMessage());
        return ['success' => false, 'message' => '操作失败: ' . $e->getMessage()];
    } catch (Error $e) {
        error_log('ExifTool remove fatal error: ' . $e->getMessage());
        return ['success' => false, 'message' => '系统错误: ' . $e->getMessage()];
    }
}

/**
 * 清除图片 EXIF 信息 - 只使用 ExifTool
 */
function removeImageExif($filePath, $mimeType) {
    // 只使用 ExifTool 清除 EXIF
    if (isExifToolAvailable()) {
        return removeExifWithExifTool($filePath);
    }
    
    return ['success' => false, 'message' => 'ExifTool 不可用，无法清除EXIF信息'];
}



// 获取数据库实例
$db = Typecho_Db::get();

// 获取参数
$page = max(1, intval($request->get('page', 1)));
$keywords = trim($request->get('keywords', ''));
$type = $request->get('type', 'all');
$view = $request->get('view', 'grid');

// 处理 AJAX 请求
if ($request->get('action')) {
    handleAjaxRequest($request, $db, $options, $user);
    exit;
}

// 获取插件配置
try {
    $config = Helper::options()->plugin('MediaLibrary');
    // 兼容复选框和旧版本配置
    $enableGetID3 = is_array($config->enableGetID3) ? in_array('1', $config->enableGetID3) : ($config->enableGetID3 == '1');
    $enableExif = is_array($config->enableExif) ? in_array('1', $config->enableExif) : ($config->enableExif == '1');
    $enableGD = is_array($config->enableGD) ? in_array('1', $config->enableGD) : ($config->enableGD == '1');
    $enableImageMagick = is_array($config->enableImageMagick) ? in_array('1', $config->enableImageMagick) : ($config->enableImageMagick == '1');
    $enableFFmpeg = is_array($config->enableFFmpeg) ? in_array('1', $config->enableFFmpeg) : ($config->enableFFmpeg == '1');
    $enableVideoCompress = is_array($config->enableVideoCompress) ? in_array('1', $config->enableVideoCompress) : ($config->enableVideoCompress == '1');
    $gdQuality = intval($config->gdQuality ?? 80);
    $videoQuality = intval($config->videoQuality ?? 23);
    $videoCodec = $config->videoCodec ?? 'libx264';
} catch (Exception $e) {
    $enableGetID3 = false;
    $enableExif = false;
    $enableGD = false;
    $enableImageMagick = false;
    $enableFFmpeg = false;
    $enableVideoCompress = false;
    $gdQuality = 80;
    $videoQuality = 23;
    $videoCodec = 'libx264';
}

// 获取系统上传限制
$phpMaxFilesize = function_exists('ini_get') ? trim(ini_get('upload_max_filesize')) : '2M';
if (preg_match("/^([0-9]+)([a-z]{1,2})$/i", $phpMaxFilesize, $matches)) {
    $phpMaxFilesize = strtolower($matches[1] . $matches[2] . (1 == strlen($matches[2]) ? 'b' : ''));
}

// 固定每页显示数量
$pageSize = 20;

// 构建查询 - 添加去重和更严格的条件
$select = $db->select()->from('table.contents')
    ->where('table.contents.type = ?', 'attachment')
    ->where('table.contents.status = ?', 'publish')  // 只查询已发布的附件
    ->order('table.contents.created', Typecho_Db::SORT_DESC);
    
if (!empty($keywords)) {
    $select->where('table.contents.title LIKE ?', '%' . $keywords . '%');
}

if ($type !== 'all') {
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
            $select->where('table.contents.text LIKE ?', '%application%');
            break;
    }
}

// 获取总数 - 使用 DISTINCT 避免重复计数
$totalQuery = clone $select;
$total = $db->fetchObject($totalQuery->select('COUNT(DISTINCT table.contents.cid) as total'))->total;

// 分页查询 - 添加 DISTINCT 和 GROUP BY
$offset = ($page - 1) * $pageSize;
$attachments = $db->fetchAll($select->group('table.contents.cid')->limit($pageSize)->offset($offset));

// 处理附件数据 - 添加去重逻辑
$processedCids = array(); // 用于跟踪已处理的 CID
$uniqueAttachments = array();

foreach ($attachments as $attachment) {
    // 跳过已处理的 CID
    if (in_array($attachment['cid'], $processedCids)) {
        continue;
    }
    
    $processedCids[] = $attachment['cid'];
    
    $textData = isset($attachment['text']) ? $attachment['text'] : '';
    
    $attachmentData = array();
    if (!empty($textData)) {
        $unserialized = @unserialize($textData);
        if (is_array($unserialized)) {
            $attachmentData = $unserialized;
        }
    }
    
    $attachment['attachment'] = $attachmentData;
    $attachment['mime'] = isset($attachmentData['mime']) ? $attachmentData['mime'] : 'application/octet-stream';
    $attachment['isImage'] = isset($attachmentData['mime']) && (
        strpos($attachmentData['mime'], 'image/') === 0 || 
        in_array(strtolower(pathinfo($attachmentData['name'] ?? '', PATHINFO_EXTENSION)), ['avif'])
    );
    // 在处理附件数据的循环中，添加更准确的文档类型判断
$attachment['isDocument'] = isset($attachmentData['mime']) && (
    strpos($attachmentData['mime'], 'application/pdf') === 0 ||
    strpos($attachmentData['mime'], 'application/msword') === 0 ||
    strpos($attachmentData['mime'], 'application/vnd.openxmlformats-officedocument.wordprocessingml') === 0 ||
    strpos($attachmentData['mime'], 'application/vnd.ms-powerpoint') === 0 ||
    strpos($attachmentData['mime'], 'application/vnd.openxmlformats-officedocument.presentationml') === 0 ||
    strpos($attachmentData['mime'], 'application/vnd.ms-excel') === 0 ||
    strpos($attachmentData['mime'], 'application/vnd.openxmlformats-officedocument.spreadsheetml') === 0
);

    $attachment['isVideo'] = isset($attachmentData['mime']) && strpos($attachmentData['mime'], 'video/') === 0;
    $attachment['size'] = formatFileSize(isset($attachmentData['size']) ? intval($attachmentData['size']) : 0);
    
    if (isset($attachmentData['path']) && !empty($attachmentData['path'])) {
        $attachment['url'] = Typecho_Common::url($attachmentData['path'], $options->siteUrl);
        $attachment['hasValidUrl'] = true;
    } else {
        $attachment['url'] = '';
        $attachment['hasValidUrl'] = false;
    }
    
    if (!isset($attachment['title']) || empty($attachment['title'])) {
        $attachment['title'] = isset($attachmentData['name']) ? $attachmentData['name'] : '未命名文件';
    }
    
    // 获取所属文章信息
    $attachment['parent_post'] = getParentPost($db, $attachment['cid']);
    
    $uniqueAttachments[] = $attachment;
}

// 使用去重后的数据
$attachments = $uniqueAttachments;

// 计算分页
$totalPages = $total > 0 ? ceil($total / $pageSize) : 1;

// 辅助函数
// 修复文件大小显示问题
function formatFileSize($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    if ($bytes == 0) return '0 B';
    
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, 2) . ' ' . $units[$pow];
}
function getParentPost($db, $attachmentCid)
{
    try {
        $attachment = $db->fetchRow($db->select()->from('table.contents')
            ->where('cid = ? AND type = ?', $attachmentCid, 'attachment'));
            
        if ($attachment && $attachment['parent'] > 0) {
            $parentPost = $db->fetchRow($db->select()->from('table.contents')
                ->where('cid = ?', $attachment['parent']));
                
            if ($parentPost) {
                return [
                    'status' => 'archived',
                    'post' => [
                        'cid' => $parentPost['cid'],
                        'title' => $parentPost['title'],
                        'type' => $parentPost['type']
                    ]
                ];
            }
        }
        
        return ['status' => 'unarchived', 'post' => null];
    } catch (Exception $e) {
        return ['status' => 'unarchived', 'post' => null];
    }
}

function getDetailedFileInfo($filePath, $enableGetID3 = false)
{
    $info = [];
    
    if (!file_exists($filePath)) {
        return $info;
    }
    
    $info['size'] = filesize($filePath);
    $info['modified'] = filemtime($filePath);
    $info['permissions'] = substr(sprintf('%o', fileperms($filePath)), -4);
    
    if (extension_loaded('fileinfo')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $info['mime'] = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        $finfoMime = finfo_open(FILEINFO_MIME);
        $info['mime_full'] = finfo_file($finfoMime, $filePath);
        finfo_close($finfoMime);
    }
    
    // 只有启用 GetID3 才使用
    if ($enableGetID3 && file_exists(__TYPECHO_ROOT_DIR__ . '/usr/plugins/MediaLibrary/lib/getid3/getid3.php')) {
        try {
            require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/MediaLibrary/lib/getid3/getid3.php';
            $getID3 = new getID3;
            $fileInfo = $getID3->analyze($filePath);
            
            if (isset($fileInfo['fileformat'])) {
                $info['format'] = $fileInfo['fileformat'];
            }
            
            if (isset($fileInfo['playtime_string'])) {
                $info['duration'] = $fileInfo['playtime_string'];
            }
            
            if (isset($fileInfo['bitrate'])) {
                $info['bitrate'] = round($fileInfo['bitrate'] / 1000) . ' kbps';
            }
            
            if (isset($fileInfo['video']['resolution_x']) && isset($fileInfo['video']['resolution_y'])) {
                $info['dimensions'] = $fileInfo['video']['resolution_x'] . ' × ' . $fileInfo['video']['resolution_y'];
            }
            
            if (isset($fileInfo['audio']['channels'])) {
                $info['channels'] = $fileInfo['audio']['channels'] . ' 声道';
            }
            
            if (isset($fileInfo['audio']['sample_rate'])) {
                $info['sample_rate'] = number_format($fileInfo['audio']['sample_rate']) . ' Hz';
            }
            
        } catch (Exception $e) {
            // GetID3 分析失败，忽略错误
        }
    }
    
    return $info;
}

function handleAjaxRequest($request, $db, $options, $user)
{
    $action = $request->get('action');
    
    // 获取插件配置
    try {
        $config = Helper::options()->plugin('MediaLibrary');
        $enableGetID3 = is_array($config->enableGetID3) ? in_array('1', $config->enableGetID3) : ($config->enableGetID3 == '1');
        $enableExif = is_array($config->enableExif) ? in_array('1', $config->enableExif) : ($config->enableExif == '1');
        $enableGD = is_array($config->enableGD) ? in_array('1', $config->enableGD) : ($config->enableGD == '1');
        $enableImageMagick = is_array($config->enableImageMagick) ? in_array('1', $config->enableImageMagick) : ($config->enableImageMagick == '1');
        $enableFFmpeg = is_array($config->enableFFmpeg) ? in_array('1', $config->enableFFmpeg) : ($config->enableFFmpeg == '1');
        $enableVideoCompress = is_array($config->enableVideoCompress) ? in_array('1', $config->enableVideoCompress) : ($config->enableVideoCompress == '1');
        $gdQuality = intval($config->gdQuality ?? 80);
        $videoQuality = intval($config->videoQuality ?? 23);
        $videoCodec = $config->videoCodec ?? 'libx264';
    } catch (Exception $e) {
        $enableGetID3 = false;
        $enableExif = false;
        $enableGD = false;
        $enableImageMagick = false;
        $enableFFmpeg = false;
        $enableVideoCompress = false;
        $gdQuality = 80;
        $videoQuality = 23;
        $videoCodec = 'libx264';
    }
    
    // 确保输出 JSON
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        switch ($action) {
            case 'delete':
                $cids = $request->getArray('cids');
                if (empty($cids)) {
                    echo json_encode(['success' => false, 'message' => '请选择要删除的文件'], JSON_UNESCAPED_UNICODE);
                    return;
                }
                
                $deleteCount = 0;
                foreach ($cids as $cid) {
                    $cid = intval($cid);
                    $attachment = $db->fetchRow($db->select()->from('table.contents')
                        ->where('cid = ? AND type = ?', $cid, 'attachment'));
                        
                    if ($attachment) {
                        // 删除物理文件
                        if (isset($attachment['text'])) {
                            $attachmentData = @unserialize($attachment['text']);
                            if (is_array($attachmentData) && isset($attachmentData['path'])) {
                                $filePath = __TYPECHO_ROOT_DIR__ . $attachmentData['path'];
                                if (file_exists($filePath)) {
                                    @unlink($filePath);
                                }
                            }
                        }
                        
                        // 删除数据库记录
                        $db->query($db->delete('table.contents')->where('cid = ?', $cid));
                        $deleteCount++;
                    }
                }
                
                echo '';
                break;
                
            case 'upload':
                if (empty($_FILES)) {
                    echo json_encode(['success' => false, 'message' => '没有文件上传'], JSON_UNESCAPED_UNICODE);
                    return;
                }
                
                try {
                    // 使用 Typecho 的上传处理
                    $upload = \Widget\Upload::alloc();
                    $result = $upload->upload($_FILES);
                    
                    if ($result) {
                        echo json_encode(['success' => true, 'count' => 1, 'data' => $result], JSON_UNESCAPED_UNICODE);
                    } else {
                        echo json_encode(['success' => false, 'message' => '上传失败'], JSON_UNESCAPED_UNICODE);
                    }
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => '上传失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }
                break;
                
            case 'get_info':
                $cid = intval($request->get('cid'));
                if (!$cid) {
                    echo json_encode(['success' => false, 'message' => '无效的文件ID'], JSON_UNESCAPED_UNICODE);
                    return;
                }
                
                $attachment = $db->fetchRow($db->select()->from('table.contents')
                    ->where('cid = ? AND type = ?', $cid, 'attachment'));
                    
                if (!$attachment) {
                    echo json_encode(['success' => false, 'message' => '文件不存在'], JSON_UNESCAPED_UNICODE);
                    return;
                }
                
                $attachmentData = array();
                if (isset($attachment['text']) && !empty($attachment['text'])) {
                    $unserialized = @unserialize($attachment['text']);
                    if (is_array($unserialized)) {
                        $attachmentData = $unserialized;
                    }
                }
                
                $parentPost = getParentPost($db, $cid);
                
                $detailedInfo = [];
                if (isset($attachmentData['path'])) {
                    $filePath = __TYPECHO_ROOT_DIR__ . $attachmentData['path'];
                    $detailedInfo = getDetailedFileInfo($filePath, $enableGetID3);
                }
                
                $info = [
                    'title' => isset($attachment['title']) ? $attachment['title'] : '未命名文件',
                    'mime' => isset($attachmentData['mime']) ? $attachmentData['mime'] : 'unknown',
                    'size' => formatFileSize(isset($attachmentData['size']) ? intval($attachmentData['size']) : 0),
                    'url' => isset($attachmentData['path']) ? 
                        Typecho_Common::url($attachmentData['path'], $options->siteUrl) : '',
                    'created' => isset($attachment['created']) ? date('Y-m-d H:i:s', $attachment['created']) : '',
                    'path' => isset($attachmentData['path']) ? $attachmentData['path'] : '',
                    'parent_post' => $parentPost,
                    'detailed_info' => $detailedInfo
                ];
                
                echo json_encode(['success' => true, 'data' => $info], JSON_UNESCAPED_UNICODE);
                break;
                
            case 'compress_images':
                $cids = $request->getArray('cids');
                $quality = intval($request->get('quality', $gdQuality));
                $outputFormat = $request->get('output_format', 'original');
                $compressMethod = $request->get('compress_method', 'gd');
                $replaceOriginal = $request->get('replace_original') === '1';
                $customName = $request->get('custom_name', '');
                
                if (empty($cids)) {
                    echo json_encode(['success' => false, 'message' => '请选择要压缩的图片'], JSON_UNESCAPED_UNICODE);
                    return;
                }
                
                $results = [];
                foreach ($cids as $cid) {
                    $result = compressImage($cid, $quality, $outputFormat, $compressMethod, $replaceOriginal, $customName, $db, $options, $user);
                    $results[] = $result;
                }
                
                echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
                break;
                
            case 'compress_videos':
                $cids = $request->getArray('cids');
                $quality = intval($request->get('video_quality', $videoQuality));
                $codec = $request->get('video_codec', $videoCodec);
                $replaceOriginal = $request->get('replace_original') === '1';
                $customName = $request->get('custom_name', '');
                
                if (empty($cids)) {
                    echo json_encode(['success' => false, 'message' => '请选择要压缩的视频'], JSON_UNESCAPED_UNICODE);
                    return;
                }
                
                $results = [];
                foreach ($cids as $cid) {
                    $result = compressVideo($cid, $quality, $codec, $replaceOriginal, $customName, $db, $options, $user);
                    $results[] = $result;
                }
                
                echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
                break;
                
            case 'check_privacy':
    // 检查是否有可用的 EXIF 工具
    $hasExifTool = isExifToolAvailable();
    $hasPhpExif = extension_loaded('exif');
    
    if (!$enableExif || (!$hasExifTool && !$hasPhpExif)) {
        echo json_encode(['success' => false, 'message' => 'EXIF功能未启用或无可用的EXIF工具'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $cids = $request->getArray('cids');
    if (empty($cids)) {
        echo json_encode(['success' => false, 'message' => '请选择要检测的图片'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $results = [];
    foreach ($cids as $cid) {
        $result = checkImagePrivacy($cid, $db, $options);
        $results[] = $result;
    }
    
    echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
    break;
                
            case 'get_gps_data':
                $cids = $request->getArray('cids');
                if (empty($cids)) {
                    echo json_encode(['success' => false, 'message' => '请选择图片文件'], JSON_UNESCAPED_UNICODE);
                    return;
                }
                
                $gpsData = [];
                foreach ($cids as $cid) {
                    $attachment = $db->fetchRow($db->select()->from('table.contents')
                        ->where('cid = ? AND type = ?', $cid, 'attachment'));
                        
                    if ($attachment) {
                        $attachmentData = @unserialize($attachment['text']);
                        if (is_array($attachmentData) && isset($attachmentData['path'])) {
                            $filePath = __TYPECHO_ROOT_DIR__ . $attachmentData['path'];
                            if (file_exists($filePath) && strpos($attachmentData['mime'], 'image/') === 0) {
                                $exifData = @exif_read_data($filePath);
                                if ($exifData && isset($exifData['GPSLatitude'], $exifData['GPSLongitude'], $exifData['GPSLatitudeRef'], $exifData['GPSLongitudeRef'])
                                    && is_array($exifData['GPSLatitude']) && is_array($exifData['GPSLongitude'])) {
                                    
                                    try {
                                        $lat = exifToFloat($exifData['GPSLatitude'], $exifData['GPSLatitudeRef']);
                                        $lng = exifToFloat($exifData['GPSLongitude'], $exifData['GPSLongitudeRef']);
                                        
                                        $gpsData[] = [
                                            'cid' => $cid,
                                            'title' => $attachment['title'],
                                            'coords' => [$lng, $lat],
                                            'url' => Typecho_Common::url($attachmentData['path'], $options->siteUrl)
                                        ];
                                    } catch (Exception $e) {
                                        // GPS解析失败，跳过
                                    }
                                }
                            }
                        }
                    }
                }
                
                echo json_encode(['success' => true, 'data' => $gpsData], JSON_UNESCAPED_UNICODE);
                break;
                
                
                // 添加获取智能建议的AJAX处理
case 'get_smart_suggestion':
    $cids = $request->getArray('cids');
    if (empty($cids)) {
        echo json_encode(['success' => false, 'message' => '请选择图片文件'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $suggestions = [];
    foreach ($cids as $cid) {
        $attachment = $db->fetchRow($db->select()->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment'));
            
        if ($attachment) {
            $attachmentData = @unserialize($attachment['text']);
            if (is_array($attachmentData) && isset($attachmentData['path'])) {
                $filePath = __TYPECHO_ROOT_DIR__ . $attachmentData['path'];
                if (file_exists($filePath) && strpos($attachmentData['mime'], 'image/') === 0) {
                    $fileSize = filesize($filePath);
                    $suggestion = getSmartCompressionSuggestion($filePath, $attachmentData['mime'], $fileSize);
                    $suggestions[] = [
                        'cid' => $cid,
                        'filename' => $attachmentData['name'],
                        'size' => formatFileSize($fileSize),
                        'suggestion' => $suggestion
                    ];
                }
            }
        }
    }
    
    echo json_encode(['success' => true, 'suggestions' => $suggestions], JSON_UNESCAPED_UNICODE);
    break;
    
    
case 'remove_exif':
    // 检查是否有可用的 EXIF 工具
    $hasExifTool = isExifToolAvailable();
    $hasPhpExif = extension_loaded('exif');
    $hasGD = extension_loaded('gd');
    
    if (!$enableExif || (!$hasExifTool && !$hasGD)) {
        echo json_encode(['success' => false, 'message' => 'EXIF功能未启用或无可用的EXIF清除工具'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $cid = intval($request->get('cid'));
    if (!$cid) {
        echo json_encode(['success' => false, 'message' => '无效的文件ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $attachment = $db->fetchRow($db->select()->from('table.contents')
        ->where('cid = ? AND type = ?', $cid, 'attachment'));
        
    if (!$attachment) {
        echo json_encode(['success' => false, 'message' => '文件不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $attachmentData = @unserialize($attachment['text']);
    if (!is_array($attachmentData) || !isset($attachmentData['path'])) {
        echo json_encode(['success' => false, 'message' => '文件数据错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $filePath = __TYPECHO_ROOT_DIR__ . $attachmentData['path'];
    if (!file_exists($filePath)) {
        echo json_encode(['success' => false, 'message' => '文件不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查是否为图片
    if (strpos($attachmentData['mime'], 'image/') !== 0) {
        echo json_encode(['success' => false, 'message' => '只能清除图片文件的EXIF信息'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 智能清除EXIF信息
    $result = removeImageExif($filePath, $attachmentData['mime']);
    
    if ($result['success']) {
        echo json_encode(['success' => true, 'message' => $result['message']], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'message' => $result['message']], JSON_UNESCAPED_UNICODE);
    }
    break;
    
    
                
            default:
                echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 修复压缩图片函数 - 支持无感替换
function compressImage($cid, $quality, $outputFormat, $compressMethod, $replaceOriginal, $customName, $db, $options, $user)
{
    $attachment = $db->fetchRow($db->select()->from('table.contents')
        ->where('cid = ? AND type = ?', $cid, 'attachment'));
        
    if (!$attachment) {
        return ['success' => false, 'message' => '文件不存在', 'cid' => $cid];
    }
    
    $attachmentData = @unserialize($attachment['text']);
    if (!is_array($attachmentData) || !isset($attachmentData['path'])) {
        return ['success' => false, 'message' => '文件数据错误', 'cid' => $cid];
    }
    
    $originalPath = __TYPECHO_ROOT_DIR__ . $attachmentData['path'];
    if (!file_exists($originalPath)) {
        return ['success' => false, 'message' => '原文件不存在', 'cid' => $cid];
    }
    
    // 检查是否为图片
    if (strpos($attachmentData['mime'], 'image/') !== 0 && 
        !in_array(strtolower(pathinfo($attachmentData['name'] ?? '', PATHINFO_EXTENSION)), ['avif'])) {
        return ['success' => false, 'message' => '只能压缩图片文件', 'cid' => $cid];
    }
    
    $pathInfo = pathinfo($originalPath);
    
    // 获取原始文件大小
    $originalSize = filesize($originalPath);
    
    if ($replaceOriginal) {
        // 替换原文件模式
        if ($outputFormat === 'original') {
            // 保持原格式，直接覆盖原文件
            $compressedPath = $originalPath;
            $tempPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_temp_compress.' . $pathInfo['extension'];
            
            // 先压缩到临时文件
            $result = compressImageWithMethod($originalPath, $tempPath, $quality, $compressMethod, $outputFormat, $attachmentData['mime']);
            
            if (!$result['success']) {
                return array_merge($result, ['cid' => $cid]);
            }
            
            // 检查压缩效果
            $tempSize = filesize($tempPath);
            if ($tempSize >= $originalSize) {
                @unlink($tempPath);
                return [
                    'success' => false, 
                    'message' => '压缩后文件大小未减少（' . formatFileSize($tempSize) . ' >= ' . formatFileSize($originalSize) . '），建议调整压缩参数',
                    'cid' => $cid,
                    'original_size' => formatFileSize($originalSize),
                    'compressed_size' => formatFileSize($tempSize)
                ];
            }
            
            // 替换原文件
            if (!@unlink($originalPath) || !rename($tempPath, $originalPath)) {
                @unlink($tempPath);
                return ['success' => false, 'message' => '文件替换失败', 'cid' => $cid];
            }
            
            $compressedSize = filesize($originalPath);
            
        } else {
            // 格式转换模式
            $newExt = $outputFormat;
            $tempPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_temp_convert.' . $newExt;
            
            // 压缩到临时文件
            $result = compressImageWithMethod($originalPath, $tempPath, $quality, $compressMethod, $outputFormat, $attachmentData['mime']);
            
            if (!$result['success']) {
                return array_merge($result, ['cid' => $cid]);
            }
            
            $tempSize = filesize($tempPath);
            
            // 删除原文件并重命名临时文件
            @unlink($originalPath);
            if (!rename($tempPath, $originalPath)) {
                return ['success' => false, 'message' => '文件替换失败', 'cid' => $cid];
            }
            
            $compressedSize = filesize($originalPath);
            
            // 更新数据库中的MIME类型和文件名
            $attachmentData['size'] = $compressedSize;
            $attachmentData['mime'] = 'image/' . $outputFormat;
            
            // 更新文件名扩展名但保持路径不变
            $newFileName = $pathInfo['filename'] . '.' . $newExt;
            $attachmentData['name'] = $newFileName;
            
            $db->query($db->update('table.contents')
                ->rows([
                    'text' => serialize($attachmentData),
                    'title' => $newFileName
                ])
                ->where('cid = ?', $cid));
            
            $savings = $originalSize > 0 ? round((1 - $compressedSize / $originalSize) * 100, 1) : 0;
            
            return [
                'success' => true,
                'message' => '图片压缩成功（格式已转换）',
                'cid' => $cid,
                'original_size' => formatFileSize($originalSize),
                'compressed_size' => formatFileSize($compressedSize),
                'savings' => $savings . '%',
                'method' => $compressMethod,
                'format' => $outputFormat,
                'format_changed' => true
            ];
        }
        
        // 更新数据库中的文件大小
        $attachmentData['size'] = $compressedSize;
        $db->query($db->update('table.contents')
            ->rows(['text' => serialize($attachmentData)])
            ->where('cid = ?', $cid));
            
    } else {
        // 保留原文件，创建新文件
        $outputExt = $outputFormat === 'original' ? $pathInfo['extension'] : $outputFormat;
        
        if (!empty($customName)) {
            $compressedPath = $pathInfo['dirname'] . '/' . $customName . '.' . $outputExt;
        } else {
            $compressedPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_compressed.' . $outputExt;
        }
        
        // 压缩图片
        $result = compressImageWithMethod($originalPath, $compressedPath, $quality, $compressMethod, $outputFormat, $attachmentData['mime']);
        
        if (!$result['success']) {
            return array_merge($result, ['cid' => $cid]);
        }
        
        // 获取压缩后文件大小
        $compressedSize = filesize($compressedPath);
        
        // 添加到数据库
        $newAttachmentData = $attachmentData;
        $newAttachmentData['path'] = str_replace(__TYPECHO_ROOT_DIR__, '', $compressedPath);
        $newAttachmentData['size'] = $compressedSize;
        $newAttachmentData['name'] = basename($compressedPath);
        
        // 更新 MIME 类型
        if ($outputFormat !== 'original') {
            $newAttachmentData['mime'] = 'image/' . $outputFormat;
        } elseif (extension_loaded('fileinfo')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = finfo_file($finfo, $compressedPath);
            if ($detectedMime) {
                $newAttachmentData['mime'] = $detectedMime;
            }
            finfo_close($finfo);
        }
        
        $struct = [
            'title' => basename($compressedPath),
            'slug' => basename($compressedPath),
            'created' => time(),
            'modified' => time(),
            'text' => serialize($newAttachmentData),
            'order' => 0,
            'authorId' => $user->uid,
            'template' => NULL,
            'type' => 'attachment',
            'status' => 'publish',
            'password' => NULL,
            'commentsNum' => 0,
            'allowComment' => 0,
            'allowPing' => 0,
            'allowFeed' => 0,
            'parent' => 0
        ];
        
        $db->query($db->insert('table.contents')->rows($struct));
    }
    
    // 计算节省的空间
    $savings = $originalSize > 0 ? round((1 - $compressedSize / $originalSize) * 100, 1) : 0;
    
    // 检查压缩效果
    if ($compressedSize >= $originalSize) {
        $message = '压缩完成，但文件大小未减少（可能是质量设置过高或原图已经很优化）';
    } else {
        $message = '图片压缩成功';
    }
    
    return [
        'success' => true,
        'message' => $message,
        'cid' => $cid,
        'original_size' => formatFileSize($originalSize),
        'compressed_size' => formatFileSize($compressedSize),
        'savings' => $savings . '%',
        'method' => $compressMethod,
        'format' => $outputFormat
    ];
}

// 添加智能压缩建议函数
function getSmartCompressionSuggestion($filePath, $mime, $fileSize)
{
    $suggestions = [
        'quality' => 80,
        'format' => 'original',
        'method' => 'gd',
        'reason' => '默认设置'
    ];
    
    // 根据文件大小调整质量
    if ($fileSize > 10 * 1024 * 1024) { // 大于10MB
        $suggestions['quality'] = 50;
        $suggestions['reason'] = '超大文件，建议大幅压缩';
    } elseif ($fileSize > 5 * 1024 * 1024) { // 大于5MB
        $suggestions['quality'] = 60;
        $suggestions['reason'] = '大文件，建议降低质量以减小体积';
    } elseif ($fileSize > 2 * 1024 * 1024) { // 大于2MB
        $suggestions['quality'] = 70;
        $suggestions['reason'] = '中等文件，适度压缩';
    } elseif ($fileSize > 500 * 1024) { // 大于500KB
        $suggestions['quality'] = 80;
        $suggestions['reason'] = '标准压缩';
    } else { // 小于500KB
        $suggestions['quality'] = 90;
        $suggestions['reason'] = '小文件，保持高质量';
    }
    
    // 根据格式调整建议
    switch ($mime) {
        case 'image/png':
            if ($fileSize > 1024 * 1024) { // PNG大于1MB建议转JPEG
                $suggestions['format'] = 'jpeg';
                $suggestions['quality'] = min($suggestions['quality'], 85);
                $suggestions['reason'] = 'PNG文件较大，建议转换为JPEG格式并适度压缩';
            } elseif ($fileSize > 500 * 1024) {
                $suggestions['quality'] = min($suggestions['quality'], 75);
                $suggestions['reason'] = 'PNG文件，建议适度压缩';
            }
            break;
            
        case 'image/gif':
            // GIF不建议压缩，可能会丢失动画
            $suggestions['quality'] = 95;
            $suggestions['format'] = 'original';
            $suggestions['reason'] = 'GIF文件，保持高质量避免丢失动画';
            break;
            
        case 'image/webp':
            $suggestions['quality'] = max(60, $suggestions['quality'] - 10);
            $suggestions['reason'] = 'WebP格式，可以使用较低质量仍保持良好效果';
            break;
            
        case 'image/jpeg':
            // JPEG已经是压缩格式，需要更保守的压缩
            if ($fileSize > 5 * 1024 * 1024) {
                $suggestions['quality'] = 60;
                $suggestions['reason'] = 'JPEG文件很大，建议适度压缩';
            } elseif ($fileSize > 2 * 1024 * 1024) {
                $suggestions['quality'] = 75;
                $suggestions['reason'] = 'JPEG文件较大，轻度压缩';
            } else {
                $suggestions['quality'] = 85;
                $suggestions['reason'] = 'JPEG文件，保持较高质量';
            }
            break;
    }
    
    // 选择最佳压缩方法
    if (extension_loaded('imagick')) {
        $suggestions['method'] = 'imagick';
    } elseif (extension_loaded('gd')) {
        $suggestions['method'] = 'gd';
    }
    
    return $suggestions;
}



// 修复视频压缩函数，添加进度显示
function compressVideo($cid, $quality, $codec, $replaceOriginal, $customName, $db, $options, $user)
{
    $attachment = $db->fetchRow($db->select()->from('table.contents')
        ->where('cid = ? AND type = ?', $cid, 'attachment'));
        
    if (!$attachment) {
        return ['success' => false, 'message' => '文件不存在', 'cid' => $cid];
    }
    
    $attachmentData = @unserialize($attachment['text']);
    if (!is_array($attachmentData) || !isset($attachmentData['path'])) {
        return ['success' => false, 'message' => '文件数据错误', 'cid' => $cid];
    }
    
    $originalPath = __TYPECHO_ROOT_DIR__ . $attachmentData['path'];
    if (!file_exists($originalPath)) {
        return ['success' => false, 'message' => '原文件不存在', 'cid' => $cid];
    }
    
    // 检查是否为视频
    if (strpos($attachmentData['mime'], 'video/') !== 0) {
        return ['success' => false, 'message' => '只能压缩视频文件', 'cid' => $cid];
    }
    
    $pathInfo = pathinfo($originalPath);
    
    if ($replaceOriginal) {
        $compressedPath = $originalPath;
        $tempPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_temp.' . $pathInfo['extension'];
    } else {
        if (!empty($customName)) {
            $compressedPath = $pathInfo['dirname'] . '/' . $customName . '.' . $pathInfo['extension'];
        } else {
            $compressedPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_compressed.' . $pathInfo['extension'];
        }
        $tempPath = $compressedPath;
    }
    
    // 获取原始文件大小
    $originalSize = filesize($originalPath);
    
    // 使用FFmpeg压缩视频
    if (!function_exists('exec')) {
        return ['success' => false, 'message' => 'exec函数被禁用', 'cid' => $cid];
    }
    
    // 创建进度文件
    $progressFile = sys_get_temp_dir() . '/video_compress_' . $cid . '.log';
    
    $output = [];
    $return_var = 0;
    
    // 构建FFmpeg命令，添加进度输出
    $cmd = 'ffmpeg -i ' . escapeshellarg($originalPath) . ' -c:v ' . $codec . ' -crf ' . $quality . ' -c:a aac -b:a 128k -movflags +faststart -progress ' . escapeshellarg($progressFile) . ' ' . escapeshellarg($tempPath) . ' 2>&1';
    
    @exec($cmd, $output, $return_var);
    
    // 清理进度文件
    if (file_exists($progressFile)) {
        @unlink($progressFile);
    }
    
    if ($return_var !== 0 || !file_exists($tempPath)) {
        return ['success' => false, 'message' => 'FFmpeg压缩失败: ' . implode("\n", array_slice($output, -5)), 'cid' => $cid];
    }
    
    // 如果是替换原文件，需要移动临时文件
    if ($replaceOriginal) {
        if (!rename($tempPath, $originalPath)) {
            @unlink($tempPath);
            return ['success' => false, 'message' => '替换原文件失败', 'cid' => $cid];
        }
    }
    
    // 获取压缩后文件大小
    $compressedSize = filesize($compressedPath);
    
    if (!$replaceOriginal) {
        // 添加到数据库
        $newAttachmentData = $attachmentData;
        $newAttachmentData['path'] = str_replace(__TYPECHO_ROOT_DIR__, '', $compressedPath);
        $newAttachmentData['size'] = $compressedSize;
        $newAttachmentData['name'] = basename($compressedPath);
        
        $struct = [
            'title' => basename($compressedPath),
            'slug' => basename($compressedPath),
            'created' => time(),
            'modified' => time(),
            'text' => serialize($newAttachmentData),
            'order' => 0,
            'authorId' => $user->uid,
            'template' => NULL,
            'type' => 'attachment',
            'status' => 'publish',
            'password' => NULL,
            'commentsNum' => 0,
            'allowComment' => 0,
            'allowPing' => 0,
            'allowFeed' => 0,
            'parent' => 0
        ];
        
        $db->query($db->insert('table.contents')->rows($struct));
    } else {
        // 替换原文件，更新数据库记录
        $attachmentData['size'] = $compressedSize;
        
        $db->query($db->update('table.contents')
            ->rows(['text' => serialize($attachmentData)])
            ->where('cid = ?', $cid));
    }
    
    // 计算节省的空间
    $savings = $originalSize > 0 ? round((1 - $compressedSize / $originalSize) * 100, 1) : 0;
    
    return [
        'success' => true,
        'message' => '视频压缩成功',
        'cid' => $cid,
        'original_size' => formatFileSize($originalSize),
        'compressed_size' => formatFileSize($compressedSize),
        'savings' => $savings . '%',
        'codec' => $codec,
        'quality' => $quality
    ];
}


function compressImageWithMethod($sourcePath, $destPath, $quality, $method, $outputFormat, $originalMime)
{
    switch ($method) {
        case 'gd':
            return compressWithGD($sourcePath, $destPath, $quality, $outputFormat, $originalMime);
        case 'imagick':
            return compressWithImageMagick($sourcePath, $destPath, $quality, $outputFormat);
        case 'ffmpeg':
            return compressWithFFmpeg($sourcePath, $destPath, $quality, $outputFormat);
        default:
            return ['success' => false, 'message' => '不支持的压缩方法'];
    }
}

function compressWithGD($sourcePath, $destPath, $quality, $outputFormat, $originalMime)
{
    // 创建图像资源
    switch ($originalMime) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $image = imagecreatefrompng($sourcePath);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($sourcePath);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($sourcePath);
            break;
        default:
            return ['success' => false, 'message' => 'GD不支持的图片格式'];
    }
    
    if (!$image) {
        return ['success' => false, 'message' => '无法读取图片'];
    }
    
    $success = false;
    $targetFormat = $outputFormat === 'original' ? $originalMime : 'image/' . $outputFormat;
    
    switch ($targetFormat) {
        case 'image/jpeg':
            $success = imagejpeg($image, $destPath, $quality);
            break;
        case 'image/png':
            $pngQuality = 9 - round(($quality / 100) * 9);
            $success = imagepng($image, $destPath, $pngQuality);
            break;
        case 'image/gif':
            $success = imagegif($image, $destPath);
            break;
        case 'image/webp':
            $success = imagewebp($image, $destPath, $quality);
            break;
        default:
            imagedestroy($image);
            return ['success' => false, 'message' => 'GD不支持输出该格式'];
    }
    
    imagedestroy($image);
    
    if ($success) {
        return ['success' => true];
    } else {
        return ['success' => false, 'message' => '图片压缩失败'];
    }
}

// 改进压缩方法，添加更好的错误处理
function compressWithImageMagick($sourcePath, $destPath, $quality, $outputFormat)
{
    if (!extension_loaded('imagick')) {
        return ['success' => false, 'message' => 'ImageMagick扩展未安装'];
    }
    
    try {
        $imagick = new Imagick($sourcePath);
        
        // 获取原始信息
        $originalFormat = $imagick->getImageFormat();
        $originalSize = filesize($sourcePath);
        
        // 设置压缩质量
        $imagick->setImageCompressionQuality($quality);
        
        // 如果是PNG转JPEG，需要设置背景色
        if ($outputFormat === 'jpeg' && strtolower($originalFormat) === 'png') {
            $imagick->setImageBackgroundColor('white');
            $imagick = $imagick->flattenImages();
        }
        
        // 设置输出格式
        if ($outputFormat !== 'original') {
            $imagick->setImageFormat($outputFormat);
        }
        
        // 优化图片
        $imagick->stripImage(); // 移除EXIF等元数据
        
        // 根据格式进行特殊优化
        switch (strtolower($outputFormat === 'original' ? $originalFormat : $outputFormat)) {
            case 'jpeg':
                $imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
                $imagick->setImageCompressionQuality($quality);
                break;
            case 'png':
                $imagick->setImageCompression(Imagick::COMPRESSION_ZIP);
                break;
            case 'webp':
                $imagick->setImageFormat('webp');
                $imagick->setImageCompressionQuality($quality);
                break;
        }
        
        // 写入文件
        $imagick->writeImage($destPath);
        $imagick->destroy();
        
        // 检查文件是否成功创建
        if (!file_exists($destPath)) {
            return ['success' => false, 'message' => 'ImageMagick压缩失败：文件未生成'];
        }
        
        $newSize = filesize($destPath);
        
        // 如果压缩后文件更大，给出警告但仍然成功
        if ($newSize >= $originalSize) {
            return [
                'success' => true, 
                'message' => 'ImageMagick压缩完成，但文件大小未减少',
                'warning' => true
            ];
        }
        
        return ['success' => true];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'ImageMagick压缩失败: ' . $e->getMessage()];
    }
}
function compressWithFFmpeg($sourcePath, $destPath, $quality, $outputFormat)
{
    if (!function_exists('exec')) {
        return ['success' => false, 'message' => 'exec函数被禁用'];
    }
    
    $output = [];
    $return_var = 0;
    
    // 构建FFmpeg命令
    $cmd = 'ffmpeg -i ' . escapeshellarg($sourcePath) . ' -q:v ' . intval($quality / 10) . ' ';
    
    if ($outputFormat !== 'original') {
        $cmd .= '-f ' . $outputFormat . ' ';
    }
    
    $cmd .= escapeshellarg($destPath) . ' 2>&1';
    
    @exec($cmd, $output, $return_var);
    
    if ($return_var === 0 && file_exists($destPath)) {
        return ['success' => true];
    } else {
        return ['success' => false, 'message' => 'FFmpeg压缩失败'];
    }
}





$currentUrl = $options->adminUrl . 'extending.php?panel=MediaLibrary%2Fpanel.php';
?>

<link rel="stylesheet" href="<?php echo Helper::options()->pluginUrl; ?>/MediaLibrary/assets/css/panel.css">

<div class="main">
    <div class="body container">
        <div class="colgroup">
            <div class="col-mb-12">
                <div class="media-library-container">
                    <div class="typecho-page-title">
                        <h2>媒体库管理</h2>
                        <p>管理您的媒体文件 - 共 <?php echo $total; ?> 个文件</p>
                    </div>
                    
                    <div class="media-toolbar">
                        <div class="toolbar-row">
                            <button class="btn btn-primary" id="upload-btn">上传文件</button>
                            <button class="btn btn-danger" id="delete-selected" style="display:none;">删除选中</button>
                            
                            <select class="form-control" id="type-select">
                                <option value="all" <?php echo $type === 'all' ? 'selected' : ''; ?>>所有文件</option>
                                <option value="image" <?php echo $type === 'image' ? 'selected' : ''; ?>>图片</option>
                                <option value="video" <?php echo $type === 'video' ? 'selected' : ''; ?>>视频</option>
                                <option value="audio" <?php echo $type === 'audio' ? 'selected' : ''; ?>>音频</option>
                                <option value="document" <?php echo $type === 'document' ? 'selected' : ''; ?>>文档</option>
                            </select>
                            
                            <input type="text" class="form-control" id="keywords-input" placeholder="搜索文件名..." 
                                   value="<?php echo htmlspecialchars($keywords); ?>" style="width: 200px;">
                            <button class="btn" id="search-btn">搜索</button>
                            
                            <!-- 分开的压缩按钮 -->
                            <?php if (($enableGD && extension_loaded('gd')) || ($enableImageMagick && extension_loaded('imagick')) || $enableFFmpeg): ?>
                                <button class="btn" id="compress-images-btn" style="display:none;" disabled>压缩图片</button>
                            <?php endif; ?>
                            
                            <?php if ($enableVideoCompress && $enableFFmpeg): ?>
                                <button class="btn" id="compress-videos-btn" style="display:none;" disabled>压缩视频</button>
                            <?php endif; ?>
                            
                            <?php 
// 检查是否有可用的 EXIF 工具
$hasExifTool = isExifToolAvailable();
$hasPhpExif = extension_loaded('exif');
if ($enableExif && ($hasExifTool || $hasPhpExif)): 
?>
    <button class="btn" id="privacy-btn" style="display:none;" disabled>隐私检测</button>
<?php endif; ?>
                            
                            <div class="view-switch">
                                <a href="#" data-view="grid" class="<?php echo $view === 'grid' ? 'active' : ''; ?>">网格</a>
                                <a href="#" data-view="list" class="<?php echo $view === 'list' ? 'active' : ''; ?>">列表</a>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($view === 'grid'): ?>
                        <?php if (!empty($attachments)): ?>
                            <div class="media-grid">
                                <?php foreach ($attachments as $attachment): ?>
                                    <div class="media-item" data-cid="<?php echo $attachment['cid']; ?>" 
                                         data-url="<?php echo htmlspecialchars($attachment['url']); ?>" 
                                         data-type="<?php echo htmlspecialchars($attachment['mime']); ?>"
                                         data-title="<?php echo htmlspecialchars($attachment['title']); ?>"
                                         data-has-url="<?php echo $attachment['hasValidUrl'] ? '1' : '0'; ?>"
                                         data-is-image="<?php echo $attachment['isImage'] ? '1' : '0'; ?>"
                                         data-is-video="<?php echo $attachment['isVideo'] ? '1' : '0'; ?>">
                                        <div class="media-checkbox">
                                            <input type="checkbox" value="<?php echo $attachment['cid']; ?>">
                                        </div>
                                        
                                        <?php if ($attachment['isImage'] && $attachment['hasValidUrl']): ?>
                                            <div class="media-preview">
                                                <img src="<?php echo $attachment['url']; ?>" alt="<?php echo htmlspecialchars($attachment['title']); ?>">
                                            </div>
                                        <?php else: ?>
                                            <div class="media-preview">
                                                <div class="file-icon">
                                                    <?php
                                                    $mime = $attachment['mime'];
                                                    if (strpos($mime, 'video/') === 0) echo 'VIDEO';
                                                    elseif (strpos($mime, 'audio/') === 0) echo 'AUDIO';
                                                    elseif (strpos($mime, 'application/pdf') === 0) echo 'PDF';
                                                    elseif (strpos($mime, 'text/') === 0) echo 'TEXT';
                                                    elseif (strpos($mime, 'application/zip') === 0 || strpos($mime, 'application/x-rar') === 0) echo 'ZIP';
                                                    elseif (strpos($mime, 'application/msword') === 0 || strpos($mime, 'application/vnd.openxmlformats-officedocument.wordprocessingml') === 0) echo 'DOC';
                                                    elseif (strpos($mime, 'application/vnd.ms-excel') === 0 || strpos($mime, 'application/vnd.openxmlformats-officedocument.spreadsheetml') === 0) echo 'XLS';
                                                    elseif (strpos($mime, 'application/vnd.ms-powerpoint') === 0 || strpos($mime, 'application/vnd.openxmlformats-officedocument.presentationml') === 0) echo 'PPT';
                                                    else echo 'FILE';
                                                    ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="media-actions">
                                            <button class="btn btn-small media-info-btn" data-cid="<?php echo $attachment['cid']; ?>" title="详情">详情</button>
                                            <button class="btn btn-small btn-danger media-delete-btn" data-cid="<?php echo $attachment['cid']; ?>" title="删除">删除</button>
                                        </div>
                                        
                                        <div class="media-info">
                                            <div class="media-title" title="<?php echo htmlspecialchars($attachment['title']); ?>">
                                                <?php echo htmlspecialchars($attachment['title']); ?>
                                            </div>
                                            <div class="media-meta">
                                                <?php echo $attachment['size']; ?> • <?php echo isset($attachment['created']) ? date('m/d', $attachment['created']) : ''; ?>
                                                <?php if ($attachment['parent_post']['status'] === 'archived'): ?>
                                                    • 已归档
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <h3>没有找到文件</h3>
                                <p>尝试上传一些文件或调整搜索条件</p>
                                <button class="btn btn-primary" id="upload-btn-empty">上传文件</button>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <table class="media-list-table">
                            <thead>
                                <tr>
                                    <th width="40"><input type="checkbox" class="select-all"></th>
                                    <th width="80">预览</th>
                                    <th>文件名</th>
                                    <th width="100">大小</th>
                                    <th width="100">所属文章</th>
                                    <th width="150">上传时间</th>
                                    <th width="100">操作</th>
                                </tr>
                            </thead>
                            <tbody>
    <?php if (!empty($attachments)): ?>
        <?php foreach ($attachments as $attachment): ?>
            <tr data-cid="<?php echo $attachment['cid']; ?>" 
                data-url="<?php echo htmlspecialchars($attachment['url']); ?>" 
                data-type="<?php echo htmlspecialchars($attachment['mime']); ?>"
                data-title="<?php echo htmlspecialchars($attachment['title']); ?>"
                data-has-url="<?php echo $attachment['hasValidUrl'] ? '1' : '0'; ?>"
                data-is-image="<?php echo $attachment['isImage'] ? '1' : '0'; ?>"
                data-is-video="<?php echo $attachment['isVideo'] ? '1' : '0'; ?>">
                <!-- 添加 data-label 属性到每个 td -->
                <td data-label="选择"><input type="checkbox" value="<?php echo $attachment['cid']; ?>"></td>
                <td data-label="预览">
                    <?php if ($attachment['isImage'] && $attachment['hasValidUrl']): ?>
                        <img src="<?php echo $attachment['url']; ?>" class="media-thumb" alt="">
                    <?php else: ?>
                        <div style="font-size: 12px; color: #666;">FILE</div>
                    <?php endif; ?>
                </td>
                <td data-label="文件名"><?php echo htmlspecialchars($attachment['title']); ?></td>
                <td data-label="大小"><?php echo $attachment['size']; ?></td>
                <td data-label="所属文章">
                    <?php if ($attachment['parent_post']['status'] === 'archived'): ?>
                        <a href="<?php echo $options->adminUrl('write-' . (0 === strpos($attachment['parent_post']['post']['type'], 'post') ? 'post' : 'page') . '.php?cid=' . $attachment['parent_post']['post']['cid']); ?>" style="color: #0073aa;"><?php echo htmlspecialchars($attachment['parent_post']['post']['title']); ?></a>
                    <?php else: ?>
                        <span style="color: #999;">未归档</span>
                    <?php endif; ?>
                </td>
                <td data-label="上传时间"><?php echo isset($attachment['created']) ? date('Y-m-d H:i', $attachment['created']) : ''; ?></td>
                <td data-label="操作" class="media-list-actions">
                    <button class="btn btn-small media-info-btn" data-cid="<?php echo $attachment['cid']; ?>">详情</button>
                    <button class="btn btn-small btn-danger media-delete-btn" data-cid="<?php echo $attachment['cid']; ?>">删除</button>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="7" class="empty-state">
                <h3>没有找到文件</h3>
                <p>尝试上传一些文件或调整搜索条件</p>
            </td>
        </tr>
    <?php endif; ?>
</tbody>

                        </table>
                    <?php endif; ?>
                    
                    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="#" onclick="return goToPage(<?php echo $page - 1; ?>, event)">« 上一页</a>
        <?php endif; ?>
        
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <?php if ($i === $page): ?>
                <span class="current"><?php echo $i; ?></span>
            <?php else: ?>
                <a href="#" onclick="return goToPage(<?php echo $i; ?>, event)"><?php echo $i; ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($page < $totalPages): ?>
            <a href="#" onclick="return goToPage(<?php echo $page + 1; ?>, event)">下一页 »</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</div>

<!-- 上传模态框 -->
<div class="modal" id="upload-modal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3>上传文件</h3>
                <span class="modal-close">&times;</span>
            </div>
            <div class="modal-body">
                <div id="upload-area" class="upload-area">
                    <p>拖拽文件到此处或点击选择文件</p>
                    <a href="#" id="upload-file-btn" class="btn btn-primary">选择文件</a>
                </div>
                <ul id="file-list" style="margin-top: 20px;"></ul>
            </div>
        </div>
    </div>
</div>

<!-- 文件详情模态框 -->
<div class="modal" id="info-modal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3>文件详情</h3>
                <span class="modal-close">&times;</span>
            </div>
            <div class="modal-body" id="file-info-content">
                <!-- 动态加载内容 -->
            </div>
        </div>
    </div>
</div>

<!-- 文件预览模态框 -->
<div class="modal preview-modal" id="preview-modal">
    <div class="modal-dialog" id="preview-modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="preview-modal-title">文件预览</h3>
                <span class="modal-close">&times;</span>
            </div>
            <div class="modal-body" id="preview-content">
                <!-- 动态加载内容 -->
            </div>
        </div>
    </div>
</div>


<!-- 修改图片压缩模态框 -->
<div class="modal" id="image-compress-modal">
    <div class="modal-dialog" style="max-width: 700px;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>批量压缩图片</h3>
                <span class="modal-close">&times;</span>
            </div>
            <div class="modal-body">
                <!-- 智能建议区域 -->
                <div id="smart-suggestion-area" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px; display: none;">
                    <h4 style="margin: 0 0 10px 0; color: #495057;">🤖 智能压缩建议</h4>
                    <div id="suggestion-content"></div>
                    <div style="margin-top: 10px;">
                        <button class="btn btn-success btn-small" id="apply-smart-suggestion">应用建议设置</button>
                        <button class="btn btn-secondary btn-small" id="get-smart-suggestion">获取建议</button>
                    </div>
                </div>
                
                <div class="compress-settings">
                    <div style="margin-bottom: 15px;">
                        <label>压缩方法:</label>
                        <select id="image-compress-method" style="width: 100%; margin-top: 5px;">
                            <?php if ($enableGD && extension_loaded('gd')): ?>
                                <option value="gd">GD 库</option>
                            <?php endif; ?>
                            <?php if ($enableImageMagick && extension_loaded('imagick')): ?>
                                <option value="imagick">ImageMagick</option>
                            <?php endif; ?>
                            <?php if ($enableFFmpeg): ?>
                                <option value="ffmpeg">FFmpeg</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label>输出格式:</label>
                        <select id="image-output-format" style="width: 100%; margin-top: 5px;">
                            <option value="original">保持原格式</option>
                            <option value="jpeg">JPEG</option>
                            <option value="png">PNG</option>
                            <option value="webp">WebP</option>
                            <option value="avif">AVIF</option>
                        </select>
                        <small style="color: #666;">注意：格式转换时，替换原文件会保持相同链接</small>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label>压缩质量: <span id="image-quality-value"><?php echo $gdQuality; ?>%</span></label>
                        <input type="range" id="image-quality-slider" min="10" max="100" value="<?php echo $gdQuality; ?>" style="width: 100%; margin-top: 5px;">
                        <div style="font-size: 12px; color: #666; margin-top: 5px;">
                            <span style="float: left;">高压缩</span>
                            <span style="float: right;">高质量</span>
                            <div style="clear: both;"></div>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label>
                            <input type="radio" name="image-replace-mode" value="replace" checked> 
                            替换原文件（保持链接不变）
                        </label>
                        <br>
                        <label>
                            <input type="radio" name="image-replace-mode" value="keep"> 
                            保留原文件（创建新文件）
                        </label>
                        <div id="image-custom-name-group" style="margin-top: 10px; display: none;">
                            <input type="text" id="image-custom-name" placeholder="自定义文件名前缀（可选）" style="width: 100%;">
                            <small style="color: #666;">留空则使用默认命名规则</small>
                        </div>
                    </div>
                </div>
                
                <div class="compress-actions" style="margin-top: 20px;">
                    <button class="btn btn-primary" id="start-image-compress">开始压缩</button>
                    <button class="btn" id="cancel-image-compress">取消</button>
                </div>
                
                <div id="image-compress-result" style="display: none; margin-top: 20px; max-height: 300px; overflow-y: auto;"></div>
            </div>
        </div>
    </div>
</div>


<!-- 视频压缩模态框 -->
<div class="modal" id="video-compress-modal">
    <div class="modal-dialog" style="max-width: 600px;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>批量压缩视频</h3>
                <span class="modal-close">&times;</span>
            </div>
            <div class="modal-body">
                <div class="compress-settings">
                    <div style="margin-bottom: 15px;">
                        <label>视频编码器:</label>
                        <select id="video-codec" style="width: 100%; margin-top: 5px;">
                            <option value="libx264" <?php echo $videoCodec === 'libx264' ? 'selected' : ''; ?>>H.264 (兼容性好)</option>
                            <option value="libx265" <?php echo $videoCodec === 'libx265' ? 'selected' : ''; ?>>H.265 (压缩率高)</option>
                            <option value="libvpx-vp9" <?php echo $videoCodec === 'libvpx-vp9' ? 'selected' : ''; ?>>VP9 (开源)</option>
                            <option value="libaom-av1" <?php echo $videoCodec === 'libaom-av1' ? 'selected' : ''; ?>>AV1 (最新标准)</option>
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label>压缩质量: <span id="video-quality-value"><?php echo $videoQuality; ?></span></label>
                        <input type="range" id="video-quality-slider" min="18" max="35" value="<?php echo $videoQuality; ?>" style="width: 100%; margin-top: 5px;">
                        <small style="color: #666;">数值越小质量越高，推荐18-28</small>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label>
                            <input type="radio" name="video-replace-mode" value="replace" checked> 
                            替换原文件
                        </label>
                        <br>
                        <label>
                            <input type="radio" name="video-replace-mode" value="keep"> 
                            保留原文件
                        </label>
                        <div id="video-custom-name-group" style="margin-top: 10px; display: none;">
                            <input type="text" id="video-custom-name" placeholder="自定义文件名前缀（可选）" style="width: 100%;">
                            <small style="color: #666;">留空则使用默认命名规则</small>
                        </div>
                    </div>
                </div>
                
                <div class="compress-actions" style="margin-top: 20px;">
                    <button class="btn btn-primary" id="start-video-compress">开始压缩</button>
                    <button class="btn" id="cancel-video-compress">取消</button>
                </div>
                
                <div id="video-compress-result" style="display: none; margin-top: 20px; max-height: 300px; overflow-y: auto;"></div>
            </div>
        </div>
    </div>
</div>

<!-- 隐私检测模态框 -->
<div class="modal" id="privacy-modal">
    <div class="modal-dialog" style="max-width: 800px;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>批量隐私检测</h3>
                <span class="modal-close">&times;</span>
            </div>
            <div class="modal-body" id="privacy-content">
                <!-- 动态加载内容 -->
            </div>
        </div>
    </div>
</div>

<!-- GPS地图模态框 -->
<div class="modal" id="gps-map-modal" style="z-index: 1002;">
    <div class="modal-dialog" style="max-width: 90vw; max-height: 90vh;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>GPS位置地图</h3>
                <span class="modal-close">&times;</span>
            </div>
            <div class="modal-body" style="padding: 0;">
                <div id="gps-map-container" style="width: 100%; height: 70vh; min-height: 500px;"></div>
            </div>
        </div>
    </div>
</div>

<!-- 引入 plupload -->
<script src="<?php $options->adminStaticUrl('js', 'moxie.js'); ?>"></script>
<script src="<?php $options->adminStaticUrl('js', 'plupload.js'); ?>"></script>

<!-- 引入ECharts -->
<script src="<?php echo Helper::options()->pluginUrl; ?>/MediaLibrary/assets/js/echarts.min.js"></script>

<script>
window.mediaLibraryCurrentUrl = '<?php echo $currentUrl; ?>';
window.mediaLibraryKeywords = '<?php echo addslashes($keywords); ?>';
window.mediaLibraryType = '<?php echo $type; ?>';
window.mediaLibraryView = '<?php echo $view; ?>';
window.mediaLibraryConfig = {
    enableGetID3: <?php echo $enableGetID3 ? 'true' : 'false'; ?>,
    enableExif: <?php echo $enableExif ? 'true' : 'false'; ?>,
    enableGD: <?php echo $enableGD ? 'true' : 'false'; ?>,
    enableImageMagick: <?php echo $enableImageMagick ? 'true' : 'false'; ?>,
    enableFFmpeg: <?php echo $enableFFmpeg ? 'true' : 'false'; ?>,
    enableVideoCompress: <?php echo $enableVideoCompress ? 'true' : 'false'; ?>,
    gdQuality: <?php echo $gdQuality; ?>,
    videoQuality: <?php echo $videoQuality; ?>,
    videoCodec: '<?php echo $videoCodec; ?>',
    phpMaxFilesize: '<?php echo $phpMaxFilesize; ?>',
    allowedTypes: 'jpg,jpeg,png,gif,bmp,webp,svg,mp4,avi,mov,wmv,flv,mp3,wav,ogg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,avif',
    adminStaticUrl: '<?php echo $options->adminStaticUrl; ?>',
    pluginUrl: '<?php echo Helper::options()->pluginUrl; ?>/MediaLibrary',
    hasExifTool: <?php echo isExifToolAvailable() ? 'true' : 'false'; ?>,
    hasPhpExif: <?php echo extension_loaded('exif') ? 'true' : 'false'; ?>
};
</script>

<script src="<?php echo Helper::options()->pluginUrl; ?>/MediaLibrary/assets/js/panel.js"></script>


<?php
include 'copyright.php';
include 'common-js.php';
include 'table-js.php';
include 'footer.php';
?>