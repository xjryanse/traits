<?php
namespace xjryanse\traits;

use Exception;
use xjryanse\logic\Arrays;
use xjryanse\finance\service\FinanceStatementOrderService;
/**
 * 财务来源表复用类
 */
trait FinanceSourceModelTrait {
    /**
     * 20231216:财务公共添加账单
     * @param string $prizeKey      printPrize
     * @param type $prizeField      order_prize
     * @param type $reflectKeys
     * @return type
     * @throws Exception
     */
    protected function financeCommAddStatementOrder($prizeKey, $prizeField, $reflectKeys = [] ) {
        $info = $this->get();
        if (!$info) {
            throw new Exception('记录不存在' . $this->uuid);
        }

        $belongTable        = self::getTable();

        $data = Arrays::keyReplace($info, $reflectKeys);
        // $data['user_id']    = $info['user_id'];        
        if(FinanceStatementOrderService::belongTableHasStatementOrder($belongTable,$this->uuid, $prizeKey)){
            throw new Exception('账单明细已存在');
        }

        return FinanceStatementOrderService::belongTablePrizeKeySaveRam($prizeKey, $info[$prizeField], $belongTable, $this->uuid, $data);
    }
    /**
     * 清除账单
     * @throws Exception
     */
    protected function financeCommClearStatementOrder(){
        $con[] = ['belong_table','=',self::getTable()];
        $con[] = ['belong_table_id','=',$this->uuid];

        $lists = FinanceStatementOrderService::where($con)->select();
        foreach($lists as $v){
            if($v['has_settle']){
                throw new Exception('账单已结算不可操作');
            }
            FinanceStatementOrderService::getInstance($v['id'])->deleteRam();
        }
    }
    /**
     * 20240122：清理未结算
     * 用于同步
     * @throws Exception
     */
    protected function financeCommClearStatementOrderNoSettle(){
        $con[] = ['belong_table','=',self::getTable()];
        $con[] = ['belong_table_id','=',$this->uuid];
        $con[] = ['has_settle','=',0];

        $lists = FinanceStatementOrderService::where($con)->select();
        foreach($lists as $v){
            FinanceStatementOrderService::getInstance($v['id'])->deleteRam();
        }
    }
    /**
     * 20231221：账单同步更新
     * @param type $prizeKey        价格key
     * @param type $prizeField      价格字段
     * @throws Exception
     */
    protected function financeCommUpdateSync($prizeKey, $prizeField){
        $info = $this->get();
        if (!$info) {
            throw new Exception('记录不存在' . $this->uuid);
        }

        $con[] = ['belong_table','=',self::getTable()];
        $con[] = ['belong_table_id','=',$this->uuid];

        $lists = FinanceStatementOrderService::where($con)->select();
        if(count($lists) >1){
            throw new Exception('存在多明细,请联系开发');
        }
        foreach($lists as $v){
            if($v['has_settle']){
                throw new Exception('账单已结算不可调整');
            }
            $uData                      = [];
            $uData['need_pay_prize']    = $info[$prizeField];
            $uData['statement_type']    = $prizeKey;

            FinanceStatementOrderService::getInstance($v['id'])->updateRam($uData);
        }
    }
    
}
