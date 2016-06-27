<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */
//declare(ticks=1);

use \GatewayWorker\Lib\Gateway;
use \Workerman\Lib\Timer;
use \GatewayWorker\Lib\Db;

/**
 * 主逻辑
 * 主要是处理 onConnect onMessage onClose 三个方法
 * onConnect 和 onClose 如果不需要可以不用实现并删除
 */
class Events
{
    
    /**
     * 当客户端连接时触发
     * 如果业务不需此回调可以删除onConnect
     * 
     * @param int $client_id 连接id
     */
    public static function onConnect($client_id)
    {
        // 向当前client_id发送数据 
        //Gateway::sendToClient($client_id, "Hello $client_id");
        // 向所有人发送
        //Gateway::sendToAll("$client_id login");
        
        // 当在线人数超过200时不再接受连接
        if(Gateway::getAllClientCount() >= 200){
            print('Number of People online is limited!');
            Gateway::closeClient($client_id);
        }
        
        
        $GLOBALS['maxMem'] = 4;
        $GLOBALS['mode'] = 1;
        print('A player connected');
    }
    
   /**
    * 当客户端发来消息时触发
    * @param int $client_id 连接id
    * @param mixed $message 具体消息
    */
   public static function onMessage($client_id, $message)
   {
	   // 将获得数据转为json对象
		$data=json_decode($message);
        print_r($data);
        // 当传入数据错误时拒绝访问并断开
        if(!$data)
		{
            print('Data Wrong!');
            Gateway::closeClient($client_id);
			return;
		}
        
		// 当人数未齐时请求游戏开始
		// 予以拒绝
		if($data->cmd != "login" && $data->cmd != "talk" && empty($_SESSION['state']))
		{
            print('People Not Enough!');
			Gateway::closeClient($client_id);
			return;
		}
		
		switch($data->cmd)
		{
			case "login":
				// 人满后若游戏未开始则开始游戏
				if(Events::checkLogin($client_id) && Events::getMemCount()==$GLOBALS['maxMem'] && Events::getData('Started') == 0)
				{
					Events::setData('Started', 1);
					// 开始游戏
                    // 定义全局变量
                    $GLOBALS["items"] = Events::getItems();
                    foreach ($GLOBALS["items"] as $key => $value) {
                        Events::insertData('Items', array('ItemID' => $key, 'ItemName' => $value, 'Rid'=>Events::getRid()));
                    }
                    $GLOBALS["monsters"] = Events::getRandomMonsters();
                    foreach ($GLOBALS["monsters"] as $key => $value) {
                        Events::insertData('Monsters', array('MonID' => $key, 'MonName' => $value, 'Rid'=>Events::getRid()));
                    }
                    $GLOBALS['hole'] = array();
                    $GLOBALS['swordChoice'] = 0;
                    Events::insertData('ServerData', array('MaxMem'=>$GLOBALS['maxMem'], 'SwordChoice'=>$GLOBALS['swordChoice'],
                     'Mode'=>$GLOBALS['mode'], 'Rid'=>Events::getRid()));
					$req=array("cmd"=>"battle choice","data"=>"","to"=>"1");
					Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
                }
			break;
            
			case "next":
                // 判断是否还存在玩家
                $nextGrpNum = Events::nextPlayer();
                if($nextGrpNum === false)
                {
                    print('Error happened in \'next\'');
                    return;
                }
                
                // 当参战玩家仅剩1人时直接开始战斗结算
                if(Events::getExistPlayerNum() === 1)
                {
                    Events::battlePrepare();
                    return;
                }
                 
                $req=array("cmd"=>"battle choice","data"=>"","to"=>"$nextGrpNum");
				Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
			
			break;
			
			case "battle":
                // 当怪物与道具都用完时准备战斗结算
                $itemLen = Events::countTable('Items');
                $monLen = Events::countTable('Monsters');
                if($itemLen == 0&& $monLen == 0)
                {
                    Events::battlePrepare();
                    return;
                }else if($itemLen != 0 && $monLen != 0)
                {
                    $GLOBALS['monsters'] = Events::getData('Monsters');
                    $req=array("cmd"=>"monster choice","data"=>"{$GLOBALS['monsters'][0]['MonID']}","to"=>"{$_SESSION['grpNum']}");
				    Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
                    
                }else if($monLen == 0)
                {
                    $req=array("cmd"=>"discard","data"=>"","to"=>"{$_SESSION['grpNum']}");
				    Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
                }
                
                
			break;
			
			case "nobattle":
                $_SESSION['state'] = 'nobattle';
                
                $req=array("cmd"=>"finished","data"=>$_SESSION['grpNum'],"to"=>"");
				Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
                
                $req=array("cmd"=>"finish","data"=>"","to"=>$_SESSION['grpNum']);
				Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
            
			break;
			
			case "monster choosed":
                // 将选择的怪物加入hole数组
                // 获得当前怪物序号
                $key = $GLOBALS['monsters'][0]['MonID'];
                $monName = $GLOBALS['monsters'][0]['MonName'];
                // 删除数据库中的monster
                Events::deleteMon($key);
                array_shift($GLOBALS['monsters']);
                // 怪物插入hole
                Events::insertData('Hole', array('MonID' => $key, 'MonName' => $monName, 'Rid'=>Events::getRid()));
                
                $req=array("cmd"=>"monster choosed","data"=>"{$_SESSION['grpNum']}","to"=>"");
				Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
                
                $req=array("cmd"=>"finish","data"=>"","to"=>"{$_SESSION['grpNum']}");
				Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
                
			break;
			
			case "monster passed":
                $itemLen = Events::countTable('Items');
                // 当装备全部扔完且放弃加怪物时自动认输
                if($itemLen === 0)
                {
                    $_SESSION['state'] = 'nobattle';
                    
                    $req=array("cmd"=>"finished","data"=>"{$_SESSION['grpNum']}","to"=>"");
				    Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
                
                    $req=array("cmd"=>"finish","data"=>"","to"=>"{$_SESSION['grpNum']}");
				    Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
                    
                    break;
                }
                // 获得当前怪物序号
                $key = $GLOBALS['monsters'][0]['MonID'];
                // 装备有剩余时丢弃怪物卡并选择丢弃的装备
                // 删除数据库中的monster
                Events::deleteMon($key);
                array_shift($GLOBALS['monsters']);
                
                $req=array("cmd"=>"discard","data"=>"","to"=>"{$_SESSION['grpNum']}");
				Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
            
			break;
			
			case "discard":
                // 删除装备数组中的指定装备
                if(Events::isExsitItem($data->data))
                    Events::deleteItem($data->data);
                else
                    print('Error happened in case \'discard\'');
                    
                $req=array("cmd"=>"discarded","data"=>"{$data->data}","to"=>"{$_SESSION['grpNum']}");
				Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
                
                $req=array("cmd"=>"finish","data"=>"","to"=>"{$_SESSION['grpNum']}");
				Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
                
			break;
			
			case "swordChoice":
                Events::setData('SwordChoice', $data->data);
                
                $req=array("cmd"=>"prepare ok","data"=>Events::getHP(),"to"=>"{$_SESSION['grpNum']}");
				Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
			
			break;
			
			case "prepare ok":
                // 获取战斗怪物顺序
                $hole = Events::getData('Hole');
                $items = Events::getData('Items');
                $mons = array();
                $hp = Events::getHP();
                foreach ($hole as $value) {
                    array_push($mons, $value['MonID']);
                }
                
                // 生成定时器
                $timerID = Timer::add(0.5, function()use(&$timerID, $items, &$mons, &$hp)
                {
                    // 是否计算结束
                    if(empty($mons))
                    {
                        $req=array("cmd"=>"win", "data"=>0, "to"=>"{$_SESSION['grpNum']}");
				        Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
                        Timer::del($timerID);
                        return;
                    }
                    $mid = array_shift($mons);
                    $harm = $mid;
                    if(Events::isExsitItem(2) && $mid <= 3){// 判断是否使用火炬
                        $harm = 0;
                    }else if(Events::isExsitItem(3) && $mid == 9){// 判断是否使用龙枪
                        $harm = 0;
                    }else if(Events::isExsitItem(4) && $mid%2 == 0){// 判断是否使用圣杯
                        $harm = 0;
                    }else if(Events::getData('SwordChoice') == $mid){// 判断是否命中斩首剑
                        $harm = 0;
                    }
                    // 计算hp
                    $hp -= $harm;
                    
                    // 发送给客户端
                    $req=array("cmd"=>"monster atk", "data"=>$mid, "to"=>"{$_SESSION['grpNum']}");
				    Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
                    
                    $req=array("cmd"=>"battle harm", "data"=>$harm, "to"=>"{$_SESSION['grpNum']}");
				    Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
                    
                    if($hp <= 0)
                    {
                        $req=array("cmd"=>"lose", "data"=>0, "to"=>"{$_SESSION['grpNum']}");
				        Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
                        Timer::del($timerID);
                    }
                    
                });
			break;
			
			case "talk":
                $talk = $_SESSION["grpNum"]."号: ".$data->data;
			    $req=array("cmd"=>"talk","data"=>"$talk","to"=>"");
                Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
			break;
			
			case "restart":
                // 再次开始游戏
                // 复原全局变量
                unset($_SESSION['Rid']);
                $rid = Events::getRid();
                $grpName = 'dungeon'.Events::getRid();
                $hole = array();
                $swordChoice = 0;
                $state = "battle";
                if(empty($_SESSION['Again']))
                {
                    $_SESSION['Again'] = 1;
                }
                $again = $_SESSION['Again']+1;
                // 设置组内所有玩家session
                $oldGrpName = $_SESSION['grpName'];
                Events::setAllSession(array('Rid' => $rid, 'grpName' => $grpName, 'hole' => $hole,
                 'swordChoice' => $swordChoice, 'state' => $state, 'Again' => $again));
                // 加入新的group
                $info = Gateway::getClientInfoByGroup($oldGrpName);
                foreach($info as $id=>$array)
                {
                    Gateway::leaveGroup($id, $oldGrpName);
                    Gateway::joinGroup($id, $_SESSION['grpName']);
                }
                // 修改数据库
                $GLOBALS["items"] = Events::getItems();
                foreach ($GLOBALS["items"] as $key => $value) {
                    Events::insertData('Items', array('ItemID' => $key, 'ItemName' => $value, 'Rid'=>Events::getRid()));
                }
                $GLOBALS["monsters"] = Events::getRandomMonsters();
                foreach ($GLOBALS["monsters"] as $key => $value) {
                    Events::insertData('Monsters', array('MonID' => $key, 'MonName' => $value, 'Rid'=>Events::getRid()));
                }
                Events::insertData('ServerData', array('MaxMem'=>$GLOBALS['maxMem'], 'SwordChoice'=>0,
                 'Mode'=>$GLOBALS['mode'], 'Rid'=>Events::getRid()));
                 // 若人数符合要求则开始游戏
                 $groupMem=Events::getMemCount();
		        if($groupMem == $GLOBALS['maxMem'])
                {
                    Events::setData('Started', 1);
	                $req=array("cmd"=>"battle choice","data"=>"","to"=>($_SESSION['Again'] - 1) % $GLOBALS['maxMem'] + 1);
	                Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
                }
		  break;
		}
   }
   
