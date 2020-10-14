<?php
namespace app\common\traits;

use think\facade\Request;
/**
 * 调试复用
 */
trait DebugTrait
{    
    //输出调试变量
    public static function debug($name='',$value='')
    {
        if ( self::isDebug() ) {
            echo $name;
            dump($value);                
        }
    }
    /**
     * 是否调试环境
     * @return type
     */
    protected static function isDebug()
    {
        $debug = Request::param('debug','');
        return $debug == 'xjryanse';
    }
}
