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
        if( session(SESSION_COMPANY_ID) && !isset($data['company_id'])){
            $data['company_id'] = session(SESSION_COMPANY_ID);
        }
        if( session(SESSION_APP_ID) && !isset($data['app_id'])){
            $data['app_id'] = session(SESSION_APP_ID);
        }
        if( session(SESSION_USER_ID) && !isset($data['creater'])){
            $data['creater'] = session(SESSION_USER_ID);
        }
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');

        return self::mainModel()->create( $data );
    }
    /*
     * 批量保存
     */
    public static function saveAll ( array $data )
    {
        $tmpArr = [];
        foreach( $data as $v){
            $tmpData        = $v ;
            if(!isset($tmpData['id'])|| !$tmpData['id']){
                $tmpData['id'] = self::mainModel()->newId();
            }
            if( session(SESSION_COMPANY_ID) && !isset($tmpData['company_id']) ){
                $tmpData['company_id'] = session(SESSION_COMPANY_ID);
            }
            if( session(SESSION_APP_ID) && !isset($tmpData['app_id']) ){
                $tmpData['app_id'] = session(SESSION_APP_ID);
            }
            if( session(SESSION_USER_ID) && !isset($tmpData['creater']) ){
                $tmpData['creater'] = session(SESSION_USER_ID);
            }

            $tmpData['create_time'] = date('Y-m-d H:i:s');
            $tmpData['update_time'] = date('Y-m-d H:i:s');
            
            $tmpArr[] = $tmpData ;
        }
        //saveAll方法新增数据默认会自动识别数据是需要新增还是更新操作，当数据中存在主键的时候会认为是更新操作，如果你需要带主键数据批量新增，可以使用下面的方式
        return self::mainModel()->saveAll( $tmpArr ,false );
    }
    /**
     * 关联表数据保存
     * @param type $mainField   主字段
     * @param type $mainValue   主字段值
     * @param type $arrField    数组字段
     * @param type $arrValues   数组值：一维数据写入数组字段，二维数据直接存储
     */
    public static function midSave( $mainField, $mainValue, $arrField, $arrValues )
    {
        self::checkTransaction();

        $con[]      = [ $mainField ,'=', $mainValue ];
        self::mainModel()->where( $con )->delete();
        $tmpData    = [];
        foreach( $arrValues as $value ){
            if(is_array( $value )){
                $tmp = $value;
            } else {
                $tmp = [];
                $tmp[ $arrField ]   =   $value;
            }
            $tmp[ $mainField ]  =   $mainValue ;
            $tmpData[] = $tmp; 
        }
        return self::saveAll( $tmpData );
    }
    /**
     * 更新
     * @param array $data
     * @return type
     * @throws Exception
     */
    public function update( array $data )
    {
        if(!$this->get()){
            throw new Exception('记录不存在');
        }
        if(!isset($data['id']) || !$data['id']){
            $data['id'] = $this->uuid;
        }
        if( session(SESSION_USER_ID) && !isset($data['updater']) ){
            $data['updater'] = session(SESSION_USER_ID);
        }
        $data['update_time'] = date('Y-m-d H:i:s');

        return self::mainModel()->update( $data );
    }
    /*
     * 设定字段的值
     * @param type $key     键
     * @param type $value   值
     */
    public function setField($key,$value)
    {
        return $this->update([$key=>$value]);
    }

    /*
     * 设定字段的值
     * @param type $key         键
     * @param type $preValue    原值
     * @param type $aftValue    新值
     * @return type
     */
    public function setFieldWithPreValCheck($key,$preValue,$aftValue)
    {
        $info = $this->get(0);
        if($info[$key] != $preValue){
            throw new Exception( self::mainModel()->getTable().'表'. $this->uuid.'记录' 
                    .$key .'的原值不是'. $preValue );
        }
        $con[] = [ $key ,'=',$preValue];
        $con[] = [ 'id' ,'=',$this->uuid ];
        return self::mainModel()->where( $con )->update([$key=>$aftValue]);
    }
    
    public function delete()
    {
        if(!$this->get()){
            throw new Exception('记录不存在');
        }
        
        return self::mainModel()->where('id',$this->uuid)->delete( );
    }    
    /**************************查询方法********************************/
    public static function lists( $con = [],$order='',$field="*")
    {
        if(self::mainModel()->hasField('app_id')){
            $con[] = ['app_id','=',session(SESSION_APP_ID)];
        }
        if( !$order && self::mainModel()->hasField('sort')){
            $order = "sort";
        }
        return self::mainModel()->where( $con )->order($order)->field($field)->cache(2)->select();
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
            $con[] = ['app_id','=',session(SESSION_APP_ID)];
        }
//        dump(self::mainModel()->hasField('app_id'));
//        return self::mainModel()->where( $con )->order($order)->cache(2)->paginate( intval($perPage) );
        $res = self::mainModel()->where( $con )->order($order)->cache(2)->paginate( intval($perPage) );
        return $res ? $res->toArray() : [] ;        
    }    
    /**
     * 自带当前公司的列表查询
     * @param type $con
     * @return type
     */
    public static function listsCompany( $con = [],$order='',$field="*")
    {
        if(self::mainModel()->hasField('app_id')){
            $con[] = ['app_id','=',session(SESSION_APP_ID)];
        }
        $con[] = ['company_id','=',session(SESSION_COMPANY_ID)];
        if( !$order && self::mainModel()->hasField('sort')){
            $order = "sort";
        }
        return self::mainModel()->where( $con )->order($order)->field($field)->cache(2)->select();
    }
    
    /**
     * 带详情的列表
     * @param type $con
     */
    public static function listsInfo( $con = [])
    {
        return self::lists( $con );
    }
    /*
     * 按字段值查询数据
     * @param type $fieldName   字段名
     * @param type $fieldValue  字段值
     * @param type $con         其他条件
     * @return type
     */
    public static function listsByField( $fieldName, $fieldValue, $con = [] )
    {
        $con[] = [ $fieldName , '=',$fieldValue ];
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
            $con[] = ['app_id','=',session(SESSION_APP_ID)];
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
            $con[] = ['app_id','=',session(SESSION_APP_ID)];
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
            $con[] = ['app_id','=',session(SESSION_APP_ID)];
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
            $con[] = ['app_id','=',session(SESSION_APP_ID)];
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
            $con[] = ['app_id','=',session(SESSION_APP_ID)];
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
    public static function checkTransaction(){
        if(!self::mainModel()->inTransaction()){
            throw new Exception('请开启数据库事务');
        }
    }
    /**
     * 校验是否当前公司数据
     * @throws Exception
     */
    public static function checkCurrentCompany( $companyId ){
        //当前无session，或当前session与指定公司id不符
        if( !session(SESSION_COMPANY_ID) || session(SESSION_COMPANY_ID) != $companyId ){
            throw new Exception('未找到数据项~~');
        }
    }
}
