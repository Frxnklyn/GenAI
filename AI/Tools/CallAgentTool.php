<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiPrompt;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\Selectors\AiAgentSelector;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use GuzzleHttp\Psr7\ServerRequest;

/**
 * Delegate a text prompt to a configured AI agent and return its reply.
 * 
 * Set `agent_alias` as in the AIChat widget and describe when to use the target agent.
 * Each call starts a separate conversation without the caller's history or attachments.
 * 
 * ```json
 * {
 *     "alias": "axenox.GenAI.CallAgentTool",
 *     "agent_alias": "my.App.PolicyAssistant",
 *     "description": "Ask the policy assistant a question about internal policies."
 * }
 * 
 * ```
 * 
 * @author Brooklyn Fränzschky
 */
class CallAgentTool extends AbstractAiTool
{
    private ?string $agentAlias = null;

    private ?UxonObject $inputData = null;

    // RekursivSchutz:
    // TODO Logik derzeit nur als Prototyp vorhanden.
    /** @var array<int, string[]> Active caller aliases by Workbench. */
    private static array $activeCallers = [];

    /**
     * The agent to call, optionally with a version constraint (for example `my.App.PolicyAssistant:^1.0`).
     * 
     * @uxon-property agent_alias
     * @uxon-type metamodel:axenox.GenAI.AI_AGENT:ALIAS_WITH_NS
     * @uxon-required true
     * 
     * @param string $alias
     * @return $this
     */
    protected function setAgentAlias(string $alias) : CallAgentTool
    {
        $this->agentAlias = trim($alias);
        return $this;
    }

    /**
     * Optional DataSheet to pass to the target agent alongside the prompt.
     * 
     * Set the object and row fields in UXON. Use `[#argument_name#]` as a whole cell
     * value to insert a scalar tool argument, for example `{"id": "[#log_id#]"}`.
     * 
     * @uxon-property input_data
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataSheet
     * @uxon-template {"object_alias": "", "rows": [{}]}
     * @uxon-placeholder argument_name
     * 
     * @param UxonObject $uxon
     * @return $this
     */
    protected function setInputData(UxonObject $uxon) : CallAgentTool
    {
        $this->inputData = $uxon;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function invoke(AiTaskHandlerInterface $agent, TaskInterface $task, array $arguments) : AiToolResultInterface
    {
        if ($this->agentAlias === null || $this->agentAlias === '') {
            throw new AiToolRuntimeError($this, $task, 'Configure agent_alias before calling an agent.');
        }
        $text = $arguments[0] ?? null;
        if (! is_string($text) || trim($text) === '') {
            throw new AiToolRuntimeError($this, $task, 'Provide a non-empty text prompt for the target agent.');
        }

        $workbench = $this->getWorkbench();
        $workbenchId = spl_object_id($workbench);
        $callerAlias = mb_strtolower($agent->getSelector()->getAliasWithNamespace());
        $targetAlias = mb_strtolower((new AiAgentSelector($workbench, $this->agentAlias))->getAliasWithNamespace());
        if ($targetAlias === $callerAlias || in_array($targetAlias, self::$activeCallers[$workbenchId] ?? [], true)) {
            throw new AiToolRuntimeError($this, $task, 'Recursive agent call to "' . $this->agentAlias . '" is not allowed.');
        }

        self::$activeCallers[$workbenchId][] = $callerAlias;
        try {
            // An empty request prevents HttpTask from importing the caller's HTTP parameters.
            $prompt = new AiPrompt($workbench, null, new ServerRequest('POST', '/'));
            $prompt->setPrompt($text);
            if ($this->inputData !== null) {
                $inputData = $this->renderInputData($task, $arguments);
                $prompt->setInputData(DataSheetFactory::createFromUxon($workbench, $inputData));
            }
            $target = $this->resolveAgent($this->agentAlias);
            $response = $target->handle($prompt);
            return new AiToolResultString($workbench, $response->getMessage(), $this->getReturnDataType());
        } finally {
            array_pop(self::$activeCallers[$workbenchId]);
            if (self::$activeCallers[$workbenchId] === []) {
                unset(self::$activeCallers[$workbenchId]);
            }
        }
    }

    /**
     * Bind arguments only to cell values, never to the DataSheet structure.
     */
    private function renderInputData(TaskInterface $task, array $arguments) : UxonObject
    {
        $namedArguments = [];
        foreach ($this->getArguments() as $index => $parameter) {
            if (array_key_exists($index, $arguments)) {
                $namedArguments[$parameter->getName()] = $arguments[$index];
            }
        }

        $inputData = $this->inputData->toArray();
        if (! array_key_exists('rows', $inputData)) {
            return new UxonObject($inputData);
        }
        if (! is_array($inputData['rows']) || ! array_is_list($inputData['rows'])) {
            throw new AiToolRuntimeError($this, $task, 'input_data.rows must be a list of DataSheet rows.');
        }
        foreach ($inputData['rows'] as $rowIndex => $row) {
            if (! is_array($row)) {
                throw new AiToolRuntimeError($this, $task, 'Each input_data row must be an object.');
            }
            foreach ($row as $column => $value) {
                if (! is_string($value) || ! preg_match('/\A\[#([^#]+)#\]\z/', $value, $matches)) {
                    continue;
                }
                $name = $matches[1];
                if (! array_key_exists($name, $namedArguments)) {
                    throw new AiToolRuntimeError($this, $task, 'Unknown input_data argument "' . $name . '".');
                }
                $argument = $namedArguments[$name];
                if ($argument !== null && ! is_scalar($argument)) {
                    throw new AiToolRuntimeError($this, $task, 'input_data argument "' . $name . '" must be scalar.');
                }
                $inputData['rows'][$rowIndex][$column] = $argument;
            }
        }
        return new UxonObject($inputData);
    }

    /**
     * Resolve the configured selector just as AIChat does.
     */
    protected function resolveAgent(string $selector) : AiAgentInterface
    {
        return AiFactory::createAgentFromString($this->getWorkbench(), $selector);
    }

    /**
     * @inheritDoc
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench) : array
    {
        return [
            (new ServiceParameter(new self($workbench)))
                ->setName('prompt')
                ->setDescription('A self-contained text request for the target agent.')
                ->setRequired(true)
        ];
    }

    /**
     * @inheritDoc
     */
    public function getReturnDataType() : DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), StringDataType::class);
    }
}
