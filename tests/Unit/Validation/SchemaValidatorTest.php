<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use SuiteCRM\MCP\Validation\SchemaRegistry;
use SuiteCRM\MCP\Validation\SchemaValidator;

final class SchemaValidatorTest extends TestCase
{
    private SchemaValidator $validator;
    private SchemaRegistry $registry;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator();
        $this->registry  = new SchemaRegistry();
    }

    public function testValidGetRecordArguments(): void
    {
        $errors = $this->validator->validate($this->registry->schemaFor('get_record'), [
            'module' => 'Accounts',
            'id'     => 'abc-123',
        ]);
        $this->assertSame([], $errors);
    }

    public function testRejectsUnknownProperty(): void
    {
        $errors = $this->validator->validate($this->registry->schemaFor('get_record'), [
            'module'    => 'Accounts',
            'id'        => 'abc-123',
            'evil_prop' => 'payload',
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not permitted', implode("\n", $errors));
    }

    public function testRejectsMissingRequired(): void
    {
        $errors = $this->validator->validate($this->registry->schemaFor('get_record'), [
            'module' => 'Accounts',
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('missing required property', implode("\n", $errors));
    }

    public function testRejectsBadModulePattern(): void
    {
        $errors = $this->validator->validate($this->registry->schemaFor('get_record'), [
            'module' => 'Accounts/../etc/passwd',
            'id'     => 'abc-123',
        ]);
        $this->assertNotEmpty($errors);
    }

    public function testRejectsOversizedQuery(): void
    {
        $errors = $this->validator->validate($this->registry->schemaFor('search_records'), [
            'query' => str_repeat('A', 1000),
        ]);
        $this->assertNotEmpty($errors);
    }

    public function testTypeErrorsAreNotCoerced(): void
    {
        $errors = $this->validator->validate($this->registry->schemaFor('list_records'), [
            'module' => 'Accounts',
            'limit'  => '50',
        ]);
        $this->assertNotEmpty($errors, 'String must not be coerced into integer.');
    }

    public function testLimitBoundsEnforced(): void
    {
        $errors = $this->validator->validate($this->registry->schemaFor('list_records'), [
            'module' => 'Accounts',
            'limit'  => 9999,
        ]);
        $this->assertNotEmpty($errors);
    }

    public function testEnumLikeArrayOfModules(): void
    {
        $errors = $this->validator->validate($this->registry->schemaFor('search_records'), [
            'query'   => 'acme',
            'modules' => ['Accounts', 'Contacts'],
        ]);
        $this->assertSame([], $errors);
    }
}
