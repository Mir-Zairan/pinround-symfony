<?php

declare(strict_types=1);

namespace App\Assist\Api;

/**
 * The name of a model in Ollama on a laptop, as the browser reports it (ai-architecture.md, Laptop mode protocol): 1 to
 * 64 characters from a-z, 0-9 and ":._-", for example "qwen3:4b". It goes into a label people read, so nothing else
 * passes: no capital, space, line end or other script.
 */
final readonly class LocalModelName
{
    /** No "u" flag, so each byte of a multibyte character is refused; \z, because $ would also accept a final newline. */
    public const string PATTERN = '/^[a-z0-9:._-]{1,64}\z/';

    public const string RULE = 'A local model name is 1 to 64 characters from a-z, 0-9 and ":._-".';

    public string $value;

    /**
     * @throws \InvalidArgumentException when the name breaks the rule; the message is RULE, never the name
     */
    public function __construct(string $value)
    {
        if (!self::isValid($value)) {
            throw new \InvalidArgumentException(self::RULE);
        }
        $this->value = $value;
    }

    public static function isValid(string $value): bool
    {
        return 1 === preg_match(self::PATTERN, $value);
    }
}
