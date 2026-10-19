<?php

declare(strict_types=1);

namespace Sso\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An account set up on first sign-in can only be reached through a provider account, so it
 * needs at least one account ID.
 */
final class RequiresAccountId implements DataAwareRule, ValidationRule
{
    /**
     * @var array
     */
    protected array $fields;

    /**
     * @var array
     */
    protected array $data = [];

    /**
     * @param array
     */
    public function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    /**
     * @inheritdoc
     */
    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (filter_var($value, FILTER_VALIDATE_BOOLEAN) !== true) {
            return;
        }

        foreach ($this->fields as $field) {
            $id = $this->data[$field] ?? null;
            if (is_string($id) && $id !== '') {
                return;
            }
        }

        $fail('Enter at least one account ID, so the user can sign in to set up their account.');
    }
}
