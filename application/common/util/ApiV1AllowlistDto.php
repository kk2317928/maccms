<?php
namespace app\common\util;

use InvalidArgumentException;

class ApiV1AllowlistDto implements ApiV1Dto
{
    private $source;
    private $fields;

    public function __construct(array $source, array $fields)
    {
        $seen = array();
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '' || isset($seen[$field])) {
                throw new InvalidArgumentException('fields');
            }
            $seen[$field] = true;
        }
        $this->source = $source;
        $this->fields = $fields;
    }

    public function toArray()
    {
        $result = array();
        foreach ($this->fields as $field) {
            if (array_key_exists($field, $this->source)) {
                $result[$field] = $this->source[$field];
            }
        }
        return $result;
    }
}
