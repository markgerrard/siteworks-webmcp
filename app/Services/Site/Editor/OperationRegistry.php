<?php

namespace App\Services\Site\Editor;

use App\Services\Site\Editor\Shop\ShopWriteOperation;
use InvalidArgumentException;

final class OperationRegistry
{
    /**
     * @var array<string, Operation>
     */
    private array $operations = [];

    /**
     * @param  iterable<Operation>  $operations
     */
    public function __construct(iterable $operations)
    {
        foreach ($operations as $operation) {
            RevisionScopes::resolve($operation->address());
            if ($operation->address() === 'shop' && ! $operation->readOnly() && ! $operation instanceof ShopWriteOperation) {
                throw new InvalidArgumentException(
                    "Shop-addressed write [{$operation->name()}] must extend ShopWriteOperation.",
                );
            }
            $this->operations[$operation->name()] = $operation;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function get(string $name): Operation
    {
        return $this->all()[$name]
            ?? throw new InvalidArgumentException("Unknown editor operation [{$name}].");
    }

    public function effectiveRequiresApproval(string $name): bool
    {
        return $this->requiresApproval($name, []);
    }

    public function effectiveDestructive(string $name): bool
    {
        return $this->destructive($name, []);
    }

    /**
     * @param  list<string>  $visited
     */
    private function requiresApproval(string $name, array $visited): bool
    {
        if (in_array($name, $visited, strict: true)) {
            return false;
        }

        $operation = $this->get($name);

        if ($operation->requiresApproval()) {
            return true;
        }

        $visited[] = $name;

        foreach ($operation->delegatesTo() as $delegate) {
            if ($this->requiresApproval($delegate, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $visited
     */
    private function destructive(string $name, array $visited): bool
    {
        if (in_array($name, $visited, strict: true)) {
            return false;
        }

        $operation = $this->get($name);

        if ($operation->destructive()) {
            return true;
        }

        $visited[] = $name;

        foreach ($operation->delegatesTo() as $delegate) {
            if ($this->destructive($delegate, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, Operation>
     */
    public function all(): array
    {
        if (! (bool) config('demo.enabled')) {
            return $this->operations;
        }

        $hidden = config('demo.hidden_operations', []);
        if (! is_array($hidden) || $hidden === []) {
            return $this->operations;
        }

        return array_filter(
            $this->operations,
            fn (Operation $operation, string $name): bool => ! in_array($name, $hidden, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public static function discover(): self
    {
        $files = glob(app_path('Services/Site/Editor/Operations/*Operation.php')) ?: [];
        sort($files);

        $operations = array_map(
            fn (string $file): Operation => app('App\\Services\\Site\\Editor\\Operations\\'.pathinfo($file, PATHINFO_FILENAME)),
            $files,
        );

        return new self($operations);
    }
}
