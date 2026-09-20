<?php

namespace addons\adminloginbg;

use think\Addons;

/**
 * 登录背景图插件
 */
class Adminloginbg extends Addons
{

    /**
     * 插件安装方法
     * @return bool
     */
    public function install()
    {
        return true;
    }

    /**
     * 插件卸载方法
     * @return bool
     */
    public function uninstall()
    {
        return true;
    }

    public function adminLoginInit(\think\Request &$request)
    {
        $info = $this->getInfo();
        if($info['state'] ==1) {
            $config = $this->getConfig();
            $background = $config['image'];
            \think\View::instance()->assign('background', $background);
        }
    }

}
