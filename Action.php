<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class MediaLibrary_Action extends Typecho_Widget implements Widget_Interface_Do
{
    private $db;
    private $options;
    private $user;

    public function __construct($request, $response, $params = NULL)
    {
        parent::__construct($request, $response, $params);
        $this->db = Typecho_Db::get();
        $this->options = Typecho_Widget::widget('Widget_Options');
        $this->user = Typecho_Widget::widget('Widget_User');
    }

    public function ajax()
    {
        $this->user->pass('contributor');
        
        $action = $this->request->get('action');
        
        switch ($action) {
            case 'delete':
                $this->deleteFiles();
                break;
            case 'get_info':
                $this->getFileInfo();
                break;
            default:
                $this->response->throwJson(['success' => false, 'message' => '未知操作']);
        }
    }
    
    private function deleteFiles()
    {
        $cids = $this->request->getArray('cids');
        if (empty($cids)) {
            $this->response->throwJson(['success' => false, 'message' => '请选择要删除的文件']);
        }
        
        $deleteCount = 0;
        foreach ($cids as $cid) {
            $attachment = $this->db->fetchRow($this->db->select()->from('table.contents')
                ->where('cid = ? AND type = ?', $cid, 'attachment'));
                
            if ($attachment) {
                $attachmentData = unserialize($attachment['text']);
                $filePath = __TYPECHO_ROOT_DIR__ . $attachmentData['path'];
                
                // 删除文件
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
                
                // 删除数据库记录
                $this->db->query($this->db->delete('table.contents')->where('cid = ?', $cid));
                $deleteCount++;
            }
        }
        
        $this->response->throwJson(['success' => true, 'message' => "成功删除 {$deleteCount} 个文件"]);
    }
    
    private function getFileInfo()
    {
        $cid = $this->request->get('cid');
        $attachment = $this->db->fetchRow($this->db->select()->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment'));
            
        if (!$attachment) {
            $this->response->throwJson(['success' => false, 'message' => '文件不存在']);
        }
        
        $attachmentData = unserialize($attachment['text']);
        $info = [
            'title' => $attachment['title'],
            'mime' => $attachmentData['mime'],
            'size' => $this->formatFileSize($attachmentData['size']),
            'url' => Typecho_Common::url($attachmentData['path'], $this->options->siteUrl),
            'created' => date('Y-m-d H:i:s', $attachment['created']),
            'path' => $attachmentData['path']
        ];
        
        $this->response->throwJson(['success' => true, 'data' => $info]);
    }
    
    private function formatFileSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    public function action()
    {
        $this->ajax();
    }
}
