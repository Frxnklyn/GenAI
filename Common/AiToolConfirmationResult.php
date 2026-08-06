<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Interfaces\AiToolConfirmationResultInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\AiResponseStatusMessageInterface;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Tool result that requests user confirmation before the actual action is executed.
 *
 * Return this from a tool's `invoke()` method when the action is destructive or
 * irreversible enough to require explicit approval. The agent will pause the LLM
 * loop, show the confirmation question together with Yes / No buttons in the chat
 * and re-invoke the tool with `__confirmed = true` once the user approves.
 */
class AiToolConfirmationResult implements AiToolConfirmationResultInterface
{
    private AiToolInterface $tool;
    private array $arguments;
    private string $question;
    /** @var ExceptionInterface[] */
    private array $exceptions = [];

    /**
     * @param AiToolInterface $tool      The tool that requested confirmation.
     * @param array           $arguments Original (named) arguments passed to the tool.
     * @param string          $question  Question shown to the user in the confirmation dialog.
     */
    public function __construct(AiToolInterface $tool, array $arguments, string $question)
    {
        $this->tool      = $tool;
        $this->arguments = $arguments;
        $this->question  = $question;
    }

    public function getConfirmationQuestion(): string
    {
        return $this->question;
    }

    public function getTool(): AiToolInterface
    {
        return $this->tool;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * Text returned to the LLM if it is ever reached (should not happen in normal flow).
     */
    public function getValue(): string
    {
        return 'Awaiting user confirmation: ' . $this->question;
    }

    public function getValueAsMarkdown(): string
    {
        return $this->getValue();
    }

    public function getValueDataType(): DataTypeInterface
    {
        return DataTypeFactory::createBaseDataType($this->getWorkbench());
    }

    /** @return AiResponseStatusMessageInterface[] */
    public function getStatusMessages(): array
    {
        return [];
    }

    public function getAppendix(): array
    {
        return [];
    }

    /** @return ExceptionInterface[] */
    public function getExceptions(): array
    {
        return $this->exceptions;
    }

    public function addException(\Throwable $exception): AiToolConfirmationResultInterface
    {
        $this->exceptions[] = $exception;
        return $this;
    }

    /** Confirmation results are not failures – they are deliberate pauses. */
    public function isFailed(): bool
    {
        return false;
    }

    public function __toString(): string
    {
        return $this->getValue();
    }

    public function getWorkbench(): WorkbenchInterface
    {
        return $this->tool->getWorkbench();
    }
}
