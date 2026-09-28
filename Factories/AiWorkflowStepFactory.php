<?php
namespace axenox\GenAI\Factories;

use axenox\GenAI\Common\Selectors\AiWorkflowStepSelector;
use axenox\GenAI\Interfaces\AiWorkflowStepInterface;
use axenox\GenAI\Interfaces\Selectors\AiWorkflowStepSelectorInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\PhpFilePathDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Creates polymorphic workflow steps from Action-like UXON aliases.
 */
abstract class AiWorkflowStepFactory
{
    /**
     * Creates a workflow step from UXON containing an alias discriminator.
     */
    public static function createFromUxon(
        WorkbenchInterface $workbench,
        UxonObject $uxon
    ) : AiWorkflowStepInterface {
        $alias = trim((string) $uxon->getProperty('alias'));
        if ($alias === '') {
            throw new InvalidArgumentException('AI workflow step UXON requires an alias');
        }

        $selector = new AiWorkflowStepSelector($workbench, $alias);
        $class = static::findClass($selector);
        $stepUxon = $uxon->copy();
        $stepUxon->unsetProperty('alias');
        $step = new $class($selector, $stepUxon);
        if (! $step instanceof AiWorkflowStepInterface) {
            throw new InvalidArgumentException(
                'AI workflow step prototype "' . $class . '" must implement ' . AiWorkflowStepInterface::class
            );
        }

        return $step;
    }

    /**
     * Resolves the PHP class selected for a workflow step.
     */
    public static function findClass(AiWorkflowStepSelectorInterface $selector) : string
    {
        switch (true) {
            case $selector->isAlias():
                $alias = $selector->toString();
                $delimiterPosition = strrpos($alias, AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER);
                if ($delimiterPosition === false) {
                    throw new InvalidArgumentException('Workflow step aliases require an app namespace');
                }
                $appAlias = substr($alias, 0, $delimiterPosition);
                $stepAlias = substr($alias, $delimiterPosition + 1);
                $path = $selector->getWorkbench()->filemanager()->getPathToVendorFolder()
                    . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $appAlias)
                    . DIRECTORY_SEPARATOR . 'AI'
                    . DIRECTORY_SEPARATOR . 'WorkflowSteps'
                    . DIRECTORY_SEPARATOR . $stepAlias . '.php';
                return PhpFilePathDataType::findClassInFile($path);
            case $selector->isClassname():
                return $selector->toString();
            case $selector->isFilepath():
                return PhpFilePathDataType::findClassInFile($selector->toString());
            default:
                throw new InvalidArgumentException(
                    'Cannot resolve AI workflow step "' . $selector->toString() . '"'
                );
        }
    }
}