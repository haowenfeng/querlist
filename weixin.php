<?php 
require './vendor/autoload.php';

use QL\QueryList;
use QL\Ext\PhantomJs;

//$weixinNum = array('女神读书');
// $listUrls = curl_getUrl($weixinNum);
// $html = curl_getHtml($listUrl);

class getWXArticle
{
    const GETLISTURL = ''; //获取
    //const COMMENTAPI = 'http://commentapi.dangdang.com'; //commentapi数据提交地址
    const COMMENTAPI = 'http://comment.api'; //commentapi数据提交地址
    const TIMEOUT = 5; //请求超时时间
    public $ql;

    public function __construct()
    {
        $this->ql = QueryList::getInstance();
    }
    /**
     * [getListUrl 获取文章详情列表地址]
     * @param  [type] $WxName [description]
     * @return [type]         [description]
     */
    public function getListUrl($WxName)
    {
        $post_data = array(
            'c' => 'wxarticle',
            'm' => 'geturl',
            'params' => array(
                'name' => $WxName
                )
        );
        $listUrl = $this->curl_post($request_url, self::TIMEOUT, $post_data);
        return $listUrl;
    }
    public function getHtml($url =null)
    {
        if (empty($url)) {
            exit('invalid url');
        }
        $this->ql->use(PhantomJs::class,'/usr/local/bin/phantomjs','browser');
        $html = $this->ql->browser($url)->getHtml();
        $this->ql->destruct();   
        return $html;
    } 
    /**
     * 文章匹配采集规则
     */
    public function ContentRules()
    {
        $rules = [
            'title' => ['.rich_media_title','text'],
            //'date' => ['#post-date','text'],
            //'author' => ['#meta_content>.rich_media_meta:eq(2)','text'],
            'content' => ['.rich_media_content','html'],
            //'content' => ['#img-content','html']
            //'images' => ['img','data-src']
        ];
        return $rules;
    }
    /**
     * [imgRules 获取图片地址规则]
     * @return [type] [description]
     */
    public function imgRules()
    {
        $imgRules = [
            'images' => ['img','data-src']
        ];
        return $imgRules;
    }
    /**
     * [getArticleInfo 根据规则获取相应内容]
     * @param  [type] $html [description]
     * @return [type]       [description]
     */
    public function getArticleInfo($html)
    {   
        if (empty($html)) {
            exit('invalid content');
        }
        $rules = $this->ContentRules();
        // 直接匹配出body中的内容
        preg_match('/<body[^>]+>(.+)\s+<\/body>/s',$html,$arr);
        $html = $arr[0];
        $data = QueryList::html($html)->rules($rules)->query()->getData();

        $res = $data->all();
        if (empty($res[0]['content'])){
            exit('nothing to do');
        }
        $remoteImgUrls = $this->getRemoteImgUrl($res[0]['content']);
        $list_img = '';
        if ($remoteImgUrls) {
            $localUrl = $this->uploadImg($remoteImgUrls);
            $res[0]['content'] = str_replace(array_keys($localUrl), array_values($localUrl), $res[0]['content']);
            $list_imgs = array_values($localUrl);
            $list_img = count($list_imgs)>1 ? $list_imgs[1] : $list_imgs[0];
        }
        $post_data =array(
            'c' => 'Articlesstorage',
            'm' => 'getData', 
            'params' =>  array(
                'title' => $res[0]['title'],
                'content' => urlencode($res[0]['content']),
                'list_img'=> str_replace(array('src="','"'),'',$list_img)
                )
        );
        $result = $this->curl_post(self::COMMENTAPI, self::TIMEOUT, json_encode($post_data));
        return $result;
    }

