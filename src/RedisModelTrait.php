<?php

namespace xjryanse\traits;

use think\Db;
use think\facade\Cache;
use xjryanse\logic\Debug;
use xjryanse\system\service\SystemErrorLogService;
use Redis;

/**
 * 带redis缓存的模型复用 主模型复用
 */
trait RedisModelTrait {

    /**
     * 获取redis连接实例
     * @return type
     */
    protected static function redisInst() {
        global $redisInst;
        if (!$redisInst) {
            $redisInst = new Redis();
            $redisInst->connect('127.0.0.1', 6379);
        }
        return $redisInst;
    }

    protected static function redisKey() {
        // 20230717识别当前连接的数据库
        $hostMd5 = md5(config('database.hostname'));
        return __CLASS__.$hostMd5;
    }

    /**
     * 高频数据暂存redis
     * @param type $data
     * @return type
     */
    protected static function redisLog($data) {
        // 有哪些类用到了redis暂存数据
        $redisClasses = Cache::get('redisLogClasses') ?: [];
        if (!in_array(__CLASS__, $redisClasses)) {
            $redisClasses[] = __CLASS__;
            // 20230621:临时:::::::::::::::::::::::::
            // 20230716：发现线上卡住？？？
            $redisClasses[] = 'app\\gps\\service\\GpsJt808DecryptService';
            $redisClasses[] = 'app\\gps\\service\\GpsJt809DecryptService';

            Cache::set('redisLogClasses', array_unique($redisClasses));
        }
        // 20221026
        $data['company_id']     = session(SESSION_COMPANY_ID);
        $data['creater']        = session(SESSION_USER_ID);
        $data['create_time']    = date('Y-m-d H:i:s');
        $data['source']         = session(SESSION_SOURCE);
        // $key = $this->chatKeyGenerate( $chatWithId );
        $key = self::redisKey();
        $res = self::redisInst()->lpush($key, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $res ? $data : [];
    }

    /**
     * redis搬到数据库
     */
    public static function redisToDb() {
        $key = self::redisKey();
        $index = 1;
        //每次只取100条
        $data = [];
        while ($index <= 50) {
            $tmpData = self::redisInst()->rpop($key);
            //只处理json格式的数据包
            if ($tmpData && is_array(json_decode($tmpData, true))) {
                $data[] = json_decode($tmpData, true);
            }
            $index++;
        }
        if (!$data) {
            return false;
        }
        Debug::dump(self::getTable().'的redisToDb数据', $data);
        //开事务保存，保存失败数据恢复redis
        Db::startTrans();
        try {
            self::saveAll($data);
            // 提交事务
            Db::commit();
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            // 20230717:错误信息记录
            SystemErrorLogService::exceptionLog($e);
            //数据恢复到redis
            while (count($data)) {
                $ttData = array_pop($data);
                //推回redis
                self::redisInst()->rpush($key.'_BACK', json_encode($ttData, JSON_UNESCAPED_UNICODE));
            }
        }
    }

    /**
     * ****************************************************
     * @return type
     */
    protected static function redisTodoKey() {
        return __CLASS__ . '_TODO';
    }

    /**
     * 20230415:redis 缓存时间key
     * @return type
     */
    protected static function redisTodoTimeKey() {
        return __CLASS__ . '_TODOTime';
    }

    /**
     * 有缓存时间，说明没被清理
     * @return type
     */
    public static function redisHasTodoTime() {
        return self::redisInst()->get(self::redisTodoTimeKey());
    }

    /*
     * 20230415:添加redis待处理任务
     */

    protected static function redisTodoAdd($data) {
        // 有哪些类用到了redis暂存数据
        $redisTodoClasses = Cache::get('redisTodoClasses') ?: [];
        if (!in_array(__CLASS__, $redisTodoClasses)) {
            $redisTodoClasses[] = __CLASS__;
            Cache::set('redisTodoClasses', $redisTodoClasses);
        }
        self::redisInst()->set(self::redisTodoTimeKey(), time());
        // $key = $this->chatKeyGenerate( $chatWithId );
        $key = self::redisTodoKey();
        $res = self::redisInst()->lpush($key, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $res ? $data : [];
    }

    /**
     * 20230415：待处理数据批量暂存
     * @param type $dataArr
     */
    protected static function redisTodoAddBatch($dataArr) {
        // 有哪些类用到了redis暂存数据
        self::redisInst()->set(self::redisTodoTimeKey(), time());
        foreach ($dataArr as $data) {
            self::redisTodoAdd($data);
        }
        return $dataArr;
    }

    /**
     * 
     */
    public static function redisTodoList() {
        $key = self::redisTodoKey();
        $index = 1;
        //每次只取100条
        $data = [];
        while ($index <= 100) {
            $tmpData = self::redisInst()->rpop($key);
            //只处理json格式的数据包
            if ($tmpData && is_array(json_decode($tmpData, true))) {
                $data[] = json_decode($tmpData, true);
            }
            $index++;
        }
        return $data;
    }
}
