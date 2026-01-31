<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAuditService;
use App\Services\SecurityAlertService;
use App\Services\SecurityPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SecurityPolicyController extends Controller
{
    protected SecurityPolicyService $policyService;
    protected AdminAuditService $auditService;
    protected SecurityAlertService $alertService;

    public function __construct(
        SecurityPolicyService $policyService,
        AdminAuditService $auditService,
        SecurityAlertService $alertService,
    ) {
        $this->policyService = $policyService;
        $this->auditService = $auditService;
        $this->alertService = $alertService;
    }

    /**
     * Display a listing of security policies.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Super admin can see all policies
        if ($user->isSuperAdmin()) {
            $policies = DB::table("security_policies")
                ->orderBy("scope_type")
                ->orderBy("key")
                ->get()
                ->map(fn($p) => $this->formatPolicy($p));

            return response()->json([
                "success" => true,
                "data" => $policies,
                "defaults" => SecurityPolicyService::DEFAULTS,
                "descriptions" => SecurityPolicyService::DESCRIPTIONS,
            ]);
        }

        // School admin can only see their school's policies + global
        if ($user->isSchoolAdmin()) {
            $policies = DB::table("security_policies")
                ->where(function ($query) use ($user) {
                    $query
                        ->where("scope_type", "global")
                        ->orWhere(function ($q) use ($user) {
                            $q->where("scope_type", "school")->where(
                                "scope_id",
                                $user->school_id,
                            );
                        });
                })
                ->orderBy("scope_type")
                ->orderBy("key")
                ->get()
                ->map(fn($p) => $this->formatPolicy($p));

            // Also include all available policies with current effective values
            $effectivePolicies = $this->policyService->getAllPolicies(
                $user->school_id,
            );

            return response()->json([
                "success" => true,
                "data" => $policies,
                "effective" => $effectivePolicies,
                "school_id" => $user->school_id,
            ]);
        }

        return response()->json(["message" => "Unauthorized"], 403);
    }

    /**
     * Store a newly created security policy.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            "scope_type" => "required|in:global,school",
            "scope_id" => "nullable|integer|exists:schools,id",
            "key" => "required|string|max:255",
            "value" => "required",
            "description" => "nullable|string|max:1000",
        ]);

        // Permission check
        if (
            !$this->canManagePolicy(
                $user,
                $validated["scope_type"],
                $validated["scope_id"] ?? null,
            )
        ) {
            return response()->json(
                [
                    "success" => false,
                    "message" => $this->getPermissionDeniedMessage(
                        $user,
                        $validated["scope_type"],
                    ),
                ],
                403,
            );
        }

        // Validate key exists in DEFAULTS (optional: allow custom keys)
        if (
            !array_key_exists(
                $validated["key"],
                SecurityPolicyService::DEFAULTS,
            )
        ) {
            return response()->json(
                [
                    "success" => false,
                    "message" =>
                        "Invalid policy key. Allowed keys: " .
                        implode(
                            ", ",
                            array_keys(SecurityPolicyService::DEFAULTS),
                        ),
                ],
                422,
            );
        }

        // Validate value
        $validationErrors = $this->policyService->validate(
            $validated["key"],
            $validated["value"],
        );
        if (!empty($validationErrors)) {
            return response()->json(
                [
                    "success" => false,
                    "message" => "Validation failed",
                    "errors" => $validationErrors,
                ],
                422,
            );
        }

        // Check if policy already exists
        $existing = DB::table("security_policies")
            ->where("scope_type", $validated["scope_type"])
            ->where("scope_id", $validated["scope_id"] ?? null)
            ->where("key", $validated["key"])
            ->first();

        if ($existing) {
            return response()->json(
                [
                    "success" => false,
                    "message" => "Policy already exists. Use PUT to update.",
                    "existing_id" => $existing->id,
                ],
                409,
            );
        }

        // Create policy
        $policyId = DB::table("security_policies")->insertGetId([
            "scope_type" => $validated["scope_type"],
            "scope_id" => $validated["scope_id"] ?? null,
            "key" => $validated["key"],
            "value" => json_encode($validated["value"]),
            "description" =>
                $validated["description"] ??
                (SecurityPolicyService::DESCRIPTIONS[$validated["key"]] ??
                    null),
            "updated_by" => $user->id,
            "created_at" => now(),
            "updated_at" => now(),
        ]);

        // Clear cache
        $this->policyService->clearCache(
            $validated["key"],
            $validated["scope_id"] ?? null,
        );

        // Audit log
        $this->auditService->log("security_policy_created", $user->id, [
            "policy_id" => $policyId,
            "key" => $validated["key"],
            "value" => $validated["value"],
            "scope_type" => $validated["scope_type"],
            "scope_id" => $validated["scope_id"] ?? null,
        ]);

        // Security alert for sensitive policy changes
        $this->createSecurityAlert(
            $user,
            "create",
            $validated["key"],
            null,
            $validated["value"],
            $validated["scope_id"] ?? null,
        );

        // Log to history
        $this->logPolicyHistory(
            $policyId,
            null,
            $validated["value"],
            $user->id,
        );

        Log::channel("security")->info("Security policy created", [
            "policy_id" => $policyId,
            "key" => $validated["key"],
            "user_id" => $user->id,
            "user_name" => $user->name,
        ]);

        return response()->json(
            [
                "success" => true,
                "message" => "Policy created successfully",
                "data" => [
                    "id" => $policyId,
                    "key" => $validated["key"],
                    "value" => $validated["value"],
                    "scope_type" => $validated["scope_type"],
                    "scope_id" => $validated["scope_id"] ?? null,
                ],
            ],
            201,
        );
    }

    /**
     * Display the specified security policy.
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $policy = DB::table("security_policies")->where("id", $id)->first();

        if (!$policy) {
            return response()->json(
                [
                    "success" => false,
                    "message" => "Policy not found",
                ],
                404,
            );
        }

        // Permission check
        if (!$this->canViewPolicy($user, $policy)) {
            return response()->json(
                [
                    "success" => false,
                    "message" => "Unauthorized to view this policy",
                ],
                403,
            );
        }

        // Get history
        $history = DB::table("security_policy_history")
            ->where("policy_id", $id)
            ->orderBy("created_at", "desc")
            ->limit(20)
            ->get();

        return response()->json([
            "success" => true,
            "data" => $this->formatPolicy($policy),
            "history" => $history,
            "default_value" =>
                SecurityPolicyService::DEFAULTS[$policy->key] ?? null,
        ]);
    }

    /**
     * Update the specified security policy.
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $policy = DB::table("security_policies")->where("id", $id)->first();

        if (!$policy) {
            return response()->json(
                [
                    "success" => false,
                    "message" => "Policy not found",
                ],
                404,
            );
        }

        // Permission check
        if (
            !$this->canManagePolicy(
                $user,
                $policy->scope_type,
                $policy->scope_id,
            )
        ) {
            return response()->json(
                [
                    "success" => false,
                    "message" => $this->getPermissionDeniedMessage(
                        $user,
                        $policy->scope_type,
                    ),
                ],
                403,
            );
        }

        $validated = $request->validate([
            "value" => "required",
            "description" => "nullable|string|max:1000",
        ]);

        // Validate value
        $validationErrors = $this->policyService->validate(
            $policy->key,
            $validated["value"],
        );
        if (!empty($validationErrors)) {
            return response()->json(
                [
                    "success" => false,
                    "message" => "Validation failed",
                    "errors" => $validationErrors,
                ],
                422,
            );
        }

        $oldValue = json_decode($policy->value, true);

        // Update policy
        DB::table("security_policies")
            ->where("id", $id)
            ->update([
                "value" => json_encode($validated["value"]),
                "description" =>
                    $validated["description"] ?? $policy->description,
                "updated_by" => $user->id,
                "updated_at" => now(),
            ]);

        // Clear cache
        $this->policyService->clearCache($policy->key, $policy->scope_id);

        // Log to history
        $this->logPolicyHistory($id, $oldValue, $validated["value"], $user->id);

        // Audit log
        $this->auditService->log("security_policy_updated", $user->id, [
            "policy_id" => $id,
            "key" => $policy->key,
            "old_value" => $oldValue,
            "new_value" => $validated["value"],
            "scope_type" => $policy->scope_type,
            "scope_id" => $policy->scope_id,
        ]);

        // Security alert
        $this->createSecurityAlert(
            $user,
            "update",
            $policy->key,
            $oldValue,
            $validated["value"],
            $policy->scope_id,
        );

        Log::channel("security")->info("Security policy updated", [
            "policy_id" => $id,
            "key" => $policy->key,
            "old_value" => $oldValue,
            "new_value" => $validated["value"],
            "user_id" => $user->id,
            "user_name" => $user->name,
        ]);

        return response()->json([
            "success" => true,
            "message" => "Policy updated successfully",
            "data" => [
                "id" => $id,
                "key" => $policy->key,
                "old_value" => $oldValue,
                "new_value" => $validated["value"],
            ],
        ]);
    }

    /**
     * Remove the specified security policy (revert to default).
     */
    public function destroy(int $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $policy = DB::table("security_policies")->where("id", $id)->first();

        if (!$policy) {
            return response()->json(
                [
                    "success" => false,
                    "message" => "Policy not found",
                ],
                404,
            );
        }

        // Permission check
        if (
            !$this->canManagePolicy(
                $user,
                $policy->scope_type,
                $policy->scope_id,
            )
        ) {
            return response()->json(
                [
                    "success" => false,
                    "message" => $this->getPermissionDeniedMessage(
                        $user,
                        $policy->scope_type,
                    ),
                ],
                403,
            );
        }

        $oldValue = json_decode($policy->value, true);

        // Delete policy
        DB::table("security_policies")->where("id", $id)->delete();

        // Clear cache
        $this->policyService->clearCache($policy->key, $policy->scope_id);

        // Log to history (mark as deleted)
        $this->logPolicyHistory($id, $oldValue, null, $user->id);

        // Audit log
        $this->auditService->log("security_policy_deleted", $user->id, [
            "policy_id" => $id,
            "key" => $policy->key,
            "deleted_value" => $oldValue,
            "scope_type" => $policy->scope_type,
            "scope_id" => $policy->scope_id,
        ]);

        // Security alert
        $this->createSecurityAlert(
            $user,
            "delete",
            $policy->key,
            $oldValue,
            null,
            $policy->scope_id,
        );

        Log::channel("security")->warning("Security policy deleted", [
            "policy_id" => $id,
            "key" => $policy->key,
            "deleted_value" => $oldValue,
            "user_id" => $user->id,
            "user_name" => $user->name,
        ]);

        return response()->json([
            "success" => true,
            "message" => "Policy deleted. Will now use default value.",
            "default_value" =>
                SecurityPolicyService::DEFAULTS[$policy->key] ?? null,
        ]);
    }

    /**
     * Get available policy keys and their descriptions.
     */
    public function keys(): JsonResponse
    {
        $keys = [];
        foreach (SecurityPolicyService::DEFAULTS as $key => $default) {
            $keys[] = [
                "key" => $key,
                "default" => $default,
                "description" =>
                    SecurityPolicyService::DESCRIPTIONS[$key] ?? null,
                "type" => $this->getValueType($default),
            ];
        }

        return response()->json([
            "success" => true,
            "data" => $keys,
        ]);
    }

    /**
     * Get policy history for a specific policy.
     */
    public function history(int $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $policy = DB::table("security_policies")->where("id", $id)->first();

        if (!$policy) {
            return response()->json(
                [
                    "success" => false,
                    "message" => "Policy not found",
                ],
                404,
            );
        }

        if (!$this->canViewPolicy($user, $policy)) {
            return response()->json(
                [
                    "success" => false,
                    "message" => "Unauthorized",
                ],
                403,
            );
        }

        $history = DB::table("security_policy_history")
            ->where("policy_id", $id)
            ->orderBy("created_at", "desc")
            ->paginate($request->input("per_page", 20));

        return response()->json([
            "success" => true,
            "data" => $history,
        ]);
    }

    /**
     * Bulk update policies (super_admin only).
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->isSuperAdmin()) {
            return response()->json(
                [
                    "success" => false,
                    "message" => "Only super_admin can perform bulk updates",
                ],
                403,
            );
        }

        $validated = $request->validate([
            "policies" => "required|array",
            "policies.*.key" => "required|string",
            "policies.*.value" => "required",
            "policies.*.scope_type" => "required|in:global,school",
            "policies.*.scope_id" => "nullable|integer",
        ]);

        $results = [];
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($validated["policies"] as $policyData) {
                $key = $policyData["key"];
                $value = $policyData["value"];
                $scopeType = $policyData["scope_type"];
                $scopeId = $policyData["scope_id"] ?? null;

                // Validate
                $validationErrors = $this->policyService->validate(
                    $key,
                    $value,
                );
                if (!empty($validationErrors)) {
                    $errors[] = [
                        "key" => $key,
                        "errors" => $validationErrors,
                    ];
                    continue;
                }

                $this->policyService->set($key, $value, $scopeId, $user->id);
                $results[] = [
                    "key" => $key,
                    "status" => "updated",
                ];
            }

            if (!empty($errors)) {
                DB::rollBack();
                return response()->json(
                    [
                        "success" => false,
                        "message" => "Some policies failed validation",
                        "errors" => $errors,
                    ],
                    422,
                );
            }

            DB::commit();

            // Audit log
            $this->auditService->log("security_policy_bulk_update", $user->id, [
                "count" => count($results),
                "keys" => array_column($results, "key"),
            ]);

            return response()->json([
                "success" => true,
                "message" => "Policies updated successfully",
                "results" => $results,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Bulk policy update failed", [
                "error" => $e->getMessage(),
                "user_id" => $user->id,
            ]);

            return response()->json(
                [
                    "success" => false,
                    "message" => "Bulk update failed: " . $e->getMessage(),
                ],
                500,
            );
        }
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Check if user can manage a policy.
     */
    protected function canManagePolicy(
        $user,
        string $scopeType,
        ?int $scopeId,
    ): bool {
        // Super admin can manage all
        if ($user->isSuperAdmin()) {
            return true;
        }

        // School admin can only manage their school's policies
        if (
            $user->isSchoolAdmin() &&
            $scopeType === "school" &&
            $scopeId === $user->school_id
        ) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can view a policy.
     */
    protected function canViewPolicy($user, $policy): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($policy->scope_type === "global") {
            return true;
        }

        return $policy->scope_id === $user->school_id;
    }

    /**
     * Get permission denied message.
     */
    protected function getPermissionDeniedMessage(
        $user,
        string $scopeType,
    ): string {
        if ($scopeType === "global") {
            return "Only super_admin can manage global policies";
        }

        return "School admin can only manage policies for their own school";
    }

    /**
     * Format policy for response.
     */
    protected function formatPolicy($policy): array
    {
        $value = json_decode($policy->value, true);
        
        // Mask sensitive data
        if (is_string($value) && \Illuminate\Support\Str::contains($policy->key, ['key', 'secret', 'token', 'password', 'api'])) {
             $value = \App\Helpers\SecurityHelper::maskSecret($value);
        }

        return [
            "id" => $policy->id,
            "scope_type" => $policy->scope_type,
            "scope_id" => $policy->scope_id,
            "key" => $policy->key,
            "value" => $value,
            "description" => $policy->description,
            "default_value" =>
                SecurityPolicyService::DEFAULTS[$policy->key] ?? null,
            "updated_by" => $policy->updated_by,
            "created_at" => $policy->created_at,
            "updated_at" => $policy->updated_at,
        ];
    }

    /**
     * Log policy change to history table.
     */
    protected function logPolicyHistory(
        int $policyId,
        $oldValue,
        $newValue,
        int $changedBy,
    ): void {
        try {
            DB::table("security_policy_history")->insert([
                "policy_id" => $policyId,
                "old_value" => json_encode($oldValue),
                "new_value" => json_encode($newValue),
                "changed_by" => $changedBy,
                "created_at" => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to log policy history", [
                "policy_id" => $policyId,
                "error" => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create security alert for policy changes.
     */
    protected function createSecurityAlert(
        $user,
        string $action,
        string $key,
        $oldValue,
        $newValue,
        ?int $schoolId,
    ): void {
        // Determine severity based on policy key
        $criticalKeys = [
            "security.admin_session_max_ip_change",
            "security.require_device_approval",
            "security.enable_geofence_check",
            "rate_limit.login_attempts",
        ];

        $highKeys = [
            "attendance.geofence_radius_meters",
            "attendance.teacher_geofence_radius_meters",
            "behavior.anomaly_score_critical",
            "qr.max_age_hours",
        ];

        $severity = "medium";
        if (in_array($key, $criticalKeys)) {
            $severity = "critical";
        } elseif (in_array($key, $highKeys)) {
            $severity = "high";
        }

        $this->alertService->createAlert(
            "security_policy_changed",
            $severity,
            "Security policy '{$key}' was {$action}d by {$user->name}",
            [
                "action" => $action,
                "policy_key" => $key,
                "old_value" => $oldValue,
                "new_value" => $newValue,
                "changed_by" => $user->id,
                "changed_by_name" => $user->name,
                "changed_by_role" => $user->role_type,
            ],
            $user->id,
            $schoolId,
            request()->ip(),
        );
    }

    /**
     * Get value type for documentation.
     */
    protected function getValueType($value): string
    {
        if (is_bool($value)) {
            return "boolean";
        }
        if (is_int($value)) {
            return "integer";
        }
        if (is_float($value)) {
            return "float";
        }
        if (is_array($value)) {
            return "array";
        }
        return "string";
    }
}
