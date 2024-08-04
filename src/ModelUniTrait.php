<?php
namespace xjryanse\traits;

use xjryanse\logic\Arrays;
use xjryanse\logic\Debug;
use xjryanse\logic\DbOperate;
use xjryanse\logic\Strings;
use xjryanse\logic\ModelQueryCon;
/**
 * 模型联动字段复用
 */
trait ModelUniTrait {
    /**
     * 20230516:关联字段数据
        array(1) {
            [0] => array(6) {
              ["field"] => string(7) "user_id"
              ["uni_field"] => string(2) "id"
              ["del_check"] => bool(true)
              ["thisTable"] => string(7) "w_order"
              ["uniTable"] => string(6) "w_user"
            }
        }
     */
    private static function privateUniFieldsArr(){
        // if(!property_exists($class, $property))
        if (!property_exists(__CLASS__, 'uniFields')) {
            return [];
        }
        $prefix     = config('database.prefix');
        $uniFields  = self::$uniFields;
        foreach($uniFields as &$v){
            $v['thisTable']     = self::getTable();
            $v['uniTable']      = $prefix. Arrays::value($v, 'uni_name');
            // 20230516：联动字段默认用id
            $v['uni_field']     = Arrays::value($v, 'uni_field','id');
            // 20230516：删除限制默认否
            $v['del_check']     = Arrays::value($v, 'del_check',false);
            // 删除消息
            $v['del_msg']       = Arrays::value($v, 'del_msg') ? : '数据在'.$v['thisTable'].'表'.$v['field'].'字段使用，不可删';

            // 20230608：是否处理关联属性中
            $v['in_list']       = Arrays::value($v, 'in_list', true);
            // 20230608：是否处理统计数据
            $v['in_statics']    = Arrays::value($v, 'in_statics', true);
            // 20230608：是否在列表中
            $v['in_exist']      = Arrays::value($v, 'in_exist', true);
            // 20230608:是否存在
            $v['existField']    = Arrays::value($v, 'exist_field') ? : DbOperate::fieldNameForExist($v['field']);
            // uniTable表的属性字段
            $classShortName     = (new \ReflectionClass(__CLASS__))->getShortName();
            $v['property']      = Arrays::value($v, 'property') ? : lcfirst($classShortName);
            // 匹配条件
            $conditionRaw       = Arrays::value($v, 'condition', []);
            $condStr            = json_encode($conditionRaw, JSON_UNESCAPED_UNICODE);
            $condJson           = Strings::dataReplace($condStr, $v);
            $condArr            = json_decode($condJson, JSON_UNESCAPED_UNICODE);
            $v['condition']     = $condArr ;
            // 没有用的字段
            unset($v['uni_name']);
            ////
        }

        return $uniFields;
    }

    private static function privateUniRevFieldsArr(){
        // if(!property_exists($class, $property))
        if (!property_exists(__CLASS__, 'uniRevFields')) {
            return [];
        }
        $prefix     = config('database.prefix');
        $uniFields  = self::$uniRevFields;
        foreach($uniFields as &$v){
            $v['thisTable']     = $prefix. Arrays::value($v, 'table');
            $v['uniTable']      = self::getTable();
            // 20230516：联动字段默认用id
            $v['uni_field']     = Arrays::value($v, 'uni_field','id');
            // 20230516：删除限制默认否
            $v['del_check']     = Arrays::value($v, 'del_check',false);
            // 删除消息
            $v['del_msg']       = Arrays::value($v, 'del_msg') ? : '数据在'.$v['thisTable'].'表'.$v['field'].'字段使用，不可删';
            // 20230608：是否处理关联属性中
            $v['in_list']       = Arrays::value($v, 'in_list', true);
            // 20230608：是否处理统计数据
            $v['in_statics']    = Arrays::value($v, 'in_statics', true);
            // 20230608：是否在列表中
            $v['in_exist']      = Arrays::value($v, 'in_exist', true);
            // 20230608:是否存在
            $v['existField']    = Arrays::value($v, 'exist_field') ? : DbOperate::fieldNameForExist($v['field']);
            // uniTable表的属性字段
            // $classShortName     = (new \ReflectionClass(__CLASS__))->getShortName();
            $v['property']      = Arrays::value($v, 'property') ? : Strings::camelize(Arrays::value($v, 'table'));
            // 匹配条件
            $conditionRaw       = Arrays::value($v, 'condition', []);
            $condStr            = json_encode($conditionRaw, JSON_UNESCAPED_UNICODE);
            $condJson           = Strings::dataReplace($condStr, $v);
            $condArr            = json_decode($condJson, JSON_UNESCAPED_UNICODE);
            $v['condition']     = $condArr ;            
            // 没有用的字段
            unset($v['uni_name']);
            ////
        }

        return $uniFields;
    }
    
