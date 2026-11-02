<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\ValidateSignature as Middleware;

class ValidateSignature extends Middleware
{
    protected $except = [
        //
    ];
}
