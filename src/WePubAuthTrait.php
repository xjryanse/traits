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
    
    protected $wePubUserInfo;
    
    /**
     * 【1】微信授权登录
     */
    protected function initWePubAuth( $acid='' )
    {
        //判断是否微信浏览器
        if( !WxBrowser::isWxBrowser() ){
            return ;
        }
        //获取acid
        if(!$acid){
            $acid = SystemCompanyService::getInstance( session('scopeCompanyId') )->fWePubId();
        }
        $this->wePubAcid = $acid;
        //acid查询公众号账户信息
        $app = WechatWePubService::getInstance($this->wePubAcid)->get();
        if(!$app){
            return ;
        }        
        
        $this->wePubAppId        = $app->appid;
        $this->wePubAppSecret    = $app->secret;
        //②获取用户信息
        $this->openid       = session('myOpenid') ? : "";
        $this->fans         = new Fans( $this->wePubAcid, $this->openid);
        $this->wxUrl        = $this->fans->wxUrl;
        if(!$this->fans->token){
            $this->wePubGetToken();         exit;
        }
        $this->wePubUserInfo    = $this->fans->getUserInfo();
    }
    /**
     * 获取token
     */
    private function wePubGetToken()
    {
        $url            = Request::url(true);
        //用于微信回调后跳转
        session('jump_url',$url);
        //Oauth2Authorize
        $this->redirect( $this->wxUrl['Connect']->Oauth2Authorize( $this->wePubAcid ) );
    }    
    

}
