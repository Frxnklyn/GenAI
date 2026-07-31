<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\DataSheetSchema;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Factories\AiResponseStatusMessageFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseStatusMessageInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Imports DataSheet UXON and shows every prepared sheet in a review dialog.
 *
 * By default, data is saved immediately. Set `auto_save` to `false` to leave
 * it unsaved and let the user review, edit and save all generated attributes
 * from the status-message dialog.
 * 
 * @author Brookly Fränzschky, Andrej Kabachnik
 */
class DataSheetImportTool extends AbstractAiTool
{
    public const ARG_DATASHEET = 'data_sheet';

    private ?UxonObject $saveAsUxon = null;

    private ?UxonObject $dataSchemasUxon = null;

    private ?DataSheetSchema $dataSchema = null;

    private bool $autoSave = true;

    /**
     * @var DataSheetSchema[]|null
     */
    private ?array $dataSchemas = null;

    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $result = null;
        $warnings = [];
        $statusMessages = [];
        try{
            $payload = $arguments[0] ?? null;
            if ($payload === null) {
                throw new AiToolRuntimeError($this, $prompt, 'Missing data argument in ImportTool');
            }

            $uxon = UxonObject::fromAnything($payload);
            $messages = [];
            if($uxon->isArray()){
                foreach ($uxon as $index => $item) {
                    if (! $item instanceof UxonObject) {
                        $warning = (new AiToolRuntimeError($this, $prompt, 'Skipped invalid import row at index ' . $index . '.'))
                            ->setLogLevel(LoggerInterface::WARNING);
                        $this->getWorkbench()->getLogger()->logException($warning);
                        $warnings[] = $warning;
                        continue;
                    }
                    $sheet = DataSheetFactory::createFromUxon($this->getWorkbench(), $item);
                    $rowCount = count($sheet->getRows());
                    if ($this->autoSave) {
                        $sheet->dataSave();
                        $messages[] = 'Imported ' . $rowCount . ' row(s) into "' . $sheet->getMetaObject()->getAliasWithNamespace() . '".';
                    } else {
                        $messages[] = 'Prepared ' . $rowCount . ' row(s) for "' . $sheet->getMetaObject()->getAliasWithNamespace() . '". The data has not been saved yet.';
                    }
                    $statusMessages[] = $this->createImportStatusMessage($prompt, $sheet);
                }
            }

            if(count($messages) === 0){
                $message = 'No Data Imported';
            }else {
                $message = implode("\n", $messages);
            }

            $result = new AiToolResultString($this, $arguments, $message, $this->getReturnDataType());
            foreach ($warnings as $warning) {
                $result->addException($warning);
            }
            foreach ($statusMessages as $statusMessage) {
                $result->addStatusMessage($statusMessage);
            }
        }catch (\Throwable $e) {
            $message = 'Error during import: ' . $e->getMessage();
            if ($e instanceof ExceptionInterface) {
                $exception = $e;
            } else {
                $exception = new AiToolRuntimeError($this, $prompt, 'Error during import. ' . $e->getMessage(), null, $e);
            }

            $agent->getWorkbench()->getLogger()->logException($exception);
            $result = (new AiToolResultString($this, $arguments, $message, $this->getReturnDataType()))
                ->addException($exception);
        }

