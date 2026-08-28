<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Fixture;

use Psr\Log\AbstractLogger;
use Storm\ApiOps\OpsAuditLog;
use Stringable;

/**
 * A PSR logger that only remembers, feeding {@see OpsAuditLog}: the tests read back which actions
 * were audited as applied versus refused.
 */
final class RecordingLog extends AbstractLogger
{
    /** @var list<array{message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{message: string, context: array<array-key, mixed>}>
     */
    public function applied(): array
    {
        return array_values(array_filter($this->records, static fn (array $r): bool => ($r['context']['outcome'] ?? null) === 'applied'));
    }
}
