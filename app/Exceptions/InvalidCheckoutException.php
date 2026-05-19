<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InvalidCheckoutException extends ValidationException
{
    /**
     * @param  array<string, array<int, string>|string>  $errors
     */
    public static function withErrors(array $errors): self
    {
        $validator = Validator::make([], []);
        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $msg) {
                $validator->errors()->add($field, $msg);
            }
        }

        return new self($validator);
    }
}
