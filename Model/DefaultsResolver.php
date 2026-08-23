<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

/** Pure precedence resolver: global ⊕ project ⊕ form; empty values never clobber. */
class DefaultsResolver
{
    public static function resolve(array $global, array $project, array $form): array
    {
        $out = [];
        foreach ([$global, $project, $form] as $layer) {
            foreach ($layer as $key => $value) {
                if (self::isEmpty($value)) {
                    continue;
                }
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private static function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }
        return $value === null || $value === '';
    }
}
