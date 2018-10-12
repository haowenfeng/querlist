<?php

require './vendor/autoload.php';
require 'UserAgentTrait.php';
require 'mysqlcon.php';
use QL\QueryList;
use QL\Ext\PhantomJs;
error_reporting(0);
/**
* 
*/
class Comment 
{
    use UserAgentTrait;
    public $ql = null;

    public function __construct()
    {
        $this->ql = QueryList::getInstance();
    }

    public function getContent($isbn)
    {
        // 安装时需要设置PhantomJS二进制文件路径
        //or Custom function name
        $this->ql->use(PhantomJs::class,'/usr/local/bin/phantomjs','browser');

        //$html = $ql->browser('https://book.douban.com/subject_search?search_text=%E6%B4%BB%E7%9D%80&cat=1001')->getHtml();
        // $html = $this->ql->browser('https://book.douban.com/subject_search?search_text=9787508686882+&cat=1001')->getHtml();
        $html = $this->ql->browser('https://book.douban.com/subject_search?search_text='.$isbn.'+&cat=1001')->getHtml();
        $this->ql->destruct();
        return $html;
    }

    public function getBookUrl($html)
    {
        //采集规则
        $rules = [
            //采集a标签的href属性
                // 'link_text' => ['.item-root>a','href']
            //采集a标签的text文本
            'link_text' => ['.item-root>a','href','', function($content){
                $content .= 'reviews?sort=time';
                return $content;
            }]
            //采集span标签的text文本
           // 'txt' => ['span','text']
        ];

        // 过程:设置HTML=>设置采集规则=>执行采集=>获取采集结果数据
        $data = QueryList::html($html)->rules($rules)->query()->getData();
        //打印结果
        $bookUrl = $data->all();
        return $bookUrl;
    }
    /**
     * [getPageNum 获取有多少页]
     * @return [type] [description]
     */
    public static function getPageNum($bookUrl)
    {
        //采集规则
        $rules = [
            //采集span标签的text文本
           'count' => ['#content>h1','text', '' , function($content){
                $pattern = '/\d+/is';
                preg_match_all($pattern, $content, $matches);
                return $matches[0][0];
           }]
        ];
        $html = self::curl_get_https($bookUrl);
        // 过程:设置HTML=>设置采集规则=>执行采集=>获取采集结果数据
        $data = QueryList::html($html)->rules($rules)->query()->getData();
        //打印结果
        $bookUrl = $data->all();
        return $bookUrl[0]['count'];
    }

    /**
     * [getCommentId 获取评论id]
     * @param  [type] $bookUrl [description]
     * @return [type]          [description]
     */
    public function getCommentId($bookUrl,$isbn)
    { 
        $commentRules = [
            'comment_id' => ['.review-short', 'data-rid'],
            'date_time' => ['.main-meta', 'text'],
            'title' => ['.main-bd>h2>a', 'text'],
            'author' => ['.main-hd>.name', 'text'],
            'author_img_url' => ['.main-hd>a>img', 'src'],
            'author_detail_url' => ['.main-hd>.avator', 'href'],
            'comment_detail_url' => ['.main-bd>h2>a', 'href']
        ]; 
        $commentIds = array();
        foreach ($bookUrl as $key => $value) {
            // $bookInfo = curl_get_https($value['link_text']);
            $bookInfo = self::curl_get_https($value);
            $data = QueryList::html($bookInfo)->rules($commentRules)->query()->getData();
            $comments = $data->all();

            $commentIds = array_merge($commentIds, $comments);
            usleep(rand(3,5));
        }
        preg_match_all('/\d+/is', $bookUrl[0], $matches);
        $productid = $matches[0][0];

        $mysqlcon->InsertProduct($productid, $isbn);
        foreach ($commentIds as $key => $value) {
            $commentIds[$key]['productid'] = $productid;
        }
        return $commentIds;
    }

