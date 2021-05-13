<?php
declare(strict_types = 1);

namespace think\plugin;

use think\Route;
use think\facade\Config;
use think\facade\Db;
use think\facade\Cache;
use think\facade\Event;
use GuzzleHttp\Client; 
use GuzzleHttp\Exception\TransferException;
/**
 * 插件服务
 * @package think\plugin
 */
class Service extends \think\Service 
{
    // 插件路径
    public $pluginPath = null;

    // 插件地址
    public $address = [];

    /**
     * 注册服务
     */
    public function register()
    {
        // 定义目录
        $this->pluginPath = root_path().'plugin'.DIRECTORY_SEPARATOR;
        define('PLUGIN_PATH',$this->pluginPath);

        // 自动创建目录
        if (!is_dir($this->pluginPath)) {
            @mkdir($this->pluginPath, 0755, true);
        }

        // 全局插件函数
        foreach (scandir(PLUGIN_PATH) as $dir) {
            if ($dir == '.' || $dir == '..') {
                continue;
            }
            $dir = PLUGIN_PATH . $dir;
            $file = $dir . '/function.php';
            if (is_dir($dir) && is_file($file)) {
                include $file;
            }
        }

        // 全局插件钩子
        $hooks = $this->app->isDebug() ? [] : Cache::get('plug-in.hooks', []);
        if (empty($hooks)) {

            $hooks = (array) Config::get('plugin.hooks');
            foreach ($hooks as $key => $value) {
                if (is_string($value)) {
                    $value = explode(',', $value);
                } else {
                    $value = (array) $value;
                }

                // 初始化获取钩子
                $hooks[$key] = array_filter(array_map(function ($elem) use ($key) {
                    return [get_plugin_class($elem), $key];
                }, $value));
            }

            Cache::set('plug-in.hooks', $hooks);
        }

        // 批量注册事件监听
        Event::listenEvents($hooks);

        // 插件初始化行为
        if (isset($hooks['appInit'])) {
            foreach ($hooks['appInit'] as $value) {
                Event::trigger('appInit', $value);
            }
        }

        // 绑定插件容器
        $this->app->bind('plugin', Service::class);
    }

    /**
     * 服务注册启动
     */
    public function boot() {

        // 注册路由
        $this->registerRoutes(function(Route $route) {

            // 插件路由
            $execute = '\\think\\plugin\\Router@execute';
            $route->rule('plugin/:plugin/[:controller]/[:action]', $execute);

            // 自定义路由规则
            $routes = Config::get('plugin.router', []);
            foreach ($routes as $key => $value) {

                if (!$value) {
                    continue;
                }
                if (is_array($value)) {
                    $domain = $value['domain'];
                    $rules = [];
                    foreach ($value['rule'] as $k => $rule) {
                        [$plugin, $controller, $action] = explode('/', $rule);
                        $rules[$k] = [
                            'plugin'        => $plugin,
                            'controller'    => $controller,
                            'action'        => $action,
                            'in.domain'      => 1,
                        ];
                    }

                    $route->domain($domain, function () use ($rules, $route, $execute) {
                        // 动态注册域名的路由规则
                        foreach ($rules as $k => $rule) {
                            $route->rule($k, $execute)
                                ->name($k)
                                ->completeMatch(true)
                                ->append($rule);
                        }
                    });

                } else {

                    list($plugin, $controller, $action) = explode('/', $value);
                    $route->rule($key, $execute)
                        ->name($key)
                        ->completeMatch(true)
                        ->append([
                            'plugin' => $plugin,
                            'controller' => $controller,
                            'action' => $action
                        ]);
                }
            }            
        });
    }

