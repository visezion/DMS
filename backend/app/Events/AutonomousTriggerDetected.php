<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AutonomousTriggerDetected
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public array $payload
    ) {
    }
}
