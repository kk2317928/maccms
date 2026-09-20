<?php
// Captcha helpers loaded by application/common.php.

if (!function_exists('captcha_check')) {
    function captcha_check($value, $id = '')
    {
        $captcha = new \think\captcha\Captcha((array) \think\Config::get('captcha'));
        return $captcha->check($value, $id);
    }
}

if (!function_exists('captcha')) {
    function captcha($id = '', $config = [])
    {
        $captcha = new \think\captcha\Captcha($config);
        return $captcha->entry($id);
    }
}