    /**
     * 远程下载函数
     * @access public 
     * @param  string $type   下载分类
     * @param  string $name   文件名称
     * @param  string $path   保存地址
     * @param  array  $extend 扩展参数
     * @return  string
     */
    public static function download(string $type = 'plugin', $name = '', $extend = []) 
    {
        $files = self::Typepath($type);
        $client = self::getClient();

        try {
            $response = $client->get($files['url'], ['query' => array_merge(['name' => $name], $extend)]);
            $body = $response->getBody();
            $content = $body->getContents();
            if (substr($content, 0, 1) === '{') {
                $json = (array)json_decode($content, true);
                // 如果传回的是一个下载链接,则再次下载
                if ($json['data'] && isset($json['data']['url'])) {
                    $response = $client->get($json['data']['url']);
                    $body = $response->getBody();
                    $content = $body->getContents();
                } else {
                    throw new PluginException($json['msg'],$json['code'],$json['data']);
                }
            }
        }
        catch(TransferException $e) {
            throw new \Exception(__("安装包下载失败"),-111);
        }
   
        // 写入文件
        $filepath = $files['file'].$name.'.zip';
        if ($write = fopen($filepath, 'w')) {
            fwrite($write, $content);
            fclose($write);
            return $filepath;
        }
 
        throw new \Exception(__("没有写入权限"),-112);
    }

