<?php

namespace App\Mcp\Tools\Editor;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

final class JsonSchemaBridge
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, Type>
     */
    public static function toBuilder(array $schema, JsonSchema $builder): array
    {
        $schema = self::normalize($schema);
        $required = array_fill_keys($schema['required'] ?? [], true);
        $properties = [];

        foreach ($schema['properties'] ?? [] as $name => $definition) {
            $properties[$name] = self::build($definition, $builder);

            if (isset($required[$name])) {
                $properties[$name]->required();
            }
        }

        return $properties;
    }

    /**
     * Fill the two intentional gaps in the operation-owned schemas without changing those operations.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function normalize(array $schema): array
    {
        $properties = $schema['properties'] ?? [];

        if (in_array('value', $schema['required'] ?? [], true) && ! isset($properties['value'])) {
            $properties['value'] = [
                'anyOf' => [
                    ['type' => 'string'],
                    ['type' => 'object'],
                ],
                'description' => 'Field value. For rich fields, pass a TipTap document object.',
            ];
        }

        if (($properties['fields']['type'] ?? null) === 'array' && ! isset($properties['fields']['items'])) {
            $properties['fields']['items'] = ['type' => 'object'];
        }

        $schema['properties'] = $properties;

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function build(array $definition, JsonSchema $builder): Type
    {
        if (isset($definition['anyOf'])) {
            $type = $builder->anyOf(array_map(
                fn (array $branch): Type => self::build($branch, $builder),
                $definition['anyOf'],
            ));
        } else {
            $declaredType = $definition['type'] ?? 'object';
            $type = is_array($declaredType)
                ? $builder->union($declaredType)
                : match ($declaredType) {
                    'array' => $builder->array(),
                    'boolean' => $builder->boolean(),
                    'integer' => $builder->integer(),
                    'number' => $builder->number(),
                    'object' => $builder->object(self::toBuilder($definition, $builder)),
                    default => $builder->string(),
                };
        }

        if (isset($definition['description'])) {
            $type->description($definition['description']);
        }
        if (isset($definition['enum'])) {
            $type->enum($definition['enum']);
        }

        if ($type instanceof \Illuminate\JsonSchema\Types\StringType) {
            if (isset($definition['minLength'])) {
                $type->min((int) $definition['minLength']);
            }
            if (isset($definition['maxLength'])) {
                $type->max((int) $definition['maxLength']);
            }
            if (isset($definition['pattern'])) {
                $type->pattern((string) $definition['pattern']);
            }
            if (isset($definition['format'])) {
                $type->format((string) $definition['format']);
            }
        }

        if ($type instanceof \Illuminate\JsonSchema\Types\IntegerType || $type instanceof \Illuminate\JsonSchema\Types\NumberType) {
            if (isset($definition['minimum'])) {
                $type->min($definition['minimum']);
            }
            if (isset($definition['maximum'])) {
                $type->max($definition['maximum']);
            }
        }

        if ($type instanceof \Illuminate\JsonSchema\Types\ArrayType) {
            if (isset($definition['items'])) {
                $type->items(self::build($definition['items'], $builder));
            }
            if (isset($definition['minItems'])) {
                $type->min((int) $definition['minItems']);
            }
            if (isset($definition['maxItems'])) {
                $type->max((int) $definition['maxItems']);
            }
        }

        return $type;
    }
}
