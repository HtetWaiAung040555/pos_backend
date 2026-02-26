<?php

namespace App\Http\Resources\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

trait FormatsLocalDateTime
{
    protected function toLocalDateTime($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = Carbon::parse($value);
        }

        return $value->timezone(config('app.timezone'))->toDateTimeString();
    }
}
