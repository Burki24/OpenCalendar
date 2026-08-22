<?php

declare(strict_types=1);

class OpenCalendarDiscovery extends IPSModuleStrict
{
    private const CALENDAR_ACCOUNT_MODULE_ID = '{966D6119-7FF3-5CA5-06C3-536FBF8100C4}';

    /** @var array<string, int> */
    private const PROVIDERS = [
        'apple'     => 0,
        'caldav'    => 1,
        'google'    => 2,
        'microsoft' => 3,
        'ics'       => 4
    ];

    private const ACCOUNT_MODE_NEW = 'new';
    private const ACCOUNT_MODE_EXISTING = 'existing';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterAttributeInteger('SelectedCalendarAccountID', 0);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'WizardProvider':
                $provider = trim((string) $Value);
                $this->assertSupportedProvider($provider);
                $this->SetBuffer('WizardProvider', $provider);
                $this->SetBuffer('WizardAccountSelection', '');
                break;

            case 'WizardProviderUndo':
                $this->SetBuffer('WizardProvider', '');
                $this->SetBuffer('WizardAccountSelection', '');
                break;

            case 'WizardAccountSelection':
                $selection = $this->decodeWizardAccountSelection($Value);
                $this->assertWizardAccountSelectionValid(
                    $this->GetBuffer('WizardProvider'),
                    $selection['mode'],
                    $selection['existingAccountID'],
                    $selection['accountName']
                );
                $this->SetBuffer(
                    'WizardAccountSelection',
                    json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                );
                break;

            case 'WizardAccountSelectionUndo':
                $this->SetBuffer('WizardAccountSelection', '');
                break;

            case 'WizardConfirmAccount':
                $this->confirmWizardAccount();
                break;

            default:
                parent::RequestAction($Ident, $Value);
        }
    }

    public function ValidateWizardAccountSelection(
        string $AccountMode,
        int $ExistingAccountID,
        string $AccountName
    ): string {
        try {
            $this->assertWizardAccountSelectionValid(
                $this->GetBuffer('WizardProvider'),
                $AccountMode,
                $ExistingAccountID,
                $AccountName
            );
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        return '';
    }

    public function ValidateWizardConfirmation(): string
    {
        try {
            $provider = $this->GetBuffer('WizardProvider');
            $selection = $this->decodeStoredWizardAccountSelection();

            $this->assertWizardAccountSelectionValid(
                $provider,
                $selection['mode'],
                $selection['existingAccountID'],
                $selection['accountName']
            );
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        return '';
    }

    private function confirmWizardAccount(): void
    {
        $provider = $this->GetBuffer('WizardProvider');
        $selection = $this->decodeStoredWizardAccountSelection();

        $this->assertWizardAccountSelectionValid(
            $provider,
            $selection['mode'],
            $selection['existingAccountID'],
            $selection['accountName']
        );

        if ($selection['mode'] === self::ACCOUNT_MODE_EXISTING) {
            $accountID = $selection['existingAccountID'];
        } else {
            $accountID = $this->createCalendarAccount($provider, $selection['accountName']);
        }

        $this->WriteAttributeInteger('SelectedCalendarAccountID', $accountID);
        $this->SetBuffer('WizardProvider', '');
        $this->SetBuffer('WizardAccountSelection', '');
    }

    private function createCalendarAccount(string $provider, string $accountName): int
    {
        $accountID = 0;

        try {
            $accountID = IPS_CreateInstance(self::CALENDAR_ACCOUNT_MODULE_ID);
            IPS_SetName($accountID, trim($accountName));

            $parentID = IPS_GetParent($this->InstanceID);
            if ($parentID > 0) {
                IPS_SetParent($accountID, $parentID);
            }

            IPS_SetProperty($accountID, 'Provider', self::PROVIDERS[$provider]);
            IPS_SetProperty($accountID, 'Active', false);
            IPS_ApplyChanges($accountID);
        } catch (Throwable $exception) {
            if ($accountID > 0 && IPS_InstanceExists($accountID)) {
                IPS_DeleteInstance($accountID);
            }

            throw new RuntimeException(
                $this->Translate('The calendar account could not be created.') . ' ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        return $accountID;
    }

    /**
     * @return array{mode: string, existingAccountID: int, accountName: string}
     */
    private function decodeWizardAccountSelection(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($this->Translate('The calendar account selection is invalid.'));
        }

        try {
            $selection = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException($this->Translate('The calendar account selection is invalid.'));
        }

        if (!is_array($selection)) {
            throw new InvalidArgumentException($this->Translate('The calendar account selection is invalid.'));
        }

        return [
            'mode'              => (string) ($selection['mode'] ?? ''),
            'existingAccountID' => (int) ($selection['existingAccountID'] ?? 0),
            'accountName'       => trim((string) ($selection['accountName'] ?? ''))
        ];
    }

    /**
     * @return array{mode: string, existingAccountID: int, accountName: string}
     */
    private function decodeStoredWizardAccountSelection(): array
    {
        return $this->decodeWizardAccountSelection($this->GetBuffer('WizardAccountSelection'));
    }

    private function assertWizardAccountSelectionValid(
        string $provider,
        string $accountMode,
        int $existingAccountID,
        string $accountName
    ): void {
        $this->assertSupportedProvider($provider);

        if ($accountMode === self::ACCOUNT_MODE_NEW) {
            if (trim($accountName) === '') {
                throw new InvalidArgumentException($this->Translate('Please enter a name for the calendar account.'));
            }

            return;
        }

        if ($accountMode !== self::ACCOUNT_MODE_EXISTING) {
            throw new InvalidArgumentException($this->Translate('Please choose how the calendar account should be provided.'));
        }

        if ($existingAccountID <= 0 || !IPS_InstanceExists($existingAccountID)) {
            throw new InvalidArgumentException($this->Translate('Please select an existing calendar account.'));
        }

        $accountIDs = IPS_GetInstanceListByModuleID(self::CALENDAR_ACCOUNT_MODULE_ID);
        if (!in_array($existingAccountID, $accountIDs, true)) {
            throw new InvalidArgumentException($this->Translate('The selected instance is not a calendar account.'));
        }

        if ((int) IPS_GetProperty($existingAccountID, 'Provider') !== self::PROVIDERS[$provider]) {
            throw new InvalidArgumentException(
                $this->Translate('The selected calendar account uses a different calendar provider.')
            );
        }
    }

    private function assertSupportedProvider(string $provider): void
    {
        if (!array_key_exists($provider, self::PROVIDERS)) {
            throw new InvalidArgumentException($this->Translate('Please select a calendar provider.'));
        }
    }
}
