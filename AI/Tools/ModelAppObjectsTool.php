<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolCriticalError;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Exceptions\AiToolRuntimeWarning;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\ObjectMarkdownPrinter;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Lists all metaobjects belonging to an app alias.
 */
class ModelAppObjectsTool extends AbstractAiTool
{
    public const ARG_SEARCH_TERM = 'search_term';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $toolWarnings = [];
        $appAlias = trim((string) ($arguments[0] ?? ''));
        if ($appAlias === '') {
            $error = new AiToolRuntimeError($this, $prompt, 'Missing required argument: `search_term`');
            return new AiToolResultString($this, $arguments, $error->getMessage(), $this->getReturnDataType(), [], [$error]);
        }

        try {
            $appUid = $this->getAppUid($appAlias);
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.OBJECT');
            $ds->getColumns()->addMultiple(['UID', 'NAME', 'ALIAS', 'ALIAS_WITH_NS']);
            $ds->getFilters()->addConditionFromString('APP', $appUid, ComparatorDataType::EQUALS);
            $ds->dataRead();
        } catch (\Throwable $e) {
            $error = new AiToolRuntimeError($this, $prompt, 'Failed to read app objects: ' . $e->getMessage(), null, $e);
            return new AiToolResultString($this, $arguments, $e->getMessage(), $this->getReturnDataType(), [], [$error]);
        }

        $rows = $ds->getRows();
        if (empty($rows)) {
            $notFoundMsg = 'No objects found for app alias `' . $appAlias . '`.';
            $warning = new AiToolRuntimeWarning($this, $prompt, $notFoundMsg);
            return new AiToolResultString($this, $arguments, $notFoundMsg, $this->getReturnDataType(), [], [$warning]);
        }

        $objectMarkdowns = [];
        $objectAliases = [];
        foreach ($rows as $row) {
            $objectAliases[] = $row['ALIAS_WITH_NS'];
            $selector = $row['UID'] ?? $row['ALIAS_WITH_NS'] ?? null;
            if (! is_string($selector) || $selector === '') {
                throw new AiToolCriticalError($this, $prompt, 'Invalid object result: no selector found in object data in row ' . json_encode($row));
            }

            try {
                $objectMarkdowns[] = (new ObjectMarkdownPrinter($this->getWorkbench(), $selector, 0))->getMarkdown();
            } catch (\Throwable $e) {
                $toolWarnings[] = new AiToolRuntimeWarning($this, $prompt, 'Failed to render object markdown. ' . $e->getMessage(), null, $e);
            }
        }

        $details = implode("\n\n---\n\n", $objectMarkdowns);
        $aliasList = "\n- `" . implode("`\n- `", $objectAliases) . '`';
        $result = <<<MD
# App object results

Objects in app `{$appAlias}`:
{$aliasList}

{$details}
MD;

        $toolResult = new AiToolResultString($this, $arguments, $result, $this->getReturnDataType());
        foreach ($toolWarnings as $warning) {
            $toolResult->addException($warning);
        }

        return $toolResult;
    }

    /**
     * Gibt die Uid für die App zurück
     *
     *
     * @param string $appAlias
     * @return string
     */
    protected function getAppUid(string $appAlias): string
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), "exface.Core.APP");

        $ds->getColumns()->addMultiple(["UID", "ALIAS"]);

        $ds->getFilters()->addConditionFromString("ALIAS", $appAlias, ComparatorDataType::EQUALS);

        $ds->dataRead();

        $rows = $ds->getRows();
        if (empty($rows)) {
            throw new \RuntimeException('No app found for alias `' . $appAlias . '`.');
        }

        return $rows[0]['UID'];
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
                ->setName(self::ARG_SEARCH_TERM)
                ->setDescription('The alias of the app to list objects for')
                ->setRequired(true)
                ->setExamples([
                    'exface.Core'
                ])
        ];
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