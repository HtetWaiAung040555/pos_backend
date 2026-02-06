<?php

namespace App\Http\Resources\Concerns;

trait FormatsLocalDateTime
{
    protected function toLocalDateTime($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = $this->asDateTime($value);
        }

        return $value->timezone(config('app.timezone'))->toDateTimeString();
    }
}
