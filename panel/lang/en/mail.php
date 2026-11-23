<?php

// Mails of the panel (E-094).
return [
    'test' => [
        'subject' => 'Test mail from OWPanel',
        'body' => 'The panel at :url sent this test mail. Outgoing mail works.',
    ],
    'reset' => [
        'subject' => 'Reset your password',
        'greeting' => 'Hello :name,',
        'intro' => 'someone asked for a link to reset the password of your account in the panel.',
        'action' => 'Set a new password',
        'expire' => 'The link is valid for :count minutes and only once.',
        'outro' => 'If that was not you, there is nothing to do; your password stays the same.',
        'salutation' => 'Your OWPanel',
    ],

    // Invitation to the customer area (E-128).
    'invitation' => [
        'subject' => 'Your access to the customer area',
        'greeting' => 'Hello :name,',
        'intro' => 'an access to the customer area has been set up for you. There you see your websites.',
        'email' => 'You log in with the email address :email.',
        'action' => 'Set password',
        'expire' => 'The link works :count hours and only once.',
        'outro' => 'If this message means nothing to you, you do not need to do anything.',
    ],
];
