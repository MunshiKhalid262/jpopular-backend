<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Laravel 12's slim skeleton ships an empty base controller; authorization
    // is opt-in. Enabled here so controllers can call $this->authorize(...).
    use AuthorizesRequests;
}
