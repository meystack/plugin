<?php
declare(strict_types = 1);
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
use think\facade\View;

/**
 * 插件路由函数
 * @package think\Router
 */
class Router 
{
    /**
     * 插件路由器
     */
    public function execute(string $plugin = null, string $controller = null,  string $action = null)
    {
        // 获取参数
        $param = input();
        foreach ($param as &$value) {
            $value = strtolower($value);
        }
        
        // 默认控制器
        $controller = $controller ?? 'index';
        $action = $action ?? 'index';

        // 配置当前控制器
        request()->setController($controller)->setAction($action);

        if (!empty($plugin) && !empty($controller) && !empty($action)) {

            // 获取插件信息
            $pluginInfo = get_plugin_infos($plugin);
            if (!$pluginInfo || !$pluginInfo['status']) {
                throw new \think\exception\HttpException(404, __('插件 %s 不存在或已被禁用', $plugin));
            }

            // 执行操作方法
            $class = get_plugin_class($plugin,'controller',$controller);
            if (!$class) {
                throw new \think\exception\HttpException(404, __('插件控制器 %s 不存在', $controller));
            }

            // 生成控制器对象
            $instance = new $class();
            $vars = [];
            if (is_callable([$instance, $action])) {

                // 改变视图
                $path = $pluginInfo['path'].'view'.DIRECTORY_SEPARATOR;
                View::config(['view_path' => $path]);
                
                // 执行操作方法
                $call = [$instance, $action];

            } else { 

                // 操作方法不存在
                $vars = [get_class($instance).'->'.$action.'()'];
                throw new \think\exception\HttpException(404, __('插件方法 %s 不存在', $vars));
            }

            // 执行插件实例函数
            return call_user_func_array($call, $vars);
        }
        else {
            abort(500, __('插件不存在或已被禁用'));
        }
    }
}
