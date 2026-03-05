<?php

namespace Pterodactyl\Rules;

use Illuminate\Contracts\Validation\Rule;
use Pterodactyl\Services\Subusers\SubuserFileAccessService;

class ValidRegex implements Rule
{
    /**
     * @param string $attribute
     */
    public function passes($attribute, $value): bool
    {
        return is_string($value) && SubuserFileAccessService::isValidExpression($value);
    }

    public function message(): string
    {
        return 'The :attribute must be a valid regular expression.';
    }
}