   // 验证登录信息，登陆成功返回true，否则false
   private static function checkLogin($client_id)
   {
       // 获取group名
       if(empty($_SESSION['grpName']))
            $_SESSION['grpName']='dungeon'.Events::getRid();
	   // 获取组内编号
		$groupMem=Events::getMemCount()+1;
		if($groupMem <=  $GLOBALS['maxMem'])
		{
			// 登陆人数4人以下时判断非重复登录后允许登录
            // 获取组内所有客户端
			$grpArr = Gateway::getClientInfoByGroup($_SESSION['grpName']);
            // 数组用于记录玩家号数
            $grpNumArray = [];
            // 新玩家号码
            $newGrpNum = 1;
			// 获取现存玩家号数
			foreach($grpArr as $client=>$value)
			{
				array_push($grpNumArray, Gateway::getSession($client)['grpNum']);
			}
            // 新玩家号码生成
            for($i=1;$i<=4;$i++){
                if(in_array($i, $grpNumArray))
                    $newGrpNum++;
                else
                    break;
            }
			if($newGrpNum>4)
                return false;
            
			// 允许登录
			Gateway::joinGroup($client_id, $_SESSION['grpName']);
            $_SESSION["state"]="battle";
            $_SESSION["grpNum"] = $newGrpNum;
            
			$req=array("cmd"=>"login","data"=>"$newGrpNum","to"=>"$newGrpNum");
			Gateway::sendToCurrentClient(json_encode($req));
			return true;
		}else{
			// 当登陆人数达到4人时限制4人外无法登陆
            if(empty($_SESSION['grpName']))
            {
                Gateway::closeClient($client_id);
				return false;
            }
			$grpArr=Gateway::getClientInfoByGroup($_SESSION['grpName']);
			global $tempClient;
			$tempClient=null;
			foreach($grpArr as $client=>$value)
			{
				if($client_id == $client)
				{
					$tempClient=$client;
					break;
				}
			}
			if($client_id != $tempClient)
			{
				Gateway::closeClient($client_id);
				return false;
			}
		}
		return false;
   }
   
