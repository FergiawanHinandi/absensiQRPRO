<?php

/**
 * PHPStan Custom Rule: Prevent DB::table() on Tenant Models
 * 
 * ✅ SECURITY AUDIT FIX: Raw Query Ban (CRITICAL)
 * 
 * This rule prevents developers from accidentally bypassing Global Scopes
 * by using DB::table() instead of Eloquent models for tenant-scoped entities.
 * 
 * BLOCKED:
 * - DB::table('attendances')->where(...) // ❌ Bypasses SchoolScope
 * - DB::table('students')->get() // ❌ Bypasses SchoolScope
 * 
 * ALLOWED:
 * - Attendance::where(...)->get() // ✅ SchoolScope applied
 * - Student::query()->where(...) // ✅ SchoolScope applied
 * 
 * Usage: Add to phpstan.neon
 */

namespace PHPStan\Rules\TenantSafety;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Node\Expr\StaticCall>
 */
class PreventRawQueryOnTenantModels implements Rule
{
    /**
     * List of tenant-scoped tables that MUST use Eloquent
     */
    private const TENANT_TABLES = [
        'attendances',
        'students',
        'users',
        'schedules',
        'qr_codes',
        'classes',
        'subjects',
        'teacher_devices',
        'security_events',
        'audit_logs',
        'notifications',
    ];

    public function getNodeType(): string
    {
        return Node\Expr\StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // Check if this is DB::table() call
        if (!$node->class instanceof Node\Name) {
            return [];
        }

        $className = $node->class->toString();
        $methodName = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

        // Only check DB::table() calls
        if ($className !== 'DB' && $className !== 'Illuminate\\Support\\Facades\\DB') {
            return [];
        }

        if ($methodName !== 'table') {
            return [];
        }

        // Get the table name argument
        if (count($node->args) === 0) {
            return [];
        }

        $tableArg = $node->args[0]->value;

        // Only check string literals
        if (!$tableArg instanceof Node\Scalar\String_) {
            return [];
        }

        $tableName = $tableArg->value;

        // Check if this is a tenant-scoped table
        if (in_array($tableName, self::TENANT_TABLES, true)) {
            return [
                RuleErrorBuilder::message(
                    sprintf(
                        'Using DB::table(\'%s\') bypasses Global Scopes and may leak data across tenants. ' .
                        'Use Eloquent model instead (e.g., %s::query()).',
                        $tableName,
                        $this->guessModelName($tableName)
                    )
                )->identifier('tenantSafety.rawQuery')->build(),
            ];
        }

        return [];
    }

    private function guessModelName(string $tableName): string
    {
        // Convert table name to likely model name
        $singular = rtrim($tableName, 's');
        $modelName = str_replace('_', '', ucwords($singular, '_'));

        return $modelName;
    }
}
