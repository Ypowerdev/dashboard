<?php

namespace App\Policies;

use App\Models\ObjectModel;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ObjectModelPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasAccessToAnyOrganization($user, 'R');
        // return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ObjectModel $objectModel): bool
    {
        return $this->hasAccessToAnyRelatedOrganization($user, $objectModel, 'R');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // return true;
        return $this->hasAccessToAnyOrganization($user, 'C');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ObjectModel $objectModel): bool
    {
        return $this->hasAccessToAnyRelatedOrganization($user, $objectModel, 'U');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ObjectModel $objectModel): bool
    {
        // return true;
        return $this->hasAccessToAnyRelatedOrganization($user, $objectModel, 'D');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ObjectModel $objectModel): bool
    {
        // return true;
        return $this->hasAccessToAnyRelatedOrganization($user, $objectModel, 'U');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ObjectModel $objectModel): bool
    {
        // return true;
        return $this->hasAccessToAnyRelatedOrganization($user, $objectModel, 'D');
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
     * Общий метод для проверки прав в связанных организациях
     */
    private function hasAccessToAnyRelatedOrganization(
        User $user, 
        ObjectModel $object, 
        string $requiredRight
    ): bool {
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
