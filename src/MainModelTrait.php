<?php
namespace xjryanse\traits;

use Exception;
/**
 * 主模型复用
 */
trait MainModelTrait {
    //复用类需实现
//    protected static $mainModelClass;
    
    protected static $mainModel;
    
    public static function mainModel() 
    {
        //实现一下获取主模型
        if(!self::$mainModel){
            self::$mainModel = new self::$mainModelClass();
        }
        return self::$mainModel;             
    }
    
    public function __call($method,$ages){
        //首字母f，且第二个字母大写，表示字段
        
        return $method .'不存在';
    }    

    /**************************操作方法********************************/
    public static function save( array $data)
    {
        if(!isset($data['id'])|| !$data['id']){
            $data['id'] = self::mainModel()->newId();
        }
        if( session('scopeCompanyId') && !isset($data['company_id'])){
            $data['company_id'] = session('scopeCompanyId');
        }
        if( session('scopeAppId') && !isset($data['app_id'])){
            $data['app_id'] = session('scopeAppId');
        }
        return self::mainModel()->create( $data );

    }
    
    public function update( array $data )
    {
        if(!$this->get()){
            throw new Exception('记录不存在');
        }
        if(!isset($data['id']) || !$data['id']){
            $data['id'] = $this->uuid;
        }
        
        return self::mainModel()->update( $data );
    }
    
    public function delete()
    {
        if(!$this->get()){
            throw new Exception('记录不存在');
        }
        
        return self::mainModel()->where('id',$this->uuid)->delete( );
    }    
    /**************************查询方法********************************/
    public static function lists( $con = [],$order='')
    {
        if(self::mainModel()->hasField('app_id')){
            $con[] = ['app_id','=',session('scopeAppId')];
        }
        if( !$order && self::mainModel()->hasField('sort')){
            $order = "sort";
        }
        return self::mainModel()->where( $con )->order($order)->cache(2)->select();
    }
    /**
     * 分页的查询
     * @param type $con
     * @param type $order
     * @param type $perPage
     * @return type
     */
    public static function paginate( $con = [],$order='',$perPage=10)
    {
        if(self::mainModel()->hasField('app_id')){
            $con[] = ['app_id','=',session('scopeAppId')];
        }
//        dump(self::mainModel()->hasField('app_id'));
        return self::mainModel()->where( $con )->order($order)->cache(2)->paginate( intval($perPage) );
    }    
    /**
     * 自带当前公司的列表查询
     * @param type $con
     * @return type
     */
    public static function listsCompany( $con = [])
    {
        if(self::mainModel()->hasField('app_id')){
            $con[] = ['app_id','=',session('scopeAppId')];
        }
        $con[] = ['company_id','=',session('scopeCompanyId')];
        return self::mainModel()->where( $con )->cache(2)->select();
    }
    
    /**
     * 带详情的列表
     * @param type $con
     */
    public static function listsInfo( $con = [])
    {
        return self::lists( $con );
    }
    /**
     * id数组
     * @param type $con
     * @return type
     */
    public static function ids( $con = [])
    {
        if(self::mainModel()->hasField('app_id')){
            $con[] = ['app_id','=',session('scopeAppId')];
        }
        return self::mainModel()->where( $con )->cache(2)->column('id');
    }
    /**
     * 根据条件返回字段数组
     * @param type $field   字段名
     * @param type $con     查询条件
     * @return type
     */
    public static function column( $field,$con = [])
    {
        if(self::mainModel()->hasField('app_id')){
            $con[] = ['app_id','=',session('scopeAppId')];
        }
        return self::mainModel()->where( $con )->cache(2)->column($field);
    }    
    
    /**
     * 条件计数
     * @param type $con
     * @return type
     */
    public static function count( $con = [])
    {
        if(self::mainModel()->hasField('app_id')){
            $con[] = ['app_id','=',session('scopeAppId')];
        }
        return self::mainModel()->where( $con )->cache(2)->count(  );
    }    
    /**
     * 条件计数
     * @param type $con
     * @return type
     */
    public static function sum( $con = [],$field = '')
    {
        if(self::mainModel()->hasField('app_id')){
            $con[] = ['app_id','=',session('scopeAppId')];
        }
        return self::mainModel()->where( $con )->cache(2)->sum( $field );
    }    
    /**
     * 
     * @param type $cache   cache为0，直接读数据库
     * @return type
     */
    public function get( $cache = 5 )
    {
        $inst = self::mainModel()->where('id',$this->uuid);
        return $cache 
                ? $inst->cache( $cache )->find() 
                : $inst->find();
    }
    
    public function info( $cache = 5  )
    {
        $info = self::mainModel()->where('id',$this->uuid)->cache( $cache )->find();
        return $info;
    }
    /**
     * 按条件查询单条数据
     * @param type $con
     * @param type $cache
     * @return type
     */
    public static function find( $con = [],$cache=5)
    {
        if(self::mainModel()->hasField('app_id')){
            $con[] = ['app_id','=',session('scopeAppId')];
        }
        $inst = self::mainModel()->where( $con );
        return $cache
                ? $inst->cache( $cache )->find()
                : $inst->find();
    }
    /**
     * 末条记录id
     * @return type
     */
    public static function lastId()
    {
        return self::mainModel()->order('id desc')->value('id');
    }
    
    /**************************校验方法********************************/
    /**
     * 校验事务是否处于开启状态
     * @throws Exception
     */
    protected static function checkTransaction(){
        if(!self::mainModel()->inTransaction()){
            throw new Exception('请开启数据库事务');
        }
    }
    /**
     * 校验是否当前公司数据
     * @throws Exception
     */
    protected static function checkCurrentCompany( $companyId ){
        //当前无session，或当前session与指定公司id不符
        if( !session('scopeCompanyId') || session('scopeCompanyId') != $companyId ){
            throw new Exception('未找到数据项~~');
        }
    }
}