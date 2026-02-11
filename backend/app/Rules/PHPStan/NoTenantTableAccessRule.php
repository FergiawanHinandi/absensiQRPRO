<?php

namespace App\Rules\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;

/**
 * NoTenantTableAccessRule
 * 
 * Prevents direct usage of DB::table() for tenant-scoped tables.
 * This ensures developers must use Eloquent Models which have Global Scopes applied.
 */
class NoTenantTableAccessRule implements Rule
{
    private const TENANT_TABLES = [
        'attendances',
        'students',
        'users',
        'schedules',
        'subscriptions',
        'audit_logs',
        'student_cards'
    ];

    public function getNodeType(): string
    {
        return Node\Expr\StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof Node\Expr\StaticCall) {
            return [];
        }

        if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
            return [];
        }

        // Check for DB::table()
        $className = $node->class->toString();
        $methodName = $node->name->toString();

        if ($className !== 'Illuminate\Support\Facades\DB' || $methodName !== 'table') {
            return [];
        }

        // Check arguments
        if (!isset($node->args[0]) || !$node->args[0]->value instanceof Node\Scalar\String_) {
            return [];
        }

        $tableName = $node->args[0]->value->value;

        if (in_array($tableName, self::TENANT_TABLES)) {
            return [
                sprintf(
                    'SECURITY VIOLATION: Direct access to tenant table "%s" via DB::table() is forbidden. Use Eloquent Model to ensure Tenant Scope is applied.',
                    $tableName
                )
            ];
        }

        return [];
    }
}
