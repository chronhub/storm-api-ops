<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;

/**
 * What a workflow type DECLARES, read out of the describe document and reduced to the one comparison
 * a screen makes: which spawns the declaration promises.
 *
 * The comparison it enables is the whole point. A saga's children say what HAPPENED; the declaration
 * says what was possible. An arc that was declared and never taken is exactly what an operator is
 * looking for in front of a saga that stopped moving, and it is a question the observed-traffic
 * consoles cannot ask at all: they draw the edges they have seen.
 *
 * ⚠️ The match is on the child WORKFLOW type, since a child row carries its type and not the slot it
 * was spawned into. Two spawns of the same workflow in different slots are therefore indistinguishable
 * from the children alone, and the screen says so rather than claiming a precision it does not have.
 */
final readonly class SagaDeclaration
{
    /**
     * @param  list<array{slot: string, workflow: string, awaited_by: string|null}>  $spawns
     */
    private function __construct(
        public bool $available,
        public ?string $reason,
        public array $spawns,
    ) {}

    /**
     * @param  array<string, mixed>|null  $workflows  the describe document's `workflows` section
     */
    public static function forType(?array $workflows, string $type): self
    {
        if ($workflows === null || ($workflows['available'] ?? false) !== true) {
            $reason = $workflows['reason'] ?? null;

            return new self(false, is_string($reason) ? $reason : 'the declaration is not available on this installation', []);
        }

        $definitions = $workflows['definitions'] ?? [];

        if (! is_array($definitions)) {
            return new self(false, 'the describe document carries no workflow definitions', []);
        }

        foreach ($definitions as $definition) {
            if (is_array($definition) && ($definition['name'] ?? null) === $type) {
                return new self(true, null, self::spawnsOf($definition));
            }
        }

        // a type the registry does not know is a real answer and not a failure: the instance may
        // predate a rename, or the screen may be looking at a store another application wrote
        return new self(false, 'no declaration is registered for this workflow type', []);
    }

    /**
     * The declared spawns whose workflow appears among no child of this instance.
     *
     * @param  list<array<string, mixed>>  $children  the instance's child rows
     * @return list<array{slot: string, workflow: string, awaited_by: string|null}>
     */
    public function neverTaken(array $children): array
    {
        $taken = [];

        foreach ($children as $child) {
            $type = $child['workflow_type'] ?? null;

            if (is_string($type)) {
                // @infection-ignore-all equivalent: an isset-style key set, the value is never read,
                // so any value under the key behaves identically
                $taken[$type] = true;
            }
        }

        return array_values(array_filter(
            $this->spawns,
            static fn (array $spawn): bool => ! isset($taken[$spawn['workflow']]),
        ));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array{slot: string, workflow: string, awaited_by: string|null}>
     */
    private static function spawnsOf(array $definition): array
    {
        $spawns = $definition['spawns'] ?? [];
        $shaped = [];

        if (! is_array($spawns)) {
            return [];
        }

        foreach ($spawns as $spawn) {
            if (! is_array($spawn) || ! is_string($spawn['slot'] ?? null) || ! is_string($spawn['workflow'] ?? null)) {
                continue;
            }

            $awaitedBy = $spawn['awaited_by'] ?? null;
            $shaped[] = ['slot' => $spawn['slot'], 'workflow' => $spawn['workflow'], 'awaited_by' => is_string($awaitedBy) ? $awaitedBy : null];
        }

        return $shaped;
    }
}
