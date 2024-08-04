<?php

namespace xjryanse\traits;

/**
 * 20230327
 * 中间表复用逻辑（存前后关联表的id）
 * 依赖  \xjryanse\traits\MainModelTrait;
 */
trait MiddleModelTrait {
    // 中间表字段关联配置
    // 中间表配置:关联字段 - 之 - 前字段；后字段；
    // protected static $middleFieldMapping = ['circuit_bus_id','bao_order_id'];
    /**
     * 提取主字段名
     * @return type
     */
    private static function middleGetMainField(){
        return self::$middleFieldMapping[0];
    }
    /*
     * 提取子字段名
     */
    private static function middleGetSubField(){
        return self::$middleFieldMapping[1];
    }
    
    /**
     * 20230327:关联表带信息查询
     * @param type $mainField       主关联字段
     * @param type $mainTable       主表
     * @param type $con             条件
     * @param type $mainTableFields 主表返回哪些字段（默认全部）
     * @return type
     */
    protected static function middleInfos($mainField, $mainTable , $con = [], $mainTableFields = [], $order = ''){
        // 提取第一列做key
        if($mainTableFields){
            // 字符串分隔的话，处理成数组
            if(!is_array($mainTableFields)){
                $mainTableFields = explode(',',$mainTableFields);
            }
            // 拼接字段
            foreach($mainTableFields as $vv){
                $keyArr[] = 'b.'.$vv;
            }
        } else {
            $keyArr[] = 'b.*';
        }

        foreach($con as &$v){
            // 拼接查询条件
            if(! strstr ($v[0],'.')){
                $v[0] = 'a.'.$v[0];
            }
            $keyArr[] = $v[0];
        }
        $keyArr[] = 'a.'.$mainField;
        // 形如：'b.*,a.customer_id,a.user_id,a.is_manager,a.job,a.is_external'
        $fieldStr = implode(',',array_unique($keyArr));
        $lists = self::mainModel()->where($con)->alias('a')->join($mainTable.' b','a.'.$mainField.' = b.id')
                ->field($fieldStr)
                ->order($order)
                ->select();
        return $lists ? $lists->toArray() : [];
    }

    /**
     * 20230421 中间表添加绑定
     */
    public static function middleBindAdd($mainIds = [],$subIds = []){
        if(!$mainIds || !$subIds){
            return [];
        }
        if(!is_array($mainIds)){
            $mainIds = [$mainIds];
        }
        if(!is_array($subIds)){
            $subIds = [$subIds];
        }
        
        $mainField  = self::$middleFieldMapping[0];
        $subField   = self::$middleFieldMapping[1];
        
        $dataArr = [];
        foreach($mainIds as $mainId){
            foreach($subIds as $subId){
                $dataArr[] = [$mainField => $mainId, $subField=>$subId];
            }
        }
        // 写入数据库
        return self::saveAll($dataArr);
    }
    /**
     * 主字段是否有子字段关联记录
     */
    protected static function middleMainHasSub($mainId,$subId){
        $mainField  = self::middleGetMainField();
        $subField   = self::middleGetSubField();
        $con[] = [$mainField,'=',$mainId];
        $con[] = [$subField,'=',$subId];
        $info = self::middleFindWithStatic($con);
        return $info ? true : false;
    }
    /**
     * 主字段查询子字段列表数据
     */
    protected static function middleMainSubList($mainIds, $con = []){
        $mainField  = self::middleGetMainField();
        $con[] = [$mainField,'in',$mainIds];
        return self::middleSelectWithStatic($con);
    }
    
    /**
     * TODO:抽离到公共方法？？
     */
    private static function middleFindWithStatic($con){
        if (method_exists(__CLASS__, 'staticConFind')) {
            $info = self::staticConFind($con);
        } else {
            $info = self::where($con)->find();
        }
        return $info;
    }
    
    private static function middleSelectWithStatic($con){
        if (method_exists(__CLASS__, 'staticConList')) {
            $lists = self::staticConList($con);
        } else {
            $listsObj = self::where($con)->select();
            $lists = $listsObj ? $listsObj->toArray() : [];
        }
        return $lists;
    }
    /**
     * 绑定数据
     * @param type $mainField       主字段
     * @param type $mainIds         主字段id数组
     * @param type $subField        从字段
     * @param type $subIds          从字段id数组
     * @param type $clear           是否清理原有数据(主字段维度)
     */
    public static function middleBindRam($mainField,$mainIds, $subField, $subIds, $clear = false){
        // 【1】清理数据
        if($clear){
            $con = [];
            $con[] = [$mainField, 'in', $mainIds];
            $ids = self::where($con)->column('id');
            foreach($ids as $id){
                // 循环删除
                self::getInstance($id)->deleteRam();
            }
        }
        // 【2】处理数据
        if(!is_array($mainIds)){
            $mainIds = [$mainIds];
        }
        if(!is_array($subIds)){
            $subIds = [$subIds];
        }

        $dataArr = [];
        foreach($mainIds as $mainId){
            foreach($subIds as $subId){
                $dataArr[] = [$mainField => $mainId, $subField=>$subId];
            }
        }
        // 写入数据库
        return self::saveAllRam($dataArr);
    }
}
