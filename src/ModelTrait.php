<?php
namespace xjryanse\traits;

use xjryanse\logic\SnowFlake;
use xjryanse\system\service\SystemFileService;
use xjryanse\logic\DbOperate;
use xjryanse\logic\Debug;
use xjryanse\logic\ModelQueryCon;
use xjryanse\logic\Cachex;
use think\facade\Request;
use think\Model;

/**
 * 模型复用
 */
trait ModelTrait {
    /**
     * 不分页的列表
     *
     * @param array $con
     *
     * @return type
     */
    public function getList(array $con = [], $order = 'id desc') {
        return $this->where($con)->order($order)->select();
    }

    /**
     * 分页查询列表
     *
     * @param array $con      查询条件
     * @param int   $per_page 每页记录数
     */
    public function paginateList(array $con = [], $per_page = 5, $order = 'id desc') {
        $config = Request::param('page', 0) ? ['page' => Request::param('page', 0)] : [];
        return $this->where($con)->order($order)->paginate(intval($per_page), false, $config);
    }

    /**
     * 分页查询列表(针对非模型)
     *
     * @param array $query
     * @param array $con      查询条件
     * @param int   $per_page 每页记录数
     */
    public static function paginateListQuery($query, array $con = [], $per_page = 5, $order = 'id desc') {
        $config = Request::param('page', 0) ? ['page' => Request::param('page', 0)] : [];
        return $query->where($con)->order($order)->paginate(intval($per_page), false, $config);
    }

    /**
     * 校验是否在事务中
     */
    public static function inTransaction() {
        return self::getConnection()->connect()->inTransaction();
    }
    /**
     * 数据表是否存在某字段
     */
    public static function hasField( $fieldName )
    {
        $tableColumns   = DbOperate::columns(self::getTable());
        $fields    = array_column( $tableColumns,'Field');
//        $fields = self::getConnection()->getFields( self::getTable());
        return in_array($fieldName, $fields);
    }

    /**
     * 写入中间表方法（新增、更新时使用）
     *
     * @param array $data       一级数据列表
     * @param int   $id         一级记录id
     * @param Model $midModel   主模型实例      // admin_role_access
     * @param type  $fieldName  数据列字段名    //role_id
     * @param type  $mainName   主字段名        //role_id
     * @param type  $allowField 允许写入字段
     *
     * @return boolean
     */
    public static function writeMidData(array $data, $id, $midModel, $fieldName = '', $mainName = '', $allowField = []) {
        if (isset($data[$fieldName])) {
            $data = $data[$fieldName];
        }
        
        $con[] = [$mainName, '=', $id];     //主字段id
        $midModel->where($con)->delete();

        //在记录列表中的更新，没有记录列表的新增
        $list = [];
        foreach ($data as $k=>&$v) {
            if (!$v) {
                continue;
            }
            
            $list[$k][ $fieldName ] = $v;
            $list[$k][ $mainName ] = $id;
        }
//        dump($list);
        return $midModel->insertAll($list);
    }
    
    /**
     * 写入一对多表方法（新增、更新时使用）
     * @param array $data       一级数据列表
     * @param int   $id         一级记录id
     * @param Model $midModel   主模型实例      // admin_role_access
     * @param type  $fieldName  数据列字段名    //role_id
     * @param type  $mainName   主字段名        //role_id
     * @param type  $allowField 允许写入字段
     *
     * @return boolean
     */
    public static function writeHasManyData(array $data, int $id, $midModel, $fieldName = '', $mainName = '', $keyField = '') {
        if (isset($data[$fieldName])) {
            $data = $data[$fieldName];
        }
        $data[ $mainName ] = $id;
        $data[ $keyField ] = $fieldName;
        //一个主键id，一个key，进行查重更新
        $con[] = [ $mainName , '=' , $id ];
        $con[] = [ $keyField , '=' , $fieldName ];

        if( $data['id'] && $midModel->where($con)->find()){
            return $midModel->where($con)->update( $data );
        } else {
            if(isset($data['id'])){
                unset( $data['id']);
            }
            return $midModel->insertGetId( $data );
        }
    }    

    /**
     * 记录锁定
     */
    public static function lock( $ids )
    {
        $con[] = ['id','in',$ids];
        return self::where( $con )->update('is_lock',1);
    }
    /**
     * 记录解锁
     * @param type $ids
     * @return type
     */
    public static function unlock( $ids )
    {
        $con[] = ['id','in',$ids];
        return self::where( $con )->update('is_lock',0);
    }
    
    public static function isLocked( $ids )
    {
        $con[] = ['id','in',$ids];
        $con[] = ['is_lock','=',1];
        return self::where( $con )->count();
    }
    
    /**
     * 生成新id
     */
    public static function newId()
    {
        $newId = SnowFlake::generateParticle();
        return strval($newId);
    }

