<?php

namespace xjryanse\traits;

use xjryanse\generate\service\GenerateTemplateService;
use xjryanse\logic\Strings;
use think\facade\Request; 

/**
 * 导出复用
 */
trait MainGenerateTrait {

    /**
     * 单条数据导出word
     * @param type $templateKey
     * @return type
     */
    public function infoGenerate() {
        self::stopUse(__METHOD__);
    }
    
    /**
     * 20230331：信息生成并下载
     * @param type $templateKey
     * @return string
     */
    public function infoGenerateDownload($templateKey) {
        $data       = $this->info();

        return self::generateDownload($templateKey, $data);
    }

    protected static function generateDownload($templateKey, $data){
        $templateId = GenerateTemplateService::keyToId($templateKey);
        $res        = GenerateTemplateService::getInstance($templateId)->generate($data);

        // http会被谷歌浏览器拦截无法下载
        $respData['url'] = Request::domain() .'/'. $res['file_path'];
        //文件名带后缀
        // $tableInfo = $this->tableInfo();
        $fileName = GenerateTemplateService::getInstance($templateId)->fFileName();
        // 苹果小程序，对doc兼容不佳（协企通出现过）统一用docx
        $respData['fileName'] = Strings::dataReplace($fileName, $data) . '.docx';

        return $respData;
    }
}
