<?php
namespace xjryanse\traits;

use xjryanse\logic\Arrays;
use xjryanse\logic\DataList;
/**
 * 批量的聚合复用类
 * 20230805
 */
trait MainModelGroupTrait {
    // 20230730:groupBatchCountArr
    public static $groupBatchCountArr = [];   //末个节点执行次数

    /**
     * 分组批量筛选
     */
    public static function groupBatchSelect($key, $keyIds, $field = "*", $con = []) {
        $con[] = [$key, 'in', $keyIds];
        $lists = self::selectXS($con, '', $field);

        //拼接
        $data = [];
        foreach ($lists as &$v) {
            $data[$v[$key]][] = $v;
        }
        return $data;
    }
    /**
     * 用于提取形如roleIds的数组
     * @param type $key
     * @param type $keyIds
     * @param type $field
     * @param type $con
     * @return type
     */
    public static function groupBatchColumn($key, $keyIds, $field = 'id', $con = []) {
        $con[] = [$key, 'in', $keyIds];
        $lists = self::selectXS($con, '', $field);

        //拼接
        $data = [];
        foreach ($lists as &$v) {
            $data[$v[$key]][] = $v[$field];
        }
        return $data;
    }
    

    /**
     * 批量find,适用于key为id的情况
     * @param type $key
     * @param type $keyIds
     * @param type $field
     * @return type
     */
    public static function groupBatchFind($ids, $field = "*") {
        $con[] = ['id', 'in', $ids];
        $listsRaw = self::mainModel()->where($con)->field($field)->select();
        $lists = $listsRaw ? $listsRaw->toArray() : [];
        //拼接
        $data = [];
        foreach ($lists as &$v) {
            // $data[$v[$key]][] = $v;
            $data[$v['id']] = $v;
        }
        return $data;
    }
    
    /**
     * 分组批量统计
     * @param type $key
     * @param type $keyIds
     * @param type $con
     */
    public static function groupBatchCount($key, $keyIds, $con = []) {
        // 20230729:优化
        if (!$keyIds) {
            return [];
        }
        // 20230819:发现带条件bug
        $uniqKey = $key . '_' . md5(json_encode($con, JSON_UNESCAPED_UNICODE));
        if (!isset(self::$groupBatchCountArr[$uniqKey]) || !self::$groupBatchCountArr[$uniqKey]) {
            self::$groupBatchCountArr[$uniqKey] = [];
        }
        return DataList::dataObjAdd(self::$groupBatchCountArr[$uniqKey], $keyIds, function ($qIds) use ($key, $con) {
                    if (method_exists(__CLASS__, 'staticGroupBatchCount')) {
                        $arr = self::staticGroupBatchCount($key, $qIds, $con);
                    } else {
                        $arr = self::dbGroupBatchCount($key, $qIds, $con);
                    }
                    return $arr;
                });
    }
    
    
    /**
     * 分组批量求和
     * @param type $key
     * @param type $keyIds
     * @param type $sumField
     * @param type $con
     * @return type
     */
    public static function groupBatchSum($key, $keyIds, $sumField, $con = []) {
        $con[] = [$key, 'in', $keyIds];
        if (self::mainModel()->hasField('is_delete')) {
            $con[] = ['is_delete', '=', 0];
        }

        return self::mainModel()->where($con)->group($key)->column('sum(' . $sumField . ')', $key);
    }
    
    
    /**
     * 20230403 分组取最新的记录
     */
    public static function groupLastRecord($timeField, $groupField, $dataField = '*', $conLast = []) {
        $times = self::where($conLast)->group($groupField)->column('max(' . $timeField . ')');
        if (!$times) {
            return [];
        }
        //根据id，提取数据
        $conL[] = [$timeField, 'in', $times];
        $listObj = self::where($conL)->order($timeField)->select();
        $listArr = $listObj ? $listObj->toArray() : [];
        $dataArr = [];
        foreach ($listArr as $v) {
            $dataArr[$v[$groupField]] = $v;
        }

        return $dataArr;
    }
    
    
    /**
     * 20230429：数据库中做分组统计
     * @param type $key
     * @param type $keyIds
     * @param type $con
     * @return type
     */
    protected static function dbGroupBatchCount($key, $keyIds, $con = []) {

        $con[] = [$key, 'in', $keyIds];
        if (self::mainModel()->hasField('is_delete')) {
            $con[] = ['is_delete', '=', 0];
        }
        //20221005:增加公司端口过滤
        if (self::mainModel()->hasField('company_id')) {
            $con[] = ['company_id', '=', session(SESSION_COMPANY_ID)];
        }
        //20230730初始化
        $result = array_fill_keys($keyIds, 0);

        // 20231020：增加分表逻辑（体检板块）
        if (property_exists(self::mainModel(), 'isSeprate') && self::mainModel()::$isSeprate) {
            self::mainModel()->setConTable();
        }

        $res = self::mainModel()->where($con)->group($key)->column('count(1)', $key);
        // dump(self::mainModel()->getLastSql());
        //20230730:处理没值的记录
        return Arrays::concat($result, $res);
    }
    
    /**
     * 20231020：通用聚合查询
     * @param type $fieldsArr   字段数组
     * @param type $groupsArr   聚合数组
     * @param type $con         查询条件
     */
    public static function commGroupSelect($fieldsArr, $groupsArr, $con = []) {
        $fieldsN = array_merge($fieldsArr, $groupsArr);
        $res = self::where($con)
                ->field(implode(',', $fieldsN))
                ->group(implode(',', $groupsArr))
                ->select();

        return $res ? $res->toArray() : [];
    }
}
