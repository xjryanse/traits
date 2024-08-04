<?php
namespace xjryanse\traits;

use think\Db;
use xjryanse\logic\SnowFlake;
use xjryanse\logic\DbOperate;
/**
 * 水平分表模型复用
 */
trait SepModelTrait {
    // 20231020:标识当前表有分表
    public static $isSeprate = true;
    /**
     * 20231019:条件分表
     * @param type $con
     */
    public function setConTable($con = []){        
        $sql = $this->sepConSql($con);
        
        $this->table = $sql . ' as aa';
        return $this->table;
    }
    /**
     * 分表查询sql
     * @param type $con
     * @return type
     */
    public function sepConSql($con = []){
        // 情况2：非id
        $rawTable = self::getRawTable();
        
        $subTables = DbOperate::allSubTableNames($rawTable);
        // 20231020：查询条件过滤
        $conFilter = DbOperate::keepHasFieldCon($con, $rawTable);
        $sqlArr = [];
        foreach($subTables as $t){
            $sqlArr[] = Db::table($t)->where($conFilter)->buildSql();
        }

        return  '('. implode(' union ', $sqlArr).')';
    }
    /**
     * 多个id，来设定分表
     */
    public function setIdsTable($ids){

        $tables = $this->calIdTables($ids);
        // 只有一张表，直接设置，返回
        if(count($tables) == 1){
            $this->table = $tables[0];
            return $this->table;
        }
        // 多张表的处理逻辑
        $con    = [];
        $con[]  = ['id','in',$ids];
        $sqlArr = [];
        foreach($tables as $t){
            $sqlArr[] = Db::table($t)->where($con)->buildSql();
        }

        $sql = '('. implode(' union ', $sqlArr).') as aa';
        $this->table = $sql;
        return $this->table;
    }
    
    /**
     * id 计算表（前4位年份）
     * @param type $ids
     */
    protected function calIdTables($ids){
        if(!is_array($ids)){
            $ids = [$ids];
        }
        $tableArr        = [];

        $rawTable = self::getRawTable();
        foreach($ids as $id){
            $year       = SnowFlake::getYear($id);
            $tableArr[] = DbOperate::getSepTable($rawTable, $year);
        }
        return array_unique($tableArr);
    }
    
    /**
     * 20231014：取分表
     * TODO:抽离逻辑
     * @param type $year
     * @return type
     */
    public function setSepTable($year)
    {
        // 
        $rawTable = self::getRawTable();
        // 取分表（绑定类，创建结构）
        $this->table = DbOperate::getSepTable($rawTable, $year);

        return $this->table;
    }
}
