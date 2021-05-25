<?php
declare(strict_types = 1);
// +----------------------------------------------------------------------
// | swiftAdmin 极速开发框架 [基于ThinkPHP6开发]
// +----------------------------------------------------------------------
// | Copyright (c) 2019-2020 http://www.swiftadmin.net
// +----------------------------------------------------------------------
// | swiftAdmin.net High Speed Development Framework
// +----------------------------------------------------------------------
// | Author: 权栈 <coolsec@foxmail.com> MIT License Code
// +----------------------------------------------------------------------

use think\helper\Str;
use think\facade\Event;
use think\facade\Config;

// 插件类库自动加载
spl_autoload_register(function ($class) {

    $class = ltrim($class, '\\');
    $dir = app()->getRootPath();
    // 去除常量 
    $namespace = 'plugin';
    if (strpos($class, $namespace) === 0) {
        $path = null;
        $class = substr($class, strlen($namespace));
        if (($pos = strripos($class, '\\')) !== false) {
            $path = str_replace('\\', '/', substr($class, 0, $pos)) . '/';
            $class = substr($class, $pos + 1);
        }
        $path .= str_replace('_', '/', $class) . '.php';
        $dir .= $namespace . $path;
        if (file_exists($dir)) {
            include $dir;
            return true;
        }
        
        return false;
    }

    return false;
});

if (!function_exists('hook')) {
    /**
     * 处理插件钩子
     * @param string $event 钩子名称
     * @param array|null $params 传入参数
     * @param bool $once 是否只返回一个结果
     * @return mixed
     */
    function hook($event, $params = null, bool $once = false)
    {
        $result = Event::trigger($event, $params, $once);
        return join('', $result);
    }
}

if (!function_exists('get_plugin_infos')) {
    /**
     * 获取插件信息
     */
    function get_plugin_infos(string $name = null,bool $force = false) {
        $plugin = get_plugin_instance($name);
        if (!$plugin) {
            return [];
        }
        return $plugin->getInfo($name,$force);
    }
}

if (!function_exists('set_plugin_infos')) {
    /**
     * 设置插件信息
     */
    function set_plugin_infos(string $name = null, array $array = []) {

        $plugin = get_plugin_instance($name);
        if (!$plugin) {
            return [];
        }

        $result = $plugin->setConfig($name,$array);

        if (!empty($result)) {
            return true;
        }

        return false;
    }
}
if (!function_exists('get_plugin_instance')) {
    /**
     * 获取插件的单例
     * @param string $name 插件名
     * @return mixed|null
     */
    function get_plugin_instance($name)
    {
        static $plugins = [];
        if (isset($plugins[$name])) {
            return $plugins[$name];
        }

        // 返回类实体
        $class = get_plugin_class($name);
        if (class_exists($class)) {
            $plugins[$name] = new $class();
            return $plugins[$name];
        } else {
            return null;
        }
    }
}

if (!function_exists('get_plugin_class')) {
    /**
     * 获取插件类的类名
     * @param string $name  插件名
     * @param string $type  返回命名空间类型
     * @param string $class 当前类名
     * @return string
     */
    function get_plugin_class($name, $type = 'hook', $class = null)
    {
        $name = trim($name);
        
        // 处理多级控制器情况
        if (!is_null($class) && strpos($class, '.')) {
            $class = explode('.', $class);

            $class[count($class) - 1] = Str::studly(end($class));
            $class = implode('\\', $class);
        } else {
            $class = Str::studly(is_null($class) ? $name : $class);
        }
        
        switch ($type) {
            case 'controller':
                $namespace = "\\plugin\\" . $name . "\\controller\\" . $class;
                break;
            default:
                $namespace = "\\plugin\\" . $name . "\\" . $class;
        }

        return class_exists($namespace) ? $namespace : '';
    }
}

