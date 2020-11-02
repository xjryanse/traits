<?php
namespace xjryanse\traits;

use think\facade\Request;
use xjryanse\logic\WxBrowser;
use xjryanse\system\service\SystemCompanyService;
use xjryanse\wechat\service\WechatWePubService;
use xjryanse\wechat\WePub\Fans;

/**
 * 微信授权登录，一般需依赖一堆类库
 */
trait WePubAuthTrait
{
    //微信公众号id
    protected $wePubAcid;
    //微信公众号appid
    protected $wePubAppId;
    //微信公众号appsecret
    protected $appSecret;    
    //公众号对应的用户信息
    protected $wePubUserInfo;
    //用于存储微信链接类
    protected $wxUrl;
    //微信粉丝类库
    protected $wePubFans;
    /**
     * 【1】微信授权登录
     */
    protected function initWePubAuth( $acid='' )
    {
        //判断是否微信浏览器
        if( !WxBrowser::isWxBrowser() ){
            return ;
        }
        //授权账户
        $this->initWePubAccount( $acid );
        //没有粉丝token，跳转授权页面
        if(!$this->wePubFans->token){
            $this->wePubGetToken();         exit;
        }
        $this->wePubUserInfo    = $this->wePubFans->getUserInfo();
    }
    /**
     * 只授权账户，不包含用户信息
     */
    protected function initWePubAccount( $acid )
    {
        //获取acid
        if(!$acid){
            $acid = SystemCompanyService::getInstance( session(SESSION_COMPANY_ID) )->fWePubId();
        }
        $this->wePubAcid = $acid;
        //acid查询公众号账户信息
        $app = WechatWePubService::getInstance($this->wePubAcid)->get();
        if(!$app){
            echo json_encode(['code' => '1',"msg"=>'公众号不存在']); exit;
        }
        
        $this->wePubAppId        = $app->appid;
        $this->wePubAppSecret    = $app->secret;
        //②获取用户信息
        $this->openid       = session('myOpenid') ? : "";
        $this->wePubFans    = new Fans( $this->wePubAcid, $this->openid);
        $this->wxUrl        = $this->wePubFans->wxUrl;
    }
            
    /**
     * 用户授权，获取token
     */
    private function wePubGetToken()
    {
        $url            = Request::url(true);
        //用于微信回调后跳转
        session( SESSION_WEPUB_CALLBACK ,$url);
        //Oauth2Authorize
        $this->redirect( $this->wxUrl['Connect']->Oauth2Authorize( $this->wePubAcid ) );
    }    
    

}