    /**
     * 图片修改器取值
     */
    public static function setImgVal( $value )
    {
        // || is_object($value)
        if($value && is_array($value)){
            //isset( $value['id']):单图；否则：多图
            //对象转成数组，比较好处理
            $value = isset( $value['id']) ? $value['id']  : implode(',',array_column($value, 'id'));
        }
        return $value;
    }
    /**
     * 时间修改器
     */
    public static function setTimeVal( $value )
    {
        return $value && strtotime($value) > 0 ? $value : null;
    }    
    /**
     * 图片获取器取值
     * @param type $value   值
     * @param type $isMulti 是否多图
     * @return type
     */
    public static function getImgVal( $value ,$isMulti = false)
    {
        if(!$value){
            return $value;
        }
        if($isMulti){
            $ids    = is_array($value) ? $value : explode( ',', $value );
            $con[]  = ['id','in', $ids ];
            $res = SystemFileService::mainModel()->where( $con )->field('id,file_type,file_path,file_path as rawPath')->cache(86400)->select();
            Debug::debug('获取图片地址Sql',SystemFileService::mainModel()->getLastSql());
            return $res ? $res->toArray() : $value;
        } else {
            Debug::debug('获取图片',$value);
            return Cachex::funcGet('FileData_'.$value, function() use ($value){
                $info = SystemFileService::mainModel()->where('id', $value )->field('id,file_type,file_path,file_path as rawPath')->cache(86400)->find();
                Debug::debug('获取图片地址',$info);
                return $info ? $info->toArray() : $value;
            });
        }
    }
    /**
     * 获取数据表前缀
     * @return type
     */
    public static function getPrefix() {
        $config = self::getConnection()->getConfig();
        return isset($config['prefix']) ? $config['prefix'] : '';
    }
    
    /**
     * 模型类对应的基础表
     * @return type
     */
    public function baseTableName()
    {
        $classShortName = (new \ReflectionClass( __CLASS__ ))->getShortName();
        return $this->getPrefix() .lcfirst(uncamelize($classShortName));
    }
    /*
     * 以基础表进行条件查询
     */
    public function baseTableSql( $con ,$field="*" )
    {
        $tableCon = $this->tableConFilt($con);
        return $this->where($tableCon)->field($field)->buildSql();
    }
    /**
     * $con 按表名过滤
     */
    public function tableConFilt( $con )
    {
        $this->table = $this->baseTableName( );
        $tableCon = [];
        foreach( $con as $value){
            if($this->hasField($value[0])){
                $tableCon[] = $value;
            }
        }
        return $tableCon;
    }
    /**
     * 关联设定字段
     * @param type $tableArr    
        $tableArr[] = ['table'=>'ydzb_goods_trade_mark' ,'mainField'=>'goods_table_id'  ,'tableField'=>'id']; 
        $tableArr[] = ['table'=>'ydzb_goods_tm_rent'    ,'mainField'=>'id'              ,'tableField'=>'id'];
     * @param type $con
     * @return string
     */
    public function setTable($tableArr = [],$con = [])
    {
        $fieldStrAll    = "alias.* ";
        //主表
        $tableStrMain   = $this->baseTableName( ) ." as alias ";
        //关联子表
        $tableSubStr    = "";
        $onStrArr       = [];
        //分表关联查询on条件
        $subTableConStr = '';
        
        foreach( $tableArr as $key=>$table){
            $alias          = "alias".$key;
            $service        = DbOperate::getService($table['table']);
            $tableSql       = $service::mainModel()->baseTableName( );
            
            $tableFields    = DbOperate::fieldsExceptByTable( $table['table'] , $this->baseTableName());
            //没有字段
            if(!$tableFields){
                continue;
            }
            $fieldStr       = DbOperate::fieldsAliasStr( $tableFields , $alias); 
            
            $fieldStrAll    .= ",".$fieldStr;
            $tableSubStr    .= " inner join " . $tableSql . " as " .$alias;
            $onStrArr[]      = " alias.". $table['mainField'] ." = " . $alias . "." .$table['tableField'];
            
            $subTableCon    = $service::mainModel()->tableConFilt($con);
            $subTableConStr .= ' and '.ModelQueryCon::conditionParse($subTableCon, $alias);                
        }
        if(!$tableSubStr){
            $this->table = $this->baseTableName( );
            return $this->table;
        }
        $onStrAll = implode(' and ',$onStrArr);
        $sql  = "(select ". $fieldStrAll ." from ".$tableStrMain.$tableSubStr ;
        if ( $onStrAll . $subTableConStr ){
            $sql    .= " on ".$onStrAll . $subTableConStr;
        }
        $sql    .= ") as eee";
        
        $this->table = $sql;
        return $sql;
    }
}