    public function getCommentInfo($commentIds)
    {
        $commentInfos = array();
        $imgRules = [
            'imgUrl' => ['img','src']
        ]; 

        foreach ($commentIds as $key => $value) {
            $commentInfo = self::curl_get_https('https://book.douban.com/j/review/'.$value['comment_id'].'/full');
            $callbackInfo = json_decode($commentInfo, true);
            $imgData = QueryList::html($callbackInfo['html'])->rules($imgRules)->query()->getData();
            $imgUrls = $imgData->all();

            if ($imgUrls) {
                $localUrl = $this->uploadImg(array_unique(array_column($imgUrls,'imgUrl')));
                $callbackInfo['html'] = str_replace(array_keys($localUrl), array_values($localUrl), $callbackInfo['html']);
            }
            $commentInfos[$key]['product_id'] = $value['productid'];
            $commentInfos[$key]['comment_id'] = $value['comment_id'];
            $commentInfos[$key]['title'] = $value['title'];
            $commentInfos[$key]['author'] = $value['author'];
            // $commentInfos[$key]['score'] = 5;
            $commentInfos[$key]['content'] = $callbackInfo['html'];
            $commentInfos[$key]['comment_date'] = $value['date_time'];
            $commentInfos[$key]['comment_detail_url'] = $value['comment_detail_url'];
            $commentInfos[$key]['author_detail_url'] = $value['author_detail_url'];
            $commentInfos[$key]['author_img_url'] = $value['author_img_url'];
            $commentInfos[$key]['status'] = 0;
            $commentInfos[$key]['info_source'] = 17;
            $commentInfos[$key]['creation_date'] = date('Y-m-d H:i:s');
            $commentInfos[$key]['last_modified_date'] = date('Y-m-d H:i:s');
            $commentInfos[$key]['is_sync'] = 0;
            usleep(100);
        }
        return $commentInfos;
    }

    public function formatBookUrl($bookUrl)
    {
        $newBookUrl = array_column($bookUrl, 'link_text');
        $commentCount = self::getPageNum($newBookUrl[0]); //书评总条数

        // var_dump($newBookUrl);exit;
        if ($commentCount>20) {
            $num = round($commentCount/20);

            for ($i=1; $i < $num; $i++) { 
                $newBookUrl[$i] = $newBookUrl[0].'&start='.$i*20;
            }
            return $newBookUrl;
        }

        return $newBookUrl;

    }
    public function uploadImg($imgUrls)
    {
        // $imgUrls = array('https://img3.doubanio.com/view/subject/l/public/s29762735.jpg');
        $request_url = 'http://dimg.dangdang.com:8001/uploadimg/community/uploadCommunityImg';
        $timeout = 20;
        $callback = array();
        foreach ($imgUrls as $key => $value) {
            $data = file_get_contents($value);
            file_put_contents('tmp/'.$key.'.jpg', $data);
            $cfile = new \CURLFile(realpath('tmp/'.$key.'.jpg'));
            //评论图片在图片系统中的所有者编号
            $image_id = time();
            $owner_type = 71;
            $image_sign = 'reviewimg';
            $parameters = array(
                'imgFile' => $cfile,                //待上传文件格式，jpg,gif,png
                'productKind' => $owner_type,       //图片存储类型，与旧的api中的owner_type对应，7，71，72，73，74
                'owner_id' => intval($image_id),    //图片id
                //'num' => intval($sort),              //图片序号
                'num' => 1,                         //图片序号 目前写死1
                'image_sign' => $image_sign,        //自定义文件名的一部分，可为空
                'appSystem' => 'comment',              //来源系统
            );

            $ch = curl_init();
            curl_setopt($ch,CURLOPT_URL, $request_url);
            curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
            curl_setopt($ch,CURLOPT_POST,true);
            curl_setopt($ch,CURLOPT_POSTFIELDS,$parameters);
            $result = curl_exec($ch);
            if (!$result) {
                $log = 'Request url:' . $request_url . ' Curl error:' .  curl_error($ch);
                log_message('Work_image_audit', $log);
                exit;
            } 
            curl_close($ch);
            list($status, $imgUrl) = explode('|',$result);
            
            if ($status == true) {
                $callback[$value] = $imgUrl;
            }
        }
        return $callback;
    }
}




$comment = new Comment();
$mysqlcon = new mysqlcon;
//$isbn = $mysqlcon->getIsbns();
$mysqlcon->updateStatus(9787560023298);
var_dump($isbn);
exit;
// $isbns = array('9787508686882','9787532125944');
$isbns = array('9787508686882');

foreach ($isbns as $k => $v) {
    
    $html = $comment->getContent($v);//算法之美
    // $html = $comment->getContent('9787532125944');//活着
    $bookUrl = $comment->getBookUrl($html);

    $bookUrls = $comment->formatBookUrl($bookUrl);

    $commentIds = $comment->getCommentId($bookUrls,$v);

    $commentInfos = $comment->getCommentInfo($commentIds); //获取到数据

    foreach ($commentInfos as $key => $value) {
        $res = $mysqlcon->InsertData($value);
        echo $res;
        echo '<-->';
    }
}
