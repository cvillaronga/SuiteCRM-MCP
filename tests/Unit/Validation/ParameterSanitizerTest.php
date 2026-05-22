<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use SuiteCRM\MCP\Validation\ParameterSanitizer;
use SuiteCRM\MCP\Validation\ValidationException;

final class ParameterSanitizerTest extends TestCase
{
    private ParameterSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new ParameterSanitizer();
    }

    public function testStripsNothingFromCleanInput(): void
    {
        $clean = $this->sanitizer->sanitiseArguments([
            'module' => 'Accounts',
            'data'   => ['name' => 'Acme', 'phone' => '+1234'],
        ]);
        $this->assertSame('Acme', $clean['data']['name']);
    }

    public function testRejectsControlCharactersInStrings(): void
    {
        $this->expectException(ValidationException::class);
        $this->sanitizer->sanitiseArguments([
            'module' => 'Accounts',
            'data'   => ['name' => "Acme\x07Corp"],
        ]);
    }

    public function testRejectsZeroWidthCharacters(): void
    {
        $this->expectException(ValidationException::class);
        $this->sanitizer->sanitiseArguments([
            'module' => 'Accounts',
            'data'   => ['name' => "Acme\u{200B}Corp"],
        ]);
    }

    public function testRejectsDeserializationSentinels(): void
    {
        $this->expectException(ValidationException::class);
        $this->sanitizer->sanitiseArguments([
            'data' => ['__class__' => 'Evil', 'name' => 'x'],
        ]);
    }

    public function testRejectsOversizedString(): void
    {
        $this->expectException(ValidationException::class);
        $this->sanitizer->sanitiseArguments([
            'data' => ['payload' => str_repeat('A', 10000)],
        ]);
    }

    public function testRejectsModuleOutsideAllowlist(): void
    {
        $this->expectException(ValidationException::class);
        $this->sanitizer->assertModuleAllowed('Users', ['Accounts', 'Contacts']);
    }

    public function testAcceptsModuleInsideAllowlist(): void
    {
        $this->sanitizer->assertModuleAllowed('Accounts', ['Accounts', 'Contacts']);
        $this->addToAssertionCount(1);
    }

    public function testRejectsModuleWithSlash(): void
    {
        $this->expectException(ValidationException::class);
        $this->sanitizer->assertModuleAllowed('Accounts/../passwd', ['Accounts/../passwd']);
    }

    public function testIdentifierMustMatchPattern(): void
    {
        $this->expectException(ValidationException::class);
        $this->sanitizer->assertIdentifier('../../etc/passwd');
    }

    public function testFilterRejectsBadKeys(): void
    {
        $this->expectException(ValidationException::class);
        $this->sanitizer->sanitiseFilter(['name][LIKE' => 'evil']);
    }

    public function testFilterAcceptsScalarValue(): void
    {
        $out = $this->sanitizer->sanitiseFilter(['name' => 'Acme']);
        $this->assertSame(['name' => 'Acme'], $out);
    }
}
