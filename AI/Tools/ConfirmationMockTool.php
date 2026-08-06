<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolConfirmationResult;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Factories\AiResponseStatusMessageFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Demo tool that exercises the full confirmation flow including LLM continuation.
 *
 * **Case 1 – first LLM call (no confirmation yet)**
 * Returns `AiToolConfirmationResult`. The agent pauses, shows
 * "Möchtest du das speichern?" with Yes / No buttons, and waits.
 *
 * **Case 2a – user clicked "Yes"**
 * The agent re-invokes the tool with `__confirmed = true`. The tool simulates
 * saving and returns a success result. The agent then feeds the result back to
 * the LLM so it can respond naturally ("I have saved …").
 *
 * **Case 2b – user clicked "No"**
 * The agent does NOT re-invoke the tool. It sends "The user declined …" to
 * the LLM as the tool result, so the LLM can acknowledge the cancellation
 * and continue the conversation.
 *
 * ## UXON example
 *
 * ```json
 * {
 *   "alias": "axenox.GenAI.ConfirmationMockTool",
 *   "name": "save_data",
 *   "description": "Saves the provided text. Always ask the user to confirm before calling this tool."
 * }
 * ```
 */
class ConfirmationMockTool extends AbstractAiTool
{
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $text = $arguments['text'] ?? $arguments[0] ?? '(no text provided)';

        // If the user has already confirmed, perform the simulated save
        if (! empty($arguments['__confirmed'])) {
            $result = new AiToolResultString(
                $this,
                $arguments,
                'Data saved successfully: "' . $text . '"',
                $this->getReturnDataType()
            );
            $result->addStatusMessage(
                AiResponseStatusMessageFactory::createOkMessage('Saved: "' . $text . '"')
            );
            return $result;
        }

        // First call – ask the user for confirmation before saving
        return new AiToolConfirmationResult(
            $this,
            $arguments,
            'Möchtest du das speichern? ("' . $text . '")'
        );
    }

    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }

    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        return [];
    }
}
