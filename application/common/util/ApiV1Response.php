<?php
namespace app\common\util;

use InvalidArgumentException;

class ApiV1Response
{
    public static function success($data, $requestId, array $meta = array())
    {
        self::assertRequestId($requestId);
        $meta = array_merge(array('request_id' => $requestId), $meta);
        return array(
            'data' => self::normalize($data),
            'meta' => $meta,
        );
    }

    public static function collection(array $items, ApiV1Pagination $pagination, $totalItems, $requestId)
    {
        $data = array();
        foreach ($items as $item) {
            $data[] = self::normalize($item);
        }
        return self::success($data, $requestId, array(
            'pagination' => $pagination->meta($totalItems),
        ));
    }

    public static function error($code, $message, $requestId, array $details = array())
    {
        self::assertRequestId($requestId);
        if (!is_string($code) || preg_match('/\A[A-Z][A-Z0-9_]*\z/D', $code) !== 1) {
            throw new InvalidArgumentException('code');
        }
        if (!is_string($message) || $message === '') {
            throw new InvalidArgumentException('message');
        }
        $error = array('code' => $code, 'message' => $message);
        if (!empty($details)) {
            $error['details'] = $details;
        }
        return array(
            'error' => $error,
            'meta' => array('request_id' => $requestId),
        );
    }

    private static function normalize($value)
    {
        if ($value instanceof ApiV1Dto) {
            return $value->toArray();
        }
        return $value;
    }

    private static function assertRequestId($requestId)
    {
        if (!is_string($requestId)
            || strlen($requestId) < 1
            || strlen($requestId) > 64
            || preg_match('/\A[A-Za-z0-9._:-]+\z/D', $requestId) !== 1
        ) {
            throw new InvalidArgumentException('request_id');
        }
    }
}
