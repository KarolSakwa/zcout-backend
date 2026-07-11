<?php

namespace App\Support\Attributes;

final class AttributeAssetPaths
{
    public static function icon(string $attributeKey): string
    {
        return '/icons/attribute-icons/' . $attributeKey . '.svg';
    }
}
