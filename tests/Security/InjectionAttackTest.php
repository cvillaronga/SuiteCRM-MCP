<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tests\Security;

use PHPUnit\Framework\TestCase;
use SuiteCRM\MCP\Validation\ParameterSanitizer;
use SuiteCRM\MCP\Validation\SchemaRegistry;
use SuiteCRM\MCP\Validation\SchemaValidator;
use SuiteCRM\MCP\Validation\ValidationException;

/**
 * Negative tests covering the specific regressions called out in the
 * NSA prompt and the advisor review: URL path injection via the
 * `module` field, filter-key injection, control-character smuggling,
 * and deserialization sentinels.
 */
final class InjectionAttackTest extends TestCase
{
    /** @dataProvider hostileModules */
    public function testHostileModuleStringsRejected(string $module): void
    {
        $validator = new SchemaValidator();
        $registry  = new SchemaRegistry();
        $errors    = $validator->validate($registry->schemaFor('get_record'), [
            'module' => $module,
            'id'     => 'abc',
        ]);
        $this->assertNotEmpty($errors, "Expected '$module' to be rejected");
    }

    public function hostileModules(): array
    {
        return [
            ['../etc/passwd'],
            ['Accounts/../../../etc'],
            ['Accounts?evil=1'],
            ['Accounts; rm -rf /'],
            ['Accounts ',],
            ['Accounts\nContacts'],
            [''],
            ['1Accounts'], // must start with letter
            [str_repeat('A', 200)],
        ];
    }

    /** @dataProvider hostileFilters */
    public function testHostileFilterKeysRejected(array $filter): void
    {
        $sanitizer = new ParameterSanitizer();
        $this->expectException(ValidationException::class);
        $sanitizer->sanitiseFilter($filter);
    }

    public function hostileFilters(): array
    {
        return [
            [['name][LIKE' => 'x']],
            [['name&evil' => 'y']],
            [['name' => str_repeat('z', 1000)]],
            [['__class__' => 'EvilHydrate']],
            [['name' => "value\x00"]],
        ];
    }

    public function testRejectsDeserializationSentinelKey(): void
    {
        $sanitizer = new ParameterSanitizer();
        $this->expectException(ValidationException::class);
        $sanitizer->sanitiseArguments([
            'data' => ['__class__' => 'PhpObjectHydrate'],
        ]);
    }
}
