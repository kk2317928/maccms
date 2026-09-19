<?php
namespace app\common\util;

use InvalidArgumentException;

final class ApiV1CatalogQuery
{
    private $values;

    private function __construct(array $values) { $this->values = $values; }

    public static function fromArray(array $query)
    {
        $values = array();
        if (isset($query['sort'])) {
            if (!is_string($query['sort']) || !in_array($query['sort'], array('latest','popular','rating'), true)) { throw new InvalidArgumentException('sort'); }
            $values['sort'] = $query['sort'];
        }
        if (isset($query['type2'])) {
            if (!is_string($query['type2']) || preg_match('/\A[a-z0-9][a-z0-9_-]{0,31}\z/D', $query['type2']) !== 1) { throw new InvalidArgumentException('type2'); }
            $values['type2'] = $query['type2'];
        }
        if (isset($query['year'])) {
            if ((!is_string($query['year']) && !is_int($query['year'])) || preg_match('/\A[0-9]{4}\z/D', (string) $query['year']) !== 1) { throw new InvalidArgumentException('year'); }
            $values['year'] = (int) $query['year'];
        }
        if (isset($query['taxonomy_kind'])) {
            if (!is_string($query['taxonomy_kind']) || !in_array($query['taxonomy_kind'], array('region','genre','tag'), true)) { throw new InvalidArgumentException('taxonomy_kind'); }
            $values['taxonomy_kind'] = $query['taxonomy_kind'];
        }
        if (isset($query['taxonomy_slug'])) {
            if (!is_string($query['taxonomy_slug']) || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $query['taxonomy_slug']) !== 1) { throw new InvalidArgumentException('taxonomy_slug'); }
            $values['taxonomy_slug'] = $query['taxonomy_slug'];
        }
        if (isset($values['taxonomy_kind']) xor isset($values['taxonomy_slug'])) { throw new InvalidArgumentException(isset($values['taxonomy_kind']) ? 'taxonomy_slug' : 'taxonomy_kind'); }
        return new self($values);
    }

    public static function searchTerm(array $query)
    {
        if (!isset($query['q']) || !is_string($query['q'])) { throw new InvalidArgumentException('q'); }
        $term = trim($query['q']);
        $length = function_exists('mb_strlen') ? mb_strlen($term, 'UTF-8') : strlen($term);
        if ($length < 1 || $length > 100) { throw new InvalidArgumentException('q'); }
        return $term;
    }

    public function toArray() { return $this->values; }
}
