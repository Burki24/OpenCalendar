<?php

declare(strict_types=1);

function assertSymcon91Runtime(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function symcon91RuntimeSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Symcon 9.1 runtime source could not be read: ' . $path);
    }

    return $source;
}

/** @return array<string, mixed> */
function symcon91RuntimeJson(string $path): array
{
    $data = json_decode(symcon91RuntimeSource($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || array_is_list($data)) {
        throw new RuntimeException('Symcon 9.1 runtime metadata must be a JSON object: ' . $path);
    }

    return $data;
}

function symcon91RuntimeType(?ReflectionType $type): string
{
    if ($type === null) {
        return '';
    }
    if ($type instanceof ReflectionNamedType) {
        return ($type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null' ? '?' : '')
            . $type->getName();
    }
    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map(symcon91RuntimeType(...), $type->getTypes()));
    }
    if ($type instanceof ReflectionIntersectionType) {
        return implode('&', array_map(symcon91RuntimeType(...), $type->getTypes()));
    }

    return '';
}

/**
 * @param list<string> $parameterTypes
 */
function assertSymcon91Method(
    ReflectionClass $class,
    string $methodName,
    string $returnType,
    array $parameterTypes,
    bool $protected = false
): void {
    assertSymcon91Runtime($class->hasMethod($methodName), $class->getName() . ' is missing ' . $methodName . '().');
    $method = $class->getMethod($methodName);
    assertSymcon91Runtime(
        $protected ? $method->isProtected() : $method->isPublic(),
        $class->getName() . '::' . $methodName . '() has the wrong visibility.'
    );
    assertSymcon91Runtime(
        symcon91RuntimeType($method->getReturnType()) === $returnType,
        $class->getName() . '::' . $methodName . '() must return ' . $returnType . '.'
    );
    $parameters = $method->getParameters();
    assertSymcon91Runtime(
        count($parameters) === count($parameterTypes),
        $class->getName() . '::' . $methodName . '() has an unexpected parameter count.'
    );
    foreach ($parameters as $index => $parameter) {
        assertSymcon91Runtime(
            symcon91RuntimeType($parameter->getType()) === $parameterTypes[$index],
            sprintf(
                '%s::%s() parameter $%s must use type %s.',
                $class->getName(),
                $methodName,
                $parameter->getName(),
                $parameterTypes[$index]
            )
        );
    }
}

$root = dirname(__DIR__);
$library = symcon91RuntimeJson($root . '/library.json');
assertSymcon91Runtime(
    version_compare((string) ($library['compatibility']['version'] ?? '0'), '9.1', '>='),
    'The dev_9.1 library must require Symcon 9.1 or newer.'
);

$workflow = symcon91RuntimeSource($root . '/.github/workflows/tests.yml');
assertSymcon91Runtime(
    str_contains($workflow, "php-version: '8.5'"),
    'The Symcon 9.1 runtime gate must execute on PHP 8.5.'
);

$modules = [
    'Kalender'              => ['class' => 'Calendar', 'name' => 'Calendar'],
    'Kalender Konto'        => ['class' => 'CalendarAccount', 'name' => 'Calendar Account'],
    'Kalender Ansicht'      => ['class' => 'CalendarView', 'name' => 'Calendar View'],
    'Kalender Konfigurator' => ['class' => 'CalendarConfigurator', 'name' => 'Calendar Configurator'],
    'Kalender Einrichtung'  => ['class' => 'OpenCalendarDiscovery', 'name' => 'OpenCalendar Discovery']
];

$moduleSources = [];
foreach ($modules as $directory => $metadata) {
    $sourcePath = $root . '/' . $directory . '/module.php';
    $moduleSource = symcon91RuntimeSource($sourcePath);
    $moduleSources[$directory] = $moduleSource;
    $moduleJson = symcon91RuntimeJson($root . '/' . $directory . '/module.json');

    assertSymcon91Runtime(
        str_contains($moduleSource, 'declare(strict_types=1);'),
        $directory . ' must enable strict PHP types.'
    );
    assertSymcon91Runtime(
        preg_match(
            '/class\s+' . preg_quote((string) $metadata['class'], '/') . '\s+extends\s+IPSModuleStrict\b/',
            $moduleSource
        ) === 1,
        $directory . ' must extend IPSModuleStrict.'
    );
    assertSymcon91Runtime(
        (string) ($moduleJson['name'] ?? '') === $metadata['name']
            && str_replace(' ', '', (string) ($moduleJson['name'] ?? '')) === $metadata['class'],
        $directory . ' module.json name must resolve exactly to the PHP class name.'
    );
    assertSymcon91Runtime(
        !preg_match('/\b(?:utf8_encode|utf8_decode|sleep|usleep)\s*\(/', $moduleSource),
        $directory . ' must not rely on deprecated encoding or blocking sleep calls at the Symcon runtime boundary.'
    );
}

