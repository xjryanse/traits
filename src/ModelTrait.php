<?php
namespace xjryanse\traits;

use xjryanse\logic\SnowFlake;
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
    public static function PaginateListQuery($query, array $con = [], $per_page = 5, $order = 'id desc') {
        $config = Request::param('page', 0) ? ['page' => Request::param('page', 0)] : [];
        return $query->where($con)->order($order)->paginate(intval($per_page), false, $config);
    }

    /**
     * 查询条件封装
     *
     * @param array $param  参数列表
     * @param array $fields 字段列表    key值0:精确查找;1:模糊查找;2:in查找;3:数据范围查找;4:时间查找
     *                      示例
     *                      $param=['hello'=>'111','like'=>'222','timea'=>4445,'timeb'=>7859,]
     *                      $fields[0] = ['hi'=>'hello','like'=>'like'];
     *                      $fields[1] = ['like'=>'like'];
     *                      $fields[3] = ['num'=>['3','5']];
     */
    public function queryCon(array $param, array $fields) {
        $con = [];
        //遍历每个字段列表
        foreach ($fields as $k => &$v) {
            //键对应查询条件：0精确查找、1模糊查找、2in查找
            //值为数组。值键对应数据库字段，值值对应参数组键名
            foreach ($v as $key => &$value) {
                if (is_int($key)) {
                    $key = $value;
                }
                //解析成where;
                $tmp = $this->condition($k, $key, $param, $value);
                if ($tmp) {
                    $con = array_merge($con, $tmp);
                }
            }
        }
        return $con;
    }

    /**
     * 查询条件预封装
     *
     * @param int|string $k     0:精确查找;1:模糊查找;2:in查找;3:数据范围查找;
     * @param string     $key   数据库字段名
     * @param array      $param 入参数组
     * @param string     $value 入参字段名
     */
    private function condition($k, $key, &$param, $value) {
        $con = [];
        switch ($k) {
            case 0: //精确查找
                if (isset($param[$value]) && strlen($param[$value])) {
                    $con[] = [$key, '=', $this->preg($param[$value])];
                }
                break;
            case 1: //模糊查找
                if (isset($param[$value]) && strlen($param[$value])) {
                    $con[] = [$key, 'like', '%' . $this->preg($param[$value]) . '%'];
                }
                break;
            case 2: //in查找
                if (isset($param[$value])) {
                    $con[] = [$key, 'in', $param[$value]];
                }
                break;
            case 3: //数据范围查找
                if (isset($param[$value][0]) && strlen($param[$value][0])) {
                    $con[] = [$key, '>=', $param[$value][0]];
                }
                if (isset($param[$value][1]) && strlen($param[$value][1])) {
                    $con[] = [$key, '<=', $param[$value][1]];
                }
                break;
            case 4: //时间范围查询
                if (isset($param[$value][0]) && strlen($param[$value][0])) {
                    $param[$value][0] = date('Y-m-d 00:00:00', strtotime($param[$value][0]));
                    $con[] = [$key, '>=', $param[$value][0]];
                }
                if (isset($param[$value][1]) && strlen($param[$value][1])) {
                    $param[$value][1] = date('Y-m-d 23:59:59', strtotime($param[$value][1]));
                    $con[] = [$key, '<=', $param[$value][1]];
                }
                break;
            case 5: //not in查询
                if (isset($param[$value])) {
                    $con[] = [$key, 'not in', $param[$value]];
                }
                break;
            case 6: //not in查询
                if (isset($param[$value])) {
//					$con[] = [$key, 'FIND_IN_SET', $param[$value]];
                    $con[] = ['', "FIND_IN_SET('" . $param[$value] . "'," . $key . ')', ''];
                }
                break;
            default:
        }
        return $con;
    }

    /**
     * 正则替换防注入
     *
     * @param type $str
     *
     * @return type
     */
    private function preg($str) {
        return preg_replace("/\+|\`|\*|\-|\$|\#|\^|\!|\@|\%|\&|\~|\[|\]|\,|\'|\s|/", "", $str);
    }

    /**
     * 是否Y-m-d日期
     *
     * @param $str 待检查字符串
     *
     * @return bool
     */
    private function isDate($str) {
        if (preg_match("/^((?:19|20)\d\d)-(0[1-9]|1[012])-(0[1-9]|[12][0-9]|3[01])$/", $str)) {
            return true;
        } else {
            return false;
        }
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
        $fields = self::getConnection()->getFields( self::getTable());
        return isset($fields[$fieldName]);
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
     * 条件拆解成and 连接
     */
    public static function conditionParse( $con )
    {
        //条件参数的形式：$con[] = ['aa','=','bb'];
        $condition = [];
        foreach($con as $v){
            $v[2] = '\''.$v[2].'\'';
            $condition[] = implode(' ',$v);
        }
        //将参数组装成 and 连接，没有条件时，丢个1，兼容where 
        $conStr = $condition ? implode( ' and ',$condition ) : 1 ;         
        return $conStr;
    }
    
    /**
     * 生成新id
     */
    public static function newId()
    {
        $newId = SnowFlake::generateParticle();
        return strval($newId);
    }        
}
