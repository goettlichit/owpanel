<?php

namespace App\Notify;

enum Channel: string
{
    case Mail = 'mail';
    case Push = 'push';
}
