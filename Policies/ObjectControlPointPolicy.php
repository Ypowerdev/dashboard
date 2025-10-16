<?php

namespace App\Policies;

use App\Models\ObjectControlPoint;
use App\Models\User;
use App\Models\ObjectModel;
use Illuminate\Auth\Access\Response;

class ObjectControlPointPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Требует дополнительной реализации при необходимости фильтрации записей
        return $this->hasAccessToAnyOrganization($user, 'R');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ObjectControlPoint $controlPoint): bool
    {
        return $this->checkObjectAccess($user, $controlPoint->object, 'R');
    }

    /**
     * Determine whether the user can create models.
     * Требует передачи object_id через запрос и проверки в контроллере
     */
    public function create(User $user): bool
    {
        // Рекомендуется выполнять проверку через контроллер
        return $this->hasAccessToAnyOrganization($user, 'C');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ObjectControlPoint $controlPoint): bool
    {
        return $this->checkObjectAccess($user, $controlPoint->object, 'U');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ObjectControlPoint $controlPoint): bool
    {
        return $this->checkObjectAccess($user, $controlPoint->object, 'D');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ObjectControlPoint $controlPoint): bool
    {
        return $this->checkObjectAccess($user, $controlPoint->object, 'U');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ObjectControlPoint $controlPoint): bool
    {
        return $this->checkObjectAccess($user, $controlPoint->object, 'D');
    }

    /**
     * Проверяет доступ к любой организации определённого типа без привязки к объекту
     */
    private function hasAccessToAnyOrganization(User $user, string $requiredRight): bool
    {
        $types = ['oiv', 'customer', 'developer', 'contractor'];
        foreach ($types as $type) {
            if ($user->hasRightToAnyOrganization($requiredRight, $type)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Проверка прав через связанный объект
     */
    private function checkObjectAccess(User $user, ?ObjectModel $object, string $requiredRight): bool
    {
        if (!$object) return false;

        $relatedOrgs = [
            ['type' => 'oiv', 'id' => $object->oiv_id],
            ['type' => 'customer', 'id' => $object->customer_id],
            ['type' => 'developer', 'id' => $object->developer_id],
            ['type' => 'contractor', 'id' => $object->contractor_id],
        ];

        foreach ($relatedOrgs as $org) {
            if ($org['id'] && $user->hasRight($requiredRight, $org['type'], $org['id'])) {
                return true;
            }
        }

        return false;
    }
}