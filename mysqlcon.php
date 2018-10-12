<?php
class mysqlcon{

    public $mysqli = null;
    public function __construct()
    {
        //创建连接：参数1：主机地址   2:数据库用户名  3:用户密码  4：数据库
        $this->mysqli = new mysqli("*******","root","","*********");
        //判断连接是否成功
        if($this->mysqli->connect_errno){
            die($this->mysqli->connect_error);
        }
        //设置编码
        $this->mysqli->set_charset("utf8");
    }
   
    public function InsertData($data)
    {   
    }
    public function InsertProduct($productid, $isbn)
    {
    }
    public function getIsbns()
    {
    }
    public function updateStatus($isbn)
    {
    }
	
}

?>
