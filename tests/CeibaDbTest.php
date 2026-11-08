<?php

namespace CeibaDB\Tests;

use PHPUnit\Framework\TestCase;
use CeibaDB;
use CeibaQuery;
use LemurDB;
use LemurQuery;
use PDO;

class CeibaDbTest extends TestCase
{
    private PDO $pdo;
    private CeibaDB $db;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            status INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        CeibaDB::setInstance($this->pdo, ['prefix' => '']);
        $this->db = CeibaDB::getInstance();
    }

    public function test_singleton_and_aliases(): void
    {
        $this->assertInstanceOf(CeibaDB::class, $this->db);
        $this->assertTrue(class_exists('CeibaDB'));
        $this->assertTrue(class_exists('CeibaQuery'));
        $this->assertTrue(class_exists('LemurDB'));
        $this->assertTrue(class_exists('LemurQuery'));
        $this->assertSame(CeibaDB::getInstance(), LemurDB::getInstance());
    }

    public function test_insert_and_get(): void
    {
        $this->db->query('users')->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'status' => 1,
        ]);

        $rows = $this->db->query('users')->where(['email' => 'john@example.com'])->get();
        $this->assertCount(1, $rows);
        $this->assertSame('John Doe', $rows[0]['name']);
    }

    public function test_query_builder_sql(): void
    {
        $query = $this->db->query('users')
            ->select(['id', 'name', 'email'])
            ->where(['status' => 1])
            ->orderby('id DESC')
            ->limit(10);

        $sql = $query->toSql();
        $this->assertStringContainsString('SELECT id, name, email FROM `users`', $sql);
        $this->assertStringContainsString('WHERE `status` = ?', $sql);
        $this->assertStringContainsString('LIMIT 0, 10', $sql);
    }

    public function test_order_by_chains_quotes_reserved_words_and_keeps_raw_expressions(): void
    {
        $sql = $this->db->query('users')->orderBy('status', 'DESC')->orderBy('name')->toSql();
        $this->assertStringContainsString('ORDER BY `status` DESC, `name` ASC', $sql);

        $this->assertStringContainsString('ORDER BY `id` DESC', $this->db->query('users')->orderBy('id DESC')->toSql());
        $this->assertStringContainsString('ORDER BY `users`.`id` ASC', $this->db->query('users')->orderBy('users.id')->toSql());
        $this->assertStringContainsString('ORDER BY `t`.`c` DESC', $this->db->query('users')->orderBy('`t`.`c`', 'DESC')->toSql());
        $this->assertStringContainsString('ORDER BY `key` ASC', $this->db->query('users')->orderBy('key')->toSql());

        $this->pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY, `group` TEXT, `key` TEXT)');
        $this->pdo->exec("INSERT INTO settings (`group`, `key`) VALUES ('b', 'x'), ('a', 'z'), ('a', 'y')");
        $rows = $this->db->query('settings')->orderBy('group')->orderBy('key')->get();
        $this->assertSame([['a', 'y'], ['a', 'z'], ['b', 'x']], array_map(fn ($r) => [$r['group'], $r['key']], $rows));
    }

    public function test_backward_compatibility_lemurdb(): void
    {
        $rows = LemurDB::getInstance()->query('users')->get();
        $this->assertIsArray($rows);
    }
}
