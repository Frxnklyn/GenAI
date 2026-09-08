<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\UxonDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Applies one structured change to a UXON attribute of exactly one model object row.
 */
class UxonPatchTool extends AbstractAiTool
{
    public const ARG_OBJECT = 'object';
    public const ARG_UID = 'uid';
    public const ARG_ATTRIBUTE = 'attribute';
    public const ARG_OPERATION = 'operation';
    public const ARG_PATH = 'path';
    public const ARG_VALUE = 'value';

    private const OPERATION_SET = 'set';
    private const OPERATION_REMOVE = 'remove';
    private const OPERATION_APPEND = 'append';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        [$objectSelector, $uid, $attributeAlias, $operation, $path, $value] = array_pad($arguments, 6, null);

        $objectSelector = trim((string) $objectSelector);
        $uid = trim((string) $uid);
        $attributeAlias = trim((string) $attributeAlias);
        $operation = strtolower(trim((string) $operation));
        $path = $this->normalizePath($path, $prompt);

        if ($objectSelector === '' || $uid === '' || $attributeAlias === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Arguments object, uid and attribute must not be empty.');
        }
        if (! in_array($operation, [self::OPERATION_SET, self::OPERATION_REMOVE, self::OPERATION_APPEND], true)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid operation. Allowed values: set, remove, append.');
        }
        if ($operation !== self::OPERATION_SET && $path === []) {
            throw new AiToolRuntimeError($this, $prompt, 'Only the set operation supports an empty path.');
        }

        try {
            $object = $this->getWorkbench()->model()->getObject($objectSelector);
            $attribute = $object->getAttribute($attributeAlias);
        } catch (\Throwable $e) {
            throw new AiToolRuntimeError($this, $prompt, 'Cannot resolve UXON patch target: ' . $e->getMessage(), null, $e);
        }

        if (! $attribute->getRelationPath()->isEmpty()) {
            throw new AiToolRuntimeError($this, $prompt, 'Only direct attributes of the selected object can be patched.');
        }
        if (! $attribute->isReadable() || ! $attribute->isWritable()) {
            throw new AiToolRuntimeError($this, $prompt, 'The selected attribute must be readable and writable.');
        }
        if (! $attribute->getDataType() instanceof UxonDataType) {
            throw new AiToolRuntimeError($this, $prompt, 'Attribute "' . $attributeAlias . '" is not an UXON attribute.');
        }

        $uidAttribute = $object->getUidAttribute();
        $dataSheet = DataSheetFactory::createFromObject($object);
        $uidColumn = $dataSheet->getColumns()->addFromUidAttribute();
        $uxonColumn = $dataSheet->getColumns()->addFromAttribute($attribute);
        $dataSheet->getFilters()->addConditionFromAttribute($uidAttribute, $uid, ComparatorDataType::EQUALS);
        $dataSheet->dataRead(2);

        if ($dataSheet->countRows() !== 1) {
            throw new AiToolRuntimeError(
                $this,
                $prompt,
                'Expected exactly one row for object "' . $object->getAliasWithNamespace() . '" and UID "' . $uid . '", found ' . $dataSheet->countRows() . '.'
            );
        }

        try {
            $uxon = UxonObject::fromAnything($uxonColumn->getValue(0));
            $patchedUxon = $this->applyOperation($uxon, $path, $operation, $value, $prompt);
            $target = $object->getAliasWithNamespace() . '.' . $attribute->getAliasWithRelationPath();
            $message = 'Updated ' . $target . ': ' . $operation . ' ' . $this->formatPath($path) . '.';
            if ($patchedUxon->toArray() === $uxon->toArray()) {
                $message = 'No change needed for ' . $target . ' at ' . $this->formatPath($path) . '.';
                return new AiToolResultString($this, $arguments, $message, $this->getReturnDataType());
            }
            $dataSheet->setCellValue($uidColumn->getName(), 0, $uidColumn->getValue(0));
            $dataSheet->setCellValue($uxonColumn->getName(), 0, $patchedUxon->toJson(false));
            $affectedRows = $dataSheet->dataUpdate(false);
        } catch (AiToolRuntimeError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AiToolRuntimeError($this, $prompt, 'Failed to patch UXON: ' . $e->getMessage(), null, $e);
        }

        if ($affectedRows !== 1) {
            throw new AiToolRuntimeError($this, $prompt, 'UXON update affected ' . $affectedRows . ' rows instead of exactly one.');
        }

