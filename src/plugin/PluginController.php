<?php
declare (strict_types = 1);
// +----------------------------------------------------------------------
// | swiftAdmin 极速开发框架 [基于ThinkPHP6开发]
// +----------------------------------------------------------------------
// | Copyright (c) 2019-2020 http://www.swiftadmin.net
// +----------------------------------------------------------------------
// | swiftAdmin.NET High Speed Development Framework
// +----------------------------------------------------------------------
// | Author: 权栈 <coolsec@foxmail.com> MIT License Code
// +----------------------------------------------------------------------
namespace think\plugin;

use think\Validate;
use think\Response;
use think\facade\View;
use app\common\library\Auth;
use think\exception\ValidateException;
use think\exception\HttpResponseException;

// 插件控制器基类
class PluginController 
{
    /**
     * 控制器/类名
     * @var string
     */
    public $controller = null;

    /**
     * 控制器方法
     * @var string
     */
    public $action = null;


    /**
     * 错误消息
     * @var string
     */
	public $error = null;

    /**
     * 接口权限
     * @var object
     */
	public $auth = null;

    /**
     * 用户登录ID
     * @var int
     */
    public $userId = null;

    /**
     * 用户数据
     * @var array
     */
    public $userData = null;

    /**
     * 控制器登录鉴权
     * @var bool
     */
    public $userLogin = false;

    /**
     * 禁止登录重复
     * @var array
     */
	public $repeatLogin = [];

    /**
     * 非鉴权方法
     * @var array
     */
	public $noNeedLogin = ['index'];

    /**
     * 跳转URL地址
     * @var string
     */
	public $JumpUrl = '/';

    /**
     * 构造方法
     * @access public
     * @param  App  $app 
     */
    public function __construct()
    {
        $this->initialize();
    }

    public function initialize() 
    {
        $this->auth = Auth::instance();
        $this->action = request()->action(true);
        $this->controller = request()->controller(true);
        if($this->auth->isLogin()) {
            $this->userId = $this->auth->userData['id']; 
            $this->userData = $this->auth->userData; 
            if(in_array($this->action,$this->repeatLogin)) {
                $this->redirect($this->JumpUrl);
            }
        }
        else { 
            if ($this->userLogin && !in_array($this->action,$this->noNeedLogin)) {
                return $this->error('请登录后访问','/');
            }
        }
        foreach (config('system.site') as $key => $value) {
            View::assign($key,$value);
        }
        View::assign('user',$this->auth->userData);
    }

        /**
     * 验证数据
     * @access protected
     * @param  array        $data     数据
     * @param  string|array $validate 验证器名或者验证规则数组
     * @param  array        $message  提示信息
     * @param  bool         $batch    是否批量验证
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate(array $data, $validate, array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                // 支持场景
                [$validate, $scene] = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v     = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }
    /**
     * 操作成功跳转的快捷方法
     * @access protected
     * @param  mixed     $msg 提示信息
     * @param  string    $url 跳转的URL地址
     * @param  mixed     $data 返回的数据
     * @param  integer   $wait 跳转等待时间
     * @param  array     $header 发送的Header信息
     * @return void
     */
    protected function success($msg = '', string $url = null, $data = '', int $count = 0,  int $code = 200, int $wait = 3, array $header = [])
    {
        if (is_null($url) && isset($_SERVER["HTTP_REFERER"])) {
            $url = $_SERVER["HTTP_REFERER"];
        } elseif ($url) {
            $url = (strpos($url, '://') || 0 === strpos($url, '/')) ? $url : app('route')->buildUrl($url);
        }

        // 默认消息
        $msg = !empty($msg) ? __($msg) :  __('操作成功！');

        $result = [
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
            'count' => $count,
            'url'  =>(string)$url,
            'wait' => $wait,
        ];

        $type = $this->getResponseType();
        if (strtolower($type) == 'html'){
            $response = view(config('app.dispatch_success'), $result);
        } else if ($type == 'json') {
            $response = json($result);
        }
        
        throw new HttpResponseException($response);
    }

    /**
     * 操作错误跳转的快捷方法
     * @access protected
     * @param  mixed     $msg 提示信息
     * @param  string    $url 跳转的URL地址
     * @param  mixed     $data 返回的数据
     * @param  integer   $wait 跳转等待时间
     * @param  array     $header 发送的Header信息
     * @return void
     */
    protected function error($msg = '',  $url = null, $data = '', int $code = 101, int $wait = 3, array $header = [])
    {
        if (is_null($url)) {
            $url = request()->isAjax() ? '' : 'javascript:history.back(-1);';
        } elseif ($url) {
            $url = (strpos($url, '://') || 0 === strpos($url, '/')) ? $url : $this->app->route->buildUrl($url);
        }
        $msg = !empty($msg) ? __($msg) :  __('操作失败！');
        $result = [
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
            'url'  =>(string)$url,
            'wait' => $wait,
        ];

        $type = $this->getResponseType();
        if ($type == 'html'){
            $response = view(config('app.dispatch_error'), $result);
        } else if ($type == 'json') {
            $response = json($result);
        }
        throw new HttpResponseException($response);
    }


    /**
     * URL重定向
     * @access protected
     * @param  string         $url 跳转的URL表达式
     * @param  array|integer  $params 其它URL参数
     * @param  integer        $code http code
     * @param  array          $with 隐式传参
     * @return void
     */
    protected function redirect($url, $params = [], $code = 302, $with = [])
    {
        $response = Response::create($url, 'redirect');

        if (is_integer($params)) {
            $code   = $params;
            $params = [];
        }
     
        $response->code($code);
        throw new HttpResponseException($response);
    }

    /**
     * 获取当前的response 输出类型
     * @access protected
     * @return string
     */
    protected function getResponseType()
    {
        return request()->isJson() || request()->isAjax() ? 'json' : 'html';
    }

    /**
     * 生成静态HTML
     * @access protected
     * @param  htmlfile         消息参数
     * @param  htmlpath         错误代码
     * @param  templateFile
     * @return void
     */
    protected function buildHtml(string $htmlfile = null,string $htmlpath = null, string $templateFile = null,$suffix = 'html') 
    {
        $content = View::fetch($templateFile); 
        $htmlpath = !empty($htmlpath) ? $htmlpath : './';
        $htmlfile = $htmlpath . $htmlfile . '.' . $suffix; 

        // 写入静态文件
        if(write_file($htmlfile, $content) === false) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * 抛出404异常
     * @access public
     * @param  msg         消息参数
     * @param  code        错误代码
     * @return void
     */
	public function throwError(string $msg = 'not found!', int $code = 404)
    {
		abort($code,$msg);
	}
}