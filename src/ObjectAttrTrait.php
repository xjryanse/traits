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
        $lists = property_exists(__CLASS__, 'objAttrConf') ? self::$objAttrConf : [];
        //20230608:TODO临时过渡
        foreach($lists as &$v){
            $v['uniField']  = '';
            $v['inList']    = true;
            $v['inStatics'] = true;
            $v['inExist']   = true;
        }

        // 20230528:增加注入
        $listsUni   = self::objAttrConfListUni();
        return array_merge($lists, $listsUni);
    }
    /**
     * 20230608
     * @return type
     */
    public static function objAttrConfListInList(){
        $lists = self::objAttrConfList();
        $arr = [];
        foreach($lists as $k=>$v){
            if($v['inList']){
                $arr[$k] = $v; 
            }
        }
        return $arr;
    }
    
    /**
     * 20230608
     * @return type
     */
    public static function objAttrConfListInStatics(){
        $lists = self::objAttrConfList();
        $arr = [];
        foreach($lists as $k=>$v){
            if($v['inStatics']){
                $arr[$k] = $v; 
            }
        }
        return $arr;
    }
    
    /**
     * 20230608
     * @return type
     */
    public static function objAttrConfListInExist(){
        $lists = self::objAttrConfListUniPre();
        $arr = [];
        foreach($lists as $v){
            if($v['inExist']){
                $arr[] = $v; 
            }
        }
        return $arr;
    }
    /**
     * 20230528:注入联动（默认后向）
     */
    protected static function objAttrConfListUni(){
        // 20230603:需要加反斜杠？？
        $className = '\\'.__CLASS__;
        $con[] = ['baseClass','=',$className];
        $lists = DbOperate::uniAttrConfArr($con);
        $objAttrConf = [];
        foreach($lists as $v){
            $objAttrConf[$v['property']] = self::objConfDataDeal($v);
        }

        return $objAttrConf;
    }
    /**
     * 20230608:前向
     * @return type
     */
    protected static function objAttrConfListUniPre(){
        // 20230603:需要加反斜杠？？
        $className = '\\'.__CLASS__;
        $con[] = ['class','=',$className];
        $lists = DbOperate::uniAttrConfArr($con);
        // 20230608：TODO；前面的应该全改成这种
        return $lists;
//        
//        $objAttrConf = [];
//        foreach($lists as $v){
//            $objAttrConf[$v['property']] = self::objConfDataDeal($v);
//        }
//
//        return $objAttrConf;
    }
    
    /**
     * 
     * @param type $v   DbOperate::uniAttrConfArr()，查询的单条数组列表;
     * @return type
     */
    private static function objConfDataDeal($v){
        return [
            'class'     =>$v['class'],
            'keyField'  =>$v['keyField'],
            'master'    =>true,
            'uniField'  =>Arrays::value($v, 'uniField', ''),
            'inList'    =>Arrays::value($v, 'inList', true),
            'inStatics' =>Arrays::value($v, 'inStatics', true),
            'inExist'   =>Arrays::value($v, 'inExist', true),
            // 20230608:字段存在，显示值
            'existField'=>Arrays::value($v, 'existField', ''),
        ];
    }
    
    
    // 20221024:批量获取属性列表
    public static function objAttrsListBatch($key, $ids){
        if(!$ids){
            return [];
        }
        if(!is_array($ids)){
            $ids = [$ids];
        }
        // 20230729??优化性能，只有一个
        if(count($ids) == 1){
            $id = $ids[0];
            return self::getInstance($id)->objAttrsList($key);
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
        // 20230730
        $objAttrs           = property_exists($this, 'objAttrs') ? $this->objAttrs : [];
        $hasObjAttrQuery    = property_exists($this, 'hasObjAttrQuery') ? $this->hasObjAttrQuery : [];
        if(!Arrays::value($objAttrs, $key) && !Arrays::value($hasObjAttrQuery,$key)){
            // 取配置
            $config = Arrays::value(self::objAttrConfList(), $key);
            if(!$config){
                throw new Exception('未配置'.$key.'的对象属性信息，请联系开发解决');
            }
            //Debug::debug('objAttrsList_'.$key.'的条件', $con);
            // 20230730:是刚写入的数据，就没必要查了
            if(DbOperate::isGlobalSave(self::getTable(), $this->uuid)){
                $listsArr = [];
            } else {
                $class      = Arrays::value($config, 'class');
                $keyField   = Arrays::value($config, 'keyField');
                $master     = Arrays::value($config, 'master', false);
                //查数据
                $con[] = [$keyField,'=',$this->uuid];
                if($class::mainModel()->hasField('is_delete')){
                    $con[] = ['is_delete', '=', 0];
                }
                $lists = $class::listSetUudata($con, $master);
                $listsArr = $lists 
                    ? (is_array($lists) ? $lists : $lists->toArray()) 
                    : [];
            }
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
        // 20230730:增加property_exists判断
        if((!property_exists($this, 'objAttrs') || is_null($this->objAttrs[$key])) 
                && (!property_exists($this, 'hasObjAttrQuery') || !Arrays::value($this->hasObjAttrQuery,$key)) ){
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
        $hasObjAttrQuery = property_exists($this, 'hasObjAttrQuery') ? $this->hasObjAttrQuery : [];
        // 20230730：似乎可以优化？？？
        if((!property_exists($this, 'objAttrs') 
                || !isset($this->objAttrs[$key]) 
                || is_null($this->objAttrs[$key])) 
                && !Arrays::value($hasObjAttrQuery,$key) ){
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
        $objAttrs           = property_exists($this, 'objAttrs') ? $this->objAttrs : [];
        $hasObjAttrQuery    = property_exists($this, 'hasObjAttrQuery') ? $this->hasObjAttrQuery : [];
        //20230801：没有获取时，先获取一遍
        if((!$objAttrs || is_null($objAttrs[$key])) && !Arrays::value($hasObjAttrQuery,$key) ){
            $this->objAttrsList($key);
        }
        //有节点，往节点末尾追加
        $hasMatch = false;
        if(Arrays::value($this->objAttrs, $key)){
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
