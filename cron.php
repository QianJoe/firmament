<?php
  error_reporting(E_ALL ^ E_WARNING ^ E_NOTICE);
  date_default_timezone_set('PRC');

  require_once "./lib/TBOps_Sign.php";
  require_once "./lib/WizardHTTP.php";
  require_once "./lib/common.php";
  
  include "./config.php";
  $conn = new mysqli($db_server, $db_username, $db_password, $db_name, $db_port);
  if($conn->connect_errno != 0)
  {
    $errmsg = sprintf("数据库连接错误(0)：%s 错误代码：%d",
	                  $conn->connect_error, $conn->connect_errno);
    debug_log($errmsg);
    return;
  }

  //取出上次签到日期
  $sql = "SELECT v FROM setting WHERE k='lastsign'";
  $res = $conn->query($sql);
  if(!$res)
  {
    $errmsg = sprintf("获取上次签到失败：%s 错误代码：%d",
	                  $conn->error, $conn->errno);
    debug_log($errmsg);
	return;
  }
  if($res->num_rows == 0)
  {
    $ls_exist = false;
    $lastsign = 0;
  }
  else
  {
    $ls_exist = true;
    $lastsign = (int)$res->fetch_row()[0];
  }

  $dt = (int)Date('Ymd');
  if($dt != $lastsign)
  {
    if(!ResetSignlog()) return;
    if(!$ls_exist)
	  $sql = "INSERT INTO setting VALUES ('lastsign','$dt')";
	else
	  $sql = "UPDATE setting SET v='$dt' WHERE k='lastsign'";
	if(!$conn->query($sql))
	{
	  $errmsg = sprintf("重置上次签到失败：%s 错误代码：%d",
	                  $conn->error, $conn->errno);
      debug_log($errmsg);
	  return;
	}
  }

  //获取待签列表
  $sql = "SELECT uid, tbun, tbcookie, tbname 
          FROM signlog NATURAL JOIN tbid 
		  WHERE date=$dt AND (status='U' OR status='R') LIMIT 30";
  $res = $conn->query($sql);
  if(!$res)
  {
    $errmsg = sprintf("获取签到记录失败：%s 错误代码：%d",
	                  $conn->error, $conn->errno);
	debug_log($errmsg);
    return;
  }

  //开始签到
  $wc = new WizardHTTP();
  $wc->SetDefHdr();

  while($arr = $res->fetch_row())
  {
    $wc->SetHdr("Cookie", $arr[2]);
    $r = Sign($wc, $arr[3]);
	$errno = $r['errno'];
    if($errno == '0')
	{
      $msg = sprintf("%s在%s吧签到成功", $arr[1], $arr[3]);
	  $status = 'O';
	}
    else
	{
      $msg = sprintf("%s在%s吧签到失败：%s 错误代码：%s",
	                 $srr[1], $arr[3], $r['errmsg'], $errno);
	  if($errno == '160002') //你之前已经签过了
	    $status = 'O';
	  else if($errno == '340003') //服务器开小差了
	    $status = 'R';
	  else
	    $status = 'F';
	}
    debug_log($msg);
	
	$sql = sprintf("UPDATE signlog SET status='%s' WHERE uid=%d AND tbname='%s' AND date=%d",
	               $status, $arr[0], $arr[3], $dt);
	if(!$conn->query($sql))
	{
	  $errmsg = sprintf("更新签到信息失败：%s 错误代码：%d",
	                    $conn->error, $conn->errno);
	  debug_log($errmsg);
      return;
	}
  }
  
  debug_log("签到完成");

  function ResetSignlog()
  {
    include "./config.php";
    $conn = new mysqli($db_server, $db_username, $db_password, $db_name, $db_port);
    if($conn->connect_errno != 0)
    {
      $errmsg = sprintf("数据库连接错误(1)：%s 错误代码：%d",
	                    $conn->connect_error, $conn->connect_errno);
      debug_log($errmsg);
	  return false;
    }
  
    $dt = (int)Date('Ymd');
    $sql = "DELETE FROM signlog WHERE date=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $dt);
    if(!$stmt->execute())
    {
      $errmsg = sprintf("删除签到记录失败：%s 错误代码：%d",
	                    $stmt->error, $stmt->errno);
      debug_log($errmsg);
	  return false;
    }
  
    $sql = "SELECT uid, tbname FROM tblist WHERE ign='F'";
    $res = $conn->query($sql);
    if(!$res)
    {
      $errmsg = sprintf("获取贴吧列表失败：%s 错误代码：%d",
	                    $conn->error, $conn->errno);
      debug_log($errmsg);
	  return false;
    }
    if($res->num_rows == 0)
    {
      debug_log("签到列表无内容");
	  return false;
    }
    $list = array();
    while($arr = $res->fetch_row())
      $list[] = $arr;
  
    //U: 待签到 O: 已签到 F: 失败 R: 等待重试
    $sql = "INSERT INTO signlog (uid,tbname,status,date) VALUES";
    foreach($list as $elem)
      $sql .= sprintf(" (%d,'%s','U',%d),", $elem[0], $elem[1], $dt);
    $sql = substr($sql, 0, strlen($sql) - 1);
    //echo $sql;
    if(!$conn->query($sql))
    {
      $errmsg = sprintf("添加签到记录失败：%s 错误代码：%d",
	                    $conn->error, $conn->errno);
      debug_log($errmsg);
	  return false;
    }
  
    debug_log("签到记录重置成功");
	return true;
  }

?>