if (!function_exists('plugin_refresh_hooks')) {
    /**
     * 刷新插件配置
     * @return boolean
     */
    function plugin_refresh_hooks(bool $truncate = true) 
    {
        $plugin = (array)Config::get('plugin');
        if ($truncate) {
            $plugin['hooks'] = [];
            $plugin['router'] = [];
        }

        // 读取插件基础函数
        $base_methods = get_class_methods("\\think\\plugin");
        $base_methods = array_merge($base_methods, ['enable', 'disable','upgrade']);

        // 获取插件列表
        $DIRlist = get_plugin_list();

        // 读取优先级
        $priority = $plugin['priority'];
        if (!is_array($priority)) {
            $priority = explode(',',$priority);
        }

        // 处理函数优先级
        $pluginList = [];
        $Priorityhook = array_merge($priority, array_keys($DIRlist));

        foreach ($Priorityhook as $key) {
            if (!isset($DIRlist[$key])) {
                continue;
            }
            $pluginList[$key] = $DIRlist[$key];
        }

        // halt($pluginList);
        // 循环处理钩子
        foreach ($pluginList as $name => $value) {

            if (!$value['status']) {
                continue;
            }

            // 读取差异化函数
            $methods = (array)get_class_methods("\\plugin\\" . $name . "\\" . ucfirst($name));
            $diff_hooks = array_diff($methods, $base_methods);
            foreach ($diff_hooks as $hook) {

                if (!isset($plugin['hooks'][$hook])) {
                    $plugin['hooks'][$hook] = [];
                }
                
                // 兼容手动配置项
                if (is_string($plugin['hooks'][$hook])) {
                    $plugin['hooks'][$hook] = explode(',', $plugin['hooks'][$hook]);
                }
                
                if (!in_array($name, $plugin['hooks'][$hook])) {
                    $plugin['hooks'][$hook][] = $name;
                }
            }

            // 获取自定义路由规则
            $rules = array_map(function ($data) use ($value) {
                return "{$value['name']}/{$data}";
            }, $value['rewrite']);
            
            $plugin['router'] = array_merge($plugin['router'],$rules);
        }

        try {
            // 写入插件配置
            arr2file(config_path().'plugin.php',$plugin);
        } catch (\Throwable $th) {
            throw new Exception("写入配置文件出错 ".$th->getMessage());
        }

        return true;
        
    }
}
if (!function_exists('get_plugin_list')) {
    /**
     * 获取插件列表
     * @param array     $list  目录
     * @return array
     */
    function get_plugin_list(&$list = [],array $function = []) {

        foreach (scandir(PLUGIN_PATH) as $name) {

            if ($name === '.' 
                || $name === '..' 
                || is_file(PLUGIN_PATH . $name)) {
                continue;
            }

            // 校验插件目录
            $pluginDir = PLUGIN_PATH.$name.DIRECTORY_SEPARATOR;
            if (!is_dir($pluginDir) || !is_file($pluginDir . ucfirst($name) . '.php')) {
                continue;
            }
            
            $config = $pluginDir.'config.php';
            $preg = function($config) {

                try {
                    // 正则处理配置文件
                    $regx = ['<?php','return',';','\n','\r'];
                    $file = file_get_contents($config);
                    $file = str_replace($regx,'',$file);
                    @eval("\$config = ".$file.'; ');
                } catch (\Throwable $th) {
                    if (!$config || !is_array($config)) {
                        $config = include $config;
                    }
                }
                return $config;
            };

            $list[$name] = is_file($config) ?  $preg($config) : [];
        }

        return $list ?? [];
    }
}
if (!function_exists('rmdirs')) {

    /**
     * 删除文件夹
     * @param string $dirname  目录名称
     * @param bool   $withself 是否删除自身
     * @return boolean
     */
    function rmdirs($dirname, $withself = true)
    {
        if (!is_dir($dirname)) {
            return false;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirname, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        
        try {
            if ($withself) {
               rmdir($dirname);
            }
        } catch (\Throwable $th) {
            throw new \Exception($th->getMessage());
        }

        return true;
    }
}

if (!function_exists('copydirs')) {

    /**
     * 复制文件夹
     * @param string $source 源文件夹
     * @param string $dest   目标文件夹
     */
    function copydirs($source, $dest)
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        foreach (
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            ) as $item
        ) {
            if ($item->isDir()) {
                $sontDir = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
                if (!is_dir($sontDir)) {
                    // 新建文件夹
                    mkdir($sontDir, 0755, true);
                }
            } else {
                copy($item->getPathname(), $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
            }
        }
    }
}

