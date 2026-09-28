<?php
namespace axenox\GenAI\Interfaces\Selectors;

use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Interfaces\Selectors\PrototypeSelectorInterface;

/**
 * Identifies an AI workflow step prototype by alias, class name or file path.
 */
interface AiWorkflowStepSelectorInterface extends PrototypeSelectorInterface, AliasSelectorInterface
{}