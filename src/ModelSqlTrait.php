<?php
namespace xjryanse\traits;

// use Exception;

/**
 * 模型Sql
 */
trait ModelSqlTrait {
    /**
     * 20240516
     * 分组取id
     * @param type $groupField
     * @return type
     */
    public static function sqlGroupMaxId($groupField){
        return self::field('max(`id`)')->group($groupField)->buildSql();
    }
    /**
     * 分组取最新的记录
     */
    public static function sqlGroupMaxRecord($groupField){
        $idSql = self::sqlGroupMaxId($groupField);
        return self::where('id in '.$idSql)->buildSql();
    }
    

}
