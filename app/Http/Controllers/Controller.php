<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesApiErrors;

abstract class Controller
{
    use HandlesApiErrors;
}
