<?php
namespace app\common\util;

use InvalidArgumentException;

final class ContentImportAuthenticator
{
    private $secret;
    private $clock;
    private $claimNonce;
    private $maxSkew;

    public function __construct($secret, callable $clock = null, callable $claimNonce = null, $maxSkew = 300)
    {
        $this->secret=(string)$secret;
        if(strlen($this->secret)<32) throw new InvalidArgumentException('Import credential is not configured securely.');
        $this->clock=$clock?:'time';
        $this->claimNonce=$claimNonce;
        $this->maxSkew=(int)$maxSkew;
    }

    public function authenticate($method,$path,$body,$timestamp,$nonce,$signature)
    {
        if(!preg_match('/^[0-9]{10}$/',(string)$timestamp)) throw new InvalidArgumentException('Invalid import timestamp.');
        $timestamp=(int)$timestamp;$now=(int)call_user_func($this->clock);
        if(abs($now-$timestamp)>$this->maxSkew) throw new InvalidArgumentException('Import timestamp expired.');
        if(!preg_match('/^[A-Za-z0-9._:-]{12,128}$/',(string)$nonce)) throw new InvalidArgumentException('Invalid import nonce.');
        if(!preg_match('/^[a-f0-9]{64}$/i',(string)$signature)) throw new InvalidArgumentException('Invalid import signature.');
        $canonical=$timestamp."\n".(string)$nonce."\n".strtoupper((string)$method)."\n".(string)$path."\n".hash('sha256',(string)$body);
        if(!hash_equals(hash_hmac('sha256',$canonical,$this->secret),strtolower((string)$signature))) throw new InvalidArgumentException('Invalid import signature.');
        if($this->claimNonce!==null && !call_user_func($this->claimNonce,(string)$nonce,$timestamp)) throw new InvalidArgumentException('Import nonce has already been used.');
        return true;
    }
}
