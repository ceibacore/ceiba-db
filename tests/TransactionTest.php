<?php

namespace CeibaDB\Tests;

use CeibaDB;
use PHPUnit\Framework\TestCase;

final class TransactionTest extends TestCase
{
    private CeibaDB $db;

    protected function setUp(): void
    {
        $this->db = CeibaDB::getInstance(['driver' => 'sqlite', 'db' => ':memory:', 'prefix' => 'tx_']);
        $this->db->pdo()->exec('CREATE TABLE tx_log (id INTEGER PRIMARY KEY, label TEXT)');
    }

    protected function tearDown(): void
    {
        CeibaDB::resetInstances();
    }

    private function labels(): array
    {
        return $this->db->query('log')->orderBy('id')->pluck('label');
    }

    public function test_commit_and_rollback(): void
    {
        $this->assertSame('ok', $this->db->transaction(function (CeibaDB $db) {
            $db->query('log')->insert(['label' => 'committed']);
            return 'ok';
        }));
        try {
            $this->db->transaction(function (CeibaDB $db): void {
                $db->query('log')->insert(['label' => 'rolled back']);
                throw new \DomainException('fail');
            });
        } catch (\DomainException) {
        }
        $this->assertSame(['committed'], $this->labels());
        $this->assertFalse($this->db->pdo()->inTransaction());
    }

    public function test_nested_transactions_use_savepoints(): void
    {
        $this->db->transaction(function (CeibaDB $db): void {
            $db->query('log')->insert(['label' => 'outer']);
            try {
                $db->transaction(function (CeibaDB $db): void {
                    $db->query('log')->insert(['label' => 'inner failed']);
                    throw new \DomainException('inner');
                });
            } catch (\DomainException) {
            }
            $db->transaction(fn (CeibaDB $db) => $db->query('log')->insert(['label' => 'inner kept']));
        });
        $this->assertSame(['outer', 'inner kept'], $this->labels());
    }

    public function test_joins_a_transaction_opened_with_raw_pdo(): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->db->transaction(fn (CeibaDB $db) => $db->query('log')->insert(['label' => 'inside raw']));
        $this->assertTrue($pdo->inTransaction(), 'The caller still owns the outer transaction');
        $pdo->rollBack();
        $this->assertSame([], $this->labels());
    }

    public function test_retries_only_deadlocks_and_serialization_failures(): void
    {
        $calls = 0;
        $result = $this->db->transaction(function (CeibaDB $db) use (&$calls) {
            $calls++;
            if ($calls < 3) {
                $deadlock = new \PDOException('Deadlock found');
                $deadlock->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
                throw $deadlock;
            }
            $db->query('log')->insert(['label' => 'after retries']);
            return $calls;
        }, 3);
        $this->assertSame(3, $result);
        $this->assertSame(['after retries'], $this->labels());

        $calls = 0;
        try {
            $this->db->transaction(function () use (&$calls): void {
                $calls++;
                throw new \PDOException('syntax error');
            }, 5);
        } catch (\PDOException) {
        }
        $this->assertSame(1, $calls, 'Non-retryable errors are not retried');

        $this->expectException(\InvalidArgumentException::class);
        $this->db->transaction(fn () => null, 0);
    }
}
