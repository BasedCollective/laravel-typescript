<?php

namespace Based\TypeScript\Generators;

use Based\TypeScript\Definitions\TypeScriptProperty;
use Based\TypeScript\Definitions\TypeScriptType;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ClosureValidationRule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;
use Laravel\Fortify\Rules\Password as FortifyPassword;

class RequestGenerator extends AbstractGenerator
{
    const CONTROL_KEYS = [
        'sometimes',
        'prohibited',
        'required',
        'nullable',
        'confirmed',
        'same',
        'present',
    ];

    protected FormRequest $request;

    protected Validator $validator;

    /** @var array<string, string|string[]> */
    protected array $customRules;

    public function getDefinition(): ?string
    {
        $rules = collect($this->validator->getRules());

        if (method_exists($this->request, 'rules')) {
            $rules = $rules->merge($this->request->rules());
        }

        if ($rules->isEmpty()) {
            return null;
        }

        $rules = $rules
            ->flatMap(fn (array|string $rules, string $property) => $this->parseRules($property, $rules))
            ->filter();

        if ($rules->isEmpty()) {
            return null;
        }

        return $this->rulesToStringArray($rules)->join(PHP_EOL . '        ');
    }

    /**
     * @throws \ReflectionException
     */
    protected function boot(): void
    {
        /** @var FormRequest $request */
        $request = $this->reflection->newInstance();
        $this->request = $request->setContainer(app());

        $clazz = new \ReflectionClass($this->request);
        $method = $clazz->getMethod('getValidatorInstance');
        $method->setAccessible(true);

        $this->validator = $method->invoke($this->request);

        $this->customRules = config('typescript.customRules');
    }

    /**
     * @param array<mixed>|string $rules
     * @return array<string, array<string, mixed>>|null
     */
    private function parseRules(string $property, array|string $rules): ?array
    {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        $rules = collect($rules)
            ->values()
            ->flatMap(fn (string|Rule $rule) => match (true) {
                is_object($rule) => $this->parseRuleObject($property, $rule),
                default => $this->parseRuleString($property, $rule)
            })
            ->except('');

        if ($rules->isEmpty()) {
            $rules['any'] = null;
        }

        if ($rules->has('prohibited')) {
            return null;
        }

        $types = $this->getPropertyTypes($rules);
        if (empty($types)) {
            return null;
        }

        $isOptional = $rules->has('sometimes') || (!$rules->has('present') && !$rules->has('required'));
        $isNullable = $rules->has('nullable');

        $properties = [
            $property => [
                'name' => $property,
                'types' => $types,
                'optional' => $isOptional,
                'nullable' => $isNullable,
            ]
        ];

        if ($rules->has('confirmed')) {
            $properties[$extraProperty = $rules['confirmed'] ?? "{$property}_confirmation"] = [
                'name' => $extraProperty,
                'types' => $types,
                'optional' => $isOptional,
                'nullable' => $isNullable,
            ];
        }

        return $properties;
    }

    /**
     * @param \Illuminate\Support\Collection $rules
     * @return string[]
     */
    private function getPropertyTypes(Collection $rules): array
    {
        return $rules
            ->keys()
            ->filter(fn (string $rule) => !in_array($rule, static::CONTROL_KEYS, true))
            ->values()
            ->all();
    }

    private function parseRuleObject(string $property, object $rule): Collection
    {
        if (method_exists($rule, '__toString')) {
            return $this->parseRuleString($property, (string) $rule);
        }

        return collect(
            match (true) {
                ($rule instanceof Password), ($rule instanceof FortifyPassword) => ['string' => null],
                ($rule instanceof ClosureValidationRule) => ['any' => null],
                in_array($clazz = get_class($rule), $this->customRules, true) => array_fill_keys(
                    Arr::wrap($this->customRules[$clazz]),
                    null
                ),
                default => null
            }
        );
    }

    private function parseRuleString(string $property, string $rule): Collection
    {
        $parts = explode(':', $rule, 2);
        $ruleName = $parts[0];
        $args = $parts[1] ?? null;

        return collect([$this->parseRuleName($property, $ruleName, $args) => $args]);
    }

    private function parseRuleName(string $property, string $rule, ?string $args = null): ?string
    {
        return match ($rule) {
            'nullable', 'sometimes', 'present', 'prohibited', 'required', 'same', 'confirmed' => $rule,
            'exists', 'unique' => $this->resolveColumn($property, $args),
            'accepted', 'accepted_if', 'boolean' => 'boolean',
            'active_url', 'after', 'after_or_equal', 'alpha', 'alpha_dash', 'alpha_num', 'before', 'before_or_equal', 'current_password', 'date', 'date_equals', 'date_format', 'digits', 'digits_between', 'email', 'ends_with', 'ip', 'ipv4', 'ipv6', 'json', 'not_regex', 'password', 'regex', 'starts_with', 'string', 'timezone', 'url', 'uuid' => 'string',
            'dimensions', 'file', 'image', 'mimetypes', 'mimes' => 'Blob | File',
            'integer', 'numeric' => 'number',
            'array' => 'array',
            default => null
        };
    }