if (!class_exists('IPSModuleStrict')) {
    class IPSModuleStrict
    {
        public function Create(): void
        {
        }

        public function ApplyChanges(): void
        {
        }

        public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
        {
        }

        public function RequestAction(string $Ident, mixed $Value): void
        {
        }

        public function GetConfigurationForm(): string
        {
            return '';
        }

        public function ReceiveData(string $JSONString): string
        {
            return '';
        }

        public function ForwardData(string $JSONString): string
        {
            return '';
        }

        public function Migrate(string $JSONData): string
        {
            return $JSONData;
        }

        protected function ProcessHookData(): void
        {
        }

        protected function ProcessOAuthData(): void
        {
        }
    }
}

foreach (array_keys($modules) as $directory) {
    require_once $root . '/' . $directory . '/module.php';
}

$reflections = [];
foreach ($modules as $directory => $metadata) {
    $class = new ReflectionClass((string) $metadata['class']);
    $reflections[$directory] = $class;

    foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class->getName()) {
            continue;
        }
        assertSymcon91Runtime(
            $method->hasReturnType(),
            $class->getName() . '::' . $method->getName() . '() is missing a return type.'
        );
        foreach ($method->getParameters() as $parameter) {
            assertSymcon91Runtime(
                $parameter->hasType(),
                sprintf(
                    '%s::%s() parameter $%s is missing a type.',
                    $class->getName(),
                    $method->getName(),
                    $parameter->getName()
                )
            );
        }
    }
}

foreach ($reflections as $class) {
    assertSymcon91Method($class, 'Create', 'void', []);
    if ($class->hasMethod('ApplyChanges')
        && $class->getMethod('ApplyChanges')->getDeclaringClass()->getName() === $class->getName()) {
        assertSymcon91Method($class, 'ApplyChanges', 'void', []);
    }
    if ($class->hasMethod('RequestAction')
        && $class->getMethod('RequestAction')->getDeclaringClass()->getName() === $class->getName()) {
        assertSymcon91Method($class, 'RequestAction', 'void', ['string', 'mixed']);
    }
    if ($class->hasMethod('MessageSink')
        && $class->getMethod('MessageSink')->getDeclaringClass()->getName() === $class->getName()) {
        assertSymcon91Method($class, 'MessageSink', 'void', ['int', 'int', 'int', 'array']);
    }
    if ($class->hasMethod('GetConfigurationForm')
        && $class->getMethod('GetConfigurationForm')->getDeclaringClass()->getName() === $class->getName()) {
        assertSymcon91Method($class, 'GetConfigurationForm', 'string', []);
    }
}

assertSymcon91Method($reflections['Kalender'], 'ReceiveData', 'string', ['string']);
assertSymcon91Method($reflections['Kalender Konto'], 'ForwardData', 'string', ['string']);
assertSymcon91Method($reflections['Kalender Ansicht'], 'Migrate', 'string', ['string']);
assertSymcon91Method($reflections['Kalender Ansicht'], 'ProcessHookData', 'void', [], true);
assertSymcon91Method($reflections['Kalender Konto'], 'ProcessOAuthData', 'void', [], true);

foreach ($moduleSources as $directory => $source) {
    if (str_contains($source, 'public function Create(): void')) {
        assertSymcon91Runtime(
            preg_match('/public function Create\(\): void\s*\{[\s\S]*?parent::Create\(\);/', $source) === 1,
            $directory . '::Create() must call parent::Create().'
        );
    }
    if (str_contains($source, 'public function ApplyChanges(): void')) {
        assertSymcon91Runtime(
            preg_match('/public function ApplyChanges\(\): void\s*\{[\s\S]*?parent::ApplyChanges\(\);/', $source) === 1,
            $directory . '::ApplyChanges() must call parent::ApplyChanges().'
        );
    }
}

