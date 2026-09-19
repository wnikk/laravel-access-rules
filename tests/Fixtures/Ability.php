<?php

namespace Tests\Fixtures;

enum Ability: string
{
    case OrdersView   = 'orders.view';
    case OrdersUpdate = 'orders.update';
}
