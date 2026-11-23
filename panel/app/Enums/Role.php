<?php

namespace App\Enums;

/**
 * Role of a panel user (E-021).
 *
 * Administrators manage servers and all websites. Customers belong to a
 * customer record and only see their own websites.
 */
enum Role: string
{
    case Admin = 'admin';
    case Customer = 'customer';

    public function label(): string
    {
        return __('account.roles.'.$this->value);
    }
}
