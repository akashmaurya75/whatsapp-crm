<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small rule-based validator.
 * Rules: required, nullable, string, email, int, min:N, max:N, in:a,b,c, url, bool
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $validated = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     */
    public function __construct(private array $data, private array $rules)
    {
        $this->run();
    }

    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $empty = $value === null || $value === '' || $value === [];
            if ($empty) {
                if (in_array('required', $rules, true)) {
                    $this->errors[$field] = 'This field is required.';
                } elseif (in_array('bool', $rules, true)) {
                    $this->validated[$field] = false;
                } else {
                    $this->validated[$field] = null;
                }
                continue;
            }
            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = match ($name) {
                    'string' => is_scalar($value) ? null : 'Must be text.',
                    'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Must be a valid email address.',
                    'int' => filter_var($value, FILTER_VALIDATE_INT) !== false ? null : 'Must be a whole number.',
                    'url' => (filter_var($value, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', (string) $value)) ? null : 'Must be an http(s) URL.',
                    'min' => mb_strlen((string) $value) >= (int) $arg ? null : "Must be at least $arg characters.",
                    'max' => mb_strlen((string) $value) <= (int) $arg ? null : "Must be at most $arg characters.",
                    'in' => in_array((string) $value, explode(',', (string) $arg), true) ? null : 'Invalid selection.',
                    'bool' => null,
                    default => null,
                };
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    continue 2;
                }
            }
            if (in_array('int', $rules, true)) {
                $value = (int) $value;
            } elseif (in_array('bool', $rules, true)) {
                $value = in_array($value, [true, 1, '1', 'on', 'true', 'yes'], true);
            } elseif (is_scalar($value)) {
                $value = (string) $value;
            }
            $this->validated[$field] = $value;
        }
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        return $this->validated;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }
}
