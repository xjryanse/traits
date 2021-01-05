<?php
namespace xjryanse\traits;

use xjryanse\user\logic\AuthLogic;
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
    /**
     * 缓存数据更新
     */
    protected static function _cacheUpdate( $id )
    {
        self::getInstance( $id )->get(0);
    }
    /**
     * 获取缓存key
     * @param type $id
     * @return type
     */
    protected static function _cacheKey( $id )
    {
        $table = self::mainModel()->getTable();
        return $table.$id;
    }
    //公共的数据过滤条件
    protected static function commCondition()
    {
        $con    = session(SESSION_USER_ID) 
                ? AuthLogic::dataCon( session(SESSION_USER_ID) , self::mainModel()->getTable())
                : [] ;
        //应用id
        if( self::mainModel()->hasField('app_id') ){
            $con[] = ['app_id','=',session(SESSION_APP_ID)];
        }
        
        return $con;
    }
    /**
     * 预保存数据
     */
    protected static function preSaveData( &$data )
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
        return $data;
    }
    /*****公共保存【外部有调用】*****/
    protected static function commSave( $data ){
        //预保存数据
        self::preSaveData($data);
        //保存
        $res = self::mainModel()->create( $data );
        if($res){
            self::_cacheUpdate($res['id']);
        }
        return $res;        
    }
    /**
     * 公共更新【外部有调用】
     * @param array $data
     * @return type
     * @throws Exception
     */
    protected function commUpdate( array $data )
    {
        $info = $this->get();
        if(!$info){
            throw new Exception(self::mainModel()->getTable().'表'.$this->uuid.'记录不存在');
        }
        if(isset($info['is_lock']) && $info['is_lock']){
            throw new Exception(self::mainModel()->getTable().'表'.$this->uuid.'记录已锁定，确需更改请联系管理员解锁');
        }
        if(!isset($data['id']) || !$data['id']){
            $data['id'] = $this->uuid;
        }
        if( session(SESSION_USER_ID) && !isset($data['updater']) ){
            $data['updater'] = session(SESSION_USER_ID);
        }
        $data['update_time'] = date('Y-m-d H:i:s');

        $res = self::mainModel()->update( $data );
        if($res){
            self::_cacheUpdate( $this->uuid );
        }
        return $res;
    }
    /**
     * 公共删除【外部有调用】
     * @return type
     * @throws Exception
     */
    protected function commDelete()
    {
        if(!$this->get()){
            throw new Exception(self::mainModel()->getTable().'表'.$this->uuid.'记录不存在');
        }
        $res = self::mainModel()->where('id',$this->uuid)->delete( );
        if($res){
            self::_cacheUpdate( $this->uuid );
        }        
        return $res;
    }
    
    /**************************操作方法********************************/
    public static function save( array $data)
    {
        return self::commSave($data);
    }
    /*
     * 批量保存
     */
    public static function saveAll ( array $data )
    {
        $tmpArr = [];
        foreach( $data as $v){
            $tmpData        = $v ;
            //预保存数据
            $tmpArr[] = self::preSaveData( $tmpData ); ;
        }
        //saveAll方法新增数据默认会自动识别数据是需要新增还是更新操作，当数据中存在主键的时候会认为是更新操作，如果你需要带主键数据批量新增，可以使用下面的方式
        $res = self::mainModel()->saveAll( $tmpArr ,false );
        foreach( $tmpArr as $v){
            if(isset($v['id']) && $v['id']){
                self::_cacheUpdate($v['id']);
            }
        }
        return $res;
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
        //预保存数据
        return $this->commUpdate($data);
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
    /**
     * 字段名取值
     * @param type $fieldName   字段名
     * @param type $default     默认值
     * @return type
     */
    public function fieldValue( $fieldName, $default='')
    {
        $info = $this->get();
        return ($info && isset($info[ $fieldName ])) 
            ? $info[ $fieldName ] 
            : $default;
    }
    /**
     * 获取f开头的驼峰方法名字段信息
     * @param type $functionName  方法名，一般__FUNCTION__即可
     * @param type $prefix          前缀
     * @return type
     */
    public function getFFieldValue( $functionName ,$prefix="f_")
    {
        //驼峰转下划线，再去除前缀
        $pattern = '/^'. $prefix .'/i';
        $fieldName = preg_replace($pattern, '', uncamelize( $functionName ));
        //调用MainModelTrait中的字段值方法
        return $this->fieldValue($fieldName);
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
        $res = self::mainModel()->where( $con )->update([$key=>$aftValue]);
        if($res){
            self::_cacheUpdate( $this->uuid );
        }
        //更新缓存
        return $res;
    }
    
    public function delete()
    {
        return $this->commDelete();
    }    
    /**************************查询方法********************************/
    public static function lists( $con = [],$order='',$field="*")
    {
        $conAll = array_merge( $con ,self::commCondition() );
        if( !$order && self::mainModel()->hasField('sort')){
            $order = "sort";
        }
        return self::mainModel()->where( $conAll )->order($order)->field($field)->cache(2)->select();
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
        $conAll = array_merge( $con ,self::commCondition() );

        $res = self::mainModel()->where( $conAll )->order($order)->cache(2)->paginate( intval($perPage) );
        return $res ? $res->toArray() : [] ;        
    }
    /**
     * 自带当前公司的列表查询
     * @param type $con
     * @return type
     */
    public static function listsCompany( $con = [],$order='',$field="*")
    {
        //公司id
        if( self::mainModel()->hasField('company_id') && session( SESSION_COMPANY_ID ) ){
            $con[] = ['company_id','=',session( SESSION_COMPANY_ID )];
        }
        $conAll = array_merge( $con ,self::commCondition() );
        
        if( !$order && self::mainModel()->hasField('sort')){
            $order = "sort";
        }
        return self::mainModel()->where( $conAll )->order($order)->field($field)->cache(2)->select();
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
        return self::mainModel()->where( $con )->count(  );
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
        return self::mainModel()->where( $con )->sum( $field );
    }    
    /**
     * 
     * @param type $cache   cache为0，直接读数据库
     * @return type
     */
    public function get( $cache = 5 )
    {
        if( $cache && cache(self::_cacheKey($this->uuid)) ){
            return cache(self::_cacheKey($this->uuid));
        }
        $res = self::mainModel()->where('id',$this->uuid)->find();
        //存缓存
        if($res){
            cache(self::_cacheKey($this->uuid),$res);
        }
        return $res;
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
