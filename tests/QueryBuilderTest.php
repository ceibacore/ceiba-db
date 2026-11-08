<?php

namespace CeibaDB\Tests;

use CeibaDB;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueryBuilderTest extends TestCase
{
    private CeibaDB $db;

    protected function setUp(): void
    {
        $this->db = CeibaDB::getInstance(['driver' => 'sqlite', 'db' => ':memory:', 'prefix' => 'qb_']);
        $this->db->pdo()->exec('CREATE TABLE qb_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, `group` TEXT,
            qty INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, deleted_at TEXT, price REAL)');
        $this->db->query('items')->insertBatch([
            ['name' => 'alpha', 'group' => 'a', 'qty' => 5, 'active' => true, 'deleted_at' => null, 'price' => 1.5],
            ['name' => 'beta', 'group' => 'b', 'qty' => 0, 'active' => false, 'deleted_at' => '2026-01-01 00:00:00', 'price' => 2.0],
            ['name' => 'gamma', 'group' => 'a', 'qty' => 9, 'active' => true, 'deleted_at' => null, 'price' => 3.25],
        ]);
    }

    protected function tearDown(): void
    {
        CeibaDB::resetInstances();
        CeibaDB::clearListeners();
    }

    private function names(array $rows): array
    {
        return array_column($rows, 'name');
    }

    public function test_where_forms_operators_and_null_semantics(): void
    {
        $q = fn () => $this->db->query('items')->orderBy('id');
        $this->assertSame(['alpha', 'gamma'], $this->names($q()->where(['deleted_at' => null])->get()));
        $this->assertSame(['beta'], $this->names($q()->where('deleted_at', '!=', null)->get()));
        $this->assertSame(['gamma'], $this->names($q()->where('qty', '>', 5)->get()));
        $this->assertSame(['alpha'], $this->names($q()->where('name', 'alpha')->get()));
        $this->assertSame(['alpha', 'beta'], $this->names($q()->where('name', 'alpha')->orWhere('name', '=', 'beta')->get()));
        $this->assertSame(['alpha', 'gamma'], $this->names($q()->where('name', 'like', '%a')->where('active', true)->get()));
        $this->assertSame(['beta'], $this->names($q()->where(['active' => false])->get()), 'false binds as 0, not empty string');
    }

    public function test_where_in_null_helpers_between_and_like(): void
    {
        $q = fn () => $this->db->query('items')->orderBy('id');
        $this->assertSame(['alpha', 'gamma'], $this->names($q()->whereIn('name', ['gamma', 'alpha'])->get()));
        $this->assertSame([], $q()->whereIn('name', [])->get(), 'Empty IN matches nothing');
        $this->assertCount(3, $q()->whereNotIn('name', [])->get(), 'Empty NOT IN matches everything');
        $this->assertSame(['beta'], $this->names($q()->whereNotIn('name', ['alpha', 'gamma'])->get()));
        $this->assertSame(['beta'], $this->names($q()->whereNotNull('deleted_at')->get()));
        $this->assertSame(['alpha', 'gamma'], $this->names($q()->whereNull('deleted_at')->get()));
        $this->assertSame(['alpha', 'gamma'], $this->names($q()->between('qty', 1, 10)->get()));
        $this->assertSame(['beta', 'gamma'], $this->names($q()->like('name', 'b%')->orLike('name', 'g%')->get()));
        $this->assertSame(['alpha', 'gamma'], $this->names($q()->where('group', 'a')->get()), 'Reserved word column');
    }

    public function test_column_arguments_reject_sql_injection(): void
    {
        $attacks = [
            fn () => $this->db->query('items')->orWhere(['name = name OR 1=1 --' => 'x']),
            fn () => $this->db->query('items')->like('name) OR (1=1', '%'),
            fn () => $this->db->query('items')->between('qty; DROP TABLE qb_items', 1, 2),
            fn () => $this->db->query('items')->orderBy('(SELECT 1)'),
            fn () => $this->db->query('items')->where('name', 'OR 1=1 --', 'x'),
            fn () => $this->db->query('items')->insert(['name`) VALUES (1); --' => 'x']),
            fn () => $this->db->query('items')->where(['id' => 1])->update(['qty = 0, name' => 'x']),
            fn () => $this->db->query('items; DROP TABLE qb_items'),
        ];
        foreach ($attacks as $index => $attack) {
            try {
                $attack();
                $this->fail("Attack {$index} was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(3, $this->db->query('items')->count());
    }

    public function test_invalid_inputs_fail_clearly(): void
    {
        $cases = [
            fn () => $this->db->query('items')->where(['name' => ['a', 'b']]),
            fn () => $this->db->query('items')->where('qty', '>', null),
            fn () => $this->db->query('items')->whereIn('name', [null]),
            fn () => $this->db->query('items')->limit(-1),
            fn () => $this->db->query('items')->insert([]),
            fn () => $this->db->query('items')->where(['id' => 1])->update([]),
            fn () => $this->db->query('items')->insertBatch([['name' => 'x'], ['qty' => 1]]),
            fn () => $this->db->query('items')->where(['name' => new \stdClass()])->get(),
        ];
        foreach ($cases as $index => $case) {
            try {
                $case();
                $this->fail("Case {$index} was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_insert_batch_matches_values_by_column_name(): void
    {
        $this->db->query('items')->insertBatch([
            ['name' => 'delta', 'qty' => 1],
            ['qty' => 2, 'name' => 'epsilon'],
        ]);
        $rows = $this->db->query('items')->whereIn('name', ['delta', 'epsilon'])->orderBy('name')->get();
        $this->assertSame([['delta', 1], ['epsilon', 2]], array_map(fn ($r) => [$r['name'], (int) $r['qty']], $rows));
    }

    public function test_large_batch_is_split_and_atomic(): void
    {
        $rows = [];
        for ($i = 0; $i < 31000; $i++) {
            $rows[] = ['name' => "n{$i}", 'qty' => $i];
        }
        $statements = 0;
        CeibaDB::listen(function (array $event) use (&$statements): void {
            if (str_starts_with($event['sql'], 'INSERT')) {
                $statements++;
            }
        });
        $this->assertSame(31000, $this->db->query('items')->insertBatch($rows));
        $this->assertSame(2, $statements, '62,000 parameters need two statements');

        $broken = $rows;
        $broken[30500] = ['name' => null, 'qty' => null, 'extra' => 1];
        try {
            $this->db->query('items')->insertBatch($broken);
            $this->fail('Mismatched row accepted');
        } catch (InvalidArgumentException) {
        }
        $this->db->pdo()->exec('CREATE TRIGGER qb_fail BEFORE INSERT ON qb_items WHEN NEW.name = \'n30500\' BEGIN SELECT RAISE(ABORT, \'boom\'); END');
        $before = $this->db->query('items')->count();
        try {
            $this->db->query('items')->insertBatch(array_map(fn ($r) => ['name' => $r['name'], 'qty' => $r['qty']], $rows));
            $this->fail('Trigger failure expected');
        } catch (\PDOException) {
        }
        $this->assertSame($before, $this->db->query('items')->count(), 'Earlier chunks were rolled back');
    }

    public function test_reads_do_not_mutate_the_builder(): void
    {
        $query = $this->db->query('items')->where(['group' => 'a'])->orderBy('id');
        $this->assertSame('alpha', $query->first()['name']);
        $this->assertTrue($query->exists());
        $this->assertSame(2, $query->count());
        $this->assertSame(['alpha', 'gamma'], $query->pluck('name'));
        $this->assertSame(['alpha', 'gamma'], $this->names($query->get()), 'first()/count() left no LIMIT behind');
        $this->assertSame(['alpha', 'gamma'], $this->names(iterator_to_array($query->cursor(), false)));
        $this->assertFalse($this->db->query('items')->where(['name' => 'zeta'])->exists());
        $this->assertNull($this->db->query('items')->where(['name' => 'zeta'])->first());
        $this->assertSame(['gamma'], $this->names($this->db->query('items')->orderBy('id')->limit(1, 2)->get()));
    }

    public function test_increment_decrement_update_delete_and_guards(): void
    {
        $this->assertSame(1, $this->db->query('items')->where(['name' => 'alpha'])->increment('qty', 3, ['active' => false]));
        $this->assertSame(1, $this->db->query('items')->where(['name' => 'gamma'])->decrement('qty', 4));
        $qty = fn (string $name) => (int) $this->db->query('items')->where(['name' => $name])->pluck('qty')[0];
        $this->assertSame(8, $qty('alpha'));
        $this->assertSame(5, $qty('gamma'));
        $this->assertSame(1, $this->db->query('items')->where(['name' => 'beta'])->update(['qty' => 7, 'deleted_at' => null]));
        $this->assertSame(1, $this->db->query('items')->whereNull('deleted_at')->where(['name' => 'beta'])->delete());
        foreach (['update' => fn ($q) => $q->update(['qty' => 1]), 'delete' => fn ($q) => $q->delete(),
            'increment' => fn ($q) => $q->increment('qty')] as $name => $operation) {
            try {
                $operation($this->db->query('items'));
                $this->fail("{$name} without WHERE accepted");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertTrue($this->db->query('items')->truncate());
        $this->assertSame(0, $this->db->query('items')->count());
    }

    public function test_typed_binding_of_dates_enums_and_stringables(): void
    {
        $when = new \DateTimeImmutable('2026-01-01 00:00:00');
        $this->assertSame(['beta'], $this->names($this->db->query('items')->where(['deleted_at' => $when])->get()));
        $this->assertSame(['alpha', 'gamma'], $this->names($this->db->query('items')->where(['group' => QbGroup::A])->orderBy('id')->get()));
        $name = new class () implements \Stringable {
            public function __toString(): string
            {
                return 'gamma';
            }
        };
        $this->assertSame(['gamma'], $this->names($this->db->query('items')->where(['name' => $name])->get()));
    }

    public function test_debug_sql_is_quoted_consistently(): void
    {
        $q = $this->db->query('items')->where(['id' => 1]);
        $this->assertSame('INSERT INTO `qb_items` (`name`, `group`) VALUES (?, ?)', $q->toInsertSql(['name' => 'x', 'group' => 'y']));
        $this->assertSame('UPDATE `qb_items` SET `group` = ? WHERE `id` = ?', $q->toUpdateSql(['group' => 'y']));
        $this->assertSame('DELETE FROM `qb_items` WHERE `id` = ?', $q->toDeleteSql());
        $this->assertSame('SELECT * FROM `qb_items` WHERE `id` = ? ORDER BY RANDOM()', $q->orderByRaw('RANDOM()')->toSql());
    }

    public function test_listener_reports_sql_duration_and_errors_without_values(): void
    {
        $events = [];
        CeibaDB::listen(function (array $event) use (&$events): void {
            $events[] = $event;
        });
        CeibaDB::listen(function (): void {
            throw new RuntimeException('listener bug');
        });
        $this->db->query('items')->where(['name' => 'secret-value'])->get();
        try {
            $this->db->query('missing')->get();
        } catch (\PDOException) {
        }
        $this->assertCount(2, $events);
        $this->assertSame('SELECT * FROM `qb_items` WHERE `name` = ?', $events[0]['sql']);
        $this->assertSame(1, $events[0]['params']);
        $this->assertIsFloat($events[0]['durationMs']);
        $this->assertNull($events[0]['error']);
        $this->assertStringNotContainsString('secret-value', json_encode($events));
        $this->assertSame(\PDOException::class, $events[1]['error']);
    }
}

enum QbGroup: string
{
    case A = 'a';
}