    /**
     * [getRemoteImgUrl 获取远程图片组]
     * @param  [type] $content [description]
     * @return [type]          [description]
     */
    public function getRemoteImgUrl($content)
    {
        $imgRules =$this->imgRules();
        $imageUrls = QueryList::html($content)->rules($imgRules)->query()->getData()->all();
        $imageUrls = array_unique(array_column($imageUrls, 'images'));
        return $imageUrls;
    }
    /**
     * [uploadImg 远程地址上传cnd服务器]
     * @param  [type] $imgUrls [description]
     * @return [type]          [description]
     */
    public function uploadImg($imgUrls)
    {
        // $imgUrls = array('https://img3.doubanio.com/view/subject/l/public/s29762735.jpg');
        $request_url = 'http://dimg.dangdang.com:8001/uploadimg/community/uploadCommunityImg';
        $timeout = 20;
        $callback = array();
        foreach ($imgUrls as $key => $value) {
        if (empty($value)) {
            continue;
        }   
            $data = file_get_contents($value);
            file_put_contents('temp/'.$key.'.jpg', $data);
            $cfile = new \CURLFile(realpath('temp/'.$key.'.jpg'));
            //评论图片在图片系统中的所有者编号
            $image_id = time();
            $owner_type = 71;
            $image_sign = 'reviewimg';
            $parameters = array(
                'imgFile' => $cfile,                //待上传文件格式，jpg,gif,png
                'productKind' => $owner_type,       //图片存储类型，与旧的api中的owner_type对应，7，71，72，73，74
                'owner_id' => intval($image_id),    //图片id
                //'num' => intval($sort),              //图片序号
                'num' => $key+1,                         //图片序号 目前写死1
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
                //$log = 'Request url:' . $request_url . ' Curl error:' .  curl_error($ch);
                //log_message('Work_image_audit', $log);
                exit;
            } 
            curl_close($ch);
            list($status, $imgUrl) = explode('|',$result);
            
            if ($status == true) {
                $callback['data-src="'.$value.'"'] = 'src="'.$imgUrl.'"';
            }
        }
        return $callback;
    }
    /**
     * [curl_post 请求接口方法]
     * @param  [type]  $request_url [description]
     * @param  integer $timeout     [description]
     * @param  array   $post_params [description]
     * @return [type]               [description]
     */
    public function curl_post($request_url, $timeout=5, $post_params=array()) 
    {
        if (empty($request_url) || empty($post_params)) {
            return false;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //Set curl to return the data instead of printing it to the browser.
        curl_setopt($ch, CURLOPT_URL, $request_url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        if (!empty($post_params)) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_params);
        }
        //if result is not ok , retry one more time
        $result = curl_exec($ch);
        $info = curl_getinfo($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return $result;
    }

}

$getWXArticle = new getWXArticle();
$filename = 'http://10.7.8.124:9988/feeddata/wxarticle/wx_urllist'.date('Ymd').'.txt';

$f= fopen($filename,"r");

while (!feof($f))
{
  $ListInfo[] = fgets($f);
}
fclose($f);
// $ListInfo = $getWXArticle->getListUrl($value);
// $UrlInfo = json_decode($ListInfo, true);
// if ($ListInfo['status'] ==0 && !empty($ListInfo['data'])) {
    foreach ($ListInfo as $k => $v) {
        //$url = 'https://mp.weixin.qq.com/s?timestamp=1538104500&src=3&ver=1&signature=Irpth9BgC7l2qtsy3w9jhkWrh3DLAR-xjFGTxTW3PBFqoF0Ca5VsTUzoLCR**qO--TXjw62BC49KErzlGSguKcdu2OSMWReoorV-TzYHPKpl9wb1hwhXIn6jgcLrPJLHHSf55COYExqt9oJL5o6vOyiFr2OIhrTIwwh*zK09nSY=';
	if ($v) {
       	    $html = $getWXArticle->getHtml(str_replace("\r\n", '', $v));
            if (empty($html)) {
                continue;
            }
            $res = $getWXArticle->getArticleInfo($html);
            var_dump($res);
	}
    }
// }






