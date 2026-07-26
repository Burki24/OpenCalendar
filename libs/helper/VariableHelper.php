<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

/**
 * Provides reusable access helpers for variables below Symcon objects.
 *
 * Intended for classes derived from IPSModule/IPSModuleStrict. The helper
 * resolves variables by ident below the current instance or an explicitly
 * supplied parent object and normalizes missing or type-incompatible values
 * to caller-defined defaults.
 *
 * @version 1.2.0
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

    /**
     * Returns the raw value of a variable identified below a Symcon object.
     *
     * If the variable does not exist, the caller-defined default is returned.
     * The value itself is deliberately not converted and therefore keeps the
     * native Symcon variable type.
     *
     * @param string   $ident    Ident of the variable below the parent object.
     * @param int|null $parentID Optional parent object ID; defaults to the current instance.
     * @param mixed    $default  Value returned when no matching variable exists.
     *
     * @return mixed Native variable value or the supplied default.
     */
    protected function GetVariableValueByIdent(string $ident, ?int $parentID = null, mixed $default = null): mixed
    {
        $variableID = $this->GetVariableIDByIdent($ident, $parentID);
        if ($variableID <= 0) {
            return $default;
        }

        return GetValue($variableID);
    }

    /**
     * Returns a Boolean variable value or a caller-defined default.
     *
     * Native Boolean values are returned unchanged. Integer and Float values
     * are accepted as numeric Boolean states where zero is false and every
     * non-zero value is true. Other value types fall back to the default.
     *
     * @param string   $ident    Ident of the variable below the parent object.
     * @param int|null $parentID Optional parent object ID; defaults to the current instance.
     * @param bool     $default  Value returned for missing or incompatible variables.
     *
     * @return bool Normalized Boolean value.
     */
    protected function GetBooleanVariableValueByIdent(string $ident, ?int $parentID = null, bool $default = false): bool
    {
        $value = $this->GetVariableValueByIdent($ident, $parentID, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        return $default;
    }

    /**
     * Returns a numeric variable value normalized to Float.
     *
     * Native Integer and Float values are accepted. Other value types fall
     * back to the caller-defined default without string-to-number conversion.
     *
     * @param string   $ident    Ident of the variable below the parent object.
     * @param int|null $parentID Optional parent object ID; defaults to the current instance.
     * @param float    $default  Value returned for missing or incompatible variables.
     *
     * @return float Integer or Float value normalized to Float.
     */
    protected function GetFloatVariableValueByIdent(string $ident, ?int $parentID = null, float $default = 0.0): float
    {
        $value = $this->GetVariableValueByIdent($ident, $parentID, $default);

        return is_int($value) || is_float($value) ? (float) $value : $default;
    }

    /**
     * Returns an Integer variable value or a caller-defined default.
     *
     * No conversion from Float, Boolean or String values is performed.
     *
     * @param string   $ident    Ident of the variable below the parent object.
     * @param int|null $parentID Optional parent object ID; defaults to the current instance.
     * @param int      $default  Value returned for missing or incompatible variables.
     *
     * @return int Native Integer value or the supplied default.
     */
    protected function GetIntegerVariableValueByIdent(string $ident, ?int $parentID = null, int $default = 0): int
    {
        $value = $this->GetVariableValueByIdent($ident, $parentID, $default);

        return is_int($value) ? $value : $default;
    }

    /**
     * Returns a String variable value or a caller-defined default.
     *
     * No conversion from Boolean or numeric values is performed.
     *
     * @param string   $ident    Ident of the variable below the parent object.
     * @param int|null $parentID Optional parent object ID; defaults to the current instance.
     * @param string   $default  Value returned for missing or incompatible variables.
     *
     * @return string Native String value or the supplied default.
     */
    protected function GetStringVariableValueByIdent(string $ident, ?int $parentID = null, string $default = ''): string
    {
        $value = $this->GetVariableValueByIdent($ident, $parentID, $default);

        return is_string($value) ? $value : $default;
    }
}
