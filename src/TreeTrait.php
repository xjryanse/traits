<?php

namespace xjryanse\traits;

/**
 * 返回码复用
 */
trait TreeTrait
{
    /**
     * TP数组转layui分页
     * @param array  $data     数组据
     * @param string $repCol  替换字段
     * @param bool   $isTree  是否树状
     * @return array
     */
    protected function forLayuiTree(array $data, $repCol='', $isTree=true, $mainColumn = 'name')
    {
        if(!empty($repCol)){
            foreach($data as $k=>$v){
                $data[$k][$mainColumn]  =$v[$repCol];
            }
        }
        return $isTree ? $this->makeTree($data) : $data;        //判断是否输出数状，否则输出原样                            
    }
    
    /**
     * 二维数组转树状数组
     * @param type $arr  二维数组
     * @param type $pid     父类id
     * @param type $pidname 父类字段名
     * @param type $child   子元素名
     * @return type
     */
    protected function makeTree($arr,$pid=0,$pidname='pid',$child='list')
    {
	$trees = [];
	foreach ($arr as $key => $item) {
            if( $item[$pidname] ==$pid ){
                $item[$child] = $this->makeTree($arr,$item['id']);
                $trees[] = $item;
            }
	}
	return $trees;
    }
}