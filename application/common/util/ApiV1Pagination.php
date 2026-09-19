<?php
namespace app\common\util;

use InvalidArgumentException;

class ApiV1Pagination
{
    const DEFAULT_PAGE = 1;
    const DEFAULT_PER_PAGE = 20;
    const MAX_PER_PAGE = 100;

    private $page;
    private $perPage;

    private function __construct($page, $perPage)
    {
        $this->page = $page;
        $this->perPage = $perPage;
    }

    public static function fromQuery(array $query)
    {
        $page = self::positiveInteger(isset($query['page']) ? $query['page'] : self::DEFAULT_PAGE, 'page');
        $perPage = self::positiveInteger(isset($query['per_page']) ? $query['per_page'] : self::DEFAULT_PER_PAGE, 'per_page');
        if ($perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException('per_page');
        }
        if (($page - 1) > intdiv(PHP_INT_MAX, $perPage)) {
            throw new InvalidArgumentException('page');
        }
        return new self($page, $perPage);
    }

    public function offset()
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function meta($totalItems)
    {
        if (!is_int($totalItems) || $totalItems < 0) {
            throw new InvalidArgumentException('total_items');
        }
        $totalPages = 0;
        if ($totalItems > 0) {
            $totalPages = intdiv($totalItems, $this->perPage);
            if (($totalItems % $this->perPage) !== 0) {
                $totalPages++;
            }
        }
        return array(
            'current_page' => $this->page,
            'per_page' => $this->perPage,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
        );
    }

    private static function positiveInteger($value, $field)
    {
        if (is_int($value)) {
            if ($value < 1) {
                throw new InvalidArgumentException($field);
            }
            return $value;
        }
        if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            throw new InvalidArgumentException($field);
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($max)
            || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)
        ) {
            throw new InvalidArgumentException($field);
        }
        return (int) $value;
    }
}
