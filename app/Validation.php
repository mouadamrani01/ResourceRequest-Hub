<?php
declare(strict_types=1);

namespace ResourceHub;

final class Validation
{
    public const TYPES = ['CPU', 'GPU', 'Storage'];
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'released'];
    public const PRIORITIES = ['low', 'normal', 'high'];

    public static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /** @return array{data: array<string, mixed>, errors: list<string>} */
    public static function request(array $input): array
    {
        $title = self::text($input['title'] ?? null);
        $type = self::text($input['resource_type'] ?? null);
        $quantity = filter_var(
            $input['quantity'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 100]]
        );
        $purpose = self::text($input['purpose'] ?? null);
        $priority = self::text($input['priority'] ?? 'normal');
        $poolId = self::optionalId($input['resource_pool_id'] ?? null);
        $projectId = self::optionalId($input['project_id'] ?? null);
        $neededFrom = self::text($input['needed_from'] ?? null);
        $neededUntil = self::text($input['needed_until'] ?? null);

        $errors = [];
        if (self::length($title) < 5 || self::length($title) > 120) {
            $errors[] = 'Title must contain 5–120 characters.';
        }
        if (!in_array($type, self::TYPES, true)) {
            $errors[] = 'Select a valid resource type.';
        }
        if ($quantity === false) {
            $errors[] = 'Quantity must be between 1 and 100.';
        }
        if (self::length($purpose) < 15 || self::length($purpose) > 1500) {
            $errors[] = 'Purpose must contain 15–1500 characters.';
        }

        if (!in_array($priority, self::PRIORITIES, true)) {
            $errors[] = 'Select a valid priority.';
        }
        if ($poolId === false || $projectId === false) {
            $errors[] = 'Invalid project or resource pool.';
        }
        if (($neededFrom !== '' && !self::validDate($neededFrom)) || ($neededUntil !== '' && !self::validDate($neededUntil))) {
            $errors[] = 'Dates must use YYYY-MM-DD.';
        } elseif ($neededFrom !== '' && $neededUntil !== '' && $neededFrom > $neededUntil) {
            $errors[] = 'End date cannot precede start date.';
        }
        return [
            'data' => [
                'title' => $title,
                'resource_type' => $type,
                'quantity' => $quantity,
                'purpose' => $purpose,
                'priority' => $priority,
                'resource_pool_id' => $poolId === false ? null : $poolId,
                'project_id' => $projectId === false ? null : $projectId,
                'needed_from' => $neededFrom === '' ? null : $neededFrom,
                'needed_until' => $neededUntil === '' ? null : $neededUntil,
            ],
            'errors' => $errors,
        ];
    }

    private static function optionalId(mixed $value): int|false|null
    {
        if ($value === null || $value === '') { return null; }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? false : (int)$id;
    }

    private static function validDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) { return false; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    public static function decision(string $decision): bool
    {
        return $decision === 'approved' || $decision === 'rejected';
    }

    public static function statusFilter(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !in_array($value, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid status filter.');
        }
        return $value;
    }

    public static function requestId(string $value): ?int
    {
        if (!preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}
