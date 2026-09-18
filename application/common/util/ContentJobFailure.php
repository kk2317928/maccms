<?php

namespace app\common\util;

use InvalidArgumentException;
use RuntimeException;

class ContentJobFailure extends RuntimeException
{
    private $errorClass;
    private $safeSummary;

    public function __construct(string $errorClass, string $safeSummary)
    {
        if (!preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/', $errorClass)) {
            throw new InvalidArgumentException('Invalid content-job failure class.');
        }
        $safeSummary = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $safeSummary));
        if ($safeSummary === '' || strlen($safeSummary) > 200) {
            throw new InvalidArgumentException('Invalid content-job safe summary.');
        }
        parent::__construct($safeSummary);
        $this->errorClass = $errorClass;
        $this->safeSummary = $safeSummary;
    }

    public function errorClass(): string
    {
        return $this->errorClass;
    }

    public function safeSummary(): string
    {
        return $this->safeSummary;
    }
}