        // TODO: Add $message to AiResponse additional messages once tool results support them without changing shared classes.
        return new AiToolResultString($this, $arguments, $message, $this->getReturnDataType());
    }

    /**
     * Converts the path argument to validated string and integer segments.
     *
     * @param mixed $path
     * @param AiPromptInterface $prompt
     * @return array<int, string|int>
     */
    private function normalizePath(mixed $path, AiPromptInterface $prompt): array
    {
        if ($path instanceof UxonObject) {
            $path = $path->toArray();
        }
        if (! is_array($path)) {
            throw new AiToolRuntimeError($this, $prompt, 'Argument path must be an array of property names and array indexes.');
        }

        foreach ($path as $segment) {
            if ((! is_string($segment) && ! is_int($segment)) || $segment === '') {
                throw new AiToolRuntimeError($this, $prompt, 'Every path segment must be a non-empty string or an integer.');
            }
        }

        return array_values($path);
    }

    /**
     * Applies a set, remove, or append operation without manipulating serialized JSON.
     *
     * @param UxonObject $uxon
     * @param array<int, string|int> $path
     * @param string $operation
     * @param mixed $value
     * @param AiPromptInterface $prompt
     * @return UxonObject
     */
    private function applyOperation(
        UxonObject $uxon,
        array $path,
        string $operation,
        mixed $value,
        AiPromptInterface $prompt
    ): UxonObject {
        if ($operation === self::OPERATION_SET && $path === []) {
            try {
                return UxonObject::fromAnything($value);
            } catch (\Throwable $e) {
                throw new AiToolRuntimeError($this, $prompt, 'A root replacement value must be a UXON object or array.', null, $e);
            }
        }

        $data = $uxon->toArray();
        $target =& $data;
        $lastSegment = array_pop($path);

        foreach ($path as $segment) {
            if (! is_array($target) || ! array_key_exists($segment, $target) || ! is_array($target[$segment])) {
                throw new AiToolRuntimeError($this, $prompt, 'UXON path parent does not exist or is not an object/array.');
            }
            $target =& $target[$segment];
        }

        switch ($operation) {
            case self::OPERATION_SET:
                if (is_int($lastSegment) && array_is_list($target) && ($lastSegment < 0 || $lastSegment > count($target))) {
                    throw new AiToolRuntimeError($this, $prompt, 'UXON array index must address an existing item or the next append position.');
                }
                $target[$lastSegment] = $this->normalizeValue($value);
                break;
            case self::OPERATION_REMOVE:
                if (! is_array($target) || ! array_key_exists($lastSegment, $target)) {
                    throw new AiToolRuntimeError($this, $prompt, 'UXON path to remove does not exist.');
                }
                if (is_int($lastSegment) && array_is_list($target)) {
                    array_splice($target, $lastSegment, 1);
                } else {
                    unset($target[$lastSegment]);
                }
                break;
            case self::OPERATION_APPEND:
                if (! is_array($target) || ! array_key_exists($lastSegment, $target) || ! is_array($target[$lastSegment])) {
                    throw new AiToolRuntimeError($this, $prompt, 'UXON path to append to does not exist or is not an array.');
                }
                $target[$lastSegment][] = $this->normalizeValue($value);
                break;
        }

        return UxonObject::fromArray($data);
    }

    /**
     * Converts nested UXON wrapper values to plain arrays.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalizeValue(mixed $value): mixed
    {
        return $value instanceof UxonObject ? $value->toArray() : $value;
    }

    /**
     * Formats an UXON path for the compact tool result.
     *
     * @param array<int, string|int> $path
     * @return string
     */
    private function formatPath(array $path): string
    {
        $formatted = '$';
        foreach ($path as $segment) {
            $formatted .= is_int($segment) ? '[' . $segment . ']' : '.' . $segment;
        }

        return $formatted;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);

        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_OBJECT)
                ->setDescription('Alias or UID of the model object containing the row to update.'),
            (new ServiceParameter($self))
                ->setName(self::ARG_UID)
                ->setDescription('UID of the single row to update.'),
            (new ServiceParameter($self))
                ->setName(self::ARG_ATTRIBUTE)
                ->setDescription('Alias of the direct readable and writable UXON attribute.'),
            (new ServiceParameter($self))
                ->setDataType(new UxonObject([
                    'alias' => 'exface.Core.GenericStringEnum',
                    'values' => [
                        self::OPERATION_SET => 'Set',
                        self::OPERATION_REMOVE => 'Remove',
                        self::OPERATION_APPEND => 'Append',
                    ],
                ]))
                ->setName(self::ARG_OPERATION)
                ->setDescription('Change to apply: set a value, remove an existing value, or append to an existing array.'),
            (new ServiceParameter($self))
                ->setDataType(new UxonObject(['alias' => 'exface.Core.Array']))
                ->setName(self::ARG_PATH)
                ->setDescription('Path from the UXON root as property names and zero-based array indexes. Use [] with set to replace the complete UXON.'),
            (new ServiceParameter($self))
                ->setDataType(new UxonObject(['alias' => 'exface.Core.Json']))
                ->setName(self::ARG_VALUE)
                ->setDescription('JSON value for set or append. Omit for remove.')
                ->setRequired(false),
        ];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getRules()
     */
    public function getRules(): ?string
    {
        return 'Use this tool only for one focused UXON change at a time. After a successful call, briefly tell the user which object attribute and UXON path were changed.';
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}