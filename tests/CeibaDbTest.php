<?php

namespace CeibaDB\Tests;

use PHPUnit\Framework\TestCase;
use LemurDB;
use CeibaDB;
use PDO;

class CeibaDbTest extends TestCase
{
    private PDO $pdo;
    private LemurDB $db;

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

        LemurDB::setInstance($this->pdo, ['prefix' => '']);
        $this->db = LemurDB::getInstance();
    }

    public function test_singleton_and_aliases(): void
    {
        $this->assertInstanceOf(LemurDB::class, $this->db);
        $this->assertTrue(class_exists('CeibaDB'));
        $this->assertTrue(class_exists('CeibaQuery'));
        $this->assertSame(LemurDB::getInstance(), CeibaDB::getInstance());
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
}
