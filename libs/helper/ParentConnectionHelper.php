<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

/**
 * Provides reusable helpers for a Symcon module's connected parent instance.
 *
 * Intended for classes derived from IPSModule/IPSModuleStrict. The helper reads
 * the physical parent connection from the current instance information and can
 * verify that the referenced parent instance still exists.
 *
 * @version 1.0.0
 */
trait ParentConnectionHelper
{
    /**
     * Returns the connected parent instance ID.
     *
     * @return int Parent instance ID, or 0 if no parent is connected.
     */
    protected function GetParentID(): int
    {
        $instance = IPS_GetInstance($this->InstanceID);

        return (int) ($instance['ConnectionID'] ?? 0);
    }

    /**
     * Checks whether a valid parent instance is connected.
     *
     * @return bool True if the configured parent instance exists.
     */
    protected function HasParent(): bool
    {
        $parentID = $this->GetParentID();

        return $parentID > 0 && IPS_InstanceExists($parentID);
    }
}
