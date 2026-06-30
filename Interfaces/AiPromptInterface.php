<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\Filesystem\FileInterface;
use exface\Core\Interfaces\iCanGenerateDebugWidgets;
use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiPromptInterface extends TaskInterface, iCanGenerateDebugWidgets
{
    /**
     * 
     * @return string
     */
    public function getUserPrompt() : string;


    public function getConversation() : ?AiConversationInterface;

    public function setConversation(AiConversationInterface $conversation) : AiPromptInterface;

    /**
     * Returns an incoming conversation UID from the prompt payload.
     */
    public function getConversationUid() : ?string;

    /**
     * @param string $text
     * @return AiPromptInterface
     */
    public function setPrompt(string $text) : AiPromptInterface;

    /**
     * @return FileInterface[]
     */
    public function getFiles(): array;

    public function hasKnowledge(string $key) : bool;

    public function addKnowledge(string $key, string $content) : AiPromptInterface;
    
    public function getKnowledge() : array;
}