    public static function uniFieldsArr(){
        $arr1 = self::privateUniFieldsArr();
        $arr2 = self::privateUniRevFieldsArr();
        
        return array_merge($arr1, $arr2);
    }
    /**
     * 20230609：根据查询条件，设定关联表
     *  结果形如：
SELECT
	asystemCompanyDept.*
FROM
	(
	SELECT
		acircuit.*
	FROM
		w_circuit_bus AS acircuit
		LEFT JOIN w_circuit AS bcircuit ON acircuit.circuit_id = bcircuit.id 
	WHERE
		bcircuit.id IS NOT NULL 
	) AS asystemCompanyDept
	LEFT JOIN w_system_company_dept AS bsystemCompanyDept ON asystemCompanyDept.dept_id = bsystemCompanyDept.id 
WHERE
	bsystemCompanyDept.id IS NULL
     */
    public function uniSetTable($con = []){
        $list   = self::$uniFields;
        // 20240707尝试
        // $list   = self::uniFieldsArr();
        
        $tableRaw  = self::getTable();
        // 20231020:因别名冲突，故去除最外层括号后重新添加。
        $table = Strings::isStartWith($tableRaw, '(') 
                ? '('.Strings::getInnerBlank($tableRaw) .')'
                : $tableRaw;
//        if(Debug::isDevIp()){
//            dump($con);exit;
//        }
        // 20230609:有关联查询
        $hasUni = false;
        foreach($list as $v){
            // 单字段
            $existField = Arrays::value($v, 'exist_field') ? : DbOperate::fieldNameForExist($v['field']);
            // 20231113:增加关联查询映射字段数组:见FinanceStaffFee
            $reflectFields  = Arrays::value($v, 'reflect_field') ? : [];
            // 一维数组：['hasStatement','hasSettle']
            $reflectKeys    = array_keys($reflectFields);
            // if(!ModelQueryCon::hasKey($con, $existField)){
            if(!ModelQueryCon::containKey($con, array_merge($reflectKeys,[$existField]))){
                continue;
            }
            $hasUni = true;
            // 【】设置别名
            $tA     = $table;

            $tB     = DbOperate::prefix().$v['uni_name'];
            $tBService = DbOperate::getService($tB);
            // 20231020:标识分表
            if(property_exists($tBService::mainModel(), 'isSeprate')  && $tBService::mainModel()::$isSeprate ){
                $tbTable = $tBService::mainModel()->setConTable();
                $tB = '('.Strings::getInnerBlank($tbTable) .')';
            }
            
            $kA     = ' a'.Strings::camelize($v['uni_name']);
            $kB     = ' b'.Strings::camelize($v['uni_name']);

            $keyArr = [];
            $keyArr[] = $kA.'.*';
            // 20231113
            if($reflectFields){
                foreach($reflectFields as $k1=>$v1){
                    $vKey       = Arrays::value($v1, 'key');
                    $nullVal    = Arrays::value($v1, 'nullVal');
                    $keyArr[] = 'ifnull('.$kB.'.'.$vKey.','.$nullVal. ') as `'.$k1.'`';         
                }
            }

            $where = '';
            // 处理存在字段
            if(ModelQueryCon::hasKey($con, $existField)){
                // 【拼装查询条件】
                $isExist = ModelQueryCon::parseValue($con, $existField);
                if($isExist){
                    $where = ' where '.$kB.'.'.$v['uni_field'].' is not null';
                } else {
                    $where = ' where '.$kB.'.'.$v['uni_field'].' is null';
                }
                // 字段写一个
                $keyArr[] = $isExist.' as `'.$existField.'`';                
            }

            // $sql    = "(select ".$kA.'.*,'.$isExist.' as `'.$existField.'` from '.$tA.' as '.$kA .' left join '.$tB.' as '.$kB.' on '.$kA.'.'.$v['field'].'='.$kB.'.'.$v['uni_field'].' '.$where.')';
            $sql    = '(select '.implode(',',$keyArr).' from '.$tA.' as '.$kA .' left join '.$tB.' as '.$kB.' on '.$kA.'.'.$v['field'].'='.$kB.'.'.$v['uni_field'].' '.$where.')';
            $table = $sql;
            // dump($sql);exit;
        }
        Debug::debug('uniSetTable的table', $table);

        if($hasUni){
            $this->table = $table.' as mainTable';
        }
        
        // return $list;
        return $this->table;
    }
    
    
}
