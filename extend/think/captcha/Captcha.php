<?php
// Self-contained compatibility implementation for MACCMS/ThinkPHP 5.
// The public API matches topthink/think-captcha 1.x (Apache-2.0).
namespace think\captcha;

use think\Session;

class Captcha
{
    protected $config = [
        'seKey' => 'ThinkPHP.CN',
        'codeSet' => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ',
        'expire' => 1800,
        'fontSize' => 16,
        'imageH' => 40,
        'imageW' => 130,
        'length' => 4,
        'reset' => true,
        'useNoise' => false,
        'useCurve' => false,
    ];

    public function __construct($config = [])
    {
        $this->config = array_merge($this->config, (array) $config);
    }

    public function __get($name)
    {
        return isset($this->config[$name]) ? $this->config[$name] : null;
    }

    public function check($code, $id = '')
    {
        $key = $this->authcode($this->seKey) . $id;
        $stored = Session::get($key, '');
        if (empty($code) || empty($stored) || !is_array($stored)) {
            return false;
        }
        if (time() - (int) $stored['verify_time'] > (int) $this->expire) {
            Session::delete($key, '');
            return false;
        }
        $valid = hash_equals((string) $stored['verify_code'], $this->authcode(strtoupper((string) $code)));
        if ($valid && $this->reset) {
            Session::delete($key, '');
        }
        return $valid;
    }

    public function entry($id = '')
    {
        if (!function_exists('imagecreate') || !function_exists('imagepng')) {
            throw new \RuntimeException('The GD extension is required to render CAPTCHA images.');
        }

        $width = max(80, (int) $this->imageW);
        $height = max(30, (int) $this->imageH);
        $image = imagecreate($width, $height);
        $background = imagecolorallocate($image, 243, 251, 254);
        $foreground = imagecolorallocate($image, random_int(1, 120), random_int(1, 120), random_int(1, 120));
        imagefilledrectangle($image, 0, 0, $width, $height, $background);

        $characters = (string) $this->codeSet;
        $length = max(1, (int) $this->length);
        $code = '';
        for ($index = 0; $index < $length; $index++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        $font = 5;
        $textWidth = imagefontwidth($font) * strlen($code);
        $textHeight = imagefontheight($font);
        imagestring($image, $font, max(2, (int) (($width - $textWidth) / 2)), max(2, (int) (($height - $textHeight) / 2)), $code, $foreground);

        $key = $this->authcode($this->seKey) . $id;
        Session::set($key, [
            'verify_code' => $this->authcode(strtoupper($code)),
            'verify_time' => time(),
        ], '');

        ob_start();
        imagepng($image);
        $content = (string) ob_get_clean();
        imagedestroy($image);

        return response($content, 200, ['Content-Length' => strlen($content)])->contentType('image/png');
    }

    private function authcode($value)
    {
        $key = substr(md5((string) $this->seKey), 5, 8);
        $value = substr(md5((string) $value), 8, 10);
        return md5($key . $value);
    }
}
