<?php

namespace App\Http\Controllers\InsertApi;

use App\Http\Controllers\Controller;
use App\Models\ApiChangeLog;
use App\Models\User;
use App\Models\UserRoleRight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Базовый контроллер API для вставки данных
 *
 * Этот класс предоставляет общую функциональность для всех контроллеров API вставки,
 * включая методы аутентификации, проверки прав доступа и логирования изменений API.
 */

class BaseApiController extends Controller
{
    /**
     * Check if user has permission to modify data for a specific object
     *
     * @param User $user
     * @param int $objectId
     * @param string $requiredPermission
     * @return bool
     */
    protected function checkUserPermission(User $user, int $objectId, string $requiredPermission = 'C'): bool
    {
        try {
            // Check if user is admin
            if ($user->is_admin) {
                return true;
            }

            // Get user roles
            $userRoles = UserRoleRight::where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->get();

    //        todo: проработать логику
            foreach ($userRoles as $role) {
                // Check if the user has the required permission
                if (strpos($role->rights, $requiredPermission) !== false) {
                    // For now, we're just checking for the permission, not the specific object
                    // In a more refined implementation, we would check if the user's company
                    // is associated with this object
                    return true;
                }
            }

            return false;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри BaseApiController/checkUserPermission()', [
                'exception' => $th,
            ]);

            return false;
        }
    }

    /**
     * Log API changes
     *
     * @param Request $request
     * @param string $entityType
     * @param int $entityId
     * @param string $action
     * @param array|null $oldValues
     * @param array|null $newValues
     * @return void
     */
    protected function logApiChange(Request $request, string $entityType, int $entityId, string $action, ?array $oldValues = null, ?array $newValues = null): void
    {
        try {
            ApiChangeLog::create([
                'user_id' => Auth::id(),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'action' => $action,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);
        } catch (\Throwable $th) {
            Log::error('ошибка внутри BaseApiController/logApiChange()', [
                'exception' => $th,
            ]);
        }
    }

    /**
     * Return standardized JSON response
     *
     * @param bool $success
     * @param string $message
     * @param array|null $data
     * @param int $statusCode
     * @return JsonResponse
     */
    protected function jsonResponse(bool $success, string $message, ?array $data = null, int $statusCode = 200): JsonResponse
    {
        try {
            $response = [
                'success' => $success,
                'message' => $message
            ];

            if ($data !== null) {
                $response['data'] = $data;
            }

            return response()->json($response, $statusCode);
        } catch (\Throwable $th) {
            Log::error('ошибка внутри BaseApiController/jsonResponse()', [
                'exception' => $th,
            ]);

            return response()->json($response, $statusCode);
        }
    }
}
