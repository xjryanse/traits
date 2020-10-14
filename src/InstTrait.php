<?php
namespace xjryanse\traits;

/**
 * 单例复用
 */
trait InstTrait
{
    protected static $instances;
    
    protected $uuid;
    
    protected function __clone(){}
    //兼容原有代码，正常使用不应直接实例化
    public function __construct( $uuid = 0 ){
        $this->uuid      = $uuid;
    }
    /**
     * 有限多例
     */
    public static function getInstance( $uuid = 0 )
    {
        if( !isset( self::$instances[ $uuid ] ) || ! self::$instances[ $uuid ] ){
            self::$instances[ $uuid ] = new self( $uuid );
        }
        return self::$instances[ $uuid ];
    }
}
