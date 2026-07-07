<?php

namespace Tests\Concerns;

use App\Http\Middleware\EnsureCsrfForStateChangingApi;

trait DisablesCsrfForTesting
{
    protected function disableCsrfForTesting(): void
    {
        $this->withoutMiddleware(EnsureCsrfForStateChangingApi::class);
    }
}
