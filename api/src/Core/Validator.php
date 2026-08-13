<?php

declare(strict_types=1);

namespace App\Core;

final class Validator
{
    private array $errors = [];

    public function required(array $data, array $fields): self
    {
        foreach ($fields as $field) {
            $value = $data[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $this->errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            }
        }
        return $this;
    }

    public function string(array $data, string $field, int $min = 0, int $max = 255, bool $optional = false): self
    {
        if ($optional && (!isset($data[$field]) || $data[$field] === '')) {
            return $this;
        }
        $value = $data[$field] ?? null;
        if (!is_string($value)) {
            $this->errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' must be text.';
            return $this;
        }
        $length = mb_strlen(trim($value));
        if ($length < $min || $length > $max) {
            $label = ucfirst(str_replace('_', ' ', $field));
            $this->errors[$field] = $min === $max
                ? "{$label} must be exactly {$min} characters."
                : "{$label} must be between {$min} and {$max} characters.";
        }
        return $this;
    }

    public function email(array $data, string $field, bool $optional = true): self
    {
        $value = trim((string) ($data[$field] ?? ''));
        if ($optional && $value === '') {
            return $this;
        }
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = 'Enter a valid email address.';
        }
        return $this;
    }

    public function in(array $data, string $field, array $allowed, bool $optional = false): self
    {
        if ($optional && (!isset($data[$field]) || $data[$field] === '')) {
            return $this;
        }
        if (!in_array($data[$field] ?? null, $allowed, true)) {
            $this->errors[$field] = 'Select a valid ' . str_replace('_', ' ', $field) . '.';
        }
        return $this;
    }

    public function date(array $data, string $field, bool $optional = false): self
    {
        if ($optional && (!isset($data[$field]) || $data[$field] === '')) {
            return $this;
        }
        $value = (string) ($data[$field] ?? '');
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            $this->errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' must use YYYY-MM-DD.';
        }
        return $this;
    }

    public function integer(array $data, string $field, int $min = 0, ?int $max = null, bool $optional = false): self
    {
        if ($optional && (!isset($data[$field]) || $data[$field] === '')) {
            return $this;
        }
        $value = filter_var($data[$field] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || ($max !== null && $value > $max)) {
            $this->errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is invalid.';
        }
        return $this;
    }

    public function add(string $field, string $message): self
    {
        $this->errors[$field] = $message;
        return $this;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function validate(): void
    {
        if ($this->errors !== []) {
            throw new HttpException(422, 'Some submitted information is invalid.', $this->errors);
        }
    }
}