   // 产生随机怪物序列
   private static function getRandomMonsters()
   {
       $keys = array('1','2','3','4','5','6','7','9');
       shuffle($keys);
       $monsters = array_flip($keys);
       
        $monsters["1"]= "哥布林";
		$monsters["2"]= "骷髅兵";
		$monsters["3"]= "兽人";
		$monsters["4"]= "吸血鬼";
		$monsters["5"]= "魔像";
		$monsters["6"]= "巫妖";
		$monsters["7"]= "恶魔";
		$monsters["9"]= "巨龙";
        
        return $monsters;
   }
   
   // 返回装备数组
   private static function getItems()
   {
       return array(
                            "1"=> "盾",
		                    "2"=> "火炬",
		                    "3"=> "龙枪",
		                    "4"=> "圣杯",
		                    "5"=> "锁甲",
		                    "6"=> "斩首剑");
   }
   
   // 返回组内成员数
   private static function getMemCount()
   {
	   return Gateway::getClientCountByGroup($_SESSION['grpName']);
   }
   
   // 返回仍选择战斗的玩家数量
   private static function getExistPlayerNum()
   {
       $num = 0;
       foreach (Gateway::getClientInfoByGroup($_SESSION['grpName']) as $key => $value) {
           if($value['state'] === 'battle')
               $num++;
       }
       return $num;
   }
   
