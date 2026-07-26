<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

/**
 * Provides reusable access helpers for variables below Symcon objects.
 *
 * Intended for classes derived from IPSModule/IPSModuleStrict. The helper
 * resolves variables by ident below the current instance or an explicitly
 * supplied parent object and normalizes a missing lookup to ID 0.
 *
 * @version 1.1.0
 */
trait VariableHelper
{
    /**
     * Returns the variable ID for an ident below a Symcon object.
     *
     * If no parent ID is supplied, the current module instance is used.
     *
     * @param string   $ident    Ident of the variable below the parent object.
     * @param int|null $parentID Optional parent object ID; defaults to the current instance.
     *
     * @return int Variable ID, or 0 if no matching variable exists.
     */
    protected function GetVariableIDByIdent(string $ident, ?int $parentID = null): int
    {
        $resolvedParentID = $parentID ?? $this->InstanceID;
        $variableID = @IPS_GetObjectIDByIdent($ident, $resolvedParentID);

        if (!is_int($variableID) || $variableID <= 0) {
            return 0;
        }

        return IPS_VariableExists($variableID) ? $variableID : 0;
    }

    /**
     * Checks whether a variable with the given ident exists below a Symcon object.
     *
     * If no parent ID is supplied, the current module instance is used.
     *
     * @param string   $ident    Ident of the variable below the parent object.
     * @param int|null $parentID Optional parent object ID; defaults to the current instance.
     *
     * @return bool True if a matching variable exists.
     */
    protected function VariableExists(string $ident, ?int $parentID = null): bool
    {
        return $this->GetVariableIDByIdent($ident, $parentID) > 0;
    }
}
