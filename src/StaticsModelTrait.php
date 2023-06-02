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

        $groupFieldStr = implode(',', $groupFields);
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

}