   // 获得当前玩家血量
   private static function getHP()
   {
	   switch($GLOBALS['mode'])
       {
           case 1:
               $hp = 3;
               $GLOBALS['items'] = Events::getData('Items');
               foreach ($GLOBALS['items'] as $value) {
                   if($value['ItemID'] == 1)
                       $hp += 3;
                   else if($value['ItemID'] == 5)
                       $hp += 5;
               }
               return $hp;
           break;
           
           case 2:
           
           break;
           
           case 3:
           
           break;
       }
   }
   
   // 返回下一个玩家的grpNum
   private static function nextPlayer()
   {
       $num = $_SESSION['grpNum']% $GLOBALS['maxMem']+1;
       $times = 0;
       print_r(Gateway::getClientInfoByGroup($_SESSION['grpName']));
	   while(true)
       {
           foreach (Gateway::getClientInfoByGroup($_SESSION['grpName']) as $key => $value) 
           {
                if($value['grpNum'] == $num)
                {
                    if($value['state'] === 'battle')
                        return $num;
                    else
                        break;
                }
           }
           $num = $num% $GLOBALS['maxMem']+1;
           $times++;
           if($times >=  $GLOBALS['maxMem'])
               return false;
       }
   }
   
   // 准备战斗结算
   private static function battlePrepare()
   {
	   print('准备战斗结算');
       // 获取战斗玩家的session
       $targetValue;
       $nextP = Events::nextPlayer();
       foreach (Gateway::getClientInfoByGroup($_SESSION['grpName']) as $key => $value)
       {
           if($value['grpNum']==$_SESSION['grpNum'] || $value['grpNum']==$nextP)
           {
               if($value['state'] === 'battle')
                   $targetValue = $value;
           }
       }
       
       if(Events::isExsitItem(6))
       {
           $req=array("cmd"=>"prepare item","data"=>'6',"to"=>$targetValue['grpNum']);
		   Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
       }else{
           $req=array("cmd"=>"prepare ok","data"=>Events::getHP(),"to"=>$targetValue['grpNum']);
		   Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
       }
   }
   
