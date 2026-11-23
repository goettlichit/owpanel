<?php

// Messages for the rules the panel uses. Keep the keys in line with
// lang/de/validation.php; Laravel's own English texts cover the rest.
return [
    'confirmed' => 'The :attribute confirmation does not match.',
    'current_password' => 'The password is incorrect.',
    'email' => 'The :attribute must be a valid e-mail address.',
    'enum' => 'The selected :attribute is invalid.',
    'in' => 'The selected :attribute is invalid.',
    'max' => [
        'string' => 'The :attribute must not be longer than :max characters.',
    ],
    'min' => [
        'string' => 'The :attribute must be at least :min characters.',
    ],
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute must be text.',
    'unique' => 'The :attribute has already been taken.',

    'attributes' => [
        'code' => 'code',
        'current_password' => 'current password',
        'email' => 'e-mail address',
        'locale' => 'language',
        'first_name' => 'first name',
        'last_name' => 'last name',
        'name' => 'name',
        'password' => 'password',
        'recovery_code' => 'recovery code',
        'theme' => 'appearance',
    ],
];
