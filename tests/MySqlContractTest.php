<?php

namespace CeibaDB\Tests;

use CeibaDB;
use PHPUnit\Framework\TestCase;

/**
 * Contract against a real MySQL/MariaDB server. Opt-in: set CEIBADB_TEST_MYSQL_HOST,
 * CEIBADB_TEST_MYSQL_PORT, CEIBADB_TEST_MYSQL_DB, CEIBADB_TEST_MYSQL_USER and
 * CEIBADB_TEST_MYSQL_PASS. Uses a random table prefix and drops its tables.
 */
final class MySqlContractTest extends TestCase
{
    private CeibaDB $db;
    private string $prefix;

    protected function setUp(): void
    {
        if (!getenv('CEIBADB_TEST_MYSQL_HOST')) {
            $this->markTestSkipped('Set CEIBADB_TEST_MYSQL_* to run the MySQL contract');
        }
        $this->prefix = 'cdb_' . bin2hex(random_bytes(3)) . '_';
        $this->db = CeibaDB::getInstance([
            'driver' => 'mysql', 'host' => getenv('CEIBADB_TEST_MYSQL_HOST'), 'port' => getenv('CEIBADB_TEST_MYSQL_PORT') ?: 3306,
            'db' => getenv('CEIBADB_TEST_MYSQL_DB'), 'username' => getenv('CEIBADB_TEST_MYSQL_USER'),
            'password' => getenv('CEIBADB_TEST_MYSQL_PASS'), 'prefix' => $this->prefix, 'timeout' => 5,
        ]);
        $this->db->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
        $this->db->pdo()->exec("CREATE TABLE {$this->prefix}items (id INT AUTO_INCREMENT PRIMARY KEY, `key` VARCHAR(20),
            `group` VARCHAR(20), active TINYINT(1) NOT NULL, qty INT NOT NULL DEFAULT 0, deleted_at DATETIME NULL) ENGINE=InnoDB");
        $this->db->pdo()->exec("CREATE TABLE {$this->prefix}docs (id CHAR(36) PRIMARY KEY, title VARCHAR(50)) ENGINE=InnoDB");
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->pdo()->exec("DROP TABLE IF EXISTS {$this->prefix}items, {$this->prefix}docs");
        }
        CeibaDB::resetInstances();
    }

    public function test_reserved_words_strict_typing_and_portable_limit(): void
    {
        $items = $this->db->query('items');
        $items->insertBatch([
            ['key' => 'b', 'group' => 'x', 'active' => false, 'qty' => 1, 'deleted_at' => null],
            ['key' => 'a', 'group' => 'x', 'active' => true, 'qty' => 2, 'deleted_at' => new \DateTimeImmutable('2026-01-01')],
            ['key' => 'c', 'group' => 'w', 'active' => true, 'qty' => 3, 'deleted_at' => null],
        ]);
        $rows = $this->db->query('items')->orderBy('group')->orderBy('key')->get();
        $this->assertSame(['c', 'a', 'b'], array_column($rows, 'key'));
        $this->assertSame(['b'], $this->db->query('items')->where(['active' => false])->pluck('key'));
        $this->assertSame(['a'], $this->db->query('items')->whereNotNull('deleted_at')->pluck('key'));
        $this->assertSame(['a'], $this->db->query('items')->orderBy('key')->limit(1, 0)->pluck('key'));
        $this->assertSame(['b'], $this->db->query('items')->orderBy('key')->limit(5, 1)->limit(1, 1)->pluck('key'));
        $this->assertSame(2, $this->db->query('items')->where('qty', '>=', 2)->count());
        $this->assertSame(1, $this->db->query('items')->where(['key' => 'c'])->increment('qty', 2));
        $this->assertSame(['5'], array_map('strval', $this->db->query('items')->where(['key' => 'c'])->pluck('qty')));
        $this->assertTrue($this->db->query('items')->truncate());
        $this->assertSame(0, $this->db->query('items')->count());
    }

    public function test_uuid_insert_returns_zero_and_savepoints_work_on_innodb(): void
    {
        $this->assertSame(0, $this->db->query('docs')->insert(['id' => '00000000-0000-4000-8000-000000000001', 'title' => 't']));
        $this->db->transaction(function (CeibaDB $db): void {
            $db->query('docs')->insert(['id' => '00000000-0000-4000-8000-000000000002', 'title' => 'outer']);
            try {
                $db->transaction(function (CeibaDB $db): void {
                    $db->query('docs')->insert(['id' => '00000000-0000-4000-8000-000000000003', 'title' => 'inner']);
                    throw new \DomainException('inner');
                });
            } catch (\DomainException) {
            }
        });
        $this->assertSame(['outer', 't'], $this->db->query('docs')->orderBy('title')->pluck('title'));
    }
}
