<?php
namespace xjryanse\traits;

use xjryanse\logic\Arrays;
use xjryanse\logic\Arrays2d;
use xjryanse\logic\Debug;
use xjryanse\logic\DbOperate;
use Exception;
/**
 * 对象属性复用
 * (配置式数据表)
 */
trait ObjectAttrTrait {
    
    public static $attrTimeCount   = 0 ;   //末个节点执行次数
    // // 定义对象的属性
    // protected $objAttrs = [];
    // // 定义对象是否查询过的属性
    // protected $hasObjAttrQuery = [];
    // // 定义对象属性的配置数组
    // protected static $objAttrConf = [
    //      'orderFlowNode'=>[
    //          'class'     =>'\\xjryanse\\order\\service\\OrderFlowNodeService',
    //          'keyField'  =>'order_id',
    //          'master'    =>true
    //      ]
    // ]
    /**
     * 20230518：提取配置数组
     */
    public static function objAttrConfArr(){
        $lists = property_exists(__CLASS__, 'objAttrConf') ? self::$objAttrConf : [];
        $resArr = [];
        foreach($lists as $k=>$v){
            $tmp = $v;
            $tmp['baseClass']   = __CLASS__;
            $tmp['property']    = $k;
            $resArr[] = $tmp;
        }
        return $resArr;
    }
    /**
     * 对象属性列表
     */
    public static function objAttrConfList(){
        $lists      =  self::$objAttrConf;
        // 20230528:增加注入
        $listsUni   = self::objAttrConfListUni();
        return array_merge($lists, $listsUni);
    }
    /**
     * 20230528:注入联动
     */
    protected static function objAttrConfListUni(){
        $con[] = ['baseClass','=',__CLASS__];
        $lists = DbOperate::uniAttrConfArr($con);
        $objAttrConf = [];
        foreach($lists as $v){
            $objAttrConf[$v['property']] = [
                'class'     =>$v['class'],
                'keyField'  =>$v['keyField'],
                'master'    =>true
            ];
        }

        return $objAttrConf;
    }
    
    // 20221024:批量获取属性列表
    public static function objAttrsListBatch($key, $ids){
        if(!$ids){
            return [];
        }
        if(!is_array($ids)){
            $ids = [$ids];
        }
        // 取配置
        $config = Arrays::value(self::objAttrConfList(), $key);
        if(!$config){
            throw new Exception('未配置'.$key.'的对象属性信息，请联系开发解决');
        }
        $class      = Arrays::value($config, 'class');
        $keyField   = Arrays::value($config, 'keyField');
        $master     = Arrays::value($config, 'master', false);

        //查数据
        $con[] = [$keyField,'in',$ids];
        if($class::mainModel()->hasField('is_delete')){
            $con[] = ['is_delete', '=', 0];
        }
        //Debug::debug('objAttrsList_'.$key.'的条件', $con);
        $lists = $class::listSetUudata($con, $master);
        $listsArr = $lists 
                ? (is_array($lists) ? $lists : $lists->toArray()) 
                : [];
        
        foreach($ids as $id){
            $inst       = self::getInstance($id);
            $conEle     = [];
            $conEle[]   = [$keyField,'=',$id];
            $inst->objAttrs[$key] = Arrays2d::listFilter($listsArr, $conEle);
            //已经有查过了就不再查了，即使为空
            $inst->hasObjAttrQuery[$key] = true;
        }
        // 批量获取属性
        return $listsArr;
    }

    /**
     * 对象属性列表
     */
    public function objAttrsList( $key ){
        if(!Arrays::value($this->objAttrs, $key) && !Arrays::value($this->hasObjAttrQuery,$key)){
            // 取配置
            $config = Arrays::value(self::objAttrConfList(), $key);
            if(!$config){
                throw new Exception('未配置'.$key.'的对象属性信息，请联系开发解决');
            }
            $class      = Arrays::value($config, 'class');
            $keyField   = Arrays::value($config, 'keyField');
            $master     = Arrays::value($config, 'master', false);
            //查数据
            $con[] = [$keyField,'=',$this->uuid];
            if($class::mainModel()->hasField('is_delete')){
                $con[] = ['is_delete', '=', 0];
            }
            //Debug::debug('objAttrsList_'.$key.'的条件', $con);
            $lists = $class::listSetUudata($con, $master);
            $listsArr = $lists 
                ? (is_array($lists) ? $lists : $lists->toArray()) 
                : [];
            //Debug::debug('objAttrsList_'.$key.'的$lists', $lists);
            $this->objAttrs[$key] = $listsArr;
            //已经有查过了就不再查了，即使为空
            $this->hasObjAttrQuery[$key] = true;
        }
        return $this->objAttrs[$key];
    }
    /**
     * 设定对象属性
     * @param type $key
     * @param type $data
     */
    public function objAttrsSet( $key, $data){
        $this->objAttrs[$key]           = $data;
        $this->hasObjAttrQuery[$key]    = true;            
    }
    
    /**
     * 20220619:删除对象属性
     * @param type $key
     * @param type $id  主键
     */
    public function objAttrsUnSet( $key, $id){
        if((!$this->objAttrs || is_null($this->objAttrs[$key])) && !Arrays::value($this->hasObjAttrQuery,$key) ){
            $this->objAttrsList($key);
        }
        
        foreach($this->objAttrs[$key] as $k=>$v){
            if($v['id'] == $id){
                unset($this->objAttrs[$key][$k]);
            }
        }     
    }
    /**
     * 新增对象属性;用于数据库中添加后，内存中同步添加
     * @param type $key
     * @param type $data
     */
    public function objAttrsPush( $key, $data){
        if((!$this->objAttrs 
                || !isset($this->objAttrs[$key]) 
                || is_null($this->objAttrs[$key])) 
                && !Arrays::value($this->hasObjAttrQuery,$key) ){
            $this->objAttrsList($key);
        }
        
        if(Arrays::value($this->hasObjAttrQuery,$key)){
            //有节点，往节点末尾追加
            $this->objAttrs[$key][] = $data;
        }
    }
    /**
     * 对象属性更新
     * @param type $key
     * @param type $dataId
     * @param type $data
     */
    public function objAttrsUpdate( $key, $dataId, $data){
        if((!$this->objAttrs || is_null($this->objAttrs[$key])) && !Arrays::value($this->hasObjAttrQuery,$key) ){
            $this->objAttrsList($key);
        }
        //有节点，往节点末尾追加
        $hasMatch = false;
        foreach($this->objAttrs[$key] as &$v){
            if($v['id'] == $dataId){
                $hasMatch = true;
                //20220622:TODO;
                if(is_object($v)){
                    $v = $v->toArray();
                }
                if(is_object($data)){
                    $data = $data->toArray();
                }
                $v = array_merge($v,$data);
            }
        }
        // 2022-12-09:如果原先没有，则追加
        if(!$hasMatch){
            $this->objAttrs[$key][] = $data;
        }
        
    }
    /*
     * 属性记录数
     */
    public function objAttrsCount($key, $con = []){
        $listRaw    = $this->objAttrsList($key);
        $list       = Arrays2d::listFilter($listRaw, $con);
        return count($list);
    }
    /**
     * 属性指定字段求和
     * @param type $key
     * @param type $sumField
     * @return type
     */
    public function objAttrsFieldSum($key, $sumField ,$con = []){
        $listRaw    = $this->objAttrsList($key);
        $list       = Arrays2d::listFilter($listRaw, $con);
        return Arrays2d::sum($list, $sumField);
    }
    
}
