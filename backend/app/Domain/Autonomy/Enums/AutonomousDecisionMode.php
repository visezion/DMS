<?php

namespace App\Domain\Autonomy\Enums;

final class AutonomousDecisionMode
{
    public const RECOMMEND_ONLY = 'recommend_only';
    public const APPROVAL_REQUIRED = 'approval_required';
    public const AUTO_EXECUTE = 'auto_execute';

    public static function all(): array
    {
        return [
            self::RECOMMEND_ONLY,
            self::APPROVAL_REQUIRED,
            self::AUTO_EXECUTE,
        ];
    }
}
