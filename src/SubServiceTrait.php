<?php
namespace xjryanse\traits;

use xjryanse\logic\DbOperate;
/**
 * 分表服务类复用：只能用于模型对应service类中。
 * 依赖于MainModelTrait类库
 */
trait SubServiceTrait {
    /**
     * 【分表模型服务类用】取分表字段
     * @return type
     */
    public function getSubFieldData( )
    {
        $info = self::mainModel()->where('id',$this->uuid)->field( implode(',',self::$subFields))->find();
        return $info ? $info->toArray() : [];
    }
    
    /**
     * 【主表模型服务类用】获取分表服务类
     * @param type $type
     * @return type
     */
    protected static function getSubService( $type )
    {
        //分表名称
        $subTableName = self::mainModel()->getTable().'_'.$type;
        //分表类库
        return DbOperate::getService( $subTableName );
    }    
    /**
     * 【主表模型服务类用】添加分表数据
     */
    public static function addSubData( object &$item , $type )
    {
        //添加形如：表名.字段名的数据
        $subService = self::getSubService( $type );
        return self::addSubServiceData($item, $subService, $item->id);
    }
    /**
     * 
     * @param object $item      数据项
     * @param type $subService  模型服务类
     * @param type $id          模型服务类的数据id
     */
    private static function addSubServiceData( object &$item, $subService, $id )
    {
        if(class_exists($subService) && method_exists($subService, 'getSubFieldData')){
            $subInfos = $subService::getInstance( $id )->getSubFieldData();
            foreach($subInfos as $k=>$v){
                $item->$k = $v;
            }
        }
        return $item;        
    }
}
