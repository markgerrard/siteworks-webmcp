<?php

use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\OperationSchemas;

function g3ThrowawayOperation(
    string $name,
    bool $requiresApproval,
    bool $destructive,
    array $delegatesTo = [],
): BaseOperation {
    return new class($name, $requiresApproval, $destructive, $delegatesTo) extends BaseOperation
    {
        /**
         * @param  list<string>  $delegatesTo
         */
        public function __construct(
            private readonly string $operationName,
            private readonly bool $approval,
            private readonly bool $isDestructive,
            private readonly array $delegates,
        ) {}

        public function name(): string
        {
            return $this->operationName;
        }

        public function readOnly(): bool
        {
            return false;
        }

        public function requiresApproval(): bool
        {
            return $this->approval;
        }

        public function destructive(): bool
        {
            return $this->isDestructive;
        }

        /**
         * @return list<string>
         */
        public function delegatesTo(): array
        {
            return $this->delegates;
        }

        public function address(): string
        {
            return 'site';
        }

        public function sideEffects(): string
        {
            return 'Throwaway gated destructive write.';
        }

        public function inputSchema(): array
        {
            return ['type' => 'object'];
        }

        public function handle(EditorContext $ctx, array $input): OperationResult
        {
            throw new RuntimeException('throwaway handle is not exercised');
        }
    };
}

it('exports requiresApproval and destructive from a throwaway operation that is on no front-end list', function () {
    $throwaway = g3ThrowawayOperation('g3_throwaway_gated_destructive', true, true);
    app()->instance(OperationRegistry::class, new OperationRegistry([$throwaway]));

    $schema = app(OperationSchemas::class)->all()['g3_throwaway_gated_destructive'];

    // Independent oracle: the throwaway's own declarations. Wrong implementation:
    // the exporter keeps a literal list that is missing a gated operation, so a
    // newly registered destructive-and-gated name is omitted from the artefact
    // Front 2 reads.
    expect($schema['requiresApproval'])->toBeTrue()
        ->and($schema['destructive'])->toBeTrue()
        ->and($schema['positionalApprovalGap'])->toBeFalse();
});

it('exports effective requiresApproval for every discovered operation from the registry, not a name list', function () {
    $registry = OperationRegistry::discover();
    $schemas = app(OperationSchemas::class)->all();

    foreach ($registry->all() as $name => $operation) {
        expect($schemas[$name]['requiresApproval'] ?? null)
            ->toBe($registry->effectiveRequiresApproval($name));
    }
});

it('exports effective destructive for every discovered operation from the registry, not a name list', function () {
    $registry = OperationRegistry::discover();
    $schemas = app(OperationSchemas::class)->all();

    foreach ($registry->all() as $name => $operation) {
        expect($schemas[$name]['destructive'] ?? null)
            ->toBe($registry->effectiveDestructive($name));
    }
});

it('declares remove_section, undo_revision and manage_video destructive so Front 2 can annotate them without a list', function () {
    $registry = OperationRegistry::discover();

    foreach (['remove_section', 'undo_revision', 'manage_video'] as $name) {
        if (! $registry->has($name)) {
            continue;
        }

        expect($registry->effectiveDestructive($name))->toBeTrue();
    }
});

it('walks delegatesTo when exporting destructive the same way effectiveRequiresApproval does', function () {
    $target = g3ThrowawayOperation('g3_destructive_target', false, true);
    $wrapper = g3ThrowawayOperation('g3_destructive_wrapper', false, false, ['g3_destructive_target']);
    app()->instance(OperationRegistry::class, new OperationRegistry([$target, $wrapper]));

    $schemas = app(OperationSchemas::class)->all();

    // Wrong implementation: exporter copies $operation->destructive() and misses
    // a wrapper that is only destructive through delegation.
    expect($schemas['g3_destructive_wrapper']['destructive'])->toBeTrue()
        ->and($schemas['g3_destructive_wrapper']['requiresApproval'])->toBeFalse();
});
