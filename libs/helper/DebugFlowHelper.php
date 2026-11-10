<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use Throwable;

require_once __DIR__ . '/DebugHelper.php';

/**
 * Tracks human-readable module operations using the envelope accepted in OpenHomeAlarm.
 * Callers select safe domain context; request bodies, credentials and arbitrary exceptions
 * must never be passed as context. Pure getters need no separate flow.
 *
 * @version 1.1.0
 */
trait DebugFlowHelper
{
    use DebugHelper;

    /** @var list<int> */
    private array $debugFlowCalls = [];
    private string $debugFlowOperation = '';
    private int $debugFlowSequence = 0;

    /**
     * Runs an entry with balanced output, including early returns and exceptions.
     * Results are summarized by type/size only; exceptions are rethrown unchanged.
     * Callers must supply safe context and may explain false/true by their domain contract.
     *
     * @param array{0:string,1:string}|null $booleanResults Fixed descriptions for false and true.
     */
    protected function DebugFlowCall(string $function, int $line, \Closure $operation, bool $withoutReturnValue = false, array $context = [], ?array $booleanResults = null): mixed
    {
        $this->DebugFlowStart($function, $line, $context);
        $result = null;
        $failed = false;
        try {
            $result = $operation();
            if (is_bool($result) && $booleanResults !== null) {
                $this->DebugFlowEvent($function, $line, 'AKTION', $booleanResults[(int) $result]);
            }
            return $result;
        } catch (Throwable $exception) {
            $failed = true;
            $this->DebugFlowException($function, $line, $exception);
            throw $exception;
        } finally {
            $this->DebugFlowEnd($function, $line, $result, $failed, $withoutReturnValue);
        }
    }

    /** Starts a call; pair it with DebugFlowEnd() in a finally block. */
    protected function DebugFlowStart(string $function, int $line, array $context = []): void
    {
        if ($this->debugFlowCalls === []) {
            $this->debugFlowOperation = uniqid(strtolower(static::class) . '-');
            $this->debugFlowSequence = 0;
        }
        $this->debugFlowCalls[] = ++$this->debugFlowSequence;
        $this->DebugFlowEvent($function, $line, 'START', 'Funktion gestartet.', $context);
    }

    /** Describes the actual return type/value without exposing exported payloads. */
    protected function DebugFlowEnd(string $function, int $line, mixed $result = null, bool $failed = false, bool $withoutReturnValue = true): void
    {
        $description = match (true) {
            $failed                            => 'Durch Ausnahme abgebrochen.',
            $withoutReturnValue                => 'Ohne Rückgabewert beendet; Einzelheiten siehe Ablauf.',
            $result === true                   => 'Ja.',
            $result === false                  => 'Nein.',
            $result === null                   => 'Kein Ergebnis.',
            is_int($result), is_float($result) => $result,
            is_string($result)                 => sprintf('Text zurückgegeben (%d Bytes); Inhalt geschützt.', strlen($result)),
            is_array($result)                  => sprintf('Struktur zurückgegeben (%d Einträge); Inhalt geschützt.', count($result)),
            default                            => 'Ergebnis vom Typ ' . get_debug_type($result) . '.'
        };
        $this->DebugFlowEvent($function, $line, 'ENDE', 'Funktion beendet.', ['Ergebnis' => $description]);
        array_pop($this->debugFlowCalls);
        if ($this->debugFlowCalls === []) {
            $this->debugFlowOperation = '';
        }
    }

    /** Emits a German domain explanation. Debug failures never affect module behavior. */
    protected function DebugFlowEvent(string $function, int $line, string $event, string $message, array $context = []): void
    {
        try {
            $this->SendSafeDebug(
                sprintf('%s | %s | %s:%d', static::class, $event, $function, $line),
                [
                    'Instanz' => $this->InstanceID ?? 0,
                    'Vorgang' => $this->debugFlowOperation ?: uniqid(strtolower(static::class) . '-'),
                    'Aufruf'  => end($this->debugFlowCalls) ?: 0,
                    'Meldung' => $message,
                    'Bezug'   => $context
                ],
                4096,
                ['code', 'pin', 'token', 'user', 'receipt', 'DisarmCode', 'DisarmCodeHash', 'client_secret', 'refresh_token']
            );
        } catch (Throwable) {
            // Logging must not interfere with hardware control or recovery.
        }
    }

    /** Records an exception without arbitrary messages, paths, arguments or payloads. */
    protected function DebugFlowException(string $function, int $line, Throwable $exception): void
    {
        $this->DebugFlowEvent($function, $line, 'FEHLER', 'Verarbeitung durch Ausnahme abgebrochen; bestehende Fehlerbehandlung bleibt wirksam.', [
            'Fehlertyp'   => $exception::class,
            'Fehlerzeile' => $exception->getLine(),
            'Quelldatei'  => basename($exception->getFile())
        ]);
    }

    /** Logs a vetted device command exactly once; acceptance does not prove device execution. */
    protected function DebugFlowRequestAction(int $variableID, mixed $value, string $function, int $line): bool
    {
        $this->DebugFlowEvent($function, $line, 'SENDEN', 'Steuerwert wird an die Symcon-Aktionsschnittstelle übergeben.', ['Variable' => $variableID, 'Wert' => $value]);
        try {
            $accepted = \RequestAction($variableID, $value);
            $this->DebugFlowEvent($function, $line, $accepted ? 'EMPFANG' : 'FEHLER', $accepted
                ? 'Symcon hat den Steuerauftrag angenommen; die Wirkung am Gerät ist noch nicht bestätigt.'
                : 'Symcon hat den Steuerauftrag abgelehnt.', ['Variable' => $variableID]);
            return $accepted;
        } catch (Throwable $exception) {
            $this->DebugFlowException($function, $line, $exception);
            throw $exception;
        }
    }

    /** Preserves the native write result. Callers must exclude private identifiers/payloads. */
    protected function DebugFlowWriteValue(string $ident, mixed $value, string $function, int $line, bool $includeValue = true, array $context = []): mixed
    {
        $result = $this->SetValue($ident, $value);
        $this->DebugFlowEvent($function, $line, 'SENDEN', 'Statuswert an Symcon übergeben.', ['Ziel' => $ident, 'Wert' => $includeValue ? $value : '[Inhalt geschützt]'] + $context);
        return $result;
    }

    /** Reads the source exactly once. Use includeValue=false for private text sources. */
    protected function DebugFlowReadValue(int $variableID, string $function, int $line, bool $includeValue = true): mixed
    {
        $value = \GetValue($variableID);
        $this->DebugFlowEvent($function, $line, 'EMPFANG', 'Quellwert aus Symcon gelesen.', ['Variable' => $variableID, 'Wert' => $includeValue ? $value : '[Inhalt geschützt]']);
        return $value;
    }
}
