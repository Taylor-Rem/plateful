<?php

use Illuminate\Validation\Rules\Password;

it('requires at least 10 characters with mixed case, numbers, and symbols in production', function () {
    app()['env'] = 'production';

    expect(Password::defaults()->toPasswordRulesString())
        ->toContain('minlength: 10')
        ->toContain('required: lower')
        ->toContain('required: upper')
        ->toContain('required: digit')
        ->toContain('required: special');
});

it('uses framework default password rules outside production', function () {
    expect(Password::defaults()->toPasswordRulesString())->toBe('minlength: 8;');
});
