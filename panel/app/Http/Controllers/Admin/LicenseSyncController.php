<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\StrataLicense;
use Illuminate\Http\RedirectResponse;

class LicenseSyncController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        StrataLicense::sync();

        return back();
    }
}