        return $result;
    }

    /**
     * Creates a status message that previews saved data or offers editing and saving for unsaved data.
     */
    protected function createImportStatusMessage(AiPromptInterface $prompt, DataSheetInterface $sheet): AiResponseStatusMessageInterface
    {
        $rowCount = count($sheet->getRows());
        $objectAlias = $sheet->getMetaObject()->getAliasWithNamespace();
        $text = $this->autoSave
            ? 'Imported ' . $rowCount . ' row(s) into "' . $objectAlias . '".'
            : 'Prepared ' . $rowCount . ' unsaved row(s) for "' . $objectAlias . '".';

        if (! $prompt->isTriggeredOnPage()) {
            return $this->autoSave
                ? AiResponseStatusMessageFactory::createOkMessage($text)
                : AiResponseStatusMessageFactory::createInfoMessage($text);
        }

        $contextWidget = $prompt->getWidgetTriggeredBy();
        return AiResponseStatusMessageFactory::createDataSheetMessage(
            $text,
            $sheet,
            $contextWidget,
            $this->autoSave
                ? AiResponseStatusMessageFactory::DATA_SHEET_WIDGET_PREVIEW
                : AiResponseStatusMessageFactory::DATA_SHEET_WIDGET_REVIEW
        );
    }

    /**
     * Set to FALSE to prepare imported data without saving it automatically.
     *
     * Unsaved data is displayed in an editable status-message dialog with a
     * save button, allowing the user to review all generated attributes first.
     *
     * @uxon-property auto_save
     * @uxon-type boolean
     * @uxon-default true
     */
    protected function setAutoSave(bool $autoSave): DataSheetImportTool
    {
        $this->autoSave = $autoSave;
        return $this;
    }

    /**
     * Target object schema used to validate and generate the `data_sheet` argument schema.
     *
     * @uxon-property save_as
     * @uxon-type \axenox\GenAI\Common\DataSheetSchema
     * @uxon-template {"object_alias":"my.App.REPORT","subsheets":[{"object_alias":"my.App.TOPIC","subsheets":[]}]}
     */
    protected function setSaveAs(UxonObject $uxon): DataSheetImportTool
    {
        $this->saveAsUxon = $uxon;
        $this->dataSchemasUxon = null;
        $this->dataSchema = null;
        $this->dataSchemas = null;

        $this->ensureSchemaArgumentConfigured();

        return $this;
    }

    /**
     * Alternative to `save_as`: list of possible import target schemas.
     *
     * @uxon-property data_schemas
     * @uxon-type \axenox\GenAI\Common\DataSheetSchema[]
     * @uxon-template [
     *   {
     *     "object_alias": "my.App.REPORT",
     *     "subsheets": []
     *   },
     *   {
     *     "object_alias": "my.App.TOPIC",
     *     "subsheets": []
     *   }
     * ]
     */
    protected function setDataSchemas(UxonObject $uxon): DataSheetImportTool
    {
        if (! $uxon->isArray()) {
            throw new RuntimeException('UXON property "save_targets" must be an array of schema objects (same shape as "save_as").');
        }

        if ($uxon->isEmpty()) {
            throw new RuntimeException('UXON property "save_targets" cannot be empty.');
        }

        $this->dataSchemasUxon = $uxon;
        $this->saveAsUxon = null;
        $this->dataSchema = null;
        $this->dataSchemas = null;

        $this->ensureSchemaArgumentConfigured();

        return $this;
    }

    public function getArguments(): array
    {
        $this->ensureSchemaArgumentConfigured();
        return parent::getArguments();
    }

    public function getArgumentSchema(string $argumentName): ?array
    {
        if ($argumentName !== self::ARG_DATASHEET) {
            return null;
        }

        return $this->getDataSheetArgumentSchema();
    }

    protected function ensureSchemaArgumentConfigured(): void
    {
        if (! $this->hasSchemaConfiguration() || ! empty(parent::getArguments())) {
            return;
        }

        $schemaJson = json_encode(
            $this->getDataSheetArgumentSchema(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $argUxon = new UxonObject([
            'name' => self::ARG_DATASHEET,
            'description' => 'DataSheet payload to import. The JSON schema is generated from `save_as` or `save_targets`.',
            'data_type' => [
                'alias' => 'exface.Core.Array',
            ],
            'custom_properties' => [
                'json_schema' => $schemaJson,
            ],
        ]);

        $argsUxon = new UxonObject();
        $argsUxon->append($argUxon);
        $this->setArguments($argsUxon);
    }

    /**
     * @return DataSheetSchema[]
     */
    protected function getDataSchemas(): array
    {
        if ($this->dataSchemas === null) {
            $this->dataSchemas = [];

            if ($this->dataSchemasUxon !== null) {
                if (! $this->dataSchemasUxon->isArray()) {
                    throw new RuntimeException('UXON property "save_targets" must be an array of schema objects.');
                }

                foreach ($this->dataSchemasUxon as $idx => $targetUxon) {
                    if (! $targetUxon instanceof UxonObject) {
                        throw new RuntimeException('Invalid schema entry at $.save_targets[' . $idx . ']. Expected UXON object.');
                    }

                    $schema = new DataSheetSchema($this->getWorkbench(), $targetUxon);
                    $this->validateSchemaNode($schema, '$.save_targets[' . $idx . ']');
                    $this->dataSchemas[] = $schema;
                }
            } elseif ($this->saveAsUxon !== null) {
                $schema = new DataSheetSchema($this->getWorkbench(), $this->saveAsUxon);
                $this->validateSchemaNode($schema, '$.save_as');
                $this->dataSchemas[] = $schema;
            } else {
                throw new RuntimeException('ImportTool requires UXON property "save_as" (object) or "save_targets" (array of save_as objects), each with at least "object_alias".');
            }

            if (empty($this->dataSchemas)) {
                throw new RuntimeException('ImportTool requires at least one save target schema.');
            }

            $this->dataSchema = $this->dataSchemas[0];
        }

        return $this->dataSchemas;
    }

    protected function getDataSchema(): DataSheetSchema
    {
        if ($this->dataSchema === null) {
            $this->dataSchema = $this->getDataSchemas()[0];
        }

        return $this->dataSchema;
    }

    protected function getDataSheetArgumentSchema(): array
    {
        $schemas = [];
        foreach ($this->getDataSchemas() as $schema) {
            $schemas[] = $schema->generateJsonSchema();
        }

        if (count($schemas) === 1) {
            return $schemas[0];
        }

        return ["type" => "array", "items" => [ "anyOf" => $schemas]];
    }

    protected function hasSchemaConfiguration(): bool
    {
        return $this->saveAsUxon !== null || $this->dataSchemasUxon !== null;
    }

    protected function validateSchemaNode(DataSheetSchema $schema, string $path): void
    {
        $objectAlias = $schema->getObjectAlias();
        if ($objectAlias === null || $objectAlias === '') {
            throw new RuntimeException('Missing required "object_alias" in ' . $path . '.');
        }

        $object = $this->getMetaObjectByAlias($objectAlias, $path);

        foreach ($schema->getRequiredAttributes() as $attributeAlias) {
            if ($attributeAlias === '') {
                throw new RuntimeException('Empty value found in "require_attributes" at ' . $path . '.');
            }
            if (! $object->hasAttribute($attributeAlias)) {
                throw new RuntimeException('Unknown required attribute "' . $attributeAlias . '" for object "' . $objectAlias . '" at ' . $path . '.');
            }
        }

        foreach ($schema->getSubsheets() as $index => $subsheetSchema) {
            $this->validateSchemaNode($subsheetSchema, $path . '.subsheets[' . $index . ']');
        }
    }

    protected function getMetaObjectByAlias(string $objectAlias, string $path): MetaObjectInterface
    {
        try {
            return $this->getWorkbench()->model()->getObject($objectAlias);
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid "object_alias" "' . $objectAlias . '" at ' . $path . '.', 0, $e);
        }
    }
    

    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);

        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_DATASHEET)
                ->setDescription('DataSheet payload to import. The JSON schema is generated from `save_as` or `save_targets`.')
                ->setDataType(new UxonObject(['alias' => 'exface.Core.Array'])),
        ];
    }

    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}