$calendarMetadata = symcon91RuntimeJson($root . '/Kalender/module.json');
$configuratorMetadata = symcon91RuntimeJson($root . '/Kalender Konfigurator/module.json');
$accountMetadata = symcon91RuntimeJson($root . '/Kalender Konto/module.json');
$accountImplemented = is_array($accountMetadata['implemented'] ?? null) ? $accountMetadata['implemented'] : [];
$accountChildren = is_array($accountMetadata['childRequirements'] ?? null) ? $accountMetadata['childRequirements'] : [];
foreach (
    [
        'Calendar'              => $calendarMetadata,
        'Calendar Configurator' => $configuratorMetadata
    ] as $name => $childMetadata
) {
    $parentRequirements = is_array($childMetadata['parentRequirements'] ?? null)
        ? $childMetadata['parentRequirements']
        : [];
    $implemented = is_array($childMetadata['implemented'] ?? null) ? $childMetadata['implemented'] : [];
    assertSymcon91Runtime(
        array_intersect($parentRequirements, $accountImplemented) !== [],
        $name . ' parentRequirements must match a data-flow interface implemented by Calendar Account.'
    );
    assertSymcon91Runtime(
        array_intersect($implemented, $accountChildren) !== [],
        $name . ' implemented data-flow interface must match Calendar Account childRequirements.'
    );
}

$dataFlowSource = symcon91RuntimeSource($root . '/libs/helper/DataFlowHelper.php');
assertSymcon91Runtime(
    str_contains($dataFlowSource, 'JSON_THROW_ON_ERROR')
        && str_contains($dataFlowSource, 'JSON_UNESCAPED_UNICODE')
        && !preg_match('/\b(?:utf8_encode|utf8_decode)\s*\(/', $dataFlowSource),
    'Internal OpenCalendar data flow must remain strict JSON/UTF-8 without deprecated binary-string conversion.'
);
assertSymcon91Runtime(
    str_contains($moduleSources['Kalender'], '$this->EncodeDataFlowMessage(self::DATA_ID_TO_PARENT, $request)')
        && str_contains(
            $moduleSources['Kalender'],
            '$this->DecodeDataFlowMessage($JSONString, self::DATA_ID_FROM_PARENT)'
        )
        && str_contains($moduleSources['Kalender Konfigurator'], '$this->EncodeDataFlowMessage('),
    'Calendar and configurator must keep all parent transport inside DataFlowHelper.'
);
$gatewaySource = symcon91RuntimeSource($root . '/Kalender Konto/traits/ChildGatewayTrait.php');
assertSymcon91Runtime(
    str_contains($gatewaySource, '$this->DecodeDataFlowMessage($JSONString, self::DATA_ID_FROM_CHILD)')
        && !preg_match('/\b(?:utf8_encode|utf8_decode)\s*\(/', $gatewaySource),
    'Calendar Account child transport must remain strict JSON without legacy UTF-8 conversion.'
);

$viewSource = $moduleSources['Kalender Ansicht'];
assertSymcon91Runtime(
    preg_match(
        '/public function Create\(\): void\s*\{[\s\S]*?RegisterHook\(\$this->ipsViewHookAddress\(\)\);/',
        $viewSource
    ) === 1
        && !str_contains($viewSource, 'WebHookModule'),
    'Calendar View must use the native IPSModuleStrict hook registration path.'
);
$accountSource = $moduleSources['Kalender Konto'];
assertSymcon91Runtime(
    str_contains($accountSource, '$this->RegisterOAuth($identifier)')
        && !str_contains($accountSource, 'WebOAuthModule'),
    'Calendar Account must use native IPSModuleStrict OAuth registration.'
);

$runtimeFiles = [];
foreach (array_keys($modules) as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $runtimeFiles[] = $file->getPathname();
        }
    }
}
$helperIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/libs'));
foreach ($helperIterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $runtimeFiles[] = $file->getPathname();
    }
}
foreach (array_unique($runtimeFiles) as $runtimeFile) {
    $source = symcon91RuntimeSource($runtimeFile);
    assertSymcon91Runtime(
        !preg_match('/\b(?:utf8_encode|utf8_decode|sleep|usleep)\s*\(/', $source),
        'Rust-runtime audit found a deprecated encoding or blocking sleep call in '
            . str_replace($root . '/', '', $runtimeFile) . '.'
    );
}

fwrite(STDOUT, "Symcon 9.1 Rust-runtime boundary audit passed.\n");