    /**
     * 解压文件
     * @param  string  $type  下载类型
     * @param  string  $name  文件名
     * @return string  $dir   文件路径
     */
    public static function unzip(string $type = 'plugin', $name = '')
    {
        if (!$type || !$name) {
            throw new \Exception(__('文件类型无效'),-113);
        }

        // 获取文件地址
        $files = self::Typepath($type);
        $filename = $files['file'] . $name . '.zip';
        
        $unzip = new \ZipArchive();
        
        if ($unzip->open($filename) !== TRUE) {
            throw new \Exception(__("访问安装包失败"),-114);
        }

        $dir = $files['file'] . $name . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755);
        }
        
        try {
            $unzip->extractTo($files['file']);
        } catch (\Throwable $th) {
            throw new \Exception(__("解压 %s 安装包失败",$name),-115);
        }finally {
            $unzip->close();
        }
        
        return $filename;
    }

    /**
     * 获取请求类型
     * @param  string  $type  下载类型
     * @return array
     */
    public static function Typepath(string $type = 'plugin') 
    {

        $address = [
            'plugin'=>[
                'url'=>'/plugin/query',
                'check'=>'/plugin/update',
                'file'=> PLUGIN_PATH,
            ]
        ];

        return isset($address[$type]) ? $address[$type] : [];
    }

    /**
     * 获取远程服务器
     * @return  string
     */
    public static function getServerUrl()
    {
        return Config::get('app.api_url');
    }
 
    /**
     * 获取请求对象
     * @return Client
     */
    public static function getClient()
    {
        $options = [
            'base_uri'        => self::getServerUrl(),
            'timeout'         => 30,
            'connect_timeout' => 30,
            'verify'          => false,
            'http_errors'     => false,
            'headers'         => [
                'X-REQUESTED-WITH' => 'XMLHttpRequest',
                'Referer'          => dirname(request()->root(true)),
                'User-Agent'       => 'SwiftAdmin',
            ]
        ];
        // 返回对象
        static $client;
        if (empty($client)) {
            $client = new Client($options);
        }

        return $client;
    }

    /**
     * 安装插件
     *
     * @param string  $name   插件名称
     * @param boolean $force  是否覆盖
     * @param array   $extend 扩展参数
     * @return  boolean
     * @throws  Exception
     */
    public static function install($name, $force = false, $extend = []) 
    {
        // 是否覆盖安装
        $filepath = Service::Typepath('plugin')['file'];
        if (!$name || (is_dir($filepath. $name) && !$force)) {
            throw new \Exception(__("请勿重复安装 %s 插件",$name),-116);
        }

        // 下载该插件
        $tempfiles = Service::download('plugin',$name,$extend);

        // 获取解压目录
        $pluginDir = Service::getPluginDir($name);

        try {

            // 解压压缩包
            Service::unzip('plugin',$name);
 
            // 校验插件是否完整
            Service::check($name);
            
            // 判断文件冲突
            if (!$force) {
                Service::noconflict($name);
            }
            
        } catch (PluginException $p) {
            throw new PluginException($p->getMessage(),$p->getCode(),$p->getData());
        } catch (\Throwable $th) {
            @rmdirs($pluginDir);
            throw new \Exception($th->getMessage(),$th->getCode());
        }  finally {
            @unlink($tempfiles);
        }

        // 安装插件
        try {
            
            $pluginClass = get_plugin_class($name);
            if (class_exists($pluginClass)) {
                $pluginObject = new $pluginClass();
                $pluginObject->install();
            }

            // 导入SQL
            Service::importsql($name);

        } catch (\Throwable $th) {
            // 异常删除解压目录
            @rmdirs($pluginDir);
            throw new \Exception($th->getMessage(),$th->getCode());
        }

        // 启用插件
        Service::enable($name, true);

        return get_plugin_infos($name);
    }

    /**
     * 升级插件
     *
     * @param string $name   插件名称
     * @param array  $extend 扩展参数
     */
    public static function upgrade($name, $extend)
    {
        $config = get_plugin_infos($name,true);
        if ($config['status']) {
            throw new \Exception(__('请先禁用插件再升级'));
        }

        // 下载插件
        $tempfiles = Service::download('plugin',$name,$extend);

        // 备份插件
        Service::backup($name);

        // 获取插件目录
        $pluginDir = Service::getPluginDir($name);

        try {

            // 删除源文件
            @rmdirs($pluginDir);
            // 解压插件
            Service::unzip('plugin',$name);

        } catch (\Throwable $th) {
            throw new \Exception($th->getMessage(),$th->getCode());
        }finally {
            // 移除临时文件
            @unlink($tempfiles);
        }

        // 更新配置
        $newConfig = get_plugin_infos($name,true);
        $config = array_merge($config,$newConfig);
        set_plugin_infos($name,$config);

        // 安装插件
        try {

            $pluginClass = get_plugin_class($name);
            if (class_exists($pluginClass)) {
                $pluginObject = new $pluginClass();
                if (method_exists($pluginObject, "upgrade")) {
                    $pluginObject->upgrade();
                }
            }

            // 导入SQL
            Service::importsql($name);

        } catch (\Throwable $th) {
            throw new \Exception($th->getMessage(),$th->getCode());
        }

        // 启用插件
        Service::enable($name, true);

        return $config;
    }

    /**
     * 备份插件
     * @param string $name 插件名称
     * @return bool
     * @throws Exception
     */
    public static function backup($name)
    {
        $backup = Service::getBackupDir();
        $file = $backup . $name . '-backup-' . date("YmdHis") . '.zip';

        $zip = new \ZipArchive();
        try {
            $zip->open($file, \ZipArchive::CREATE);
            $pluginDir = Service::getPluginDir($name);

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pluginDir, \RecursiveDirectoryIterator::SKIP_DOTS), 
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                // 如果是文件
                if ($fileinfo->isFile()) {
                    $filePath = $fileinfo->getPathName();
                    $zip->addFile($filePath,str_replace(PLUGIN_PATH, '', $filePath));
                }
                else {
                    $localDir = str_replace(PLUGIN_PATH,"",$fileinfo->getPathName());
                    $zip->addEmptyDir($localDir);
                }
            }

        } catch (\Throwable $th) {
            throw new \Exception($th->getMessage());
        }finally {
            $zip->close();
        }

        return true;
    }

    /**
     * 卸载插件
     *
     * @param string  $name
     * @param boolean $force 是否强制卸载
     * @return  boolean
     * @throws  Exception
     */
    public static function uninstall($name, $force = true)
    {
        if (!$name || !is_dir(PLUGIN_PATH . $name)) {
            throw new \Exception(__("插件数据不存在"),-117);
        }

        // 返回冲突文件
        if (!$force) {
            Service::noconflict($name);
        }

        // 获取插件目录
        $pluginDir = Service::getPluginDir($name);
        
        // 移除插件资源文件
        if ($force) {
            $list = Service::getGlobalFiles($name);
            foreach ($list as $value) {
                @unlink(root_path() . $value);
            }
        }

        // 执行卸载脚本
        try {

            $class = get_plugin_class($name);
            if (class_exists($class)) {
                $plugin = new $class();
                $plugin->uninstall();
            }

        } catch (\Throwable $th) {
            throw new \Exception($th->getMessage());
        }

        // 移除插件目录
        @rmdirs($pluginDir);

        return true;
    }

    /**
     * 导入SQL
     *
     * @param string $name 插件名称
     * @return  boolean
     */
    public static function importsql($name)
    {
        $sqlFile = self::getPluginDir($name) . 'install.sql';
        if (is_file($sqlFile)) {
            $lines = file($sqlFile);
            $templine = '';
            foreach ($lines as $line) {
                if (substr($line, 0, 2) == '--' || $line == '' || substr($line, 0, 2) == '/*') {
                    continue;
                }

                $templine .= $line;
                if (substr(trim($line), -1, 1) == ';') {
                    $templine = str_ireplace('__PREFIX__', env('database.prefix'), $templine);
                    $templine = str_ireplace('INSERT INTO ', 'INSERT IGNORE INTO ', $templine);
                    try {
                        Db::getPdo()->exec($templine);
                    } catch (\PDOException $e) {
                        // $e->getMessage();
                    }

                    $templine = '';
                }
            }
        }

        return true;
    }

    /**
     * 启用
     * @param string  $name  插件名称
     * @param boolean $force 是否强制覆盖
     * @return  boolean
     */
    public static function enable($name, $force = false)
    {
        if (!$name || !is_dir(PLUGIN_PATH . $name)) {
            throw new \Exception(__('插件数据不存在'),-117);
        }

        if (!$force) {
            Service::noconflict($name);
        }

        // 是否备份冲突文件
        if (config('system.plugin.mutex_file')) {
            // 仅备份冲突文件
            $conflictFiles = self::getGlobalFiles($name, true);
            if (!empty($conflictFiles)) {
                $zip = new \ZipArchive();
                try {
                    $zipname = PLUGIN_PATH . $name . "-conflict-enable-" . date("YmdHis") . ".zip";
                    $zip->open($zipname, \ZipArchive::CREATE);
                    foreach ($conflictFiles as $k => $v) {
                        $zip->addFile(root_path() . $v, $v);
                    }

                } catch (\Throwable $th) {
                    throw new \Exception($th->getMessage());
                }finally {
                    $zip->close();
                }
            }
        }

        // 复制资源到全局
        $pluginDir = self::getPluginDir($name);
        $checkDirList = self::getCheckDirs();
        foreach ($checkDirList as $dir) {
            if (is_dir($pluginDir . $dir)) {
                copydirs($pluginDir . $dir, root_path() . $dir);
            }
        }

        // 执行启用脚本
        try {
            $class = get_plugin_class($name);
            if (class_exists($class)) {
                $plugin = new $class();
                if (method_exists($class, "enable")) {
                    $plugin->enable();
                }
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }

        // 修改插件状态
        $pluginInfo = get_plugin_infos($name,true);
        $pluginInfo['status'] = 1;
        set_plugin_infos($name,$pluginInfo);
        plugin_refresh_hooks();

        return true;
    }

    /**
     * 禁用
     *
     * @param string  $name  插件名称
     * @param boolean $force 是否强制禁用
     * @return  boolean
     * @throws  Exception
     */
    public static function disable($name, $force = false)
    {
        // 检查当前目录是否存在
        if (!$name || !is_dir(PLUGIN_PATH . $name)) {
            throw new \Exception(__('插件数据不存在'),-117);
        }

        // 获取冲突文件
        if (!$force) {
            Service::noconflict($name);
        }

        // 备份文件
        if (config('system.plugin.mutex_file')) {
            // 仅备份冲突文件
            $conflictFiles = self::getGlobalFiles($name, true);
            if (!empty($conflictFiles)) {
                $zip = new \ZipArchive();
                try {
                    $zipname = PLUGIN_PATH . $name . "-conflict-disable-" . date("YmdHis") . ".zip";
                    $zip->open($zipname, \ZipArchive::CREATE);
                    foreach ($conflictFiles as $k => $v) {
                        $zip->addFile(root_path() . $v, $v);
                    }

                } catch (\Throwable $th) {
                    throw new \Exception($th->getMessage(),-999);
                }finally {
                    $zip->close();
                }
            }
        }

        // 移除已经复制的静态资源
        $pluginDir = self::getPluginDir($name);
        foreach (self::getCheckDirs() as $checkdir) {

            if (is_dir($pluginDir .$checkdir)) {

                // 匹配出所有的文件
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($pluginDir.$checkdir, 
                    \RecursiveDirectoryIterator::SKIP_DOTS), 
                    \RecursiveIteratorIterator::CHILD_FIRST
                );

                foreach ($files as $fileinfo) {
                    $dirfile = str_replace($pluginDir,root_path(),$fileinfo->getPathname());
                    if ($fileinfo->isFile()) { // 删除文件
                        @unlink($dirfile);
                    }else if ($fileinfo->isDir()) { // 删除空文件夹
                        remove_empty_folder($dirfile);
                    }
                }
            }
        }

        // 执行禁用脚本
        try {
            $class = get_plugin_class($name);
            if (class_exists($class)) {
                $plugin = new $class();
                if (method_exists($class, "disable")) {
                    $plugin->disable();
                }
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage(),-999);
        }

        // 修改配置信息
        $pluginInfo = get_plugin_infos($name,true);
        $pluginInfo['status'] = 0;
        set_plugin_infos($name,$pluginInfo);
        plugin_refresh_hooks();

        return true;
    }

    /**
     * 是否有冲突
     *
     * @param string $name 插件名称
     * @return  boolean
     * @throws  Exception
     */
    public static function noconflict($name)
    {
        // 检测冲突文件
        $list = self::getGlobalFiles($name, true);
        if ($list) {
            // 发现冲突文件，抛出异常
            throw new \Exception(__("插件 %s 存在文件冲突",$name),-118);
        }
        return true;
    }

    /**
     * 获取插件在全局的文件
     *
     * @param string  $name         插件名称
     * @param boolean $onlyconflict 是否只返回冲突文件
     * @return  array
     */
    public static function getGlobalFiles($name, $onlyconflict = false)
    {
        $list = [];
        $pluginDir = self::getPluginDir($name);
        $checkDirList = self::getCheckDirs();

        // 扫描插件目录是否有覆盖的文件
        foreach ($checkDirList as $dirName) {

            //检测目录是否存在
            if (!is_dir($pluginDir . $dirName)) {
                continue;
            }

            //匹配出所有的文件
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pluginDir . $dirName, \RecursiveDirectoryIterator::SKIP_DOTS), 
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                // 如果是文件
                if ($fileinfo->isFile()) {
                    $filePath = $fileinfo->getPathName();
                    $path = str_replace($pluginDir, '', $filePath);

					// 判断冲突文件
                    if ($onlyconflict) {
                        $destPath = root_path() . $path;
                        if (is_file($destPath)) {
                            if (filesize($filePath) != filesize($destPath) || md5_file($filePath) != md5_file($destPath)) {
                                $list[] = $path;
                            }
                        }
                    } else {
                        $list[] = $path;
                    }
                }
            }
        }

        $list = array_filter(array_unique($list));

        return $list;
    }

    /**
     * 获取指定插件的目录
     */
    public static function getPluginDir($name)
    {
        $dir = PLUGIN_PATH . $name . DIRECTORY_SEPARATOR;
        return $dir;
    }

    /**
     * 获取插件备份目录
     */
    public static function getBackupDir()
    {
        $dir = root_path() . 'runtime/plugin' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }


    /**
     * 获取检测的全局文件夹目录
     * @return  array
     */
    public static function getCheckDirs()
    {
        return [
            'app',
            'public'
        ];
    }

    /**
     * 检测插件是否完整
     *
     * @param string $name 插件名称
     * @return  boolean
     * @throws  Exception
     */
    public static function check($name)
    {
        if (!$name || !is_dir(PLUGIN_PATH . $name)) {
            throw new \Exception(__('插件目录不存在'),-117);
        }

        $pluginClass = get_plugin_class($name);
        if (!$pluginClass) {
            throw new \Exception(__("插件安装出现异常"),-119);
        }

        $plugin = new $pluginClass();
        if (!$plugin->checkInfo()) {
            throw new \Exception(__("插件信息配置不正确"),-120);
        }
        
        return true;
    }

    /**
     * 获取缓存标识
     * @return string
     */
    public static function tags() {
        $domain = request()->domain();
        return md5($domain.'SWIFTADMIN');
    }

    /**
     * 获取最后产生的错误
     * @return string
     */
    public static function getError()
    {
        return Cache::get(self::tags());
    }

    /**
     * 设置错误
     * @param string $error 信息信息
     */
    protected static function setError($error)
    {
        Cache::set(self::tags(),$error);
    }


}