<?php

require './vendor/autoload.php';
use QL\QueryList;
use QL\Ext\PhantomJs;
$ql = QueryList::getInstance();
error_reporting(0);
function curl_get_https($url){
    $curl = curl_init(); // 启动一个CURL会话
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // 跳过证书检查
    // curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, true);  // 从证书中检查SSL加密算法是否存在（false）
    $tmpInfo = curl_exec($curl);     //返回api的json对象
    //关闭URL请求
    curl_close($curl);
    return $tmpInfo;    //返回json对象
}
// 安装时需要设置PhantomJS二进制文件路径
//or Custom function name
$ql->use(PhantomJs::class,'/usr/local/bin/phantomjs','browser');

//$html = $ql->browser('https://book.douban.com/subject_search?search_text=%E6%B4%BB%E7%9D%80&cat=1001')->getHtml();
$html = $ql->browser('https://book.douban.com/subject_search?search_text=9787508686882+&cat=1001')->getHtml();

echo $html;exit;
//采集规则
$rules = [
    //采集a标签的href属性
   // 'html' => ['#root','html']
    //采集a标签的text文本
    'link_text' => ['.item-root>a','href'],
    //采集span标签的text文本
   // 'txt' => ['span','text']
];

// 过程:设置HTML=>设置采集规则=>执行采集=>获取采集结果数据
$data = QueryList::html($html)->rules($rules)->query()->getData();
//打印结果
$bookInfoUrl = $data->all();
print_r($bookInfoUrl);exit;
$commentRules = [
	'comment_id' => ['.review-short', 'data-rid']

]; 

$url = array();
foreach ($bookInfoUrl as $key => $value) {
	$bookInfo = curl_get_https($value['link_text']);
	$data = QueryList::html($bookInfo)->rules($commentRules)->query()->getData();
	$comments = $data->all();
	$url = array_merge($url, $comments);
}

$commentInfos = array();
foreach ($url as $key => $value) {
	$commentInfo = curl_get_https('https://book.douban.com/j/review/'.$value['comment_id'].'/full');
	$callbackInfo = json_decode($commentInfo, true);
	$commentInfos[] = $callbackInfo['html'];
}

print_r($commentInfos);
// $bookInfo = curl_get_https($bookInfoUrl[0]['link_text']);

// $data = QueryList::html($bookInfo)->rules($commentRules)->query()->getData();
// print_r($data->all());