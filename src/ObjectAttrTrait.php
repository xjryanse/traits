<?php
namespace xjryanse\traits;

use xjryanse\logic\Arrays;
use xjryanse\logic\Debug;
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
     * 对象属性列表
     */
    public function objAttrsList( $key ){        
        if(!Arrays::value($this->objAttrs, $key) && !Arrays::value($this->hasObjAttrQuery,$key)){
            // 取配置
            $config = Arrays::value(self::$objAttrConf, $key);
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
            //Debug::debug('objAttrsList_'.$key.'的$lists', $lists);
            $this->objAttrs[$key] = $lists ? $lists->toArray() : [];
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
        if((!$this->objAttrs || is_null($this->objAttrs[$key])) && !Arrays::value($this->hasObjAttrQuery,$key) ){
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
        foreach($this->objAttrs[$key] as &$v){
            if($v['id'] == $dataId){
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
}
