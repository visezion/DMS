<?php

namespace App\Domain\Autonomy\Enums;

final class AutonomousDecisionStatus
{
    public const GENERATED = 'generated';
    public const PENDING_APPROVAL = 'pending_approval';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const EXECUTING = 'executing';
    public const EXECUTED = 'executed';
    public const FAILED = 'failed';
    public const ROLLED_BACK = 'rolled_back';

    public static function all(): array
    {
        return [
            self::GENERATED,
            self::PENDING_APPROVAL,
            self::APPROVED,
            self::REJECTED,
            self::EXECUTING,
            self::EXECUTED,
            self::FAILED,
            self::ROLLED_BACK,
        ];
    }
}
