<?php
namespace app\service\auth;

use think\db\Query;

/**
 * Applies a user's data scope to a query. Centralized so all repository
 * code goes through one place — bypassing it is a code-review failure.
 */
class ScopeFilter
{
    public function apply(Query $q, array $scope, string $locationCol = 'location_id', string $departmentCol = 'department_id'): Query
    {
        if (!empty($scope['global'])) return $q;

        $hasLoc = !empty($scope['locations']);
        $hasDep = !empty($scope['departments']);

        if (!$hasLoc && !$hasDep) {
            // No scope assigned: return zero rows (defensive default).
            return $q->whereRaw('1=0');
        }

        return $q->where(function ($sub) use ($scope, $hasLoc, $hasDep, $locationCol, $departmentCol) {
            if ($hasLoc) $sub->whereOr($locationCol, 'in', $scope['locations']);
            if ($hasDep) $sub->whereOr($departmentCol, 'in', $scope['departments']);
        });
    }

    /** Verifies a single (location, department) pair against scope. */
    public function permits(array $scope, ?int $locationId, ?int $departmentId): bool
    {
        if (!empty($scope['global'])) return true;
        if ($locationId !== null && in_array($locationId, $scope['locations'] ?? [], true)) return true;
        if ($departmentId !== null && in_array($departmentId, $scope['departments'] ?? [], true)) return true;
        return false;
    }
}
