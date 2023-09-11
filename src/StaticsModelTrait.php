<?php
namespace xjryanse\traits;

use xjryanse\logic\Datetime;
use xjryanse\logic\DataList;
use xjryanse\system\service\columnlist\Dynenum;
/**
 * 统计模型复用
 * (配置式数据表)
 */
trait StaticsModelTrait {
    protected static $staticsFields = [];
    
    /**
     * 20230413:set
     * @param type $groupField  :如bus_id
     * @param type $prizeField  :价格字段：如prize
     */
    public static function setStaticsFields($groupField,$prizeField){
        $fields[] = $groupField;
        // 趟数
        $fields[] = "count(1) AS `recordCount`";
        $fields[] = "sum( " . $prizeField . " ) AS `allMoney`";
        self::$staticsFields = $fields;
    }
    
    protected static function staticsFields(){
        return self::$staticsFields;
    }
    
    /**
     * 2023-01-16：月统计数据
     * @param type $yearmonth
     * @param type $moneyType
     * @param type $timeField
     * @return int
     */
    public static function staticsMonthly($yearmonth, $moneyType, $timeField, $groupFields, $typeFieldName, $dynArrs = []){
        $con        = Datetime::yearMonthTimeCon($timeField, $yearmonth);
        $con[]      = ['company_id','=',session(SESSION_COMPANY_ID)];

        $fields     = self::staticsFields();
        $fields[]   = "date_format( `".$timeField."`, '%Y-%m' ) AS `yearmonth`";
        $fields[]   = "date_format( `".$timeField."`, '%d' ) AS `date`";

        $groupFieldStr = implode(',', $groupFields);
        // ①提取原始数据
        $lists      = self::mainModel()
                ->where($con)
                ->group("date_format( `".$timeField."`, '%Y-%m-%d' ),".$groupFieldStr)
                ->field(implode(',',$fields))
                ->select();

        $listsArr   = $lists ? $lists->toArray() : [];
        //拼接月份数据，列转行
        $res = DataList::toMonthlyData($yearmonth, $listsArr, $groupFields, $typeFieldName, $moneyType);
        //拼接动态枚举数据
        $res['dynDataList']     =  Dynenum::dynDataList($listsArr, $dynArrs);
        
        $res['per_page']        = 9999;
        $res['current_page']    = 1;
        $res['last_page']       = 1;
        return $res;
    }
    
    /**
     * 2023-01-16：年统计数据
     * @param type $year
     * @param type $moneyType
     * @return int
     */
    public static function staticsYearly($year, $moneyType, $timeField, $groupFields,$typeFieldName, $dynArrs = []){
        $con[]      = [$timeField,'>=',$year.'-01-01 00:00:00'];
        $con[]      = [$timeField,'<=',$year.'-12-31 23:59:59'];
        $con[]      = ['company_id','=',session(SESSION_COMPANY_ID)];

        $fields     = self::staticsFields();
        $fields[]   = "date_format( `".$timeField."`, '%Y' ) AS `year`";
        $fields[]   = "date_format( `".$timeField."`, '%m' ) AS `month`";

        $groupFieldStr = is_array($groupFields) ? implode(',', $groupFields) : $groupFields;
        // ①提取原始数据
        $lists      = self::mainModel()
                ->where($con)
                ->group("date_format( `".$timeField."`, '%Y-%m' ),".$groupFieldStr)
                ->field(implode(',',$fields))
                ->select();

        $listsArr   = $lists ? $lists->toArray() : [];
        //拼接月份数据，列转行
        $res = DataList::toYearlyData($year, $listsArr, $groupFields, $typeFieldName, $moneyType);
        //拼接动态枚举数据
        $res['dynDataList']     =  Dynenum::dynDataList($listsArr, $dynArrs);

        $res['per_page']        = 9999;
        $res['current_page']    = 1;
        $res['last_page']       = 1;
        return $res;
    }
    
    /*
     * 20320724:排行榜
     * 今天，昨天，近7日，近30日
     * @param type $groupField  聚合字段，逗号分隔
     * @param type $sumFields   求和字段，数组
     * @param type $orderBy     排序
     * @param type $con         条件
     * @return type
     */
    public static function staticsRanking($groupField, $sumFields, $orderBy = 'num desc',$con = []){
        $fields = explode(',',$groupField);
        foreach($sumFields as $v){
            $fields[] = 'sum(`'.$v.'`) as `'.$v.'`';
        }
        $fields[] = 'count(1) as num';

        $res = self::where($con)->field(implode(',',$fields))->group($groupField)->order($orderBy)->select();
        return $res ? $res->toArray() : [];
    }
    /**
     * 20230818:全部数据聚合统计
     */
    public static function staticsAll($groupField){
        if(!$groupField){
            throw new Exception('聚合字段必须:'.self::getTable());
        }
        $groupFields = explode(',',$groupField);
        
        $fields     = self::staticsFields();
        $fields[]   = 'count(1) as num';
        $fieldsArr  = array_unique(array_merge($fields,$groupFields));

        $con = [];
        $lists = self::where($con)
                ->field(implode(',', $fieldsArr))
                ->group($groupField)->select();
        return $lists ? $lists->toArray() : [];
    }

}
