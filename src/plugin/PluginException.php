<?php

namespace think\plugin;

use think\Exception;

/**
 * 插件异常处理类
 * @package think\PluginException
 */
class PluginException extends Exception
{

    public function __construct($message, $code = 0, $data = '')
    {
        $this->message  = $message;
        $this->code     = $code;
        $this->data     = $data;
    }

}
