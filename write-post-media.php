<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

// 获取搜索参数
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// 获取媒体文件
$db = Typecho_Db::get();

// 构建查询 - 移除分页，只显示前50个文件
$select = $db->select()->from('table.contents')
    ->where('type = ?', 'attachment')
    ->where('status = ?', 'publish')
    ->order('created', Typecho_Db::SORT_DESC)
    ->limit(50); // 固定显示50个文件

// 如果有搜索条件，添加搜索
if (!empty($search)) {
    $select->where('title LIKE ? OR text LIKE ?', '%' . $search . '%', '%' . $search . '%');
}

$allAttachments = $db->fetchAll($select);

// 处理附件数据
foreach ($allAttachments as &$attachment) {
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
    
    // 添加文件大小格式化
    $attachment['size'] = formatFileSize(isset($attachmentData['size']) ? intval($attachmentData['size']) : 0);
}

$currentUrl = $options->adminUrl . 'extending.php?panel=MediaLibrary%2Fpanel.php';

// 格式化文件大小的函数
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
?>

<link rel="stylesheet" href="<?php echo Helper::options()->pluginUrl; ?>/MediaLibrary/assets/css/media-library.css">

<div class="media-library-widget">
    <div class="media-library-header">
        <div class="media-library-title">
            <span>媒体库 (<?php echo count($allAttachments); ?>)</span>
        </div>
        <div class="media-library-controls">
            <input type="text" class="media-library-search" placeholder="搜索文件名..." 
                   id="media-search-input" value="<?php echo htmlspecialchars($search); ?>">
            <button type="button" onclick="searchMedia()" class="media-search-btn">搜索</button>
            <?php if (!empty($search)): ?>
                <button type="button" onclick="clearSearch()" class="media-clear-btn">清除</button>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="media-library-content">
        <div class="media-library-grid" id="media-library-grid">
            <?php if (!empty($allAttachments)): ?>
                <?php foreach ($allAttachments as $attachment): ?>
                    <div class="media-item" data-cid="<?php echo $attachment['cid']; ?>" 
                         data-url="<?php echo htmlspecialchars($attachment['url']); ?>" 
                         data-title="<?php echo htmlspecialchars($attachment['title']); ?>"
                         data-is-image="<?php echo $attachment['isImage'] ? '1' : '0'; ?>"
                         onclick="selectMedia(this)">
                        
                        <?php if ($attachment['isImage'] && $attachment['hasValidUrl']): ?>
                            <div class="media-preview">
                                <img src="<?php echo $attachment['url']; ?>" alt="<?php echo htmlspecialchars($attachment['title']); ?>">
                            </div>
                        <?php else: ?>
                            <div class="media-preview file-preview">
                                <div class="file-icon">
                                    <?php
                                    $mime = $attachment['mime'];
                                    if (strpos($mime, 'video/') === 0) echo 'VIDEO';
                                    elseif (strpos($mime, 'audio/') === 0) echo 'AUDIO';
                                    elseif (strpos($mime, 'application/pdf') === 0) echo 'PDF';
                                    elseif (strpos($mime, 'text/') === 0) echo 'TEXT';
                                    elseif (strpos($mime, 'application/zip') === 0 || strpos($mime, 'application/x-rar') === 0) echo 'ZIP';
                                    else echo 'FILE';
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="media-info">
                            <div class="media-title" title="<?php echo htmlspecialchars($attachment['title']); ?>">
                                <?php echo htmlspecialchars($attachment['title']); ?>
                            </div>
                            <div class="media-meta">
                                <?php echo $attachment['size']; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="media-empty">
                    <?php if (!empty($search)): ?>
                        <p>没有找到匹配 "<?php echo htmlspecialchars($search); ?>" 的文件</p>
                        <button type="button" onclick="clearSearch()">显示所有文件</button>
                    <?php else: ?>
                        <p>暂无媒体文件</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="media-library-actions">
            <a href="javascript:void(0)" onclick="openMediaManageWindow()">管理媒体库</a>
        </div>
    </div>
</div>

<!-- 复制媒体模态框 -->
<div class="media-copy-modal" id="media-copy-modal">
    <div class="media-copy-dialog">
        <div class="media-copy-header">
            <h3>复制媒体代码</h3>
            <span class="media-copy-close">&times;</span>
        </div>
        <div class="media-copy-body">
            <div class="media-copy-preview" id="media-copy-preview">
                <!-- 动态内容 -->
            </div>
            
            <div class="media-copy-form">
                <label for="media-alt">替代文本 (Alt):</label>
                <input type="text" id="media-alt" placeholder="描述图片内容">
                
                <label for="media-size" style="margin-top: 10px;">代码类型:</label>
                <select id="media-size" onchange="updateCopyCode()">
                    <option value="link">链接</option>
                    <option value="image" selected>图片</option>
                    <option value="markdown">Markdown</option>
                </select>
                
                <label for="media-code" style="margin-top: 10px;">生成的代码:</label>
                <textarea id="media-code" readonly onclick="this.select()"></textarea>
            </div>
            
            <div class="media-copy-actions">
                <div class="copy-status" id="copy-status">复制成功！</div>
                <button type="button" onclick="closeMediaCopyModal()">取消</button>
                <button type="button" class="primary" onclick="copyMediaCode()">复制代码</button>
            </div>
        </div>
    </div>
</div>

<script>
window.mediaAttachments = <?php echo json_encode($allAttachments); ?>;
window.mediaLibraryUrl = '<?php echo $currentUrl; ?>';
window.currentSearch = '<?php echo addslashes($search); ?>';

// 搜索功能
function searchMedia() {
    var searchValue = document.getElementById('media-search-input').value.trim();
    var currentUrl = window.location.href.split('?')[0];
    var params = [];
    
    if (searchValue) {
        params.push('search=' + encodeURIComponent(searchValue));
    }
    
    var newUrl = currentUrl + (params.length ? '?' + params.join('&') : '');
    window.location.href = newUrl;
}

// 清除搜索
function clearSearch() {
    var currentUrl = window.location.href.split('?')[0];
    window.location.href = currentUrl;
}

// 回车搜索
document.getElementById('media-search-input').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        searchMedia();
    }
});
</script>
<script src="<?php echo Helper::options()->pluginUrl; ?>/MediaLibrary/assets/js/write-post-media.js"></script>
