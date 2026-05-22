<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Validation;

class ValidationException extends \RuntimeException
{
    /** @var array<int,string> */
    private array $errors;

    /**
     * @param array<int,string> $errors
     */
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /** @return array<int,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
