<?php

declare(strict_types=1);

namespace Academe\LaravelJournal;

use Academe\LaravelJournal\Models\Journal;
use Illuminate\Database\Connection;

/**
 * Batches cached-balance recomputes so a database transaction that
 * writes many journal entries recomputes each affected journal once,
 * instead of once per entry.
 *
 * In the default 'on_commit' mode, a journal touched inside a database
 * transaction is queued here; the queue is flushed just before the
 * outermost transaction commits (via the TransactionCommitting
 * listener), so the balance update still commits atomically with the
 * entries it reflects. Outside a transaction — or in 'immediate' mode —
 * the recompute happens synchronously, exactly as it did before this
 * class existed.
 *
 * A per-connection after-commit callback is also registered as a safety
 * net for environments where the physical outermost transaction never
 * commits: test suites wrap each test in a transaction, so the
 * committing event never fires there, but Laravel's testing
 * transactions manager still executes after-commit callbacks when
 * control returns to the test wrapper. In production the net is a
 * no-op, because the committing listener has already emptied the queue.
 *
 * Registered as a container singleton.
 */
class PendingBalanceUpdates
{
    /**
     * Journals awaiting a recompute, keyed by connection name then
     * journal key.
     *
     * @var array<string, array<int|string, Journal>>
     */
    protected array $pending = [];

    /**
     * Connections that currently have an after-commit net registered.
     *
     * @var array<string, bool>
     */
    protected array $netRegistered = [];

    /**
     * Record that a journal's cached balance is out of date; recompute
     * now or queue for the commit, depending on the configured mode.
     */
    public function record(Journal $journal): void
    {
        $connection = $journal->getConnection();

        if (config('journal.balance_update', 'on_commit') !== 'on_commit'
            || $connection->transactionLevel() === 0
        ) {
            $journal->resetCurrentBalance();

            return;
        }

        $this->pending[(string) $connection->getName()][$journal->getKey()] = $journal;

        $this->registerNet($connection);
    }

    /**
     * Recompute every pending journal for the connection. Called by the
     * TransactionCommitting listener (inside the transaction) and by
     * the after-commit net (a no-op when the listener already ran).
     */
    public function flush(string $connectionName): void
    {
        $journals = $this->pending[$connectionName] ?? [];

        unset($this->pending[$connectionName]);

        foreach ($journals as $journal) {
            $journal->resetCurrentBalance();
        }
    }

    /**
     * Forget the connection's pending journals without recomputing:
     * the transaction that queued them has been rolled back.
     */
    public function discard(string $connectionName): void
    {
        unset($this->pending[$connectionName]);
    }

    /**
     * Allow a new net to be registered after a rollback may have
     * dropped the connection's after-commit callbacks.
     */
    public function resetNet(string $connectionName): void
    {
        unset($this->netRegistered[$connectionName]);
    }

    protected function registerNet(Connection $connection): void
    {
        $name = (string) $connection->getName();

        if ($this->netRegistered[$name] ?? false) {
            return;
        }

        $this->netRegistered[$name] = true;

        $connection->afterCommit(function () use ($name): void {
            $this->netRegistered[$name] = false;

            $this->flush($name);
        });
    }
}
