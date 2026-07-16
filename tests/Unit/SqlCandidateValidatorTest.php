<?php

namespace Tests\Unit;

use App\Services\Vanna\SqlCandidateValidator;
use Tests\TestCase;

class SqlCandidateValidatorTest extends TestCase
{
    private SqlCandidateValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SqlCandidateValidator();
    }

    public function test_plain_select_is_safe(): void
    {
        $this->assertTrue($this->validator->isSafeSelect('SELECT * FROM clients'));
    }

    public function test_with_cte_is_safe(): void
    {
        $sql = 'WITH cte AS (SELECT id FROM clients) SELECT * FROM cte';
        $this->assertTrue($this->validator->isSafeSelect($sql));
    }

    public function test_drop_table_is_blocked(): void
    {
        $this->assertFalse($this->validator->isSafeSelect('DROP TABLE x'));
    }

    public function test_delete_from_is_blocked(): void
    {
        $this->assertFalse($this->validator->isSafeSelect('DELETE FROM clients'));
    }

    public function test_update_set_is_blocked(): void
    {
        $this->assertFalse($this->validator->isSafeSelect('UPDATE clients SET name = 1'));
    }

    public function test_insert_into_is_blocked(): void
    {
        $this->assertFalse($this->validator->isSafeSelect("INSERT INTO x VALUES (1)"));
    }

    public function test_select_into_outfile_is_blocked(): void
    {
        $this->assertFalse($this->validator->isSafeSelect("SELECT * FROM clients INTO OUTFILE '/tmp/x'"));
    }

    public function test_update_with_newline_after_keyword_is_blocked(): void
    {
        // Prueba que el guard usa límite de palabra (\b), no un espacio literal de cola.
        $this->assertFalse($this->validator->isSafeSelect("UPDATE\nusers SET x = 1"));
    }

    public function test_executes_read_only_true_for_valid_query(): void
    {
        $this->assertTrue($this->validator->executesReadOnly('SELECT COUNT(*) FROM clients'));
    }

    public function test_executes_read_only_false_for_missing_table(): void
    {
        $this->assertFalse($this->validator->executesReadOnly('SELECT * FROM tabla_inexistente_xyz'));
    }

    public function test_normalize_lowercases_and_collapses_whitespace(): void
    {
        $a = $this->validator->normalize("SELECT   *\nFROM   Clients");
        $b = $this->validator->normalize('select * from clients');
        $this->assertSame($a, $b);
    }
}