   // 通过数据库查询条数得到Rid
   private static function getRid()
   {
       if(!empty($_SESSION['Rid']))
           return $_SESSION['Rid'];
       else {
           $_SESSION['Rid'] = Db::instance('dungeon')->select('COUNT(*)')->from('ServerData')->single() + 1;
           return $_SESSION['Rid'];
       }
   }
   
   // 查询表中相关记录的条数
   private static function countTable($table)
   {
       return Db::instance('dungeon')->select('COUNT(*)')->from($table)->where('Rid= '.Events::getRid())->single();
   }
   
   // 读取数据库
   private static function getData($table)
   {
       $db = Db::instance('dungeon');
       if($table !== 'Hole'&&$table !== 'Monsters' && $table !== 'Items')
       {
           return $db->select($table)->from('ServerData')->where('Rid= '.Events::getRid())->single();
       }else{
           return $db->select('*')->from($table)->where('Rid= '.Events::getRid())->query();
       }
   }
   
   // 查询某item是否存在，是返回1，否返回0
   private static function isExsitItem($id)
   {
       $db = Db::instance('dungeon');
       return $db->select('COUNT(*)')->from('Items')->where('Rid= '.Events::getRid()." and ItemID = $id")->single();
   }
   
   // 修改数据库
   private static function setData($table, $value)
   {
       $db = Db::instance('dungeon');
       if($table !== 'Hole'&&$table !== 'Monsters'&&$table !== 'Items')
            $db->update('ServerData')->cols(array($table=>$value))->where('Rid= '.Events::getRid())->query();
       else
            $db->update($table)->cols($value)->where('Rid= '.Events::getRid())->query();
   }
   
   // 插入数据库
   private static function insertData($table, $value)
   {
       $db = Db::instance('dungeon');
       $db->insert($table)->cols($value)->query();
   }
   
   // 删除数据库
   private static function deleteMon($value)
   {
       $db = Db::instance('dungeon');
       $db->delete('Monsters')->where('Rid= '.Events::getRid().' and MonID='.$value)->query();
   }
   
   private static function deleteItem($value)
   {
       $db = Db::instance('dungeon');
       $db->delete('Items')->where('Rid= '.Events::getRid().' and ItemID='.$value)->query();
   }
   
   private static function deleteHole($value)
   {
       $db = Db::instance('dungeon');
       $db->delete('Hole')->where('Rid= '.Events::getRid().' and MonID='.$value)->query();
   }
   
   private static function setAllSession($session_arr)
   {
       $info = Gateway::getClientInfoByGroup($_SESSION['grpName']);
       foreach($info as $id=>$array)
       {
           Gateway::updateSession($id, $session_arr);
       }
   }
   
   /**
    * 当用户断开连接时触发
    * @param int $client_id 连接id
    */
   public static function onClose($client_id)
   {
       // 如果不是在组内则返回
       if(empty($_SESSION['grpName']))
            return;
       // 向组内所有人发送
       $req=array("cmd"=>"log out","data"=>"{$_SESSION['grpNum']}","to"=>'');
       Gateway::sendToGroup($_SESSION['grpName'],json_encode($req));
       if(Events::getMemCount() == 0)
            Events::setData('Started', 0);
   }
}
