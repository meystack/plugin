<?php
declare(strict_types = 1);

namespace think;

use think\facade\View;
use think\facade\Cache;
use think\facade\Config;

/**
 * 插件核心类
 */
abstract class Plugin 
{
    // 视图实例对象
    protected $view = null;

    // 当前错误信息
    protected $_error;

    // 插件目录
    public $PluginPath = null;

    // 插件标识
    protected $PluginName = null;

    // 插件配置路径
    protected $configPath = '';

    // 插件信息作用域
    protected $infoRange = '';

    /**
     * 架构函数
     * @access public
     */
    public function __construct($name = null) 
    {
        $name = is_null($name) ? $this->getName() : $name;

        // 设置插件标识
        $this->PluginName = $name;

        // 获取插件目录
        $this->PluginPath = PLUGIN_PATH . $name . DIRECTORY_SEPARATOR;

        // 设置插件视图
        $this->view = clone View::engine('Think');
        $this->view->config([
			'view_path' => $this->PluginPath . 'view' . DIRECTORY_SEPARATOR
		]);
    
        // 控制器初始化
        if (method_exists($this, 'initialize')) {
            $this->initialize();
        }
    }
    
    // 初始化
    protected function initialize()
    {}

    /**
     * 读取基础配置信息
     * @param string $name
     * @return array
     */
    final public function getInfo(string $name = null, bool $force = false)
    {
        if (empty($name)) {
            $name = $this->getName();
        }

        $array = [];
        $tags = sha1($name);
        if (!$force) {

            if ($array = Cache::get($tags)) {
                return $array;
            }
        }

        try {

            // 读取配置信息
            $filePath = $this->PluginPath.'config.php';
            if (is_file($filePath)) {

                try {
                    // 正则匹配处理数据
                    $regx = ['<?php','return',';','\n','\r'];
                    $file = file_get_contents($filePath);
                    $file = str_replace($regx,'',$file);
                    @eval("\$array = ".$file.'; ');
                } catch (\Throwable $th) {
                    if (!$array || !is_array($array)) {
                        $array = include $filePath;
                    }
                }

                if (is_array($array)) {
                    $array['url'] = '/plugin/'.$name;
                    $array['path'] = $this->PluginPath;
                    $array['config'] = is_file($this->PluginPath.'config.html') ? 1 : 0;
                    $array['filePath'] = $filePath;
                }
            }

        } catch (\Throwable $th) {
            throw new \Exception($th->getMessage(),-999);
        }

        Cache::set($tags,$array,Config::get('system.cache.cache_time'));
        return $array ?? [];
    }

    /**
     * 获取当前模块名
     * @return string
     */
    final public function getName()
    {
        if ($this->PluginName) {
            return $this->PluginName;
        }
        $data = explode('\\', get_class($this));
        return strtolower(array_pop($data));
    }

    /**
     * 获取插件的配置数组
     * @param string $name 可选模块名
     * @return array
     */
    final public function getConfig($name = '', $force = false)
    {
        return $this->getInfo($name,$force);
    }

    /**
     * 设置配置数据
     * @param       $name
     * @param array $value
     * @return array
     */
    final public function setConfig($name = '', $value = [])
    {
        if (empty($name)) {
            $name = $this->getName();
        }

        // 重载配置
        $config = $this->getConfig($name,true);
        $config = array_merge($config, $value);
        $filePath = $this->PluginPath.'config.php';
        arr2file($filePath,$config);
        Cache::set(sha1($name),null);
        return $config;
    }    

    /**
     * 检查基础配置信息是否完整
     * @return bool
     */
    final public function checkInfo()
    {
        $info = $this->getInfo();
        $info_check_keys = ['name', 'title', 'intro', 'author', 'version', 'status'];
        foreach ($info_check_keys as $value) {
            if (!array_key_exists($value, $info)) {
                return false;
            }
        }
        return true;
    }

    /**
	 * 加载模板输出
	 * @param string $template
	 * @param array $vars           模板文件名
	 * @return false|mixed|string   模板输出变量
	 * @throws \think\Exception
	 */
	protected function fetch($template = '', $vars = [])
	{
		return $this->view->fetch('/' . $template, $vars);
	}

	/**
	 * 渲染内容输出
	 * @access protected
	 * @param  string $content 模板内容
	 * @param  array  $vars    模板输出变量
	 * @return mixed
	 */
	protected function display($content = '', $vars = [])
	{
		return $this->view->display($content, $vars);
	}

	/**
	 * 模板变量赋值
	 * @access protected
	 * @param  mixed $name  要显示的模板变量
	 * @param  mixed $value 变量的值
	 * @return $this
	 */
	protected function assign($name, $value = '')
	{
		$this->view->assign([$name => $value]);

		return $this;
	}

	/**
	 * 初始化模板引擎
	 * @access protected
	 * @param  array|string $engine 引擎参数
	 * @return $this
	 */
	protected function engine($engine)
	{
		$this->view->engine($engine);

		return $this;
	}


    //必须实现安装
    abstract public function install();

    //必须卸载插件方法
    abstract public function uninstall();
}