    private function resolveColumn(string $property, ?string $args): ?string
    {
        if ($args === null || $args === '') {
            return null;
        }

        $parts = explode(',', $args);
        $table = $parts[0];
        $columnName = $parts[1] ?? $property;

        /** @var \Illuminate\Database\Connection $connection */
        $connection = DB::connection();
        $prefix = $connection->getTablePrefix();

        if (!Schema::hasTable("$prefix$table") || !Schema::hasColumn("$prefix$table", $columnName)) {
            return null;
        }

        $columns = collect(Schema::getColumns("$prefix$table"));
        $column = $columns->first(fn (array $col) => $col['name'] === $columnName);

        if (!$column) {
            return null;
        }

        return $this->getColumnType($column['type_name']);
    }

    protected function getColumnType(string $type): string|array
    {
        $type = strtolower($type);

        return match ($type) {
            // JSON/Array types
            'json', 'jsonb', 'array', 'simple_array' => [TypeScriptType::array(), TypeScriptType::ANY],
            // String types
            'string', 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext',
            'binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'bytea',
            'date', 'datetime', 'datetimetz', 'timestamp', 'timestamptz', 'time', 'timetz',
            'dateinterval', 'interval', 'year', 'uuid', 'guid', 'ulid', 'enum', 'set' => TypeScriptType::STRING,
            // Number types
            'int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'int2', 'int4', 'int8',
            'float', 'double', 'decimal', 'numeric', 'real', 'float4', 'float8', 'money' => TypeScriptType::NUMBER,
            // Boolean types
            'bool', 'boolean' => TypeScriptType::BOOLEAN,
            default => TypeScriptType::ANY,
        };
    }

    private function rulesToStringArray(Collection $rules, int $depth = 0): Collection
    {
        /** @var Collection $arrayRules */
        /** @var Collection $rules */
        [$arrayRules, $rules] = $rules->partition(
            fn (array $value) => in_array('array', $value['types'], true) ||
                str_contains($value['name'], '.')
        );

        return $rules
            ->merge($this->mergeArrays($arrayRules, $depth + 1))
            ->values()
            ->map(fn (array $value) => strval(app()->make(TypeScriptProperty::class, $value)))
            ->values();
    }

    private function mergeArrays(Collection $rules, int $depth): Collection
    {
        /** @var Collection $dotRules */
        /** @var Collection $rules */
        [$dotRules, $rules] = $rules
            ->map(function (array $value, string $property) {
                $value['name'] = $property;
                $value['types'] = array_values(
                    array_filter(
                        $value['types'],
                        fn (string $type) => $type !== 'array'
                    )
                );
                $value['is_array'] = null;
                $value['children'] = [];

                return $value;
            })
            ->partition(fn (array $value, string $property) => str_contains($property, '.'));

        $rules = $rules->all();

        /** @var string $property */
        /** @var array $value */
        foreach ($dotRules as $property => $value) {
            [$property, $remainder] = explode('.', $property, 2);

            if (!array_key_exists($property, $rules)) {
                $rules[$property] = [
                    'name' => $property,
                    'types' => [],
                    'optional' => false,
                    'nullable' => false,
                    'is_array' => null,
                    'children' => [],
                ];
            }

            $isArray = $remainder === '*' || str_starts_with($remainder, '*.');

            if ($rules[$property]['is_array'] !== null && $rules[$property]['is_array'] !== $isArray) {
                throw new \RuntimeException('Cannot combine array and object rules for the same property');
            }

            $rules[$property]['is_array'] = $isArray;

            if ($remainder === '*') {
                $rules[$property]['types'] = array_merge(
                    $rules[$property]['types'],
                    $value['types']
                );

                continue;
            }

            if ($isArray) {
                $remainder = substr($remainder, 2);
            }

            $rules[$property]['children'][$remainder] = $value;
        }

        $rules = collect($rules);

        $prefix = str_repeat(" ", 8 + $depth * 4);
        $endPrefix = substr($prefix, 4);

        return $rules
            ->map(function (array $value) use ($depth, $prefix, $endPrefix) {
                $result = Arr::except($value, ['is_array', 'children']);

                if (empty($value['children'])) {
                    if (empty($result['types'])) {
                        $result['types'] = ['any'];
                    } elseif ($value['is_array'] !== null) {
                        $result['types'] = ['Array<' . implode(' | ', $result['types']) . '>'];
                    }

                    return $result;
                }

                /** @var Collection $plainArray */
                /** @var Collection $children */
                [$plainArray, $children] = collect($value['children'])
                    ->partition(fn (array $value) => $value['name'] === '*');

                if ($plainArray->isNotEmpty()) {
                    $types = implode(' | ', $plainArray->first()['types']) ?: 'any';

                    $result['types'][] = "Array<{$types}>";
                }

                if ($children->isNotEmpty()) {
                    $typeObject = $this->rulesToStringArray($children, $depth)
                        ->map(fn (string $line) => "$prefix$line")
                        ->join(PHP_EOL);

                    $typeObject = <<< END
{
$typeObject
$endPrefix}
END;


                    if ($value['is_array']) {
                        $typeObject = "Array<$typeObject>";
                    }

                    $result['types'][] = $typeObject;
                }

                return $result;
            });
    }
}
