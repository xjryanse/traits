<?php
namespace xjryanse\traits;

use think\facade\Request;
/**
 * 请求复用
 */
trait RequestTrait
{
    /**
     * 过滤前端传来的空数据
     * 一般用于查询条件
     * @param type $param
     * @return type
     */
    protected function unsetEmpty( &$param ){
        if(is_array($param)){
            foreach($param as $k=>&$v){
                if(!is_array($v) && !strlen($v)){
                    unset($param[$k]);
                }
                if(is_array($v)){
                    $this->unsetEmpty( $v);
                }
            }
        }
        return $param;
    }
}
