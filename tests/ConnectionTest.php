<?php

namespace CeibaDB\Tests;

use CeibaDB;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        CeibaDB::resetInstances();
    }

    public function test_instances_are_cached_per_configuration_and_can_be_released(): void
    {
        $a = CeibaDB::getInstance(['driver' => 'sqlite', 'db' => ':memory:', 'prefix' => 'a_']);
        $b = CeibaDB::getInstance(['driver' => 'sqlite', 'db' => ':memory:', 'prefix' => 'b_']);
        $this->assertNotSame($a, $b);
        $this->assertSame($a, CeibaDB::getInstance(['driver' => 'sqlite', 'db' => ':memory:', 'prefix' => 'a_']));
        $this->assertSame($a, CeibaDB::getInstance(), 'First instance is the default');
        $this->assertSame(2, CeibaDB::instanceCount());

        CeibaDB::forget(['driver' => 'sqlite', 'db' => ':memory:', 'prefix' => 'b_']);
        $this->assertSame(1, CeibaDB::instanceCount());
        $this->assertNotSame($b, CeibaDB::getInstance(['driver' => 'sqlite', 'db' => ':memory:', 'prefix' => 'b_']));
    }

    public function test_disconnect_reconnects_lazily(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ceibadb');
        try {
            $db = CeibaDB::getInstance(['driver' => 'sqlite', 'db' => $file]);
            $db->pdo()->exec('CREATE TABLE t (id INTEGER)');
            $first = $db->pdo();
            $db->disconnect();
            $this->assertNotSame($first, $db->pdo());
            $this->assertSame(0, $db->query('t')->count());
        } finally {
            CeibaDB::resetInstances();
            @unlink($file);
        }
    }

    public function test_unconfigured_default_fails_clearly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CeibaDB is not configured');
        CeibaDB::getInstance();
    }

    public function test_mock_instance_wins_until_reset(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        CeibaDB::setInstance($pdo, ['prefix' => '']);
        $this->assertSame($pdo, CeibaDB::getInstance(['driver' => 'sqlite', 'db' => 'other.db'])->pdo());
        CeibaDB::resetInstances();
        $this->assertNotSame($pdo, CeibaDB::getInstance(['driver' => 'sqlite', 'db' => ':memory:'])->pdo());
    }

    public function test_dsn_options_are_validated(): void
    {
        $base = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'db' => 'app', 'username' => 'u', 'password' => 'p'];
        foreach ([
            ['driver' => 'oracle'],
            ['host' => '127.0.0.1;dbname=other'],
            ['db' => "app\n"],
            ['port' => '3306;x'],
            ['port' => 70000],
            ['charset' => 'utf8mb4;sslmode=disable'],
            ['db' => ''],
        ] as $override) {
            try {
                CeibaDB::getInstance($override + $base);
                $this->fail('Accepted ' . json_encode($override));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_connection_errors_never_leak_the_password(): void
    {
        putenv('CEIBADB_DEBUG=1');
        $previousLog = ini_set('error_log', '/dev/null');
        try {
            CeibaDB::getInstance(['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'db' => 'x',
                'username' => 'u', 'password' => 'top-secret-pass', 'timeout' => 1]);
            $this->fail('Connection should fail');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('top-secret-pass', $e->getMessage());
            $this->assertStringContainsString('[REDACTED]', $e->getMessage());
        } finally {
            putenv('CEIBADB_DEBUG');
            ini_set('error_log', (string) $previousLog);
        }
    }

    public function test_version_constant(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', CeibaDB::VERSION);
    }
}
