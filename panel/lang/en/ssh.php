<?php

// SSH login of a server (E-155), administrators only.
return [
    'title' => 'SSH',
    'card' => 'Password login',
    'state_on' => 'Administrators can log in over SSH with a password.',
    'state_off' => 'Administrators log in over SSH with a key only. SFTP customers keep their password.',
    'state_on_badge' => 'allowed',
    'state_off_badge' => 'off',
    'foreign' => 'Turned off by a setting outside the panel. It can only be allowed again there.',
    'account' => 'Account',
    'key' => 'SSH key',
    'key_yes' => 'present',
    'key_no' => 'missing',
    'missing' => 'While an account has no key, password login cannot be turned off: you would lock yourself out. Add a key to ~/.ssh/authorized_keys first.',
    'turn_off' => 'Turn off password login',
    'turn_on' => 'Allow password login again',
    'turning_off' => 'Turning off …',
    'turning_on' => 'Allowing …',
    'busy' => 'A change to SSH is running for this server. Please wait until it is done.',
    'unavailable' => 'The state could not be read: :error',
    'last_failed' => 'The last change failed.',
    'sftp_title' => 'SFTP and AllowUsers/AllowGroups',
    'sftp_ok' => 'sshd does not restrict login, or the group owpanel-sftp is allowed.',
    'sftp_blocked' => 'sshd allows only certain users or groups, and owpanel-sftp is not among them. SFTP customers cannot get in. Add "AllowGroups … owpanel-sftp" to the sshd configuration.',
    'about' => [
        'Without password login, attackers with guessed passwords no longer get in. Administrators then log in with an SSH key only.',
        'SFTP customers keep their password, because the group owpanel-sftp has its own rule.',
        'The panel only turns it off when root and every other account with login has a key. The configuration is checked with sshd -t before it is loaded.',
        'The base setup does not change this setting.',
    ],
];