/**
 * 移除空目录
 * @param string $dir 目录
 */
function remove_empty_folder($dir)
{
    try {
        $isDirEmpty = !(new \FilesystemIterator($dir))->valid();
        if ($isDirEmpty) {
            @rmdir($dir);
            remove_empty_folder(dirname($dir));
        }
    } catch (\UnexpectedValueException $e) {

    } catch (\Exception $e) {

    }
}

/**
 * 获取插件创建的表
 * @param string $name 插件名
 * @return array
 */
function get_plugin_tables($name)
{
    $pluginInfo = get_plugin_infos($name);
    
    if (!$pluginInfo) {
        return [];
    }

    $regex = "/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z_]+)`?/mi";
    $sqlFile = PLUGIN_PATH . $name . DIRECTORY_SEPARATOR . 'install.sql';
    $tables = [];
    if (is_file($sqlFile)) {
        preg_match_all($regex, file_get_contents($sqlFile), $matches);
        if ($matches && isset($matches[2]) && $matches[2]) {
            $prefix = env('database.prefix');
            $tables = array_map(function ($item) use ($prefix) {
                return str_replace("__PREFIX__", $prefix, $item);
            }, $matches[2]);
        }
    }
    return $tables;
}

if (!function_exists('get_plugin_url')) {
    /**
     * 插件显示内容里生成访问插件的url
     * @param $url 插件名/控制器/方法
     * @param array $param
     * @param bool|string $suffix 生成的URL后缀
     * @param bool|string $domain 域名
     * @return bool|string
     */
    function get_plugin_url($url = null, $vars = [], $suffix = true, $domain = false)
    {
        $url = ltrim($url, '/');
        $plugin = substr($url, 0, stripos($url, '/'));
        if (!is_array($vars)) {
            parse_str($vars, $params);
            $vars = $params;
        }
        $params = [];
        foreach ($vars as $k => $v) {
            if (substr($k, 0, 1) === ':') {
                $params[$k] = $v;
                unset($vars[$k]);
            }
        }

        $val = "@plugin/{$url}";
        $config = get_plugin_infos($plugin,true);
        $indomain = isset($config['indomain']) && $config['indomain'] ? true : false;
        $domainprefix = $config && isset($config['domain']) && $config['domain'] ? $config['domain'] : '';
        $domain = $domainprefix && Config::get('url_domain_deploy') ? $domainprefix : $domain;
        $rewrite = $config && isset($config['rewrite']) && $config['rewrite'] ? $config['rewrite'] : [];
        if ($rewrite) {
            
            $path = substr($url, stripos($url, '/') + 1);
            $keys = array_search($path,$rewrite);
            if (isset($rewrite[$keys]) && $rewrite[$keys]) {
                $val = $keys;
                array_walk($params, function ($value, $key) use (&$val) {
                    $val = str_replace("[{$value}]", $key, $val);
                });
                $val = str_replace(['^', '$'], '', $val);
                if (substr($val, -1) === '/') {
                    $suffix = false;
                }
            } else {

                // 如果采用了域名部署,则需要去掉前两段
                if ($indomain && $domainprefix) {
                    $arr = explode("/", $val);
                    $val = implode("/", array_slice($arr, 2));
                }
            }
        } else {
            // 如果采用了域名部署,则需要去掉前两段
            if ($indomain && $domainprefix) {
                $arr = explode("/", $val);
                $val = implode("/", array_slice($arr, 2));
            }
            foreach ($params as $k => $v) {
                $vars[substr($k, 1)] = $v;
            }
        }
        $url = url($val, [], $suffix, $domain) . ($vars ? '?' . http_build_query($vars) : '');
        $url = preg_replace("/\/((?!index)[\w]+)\.php\//i", "/", $url);
        return $url;
    }
}
