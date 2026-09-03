<?php

namespace App\Services\Site\Editor;

use App\Services\Site\Editor\Shop\ShopWriteOperation;

final class ExpectedRevision
{
    /**
     * Normalise the input by resolving the expected_revision alias into the
     * concrete revision key appropriate for the operation's address kind.
     *
     * Called by all three fronts ahead of their own preflights, so this
     * logic is implemented once rather than three times.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|OperationResult  Rewritten input, or a validation result on conflict.
     */
    public static function normalise(Operation $op, array $input): array|OperationResult
    {
        if ($op instanceof ShopWriteOperation) {
            ShopWriteOperation::bindIncoming($op, $input);
        }

        if ($op->readOnly()) {
            return $input;
        }

        $alias = self::intOrNull($input['expected_revision'] ?? null);

        if ($alias === null) {
            return $input;
        }

        $address = $op->address();
        $concreteKey = RevisionScopes::inputKey($address);

        // Site-addressed with MIXED_ADDRESS possibility: check if call is actually mixed.
        if ($address === 'site' && in_array($op->name(), EditorOperations::MIXED_ADDRESS, true)) {
            $hasRevisionBase = array_key_exists('revision_base', $input)
                && self::intOrNull($input['revision_base'] ?? null) !== null;
            $assignTrue = ($input['assign'] ?? false) === true;

            if ($hasRevisionBase || $assignTrue) {
                // Mixed call: must name both concrete keys; a bare alias is rejected.
                $hasComposition = array_key_exists('composition_revision', $input)
                    && self::intOrNull($input['composition_revision'] ?? null) !== null;

                if (! $hasComposition) {
                    return OperationResult::fail(
                        'validation',
                        'A mixed-address call must name composition_revision and revision_base explicitly; expected_revision is not enough.',
                        new EditorState(siteId: 0, pageId: null, draftRevisionId: null, compositionRevision: 0, pendingPublish: false, structureEpoch: null),
                        ['fields' => [
                            'expected_revision' => ['name composition_revision and revision_base for a mixed-address operation'],
                        ]],
                    );
                }
            }

            // Non-mixed call: alias resolves to composition_revision below.
        }

        return self::resolveInto($input, $concreteKey, $alias);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function missingBase(Operation $operation, array $input): bool
    {
        if (! self::requiresBase($operation)) {
            return false;
        }

        $inputKey = RevisionScopes::inputKey($operation->address());

        return self::intOrNull($input[$inputKey] ?? null) === null;
    }

    public static function requiresBase(Operation $operation): bool
    {
        if ($operation->readOnly() || $operation->managesOwnRevision()) {
            return false;
        }

        $inputKey = RevisionScopes::inputKey($operation->address());

        return $operation->address() !== 'page'
            || array_key_exists($inputKey, $operation->inputSchema()['properties'] ?? []);
    }

    /**
     * Advertise the alias without making the concrete key exclusively required.
     * The at-least-one rule is enforced by normalise() plus missingBase() because
     * Front 3 cannot preserve a root-level oneOf through JsonSchemaBridge.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function schema(Operation $operation, array $schema): array
    {
        if ($operation->readOnly()) {
            return $schema;
        }

        $inputKey = RevisionScopes::inputKey($operation->address());

        if (! array_key_exists($inputKey, $schema['properties'] ?? [])) {
            return $schema;
        }

        $schema['required'] = array_values(array_filter(
            $schema['required'] ?? [],
            static fn (string $required): bool => $required !== $inputKey,
        ));
        $schema['properties']['expected_revision'] = [
            'type' => 'integer',
            'description' => "Alias for {$inputKey}. At least one of expected_revision and {$inputKey} is required.",
        ];

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|OperationResult
     */
    private static function resolveInto(array $input, string $concreteKey, int $alias): array|OperationResult
    {
        $concrete = self::intOrNull($input[$concreteKey] ?? null);

        if ($concrete === null) {
            // Alias alone: populate the concrete key.
            $input[$concreteKey] = $alias;

            return $input;
        }

        if ($concrete === $alias) {
            // Alias and concrete key agree: keep both, no conflict.
            return $input;
        }

        // Conflict: both present and unequal.
        return OperationResult::fail(
            'validation',
            "expected_revision ({$alias}) and {$concreteKey} ({$concrete}) differ; provide only one.",
            new EditorState(siteId: 0, pageId: null, draftRevisionId: null, compositionRevision: 0, pendingPublish: false, structureEpoch: null),
            ['fields' => [
                'expected_revision' => ["conflicts with {$concreteKey}"],
                $concreteKey => ['conflicts with expected_revision'],
            ]],
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(0|-?[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
