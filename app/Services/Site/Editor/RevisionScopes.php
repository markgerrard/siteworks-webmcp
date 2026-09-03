<?php

namespace App\Services\Site\Editor;

use InvalidArgumentException;

final class RevisionScopes
{
    /**
     * @var array<string, array{scope: string, input_key: string, check: callable(EditorContext, int, EditorState): ?OperationResult}>
     */
    private static array $scopes = [];

    /**
     * Register a revision scope at boot time.
     */
    public static function register(string $scope, string $inputKey, callable $check): void
    {
        if (isset(self::$scopes[$scope])) {
            return;
        }

        self::$scopes[$scope] = [
            'scope' => $scope,
            'input_key' => $inputKey,
            'check' => $check,
        ];
    }

    /**
     * Reset the registry for testing.
     */
    public static function reset(): void
    {
        self::$scopes = [];
    }

    /**
     * Resolve a scope string to its input key.
     *
     * @throws InvalidArgumentException when the scope is not registered.
     */
    public static function inputKey(string $scope): string
    {
        return self::resolve($scope)['input_key'];
    }

    /**
     * Resolve a scope to its concrete base key.
     * Convenience alias for inputKey().
     */
    public static function for(string $scope): string
    {
        return self::inputKey($scope);
    }

    /**
     * @return array{scope: string, input_key: string, check: callable(EditorContext, int, EditorState): ?OperationResult}
     */
    public static function resolve(string $scope): array
    {
        return self::$scopes[$scope]
            ?? throw new InvalidArgumentException("Unknown revision scope [{$scope}].");
    }

    public static function check(
        string $scope,
        EditorContext $context,
        int $expectedRevision,
        EditorState $state,
    ): ?OperationResult {
        return self::resolve($scope)['check']($context, $expectedRevision, $state);
    }

    /**
     * @return list<string>
     */
    public static function scopes(): array
    {
        return array_keys(self::$scopes);
    }
}
