<?php

namespace CeibaDB\Tests;

use CeibaDB;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdoResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        CeibaDB::resetInstances();
    }

    public function test_instances_share_the_resolved_pdo_and_keep_their_prefix(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE ase_items (id INTEGER)');
        $pdo->exec('CREATE TABLE cms_items (id INTEGER)');
        $seen = [];
        CeibaDB::usePdoResolver(function (array $config) use ($pdo, &$seen): \PDO {
            $seen[] = $config['prefix'];
            return $pdo;
        });

        $ase = CeibaDB::getInstance(['driver' => 'mysql', 'host' => 'unreachable.invalid', 'db' => 'prod', 'prefix' => 'ase_']);
        $cms = CeibaDB::getInstance(['driver' => 'mysql', 'host' => 'unreachable.invalid', 'db' => 'prod', 'prefix' => 'cms_']);

        $this->assertNotSame($ase, $cms);
        $this->assertSame($pdo, $ase->pdo());
        $this->assertSame($pdo, $cms->pdo());
        $this->assertSame('ase_', $ase->getPrefix());
        $ase->query('items')->insert(['id' => 1]);
        $this->assertSame(1, $ase->query('items')->count());
        $this->assertSame(0, $cms->query('items')->count());
        $this->assertSame(['ase_', 'cms_'], array_values(array_unique($seen)), 'The resolver receives each instance configuration');
        $this->assertTrue(CeibaDB::hasPdoResolver());
    }

    public function test_writes_join_the_host_transaction(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE t_items (id INTEGER)');
        CeibaDB::usePdoResolver(fn () => $pdo);
        $db = CeibaDB::getInstance(['driver' => 'sqlite', 'db' => ':memory:', 'prefix' => 't_']);

        $pdo->beginTransaction();
        $db->transaction(fn ($db) => $db->query('items')->insert(['id' => 1]));
        $this->assertTrue($pdo->inTransaction(), 'Nested work must not commit the host transaction');
        $pdo->rollBack();
        $this->assertSame(0, $db->query('items')->count());
    }

    public function test_resolver_follows_a_replaced_connection(): void
    {
        $current = new \PDO('sqlite::memory:');
        CeibaDB::usePdoResolver(function () use (&$current) { return $current; });
        $db = CeibaDB::getInstance(['driver' => 'sqlite', 'db' => ':memory:', 'prefix' => '']);
        $first = $db->pdo();
        $current = new \PDO('sqlite::memory:');
        $this->assertNotSame($first, $db->pdo());
    }

    public function test_setting_or_clearing_the_resolver_rebinds_cached_instances(): void
    {
        $config = ['driver' => 'sqlite', 'db' => ':memory:', 'prefix' => ''];
        $own = CeibaDB::getInstance($config);
        $shared = new \PDO('sqlite::memory:');
        CeibaDB::usePdoResolver(fn () => $shared);
        $this->assertSame($shared, CeibaDB::getInstance($config)->pdo());
        $this->assertNotSame($own, CeibaDB::getInstance($config));

        CeibaDB::usePdoResolver(null);
        $this->assertNotSame($shared, CeibaDB::getInstance($config)->pdo());
        $this->assertFalse(CeibaDB::hasPdoResolver());
    }

    public function test_reset_clears_the_resolver(): void
    {
        CeibaDB::usePdoResolver(fn () => new \PDO('sqlite::memory:'));
        CeibaDB::resetInstances();
        $this->assertFalse(CeibaDB::hasPdoResolver());
    }

    public function test_resolver_must_return_a_pdo(): void
    {
        CeibaDB::usePdoResolver(fn () => 'nope');
        $this->expectException(\TypeError::class);
        CeibaDB::getInstance(['driver' => 'sqlite', 'db' => ':memory:'])->pdo();
    }

    public function test_disconnect_never_drops_a_resolved_connection(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        CeibaDB::usePdoResolver(fn () => $pdo);
        $db = CeibaDB::getInstance(['driver' => 'sqlite', 'db' => ':memory:']);
        $db->disconnect();
        $this->assertSame($pdo, $db->pdo());
    }
}
